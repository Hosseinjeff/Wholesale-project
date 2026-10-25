function appendSupplierOfferToInventoryLog(spreadsheet, product, messageData, existingKeys) {
  const inventorySheet = getOrCreateSheet(spreadsheet, 'Inventory log', INVENTORY_LOG_HEADERS);
  ensureSheetHeaders(inventorySheet, INVENTORY_LOG_HEADERS);
  const inventoryValues = inventorySheet.getDataRange().getValues();
  const inventoryHeaders = inventoryValues[0] || [];
  const keys = existingKeys || buildInventoryMergeKeySet(inventoryValues, inventoryHeaders);
  validateProductPriceScale(product, messageData, inventoryValues, inventoryHeaders);
  const channelRow = createChannelObjectFromProduct(product, messageData);
  channelRow['Product ID'] = findExistingInventoryProductId(inventoryValues, inventoryHeaders, channelRow) || generateBotProductId();
  const key = getChannelMergeKey(channelRow);

  if (key && keys[key]) {
    const existing = keys[key];
    if (existing.status === 'waiting_confirmation' && existing.rowIndex) {
      const row = createInventoryLogRowFromChannelRow(channelRow, inventoryHeaders);
      inventorySheet.getRange(existing.rowIndex, 1, 1, row.length).setValues([row]);
      formatInventoryDateColumn(inventorySheet, inventoryHeaders, 'Original Post Date', existing.rowIndex, 1);
      keys[key] = { rowIndex: existing.rowIndex, status: channelRow['Status'] || 'for sale' };
      return { row: existing.rowIndex, skipped: false, updated: true };
    }
    return { row: null, skipped: true };
  }

  const row = createInventoryLogRowFromChannelRow(channelRow, inventoryHeaders);
  inventorySheet.appendRow(row);
  const rowIndex = inventorySheet.getLastRow();
  formatInventoryDateColumn(inventorySheet, inventoryHeaders, 'Original Post Date', rowIndex, 1);
  if (key) keys[key] = { rowIndex: rowIndex, status: channelRow['Status'] || 'for sale' };
  return { row: rowIndex, skipped: false };
}

function resolveExtractionStatusPhrase(extractionStatus, priceConfirmationMethod) {
  const extraction = String(extractionStatus || '').trim();
  const method = String(priceConfirmationMethod || '').trim();
  return method || extraction;
}

function createChannelObjectFromProduct(product, messageData) {
  const normalized = _normalizePrices(product || {});
  return {
    'Product Name': product.name || product.product_name || 'Unknown',
    'Import Timestamp': new Date(),
    'Original Post Date': messageData.original_post_date || messageData.originalPostDate || '',
    'Product ID': generateBotProductId(),
    'Variation Type': product.variation_type || product.variant || '',
    'Brand': product.brand || '',
    'Category': product.category || '',
    'Sale Price': normalized.sale_price,
    'Consumer Price': normalized.consumer_price,
    'Unit Price': normalized.unit_price,
    'Package Price': normalized.package_price,
    'Consumer Package Price': normalized.consumer_package_price,
    'Regular Package Price': normalized.consumer_package_price,
    'Margin': normalized.consumer_price && normalized.sale_price ? (1 - normalized.sale_price / normalized.consumer_price) : '',
    'Packaging': product.packaging || '',
    'Description': product.description || '',
    'Volume': product.volume || '',
    'Weight': product.weight || '',
    'Stock Status': product.stock_status || product.status || 'Available',
    'Extraction Warning': getProductExtractionWarning(product),
    'Original Message': messageData.content || '',
    'Channel Username': messageData.channel_username || messageData.channel || '',
    'Message ID (Platform)': product.message_id || product.messageId || messageData.id || '',
    'Message Link': messageData.url || messageData.message_url || '',
    'Extraction Confidence Score': product.extraction_confidence || product.confidence || 0,
    'Extraction Status': resolveExtractionStatusPhrase(product.extraction_status || '', product.price_confirmation_method || ''),
    'Anchor Type': product.price_anchor_type || '',
    'Anchor ID': product.price_anchor_id || '',
    'Anchor Name': product.price_anchor_name || '',
    'Anchor Score': product.price_anchor_match_score || '',
    'Detected Price': product.detected_price || '',
    'Batch ID': messageData.batch_id || ''
  };
}

function mergeChannelsToInventoryLog() {
  const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
  const channelsSheet = getRequiredSheetByAnyName(spreadsheet, ['Channels', 'channels', 'channel']);
  const inventorySheet = getRequiredSheetByAnyName(spreadsheet, ['Inventory log', 'inventory log']);
  ensureSheetHeaders(inventorySheet, INVENTORY_LOG_HEADERS);

  const channelRows = readSheetObjects(channelsSheet);
  const inventoryValues = inventorySheet.getDataRange().getValues();
  const inventoryHeaders = inventoryValues[0] || [];
  const existingKeys = buildInventoryMergeKeySet(inventoryValues, inventoryHeaders);
  const rowsToAppend = [];

  channelRows.forEach(function(channelRow) {
    const key = getChannelMergeKey(channelRow);
    if (!key || existingKeys[key]) return;
    channelRow['Product ID'] = findExistingInventoryProductId(inventoryValues, inventoryHeaders, channelRow) || getEnglishProductId(firstValue(channelRow, ['Product ID']));
    rowsToAppend.push(createInventoryLogRowFromChannelRow(channelRow, inventoryHeaders));
    existingKeys[key] = true;
  });

  if (rowsToAppend.length > 0) {
    const startRow = inventorySheet.getLastRow() + 1;
    inventorySheet.getRange(startRow, 1, rowsToAppend.length, inventoryHeaders.length).setValues(rowsToAppend);
    formatInventoryDateColumn(inventorySheet, inventoryHeaders, 'Original Post Date', startRow, rowsToAppend.length);
  }

  return jsonResponse({
    status: 'success',
    message: 'Channels merged into Inventory log',
    source_sheet: channelsSheet.getName(),
    inventory_sheet: inventorySheet.getName(),
    scanned_channel_rows: channelRows.length,
    appended_rows: rowsToAppend.length,
    timestamp: new Date().toISOString()
  });
}

function buildInventoryMergeKeySet(values, headers) {
  const keys = {};
  const headerMap = headerIndexMap(headers);
  for (let i = 1; i < values.length; i++) {
    const row = values[i];
    const source = row[headerMap['Source Sheet']] || '';
    if (String(source).toLowerCase() !== 'channels') continue;
    const key = [
      source,
      row[headerMap['Channel Username']] || '',
      row[headerMap['Message ID (Platform)']] || '',
      row[headerMap['Product Name']] || '',
      row[headerMap['Variation Type']] || ''
    ].join('|');
    keys[key] = {
      rowIndex: i + 1,
      status: String(row[headerMap['Status']] || '').toLowerCase()
    };
  }
  return keys;
}

function createInventoryLogRowFromChannelRow(channelRow, inventoryHeaders) {
  const packageCount = extractPackageCount(firstValue(channelRow, ['Packaging']));
  const importTimestamp = normalizeSheetTimestamp(firstValue(channelRow, ['Import Timestamp', 'Timestamp']));
  const originalPostDate = normalizeOriginalPostDate(firstValue(channelRow, ['Original Post Date']));
  const productId = getEnglishProductId(firstValue(channelRow, ['Product ID']));
  const explicitSalePackagePrice = firstNumber(channelRow, ['Package Price']);
  const explicitRegularPackagePrice = firstNumber(channelRow, ['Consumer Package Price', 'Regular Package Price', 'Retail Package Price']);
  const explicitSaleUnitPrice = firstNumber(channelRow, ['Unit Price']) || (explicitSalePackagePrice ? 0 : firstNumber(channelRow, ['Sale Price', 'Wholesale Price', 'Price']));
  const explicitRegularUnitPrice = firstNumber(channelRow, ['Consumer Unit Price', 'Regular Unit Price']) || (explicitRegularPackagePrice ? 0 : firstNumber(channelRow, ['Consumer Price', 'Regular Price', 'Retail Price']));
  const salePair = inferUnitPackagePair(
    explicitSaleUnitPrice,
    explicitSalePackagePrice,
    packageCount
  );
  const regularPair = inferUnitPackagePair(
    explicitRegularUnitPrice,
    explicitRegularPackagePrice,
    packageCount
  );
  const unit = inferInventoryUnit(channelRow, packageCount);
  const extractionWarning = firstValue(channelRow, ['Extraction Warning']);
  const data = {
    'OK?': extractionWarning ? false : true,
    'Product Name': firstValue(channelRow, ['Product Name', 'Name', 'Title', 'Product']),
    'Import Timestamp': importTimestamp,
    'Original Post Date': originalPostDate,
    'Product ID': productId,
    'Action': firstValue(channelRow, ['Action']) || 'supplier_offer',
    'Status': firstValue(channelRow, ['Status']) || 'for sale',
    'Owner': firstValue(channelRow, ['Channel Username', 'Channel', 'Supplier', 'Source']),
    'Exp. span': '',
    'prod. Date': '',
    'Count': 0,
    'unit': unit,
    'Variation Type': firstValue(channelRow, ['Variation Type']),
    'Brand': firstValue(channelRow, ['Brand']),
    'Category': firstValue(channelRow, ['Category']),
    'Packaging': packageCount || '',
    'هزینه (تومان)': '',
    'Exp. date': '',
    'Exp. Warning': extractionWarning,
    'Sale Price': salePair.package,
    'Sale Unit Price': salePair.unit,
    'Regular Package Price': regularPair.package,
    'Regular Unit Price': regularPair.unit,
    'Source Sheet': 'Channels',
    'Channel Username': firstValue(channelRow, ['Channel Username', 'Channel', 'Supplier', 'Source']),
    'Message ID (Platform)': firstValue(channelRow, ['Message ID (Platform)', 'Message ID', 'ID']),
    'Message Link': firstValue(channelRow, ['Message Link', 'URL', 'Message URL']),
    'Original Message': firstValue(channelRow, ['Original Message', 'Description']),
    'Volume': firstValue(channelRow, ['Volume']),
    'Weight': firstValue(channelRow, ['Weight']),
    'Extraction Confidence Score': firstValue(channelRow, ['Extraction Confidence Score']),
    'Extraction Status': resolveExtractionStatusPhrase(firstValue(channelRow, ['Extraction Status']), firstValue(channelRow, ['Price Confirmation Method'])),
    'Anchor Type': firstValue(channelRow, ['Anchor Type']),
    'Anchor ID': firstValue(channelRow, ['Anchor ID']),
    'Anchor Name': firstValue(channelRow, ['Anchor Name']),
    'Anchor Score': firstValue(channelRow, ['Anchor Score']),
    'Detected Price': firstValue(channelRow, ['Detected Price']),
    'Batch ID': firstValue(channelRow, ['Batch ID'])
  };

  const normalizedData = {};
  Object.keys(data).forEach(function(key) {
    normalizedData[normalizeHeaderKey(key)] = data[key];
  });
  return inventoryHeaders.map(function(header) {
    const value = normalizedData[normalizeHeaderKey(header)];
    return value !== undefined ? value : '';
  });
}

function getChannelMergeKey(channelRow) {
  return [
    'Channels',
    firstValue(channelRow, ['Channel Username', 'Channel', 'Supplier', 'Source']),
    firstValue(channelRow, ['Message ID (Platform)', 'Message ID', 'ID']),
    firstValue(channelRow, ['Product Name', 'Name', 'Title', 'Product']),
    firstValue(channelRow, ['Variation Type'])
  ].join('|');
}

function headerIndexMap(headers) {
  const map = {};
  for (let i = 0; i < headers.length; i++) map[headers[i]] = i;
  return map;
}

function findHeaderIndex(headers, target) {
  const wanted = normalizeHeaderKey(target);
  for (let i = 0; i < headers.length; i++) {
    if (normalizeHeaderKey(headers[i]) === wanted) return i;
  }
  return undefined;
}

function findHeaderIndexes(headers, target) {
  const wanted = normalizeHeaderKey(target);
  const indexes = [];
  for (let i = 0; i < headers.length; i++) {
    if (normalizeHeaderKey(headers[i]) === wanted) indexes.push(i);
  }
  return indexes;
}

function normalizeHeaderKey(value) {
  return String(value || '').replace(/[\s\u200B-\u200D\uFEFF]+/g, '').toLowerCase();
}

function normalizeIsoTimestamp(value) {
  if (!value) return '';
  const date = value instanceof Date ? value : new Date(value);
  if (isNaN(date.getTime())) return String(value);
  return date.toISOString();
}

function normalizeSheetTimestamp(value) {
  if (!value) return new Date();
  const date = value instanceof Date ? value : new Date(value);
  return isNaN(date.getTime()) ? new Date() : date;
}

function normalizeOriginalPostDate(value) {
  if (value === '' || value === null || value === undefined) return '';
  if (value instanceof Date) return isNaN(value.getTime()) ? '' : new Date(value.getTime());
  const numeric = typeof value === 'number' || /^\d+(?:\.\d+)?$/.test(String(value).trim());
  const date = numeric
    ? new Date(Number(value) * (Math.abs(Number(value)) < 1000000000000 ? 1000 : 1))
    : new Date(value);
  return isNaN(date.getTime()) ? '' : date;
}

function formatInventoryDateColumn(sheet, headers, header, startRow, rowCount) {
  const index = findHeaderIndex(headers, header);
  if (index !== undefined && rowCount > 0) {
    sheet.getRange(startRow, index + 1, rowCount, 1).setNumberFormat('yyyy-mm-dd hh:mm:ss');
  }
}

function generateBotProductId() {
  return 'prod_' + Utilities.getUuid().replace(/-/g, '').substring(0, 10);
}

function getEnglishProductId(value) {
  const id = String(value || '').trim();
  if (/^[A-Za-z0-9_\-]+$/.test(id)) return id;
  return generateBotProductId();
}

function findExistingInventoryProductId(values, headers, channelRow) {
  const headerMap = headerIndexMap(headers);
  const idIdx = headerMap['Product ID'];
  const nameIdx = headerMap['Product Name'];
  const brandIdx = headerMap['Brand'];
  const variationIdx = headerMap['Variation Type'];
  const existingName = normalizeProductMatchText(firstValue(channelRow, ['Product Name', 'Name', 'Title', 'Product']));
  const existingBrand = normalizeProductMatchText(firstValue(channelRow, ['Brand']));
  const existingVariation = normalizeProductMatchText(firstValue(channelRow, ['Variation Type']));
  if (!existingName || idIdx === undefined || nameIdx === undefined) return '';

  for (let i = values.length - 1; i >= 1; i--) {
    const row = values[i];
    const rowId = row[idIdx];
    if (!rowId || !/^[A-Za-z0-9_\-]+$/.test(String(rowId))) continue;
    if (normalizeProductMatchText(row[nameIdx]) !== existingName) continue;
    if (brandIdx !== undefined && existingBrand && normalizeProductMatchText(row[brandIdx]) !== existingBrand) continue;
    if (variationIdx !== undefined && existingVariation && normalizeProductMatchText(row[variationIdx]) !== existingVariation) continue;
    return String(rowId);
  }
  return '';
}

function normalizeProductMatchText(value) {
  return String(value || '').trim().toLowerCase().replace(/\s+/g, ' ');
}

function applyPriceAnchorToInventoryRows(spreadsheet, messageId, anchor) {
  if (!anchor) return 0;
  const inventorySheet = getOrCreateSheet(spreadsheet, 'Inventory log', INVENTORY_LOG_HEADERS);
  ensureSheetHeaders(inventorySheet, INVENTORY_LOG_HEADERS);
  const values = inventorySheet.getDataRange().getValues();
  const headers = values[0] || [];
  const messageIdx = findHeaderIndex(headers, 'Message ID (Platform)');
  const typeIdx = findHeaderIndex(headers, 'Anchor Type');
  const idIdx = findHeaderIndex(headers, 'Anchor ID');
  const nameIdx = findHeaderIndex(headers, 'Anchor Name');
  const scoreIdx = findHeaderIndex(headers, 'Anchor Score');
  const productNameIdx = findHeaderIndex(headers, 'Product Name');
  const targetProductName = normalizeProductMatchText(anchor.product_name || '');
  if (messageIdx === undefined) return 0;
  let updated = 0;
  for (let i = 1; i < values.length; i++) {
    if (String(values[i][messageIdx] || '').trim() !== String(messageId || '').trim()) continue;
    if (targetProductName && productNameIdx !== undefined && normalizeProductMatchText(values[i][productNameIdx]) !== targetProductName) continue;
    if (typeIdx !== undefined) inventorySheet.getRange(i + 1, typeIdx + 1).setValue(anchor.type || 'reference');
    if (idIdx !== undefined) inventorySheet.getRange(i + 1, idIdx + 1).setValue(anchor.id || '');
    if (nameIdx !== undefined) inventorySheet.getRange(i + 1, nameIdx + 1).setValue(anchor.name || '');
    if (scoreIdx !== undefined) inventorySheet.getRange(i + 1, scoreIdx + 1).setValue(anchor.match_score || '');
    updated++;
  }
  return updated;
}

function applyPriceConfirmationToWaitingInventory(spreadsheet, messageId, choice) {
  const inventorySheet = getOrCreateSheet(spreadsheet, 'Inventory log', INVENTORY_LOG_HEADERS);
  ensureSheetHeaders(inventorySheet, INVENTORY_LOG_HEADERS);
  const values = inventorySheet.getDataRange().getValues();
  const headers = values[0] || [];
  const hm = headerIndexMap(headers);
  const messageIdx = hm['Message ID (Platform)'] !== undefined ? hm['Message ID (Platform)'] : findHeaderIndex(headers, 'Message ID (Platform)');
  const statusIdx = hm['Status'] !== undefined ? hm['Status'] : findHeaderIndex(headers, 'Status');
  const salePackageIdx = hm['Sale Price'] !== undefined ? hm['Sale Price'] : findHeaderIndex(headers, 'Sale Price');
  const saleUnitIdx = hm['Sale Unit Price'] !== undefined ? hm['Sale Unit Price'] : findHeaderIndex(headers, 'Sale Unit Price');
  const regularPackageIdx = hm['Regular Package Price'] !== undefined ? hm['Regular Package Price'] : findHeaderIndex(headers, 'Regular Package Price');
  const regularUnitIdx = hm['Regular Unit Price'] !== undefined ? hm['Regular Unit Price'] : findHeaderIndex(headers, 'Regular Unit Price');
  const originalMessageIdx = hm['Original Message'] !== undefined ? hm['Original Message'] : findHeaderIndex(headers, 'Original Message');
  const packageIdx = hm['Packaging'] !== undefined ? hm['Packaging'] : findHeaderIndex(headers, 'Packaging');
  const extractionIndexes = findHeaderIndexes(headers, 'Extraction Status');
  const detectedIdx = hm['Detected Price'] !== undefined ? hm['Detected Price'] : findHeaderIndex(headers, 'Detected Price');
  if (messageIdx === undefined || statusIdx === undefined) return { updated: 0 };

  let updated = 0;
  let matched = 0;
  for (let i = 1; i < values.length; i++) {
    const row = values[i];
    if (String(row[messageIdx] || '').trim() !== String(messageId || '').trim()) continue;
    matched++;

    const salePackage = salePackageIdx !== undefined ? Number(row[salePackageIdx] || 0) : 0;
    const saleUnit = saleUnitIdx !== undefined ? Number(row[saleUnitIdx] || 0) : 0;
    const storedDetected = detectedIdx !== undefined ? Number(row[detectedIdx] || 0) : 0;
    const detected = storedDetected || saleUnit || salePackage;
    const packageCount = packageIdx !== undefined ? Number(row[packageIdx] || 0) : 0;

    if (choice === 'sale_unit' && saleUnitIdx !== undefined && detected > 0) {
      inventorySheet.getRange(i + 1, saleUnitIdx + 1).setValue(detected);
      if (salePackageIdx !== undefined && packageCount > 1) inventorySheet.getRange(i + 1, salePackageIdx + 1).setValue(Math.round(detected * packageCount));
    }
    if (choice === 'sale_package' && salePackageIdx !== undefined && detected > 0) {
      inventorySheet.getRange(i + 1, salePackageIdx + 1).setValue(detected);
      if (saleUnitIdx !== undefined && packageCount > 1) inventorySheet.getRange(i + 1, saleUnitIdx + 1).setValue(Math.round(detected / packageCount));
    }

    const originalMessage = originalMessageIdx !== undefined ? row[originalMessageIdx] : '';
    const explicitRegular = extractExplicitRegularPricesFromMessage(originalMessage);
    const storedRegularUnit = regularUnitIdx !== undefined ? Number(row[regularUnitIdx] || 0) : 0;
    const storedRegularPackage = regularPackageIdx !== undefined ? Number(row[regularPackageIdx] || 0) : 0;
    const regularPair = inferUnitPackagePair(
      explicitRegular.unit_price || storedRegularUnit,
      explicitRegular.package_price || (explicitRegular.unit_price ? 0 : storedRegularPackage),
      packageCount
    );
    if (regularUnitIdx !== undefined && regularPair.unit) inventorySheet.getRange(i + 1, regularUnitIdx + 1).setValue(regularPair.unit);
    if (regularPackageIdx !== undefined && regularPair.package) inventorySheet.getRange(i + 1, regularPackageIdx + 1).setValue(regularPair.package);

    inventorySheet.getRange(i + 1, statusIdx + 1).setValue('for sale');
    const confirmedExtractionStatus = choice === 'sale_package' ? 'user_confirmed_sale_package' : 'user_confirmed_sale_unit';
    for (let j = 0; j < extractionIndexes.length; j++) {
      inventorySheet.getRange(i + 1, extractionIndexes[j] + 1).setValue(confirmedExtractionStatus);
    }
    updated++;
  }
  return { updated: updated, matched: matched };
}

function getPriceConfirmationHint(message) {
  try {
    const raw = message && message.raw_metadata ? JSON.parse(message.raw_metadata) : {};
    const choice = raw && raw.price_confirmation && raw.price_confirmation.choice;
    if (choice === 'sale_unit') return 'sale unit price';
    if (choice === 'sale_package') return 'sale package price';
  } catch (e) {}
  return '';
}

function parseExplicitPriceValue(value) {
  if (!value) return 0;
  var clean = persianToEnglishNumbers(String(value)).replace(/[\/،,٫\u066B\s]/g, '').replace(/\./g, '').replace(/\D/g, '');
  return parseInt(clean, 10) || 0;
}

function extractExplicitSalePricesFromMessage(messageText) {
  var text = persianToEnglishNumbers(String(messageText || '')).replace(/[\u200B-\u200D\uFEFF]/g, ' ');
  var result = { unit_price: 0, package_price: 0 };
  if (!text) return result;

  var unitPatterns = [
    /(?:قیمت\s*)?(?:دونه\s*ای|دانه\s*ای|تکی|هر\s*عدد|هر\s*دانه|هر\s*واحد|(?:هر\s*(?:یک\s*)?|یک\s*)(?:بسته|جعبه|باکس|پک|شیشه|بشکه)|کیلویی|کیلو(?:یی)?)(?:\s*[:：]?\s*)([0-9][0-9\s\/،,٫.]{2,})(?:\s*(?:تومن|تومان))?/i,
    /([0-9][0-9\s\/،,٫.]{2,})(?:\s*(?:تومن|تومان)\s*)(?:دونه\s*ای|دانه\s*ای|تکی|هر\s*عدد|هر\s*(?:بسته|جعبه|باکس|پک|شیشه|بشکه)|کیلویی|کیلو(?:یی)?)/i
  ];
  var packagePatterns = [
    /قیمت\s*(?:هر\s*)?(?:یک\s*)?(?:کارتن|کارتنی)(?:\s*[:：]?\s*)([0-9][0-9\s\/،,٫.]{2,})(?:\s*(?:تومن|تومان))?/i,
    /([0-9][0-9\s\/،,٫.]{2,})(?:\s*(?:تومن|تومان)\s*)(?:هر\s*)?(?:یک\s*)?(?:کارتن|کارتنی)/i,
    /قیمت\s*(?:کل\s*)?(?:باکس|پک|بسته|جعبه)(?:\s*[:：]?\s*)([0-9][0-9\s\/،,٫.]{2,})(?:\s*(?:تومن|تومان))?/i
  ];

  for (var i = 0; i < unitPatterns.length && !result.unit_price; i++) {
    var um = text.match(unitPatterns[i]);
    if (um) result.unit_price = parseExplicitPriceValue(um[1]);
  }
  for (var j = 0; j < packagePatterns.length && !result.package_price; j++) {
    var pm = text.match(packagePatterns[j]);
    if (pm) result.package_price = parseExplicitPriceValue(pm[1]);
  }
  return result;
}

function extractExplicitRegularPricesFromMessage(messageText) {
  var text = persianToEnglishNumbers(String(messageText || '')).replace(/[\u200B-\u200D\uFEFF]/g, ' ');
  var result = { unit_price: 0, package_price: 0 };
  if (!text) return result;

  var packagePatterns = [
    /(?:قیمت\s*)?(?:مصرف(?:\s*کننده)?|خرده)\s*(?:کارتن|کارتنی)\s*[:：]?\s*([0-9][0-9\s\/،,٫.]{2,})(?:\s*(?:تومن|تومان))?/i,
    /(?:قیمت\s*)?(?:مصرف(?:\s*کننده)?|خرده)\s*[:：]?\s*([0-9][0-9\s\/،,٫.]{2,})\s*(?:تومن|تومان)?\s*(?:کارتن|کارتنی)/i
  ];
  for (var i = 0; i < packagePatterns.length && !result.package_price; i++) {
    var packageMatch = text.match(packagePatterns[i]);
    if (packageMatch) result.package_price = parseExplicitPriceValue(packageMatch[1]);
  }

  if (!result.package_price) {
    var unitMatch = text.match(/(?:قیمت\s*)?(?:مصرف(?:\s*کننده)?|خرده)\s*[:：]?\s*([0-9][0-9\s\/،,٫.]{2,})(?:\s*(?:تومن|تومان))?/i);
    if (unitMatch) result.unit_price = parseExplicitPriceValue(unitMatch[1]);
  }
  return result;
}

function applyExplicitMessagePrices(messageText, product) {
  if (!product) return '';
  var prices = extractExplicitSalePricesFromMessage(messageText);
  var regularPrices = extractExplicitRegularPricesFromMessage(messageText);
  if (regularPrices.unit_price > 0) {
    product.consumer_price = regularPrices.unit_price;
    product.actual_price = regularPrices.unit_price;
    delete product.consumer_package_price;
    delete product.regular_package_price;
  }
  if (regularPrices.package_price > 0) {
    product.consumer_package_price = regularPrices.package_price;
    product.regular_package_price = regularPrices.package_price;
  }
  if (prices.unit_price > 0) {
    product.unit_price = prices.unit_price;
    product.sale_price = prices.unit_price;
    product.detected_price = prices.unit_price;
  }
  if (prices.package_price > 0) {
    product.package_price = prices.package_price;
    product.double_pack_price = prices.package_price;
    if (!product.detected_price) product.detected_price = prices.package_price;
  }
  if (prices.unit_price > 0 && prices.package_price > 0) {
    product.confirmed_price_role = 'both';
    return 'both';
  }
  if (prices.unit_price > 0) {
    product.confirmed_price_role = 'unit';
    return 'unit';
  }
  if (prices.package_price > 0) {
    product.confirmed_price_role = 'package';
    return 'package';
  }
  return '';
}

function detectExplicitSalePriceRole(messageText, product) {
  product = product || {};
  if (product.confirmed_price_role) return product.confirmed_price_role;
  const prices = extractExplicitSalePricesFromMessage(messageText || product.description || '');
  if (prices.unit_price > 0 && prices.package_price > 0) return 'both';
  if (prices.unit_price > 0) return 'unit';
  if (prices.package_price > 0) return 'package';
  return '';
}

function applyPriceConfirmationHint(message, product) {
  const hint = getPriceConfirmationHint(message);
  if (!hint || !product) return;
  const price = product.sale_price || product.price || product.wholesale_price || product.unit_price || product.package_price;
  if (!price) return;
  if (hint === 'sale unit price') {
    product.unit_price = price;
    product.sale_price = price;
    product.confirmed_price_role = 'unit';
    delete product.package_price;
    delete product.double_pack_price;
  }
  if (hint === 'sale package price') {
    product.package_price = price;
    product.double_pack_price = price;
    product.confirmed_price_role = 'package';
    delete product.unit_price;
    delete product.sale_price;
  }
}

function assessPriceCertainty(rawProduct, normalizedProduct, options) {
  options = options || {};
  rawProduct = rawProduct || {};
  normalizedProduct = normalizedProduct || {};
  const explicitRole = options.explicitPriceRole || rawProduct.confirmed_price_role || '';
  const hasSaleUnit = (explicitRole === 'unit' || explicitRole === 'both') && Number(rawProduct.unit_price || rawProduct.sale_price || rawProduct.price || rawProduct.wholesale_price || 0) > 0;
  const hasSalePackage = (explicitRole === 'package' || explicitRole === 'both') && Number(rawProduct.package_price || rawProduct.box_price || rawProduct.carton_price || rawProduct.double_pack_price || rawProduct.sale_price || rawProduct.price || rawProduct.wholesale_price || 0) > 0;
  const hasPlainSale = Number(rawProduct.sale_price || rawProduct.price || rawProduct.wholesale_price || rawProduct.unit_price || rawProduct.package_price || 0) > 0;

  if (rawProduct.needs_review) return { certain: false, reason: rawProduct.review_reason || 'AI marked extraction uncertain' };
  // Don't flag ambiguity if reference already resolved it
  if (options.alreadyResolvedByReference) return { certain: true, reason: 'Reference price resolved ambiguity' };
  if (!hasSaleUnit && !hasSalePackage && hasPlainSale) return { certain: false, reason: 'Sale price role is ambiguous: unit or package' };
  if (!hasSaleUnit && !hasSalePackage && Number(normalizedProduct.sale_price || 0) > 0) return { certain: false, reason: 'Sale price role is ambiguous: unit or package' };
  return { certain: true, reason: '' };
}

function buildPriceConfirmationRequest(message, product, normalizedProduct, reason, scaleAssessment, referenceMatch) {
  scaleAssessment = scaleAssessment || {};
  referenceMatch = referenceMatch || {};
  return {
    type: 'price_confirmation',
    message_id: String(message.id || ''),
    batch_id: String(message.batch_id || ''),
    chat_id: message.chat_id || '',
    product_name: product.name || product.product_name || 'Unknown',
    reason: reason || scaleAssessment.reason || 'Needs operator confirmation',
    channel_link: message.url || message.message_url || '',
    scale_context: {
      detected_price: scaleAssessment.detected_price || '',
      package_count: scaleAssessment.package_count || extractPackageCountFromProduct(product) || '',
      suggested_unit_price: scaleAssessment.suggested_unit_price || '',
      suggested_package_price: scaleAssessment.suggested_package_price || '',
      historical_median_unit_price: scaleAssessment.historical_median_unit_price || '',
      reference_unit_price: scaleAssessment.reference_unit_price || '',
      reference_package_price: scaleAssessment.reference_package_price || '',
      reference_source: scaleAssessment.reference_source || '',
      matched_reference_product: referenceMatch.name || referenceMatch.clean_name || '',
      reference_id: referenceMatch.reference_id || referenceMatch.product_code || referenceMatch.key || '',
      product_match_key: getReferenceMatchOverrideKey(product),
      match_confidence: referenceMatch.match_score || 0,
      match_components: referenceMatch.match_components || null
    },
    proposed: {
      sale_price: normalizedProduct.sale_price || '',
      sale_unit_price: normalizedProduct.unit_price || '',
      regular_package_price: normalizedProduct.consumer_package_price || '',
      regular_unit_price: normalizedProduct.consumer_price || '',
      packaging: product.packaging || ''
    }
  };
}

function inferUnitPackagePair(unitValue, packageValue, packageCount) {
  let unit = Number(unitValue) || 0;
  let packagePrice = Number(packageValue) || 0;
  const count = Number(packageCount) || 0;

  if (count > 1) {
    if (unit > 0 && packagePrice <= 0) packagePrice = unit * count;
    if (packagePrice > 0 && unit <= 0) unit = packagePrice / count;
  }

  return { unit: unit || '', package: packagePrice || '' };
}

function calculateInventoryCost(unitOrSalePrice, explicitPackagePrice, packageCount) {
  if (explicitPackagePrice > 0) return explicitPackagePrice;
  if (unitOrSalePrice > 0 && packageCount > 1) return unitOrSalePrice * packageCount;
  return unitOrSalePrice || 0;
}

function inferInventoryUnit(channelRow, packageCount) {
  const text = [
    firstValue(channelRow, ['Packaging']),
    firstValue(channelRow, ['Variation Type']),
    firstValue(channelRow, ['Weight']),
    firstValue(channelRow, ['Volume']),
    firstValue(channelRow, ['Original Message', 'Description'])
  ].join(' ').toLowerCase();
  const isPack = /(?:کارتن|كارتن|باکس|باكس|جعبه|پک|بسته|pack|box|carton|package)/i.test(text) || (packageCount && packageCount > 1);
  if (isPack) return 'pack';
  if (/(?:کیلو|كيلو|kilo|kg)/i.test(text)) return 'kilo';
  return 'single';
}

function extractPackageCount(value) {
  const normalized = persianToEnglishNumbers(String(value || ''));
  const match = normalized.match(/\d+/);
  return match ? parseInt(match[0], 10) : '';
}

function getProductExtractionWarning(product) {
  if (!product) return '';
  if (product.extraction_warning) return String(product.extraction_warning);
  if (!product.raw_metadata) return '';
  try {
    var metadata = JSON.parse(String(product.raw_metadata));
    return metadata && metadata.review_reason ? String(metadata.review_reason) : '';
  } catch (e) {
    return '';
  }
}

function extractPackageCountFromProduct(product) {
  var text = [
    product && product.packaging,
    product && product.name,
    product && product.product_name,
    product && product.description,
    product && product.original_message
  ].join(' ');
  text = persianToEnglishNumbers(String(text || ''));

  var unitMarker = text.match(/(?:^|[^\d])(\d{1,3})\s*(?:ع|عدد|عددی|تایی|pcs|pc)(?:[^\d]|$)/i);
  if (unitMarker) {
    var markerCount = parseInt(unitMarker[1], 10);
    if (markerCount > 1) return markerCount;
  }

  var contextual = text.match(/(?:باکس|باكس|کارتن|كارتن|شیرینگ|پک|بسته|box|carton|pack|package)(?:\s*(?:حاوی|شامل|دارای))?\s*(\d{1,3})\s*(?:ع|عددی|عدد|تایی|pcs|pc|x|بسته|جعبه|باکس|پک|شیشه|ساشه)?|(?:^|\s)(\d{1,3})\s*(?:ع|عددی|عدد|تایی|pcs|pc|بسته|جعبه|باکس|پک|شیشه|ساشه)\s*(?:در\s*)?(?:باکس|باكس|کارتن|كارتن|شیرینگ|پک|بسته|box|carton|pack|package)/i);
  if (contextual) {
    var contextualCount = parseInt(contextual[1] || contextual[2], 10);
    if (contextualCount > 1) return contextualCount;
  }

  var multiply = text.match(/[\(\s](\d{1,2})\s*[x*×]\s*(\d{1,2})[\)\s]/i);
  if (multiply) {
    var multipliedCount = parseInt(multiply[1], 10) * parseInt(multiply[2], 10);
    if (multipliedCount > 1 && multipliedCount <= 200) return multipliedCount;
  }

  return '';
}
