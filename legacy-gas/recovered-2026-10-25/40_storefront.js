function rebuildStorefront() {
  const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
  const inventorySheet = getRequiredSheetByAnyName(spreadsheet, ['Inventory log', 'inventory log']);
  const storefrontSheet = getOrCreateSheet(spreadsheet, 'storefront', STOREFRONT_HEADERS);
  const inventoryRows = readSheetObjects(inventorySheet);
  const stockByKey = buildInventoryStockMap(inventoryRows);
  const latestOffers = selectLatestSupplierOffers(inventoryRows);
  const now = new Date().toISOString();
  const outputRows = [];

  const allKeys = {};
  Object.keys(latestOffers).forEach(function(key) { allKeys[key] = true; });
  Object.keys(stockByKey).forEach(function(key) { allKeys[key] = true; });

  Object.keys(allKeys).sort().forEach(function(key) {
    const supplierOffers = latestOffers[key] || [];
    const offer = selectBestCurrentOffer(supplierOffers);
    const stock = stockByKey[key] || { in: 0, out: 0, current: 0, avgCost: 0, latestRow: {} };
    const ownedStock = stock.current > 0;
    const selectedRow = ownedStock ? (stock.latestRow || {}) : offer;
    const latestPriceRow = selectLatestProductOffer(supplierOffers) || selectedRow;
    const packageCount = firstNumber(selectedRow, ['Count', 'Packaging']);
    const saleUnitPrice = firstNumber(latestPriceRow, ['Sale Unit Price', 'Unit Price']);
    const regularUnitPrice = firstNumber(latestPriceRow, ['Regular Unit Price', 'Consumer Unit Price']);
    const salePackagePrice = firstNumber(latestPriceRow, ['Sale Price', 'Package Price']);
    const sourceUnitCost = ownedStock
      ? (stock.avgCost || firstNumber(selectedRow, ['Unit Cost', 'Cost', 'Purchase Price', 'هزینه (تومان)']))
      : (saleUnitPrice || (salePackagePrice && packageCount ? salePackagePrice / packageCount : 0));
    const sourcePackageCost = sourceUnitCost && packageCount ? sourceUnitCost * packageCount : salePackagePrice;
    const visible = ownedStock || isCurrentSupplierOffer(offer);
    const storefrontUnitPrice = calculateStorefrontPrice(sourceUnitCost, ownedStock);
    const storefrontPackagePrice = sourcePackageCost
      ? calculateStorefrontPrice(sourcePackageCost, ownedStock)
      : (storefrontUnitPrice && packageCount ? storefrontUnitPrice * packageCount : '');

    outputRows.push([
      firstValue(latestPriceRow, ['Product ID', 'SKU']) || firstValue(selectedRow, ['Product ID', 'SKU']),
      firstValue(selectedRow, ['Product Name', 'Name', 'Title', 'Product']) || key,
      firstValue(selectedRow, ['Variation Type']),
      firstValue(selectedRow, ['Brand']),
      firstValue(selectedRow, ['Category']),
      firstValue(selectedRow, ['Packaging']),
      packageCount || '',
      firstValue(selectedRow, ['unit', 'Unit']),
      firstValue(selectedRow, ['Volume']),
      firstValue(selectedRow, ['Weight']),
      stock.current,
      stock.in,
      stock.out,
      ownedStock ? '' : firstValue(offer, ['Owner', 'Channel Username', 'Channel', 'Supplier', 'Source']),
      ownedStock ? '' : firstValue(offer, ['Channel Username', 'Channel']),
      saleUnitPrice || '',
      regularUnitPrice || '',
      salePackagePrice || '',
      firstNumber(latestPriceRow, ['Regular Package Price', 'Consumer Package Price']) || '',
      sourceUnitCost || '',
      sourcePackageCost || '',
      storefrontUnitPrice,
      storefrontPackagePrice,
      ownedStock ? 'owned_markup' : 'supplier_markup',
      ownedStock ? 'owned_inventory' : 'supplier_offer',
      visible ? 'visible' : 'hidden',
      firstValue(selectedRow, ['Status']),
      firstValue(selectedRow, ['Original Post Date']),
      firstValue(selectedRow, ['Import Timestamp', 'Last Updated', 'Timestamp']),
      firstValue(selectedRow, ['Message ID (Platform)', 'Message ID', 'ID']),
      firstValue(selectedRow, ['Message Link', 'URL', 'Message URL']),
      firstValue(selectedRow, ['Extraction Status']),
      firstValue(selectedRow, ['Anchor Type']),
      firstValue(selectedRow, ['Anchor ID']),
      firstValue(selectedRow, ['Anchor Name']),
      firstValue(selectedRow, ['Anchor Score']),
      now
    ]);
  });

  storefrontSheet.clearContents();
  storefrontSheet.getRange(1, 1, 1, STOREFRONT_HEADERS.length).setValues([STOREFRONT_HEADERS]);
  if (outputRows.length > 0) {
    storefrontSheet.getRange(2, 1, outputRows.length, STOREFRONT_HEADERS.length).setValues(outputRows);
  }

  return jsonResponse({
    status: 'success',
    message: 'Storefront rebuilt from latest supplier offers',
    source_sheet: inventorySheet.getName(),
    storefront_sheet: storefrontSheet.getName(),
    storefront_rows: outputRows.length,
    timestamp: now
  });
}

const STOREFRONT_HEADERS = [
  'Product ID',
  'Product Name',
  'Variation Type',
  'Brand',
  'Category',
  'Packaging',
  'Count',
  'unit',
  'Volume',
  'Weight',
  'Current Quantity',
  'Total In',
  'Total Out',
  'Selected Supplier',
  'Channel Username',
  'Sale Price',
  'Regular Price',
  'Sale Package Price',
  'Regular Package Price',
  'Source Unit Cost',
  'Source Package Cost',
  'Storefront Unit Price',
  'Storefront Package Price',
  'Pricing Rule',
  'Fulfillment Source',
  'Visibility',
  'Status',
  'Original Post Date',
  'Import Timestamp',
  'Message ID (Platform)',
  'Message Link',
  'Extraction Status',
  'Anchor Type',
  'Anchor ID',
  'Anchor Name',
  'Anchor Score',
  'Storefront Updated At'
];

function getRequiredSheetByAnyName(spreadsheet, names) {
  for (let i = 0; i < names.length; i++) {
    const sheet = spreadsheet.getSheetByName(names[i]);
    if (sheet) return sheet;
  }
  throw new Error('Missing required sheet. Tried: ' + names.join(', '));
}

function readSheetObjects(sheet) {
  const values = sheet.getDataRange().getValues();
  if (values.length < 2) return [];
  const headers = values[0].map(function(header) { return String(header || '').trim(); });
  const rows = [];
  for (let r = 1; r < values.length; r++) {
    const row = {};
    let hasValue = false;
    for (let c = 0; c < headers.length; c++) {
      row[headers[c]] = values[r][c];
      if (values[r][c] !== '' && values[r][c] !== null && values[r][c] !== undefined) hasValue = true;
    }
    if (hasValue) rows.push(row);
  }
  return rows;
}

function buildInventoryStockMap(rows) {
  const stock = {};
  rows.forEach(function(row) {
    if (String(firstValue(row, ['Action']) || '').toLowerCase() === 'supplier_offer') return;
    const key = getCanonicalProductKey(row);
    if (!key) return;

    const quantity = firstNumber(row, ['Quantity', 'Qty', 'Amount', 'Count']) || 0;
    const cost = firstNumber(row, ['Unit Cost', 'Cost', 'Purchase Price', 'هزینه (تومان)']) || 0;
    const type = String(firstValue(row, ['Type', 'Movement', 'Direction', 'Action', 'In/Out']) || '').toLowerCase();
    const signedQuantity = type.indexOf('out') !== -1 || type.indexOf('sale') !== -1 || type.indexOf('remove') !== -1 ? -Math.abs(quantity) : Math.abs(quantity);

    if (!stock[key]) stock[key] = { in: 0, out: 0, current: 0, costTotal: 0, avgCost: 0, latestRow: null };
    if (signedQuantity >= 0) {
      stock[key].in += signedQuantity;
      stock[key].costTotal += signedQuantity * cost;
    } else {
      stock[key].out += Math.abs(signedQuantity);
    }
    stock[key].current += signedQuantity;
    stock[key].avgCost = stock[key].in > 0 ? stock[key].costTotal / stock[key].in : 0;
    if (!stock[key].latestRow || getOfferTimestamp(row) >= getOfferTimestamp(stock[key].latestRow)) {
      stock[key].latestRow = row;
    }
  });
  return stock;
}

function selectLatestSupplierOffers(rows) {
  const latestByProductSupplier = {};
  rows.forEach(function(row) {
    if (String(firstValue(row, ['Action']) || '').toLowerCase() !== 'supplier_offer') return;
    if (!isConfirmedInventoryRow(row)) return;
    const productKey = getCanonicalProductKey(row);
    if (!productKey) return;
    const supplierKey = normalizeStorefrontKey(firstValue(row, ['Owner', 'Channel Username', 'Channel', 'Supplier', 'Source']) || 'unknown');
    const compoundKey = productKey + '|' + supplierKey;
    const current = latestByProductSupplier[compoundKey];
    if (!current || getOfferTimestamp(row) > getOfferTimestamp(current)) latestByProductSupplier[compoundKey] = row;
  });

  const grouped = {};
  Object.keys(latestByProductSupplier).forEach(function(compoundKey) {
    const row = latestByProductSupplier[compoundKey];
    const productKey = getCanonicalProductKey(row);
    if (!grouped[productKey]) grouped[productKey] = [];
    grouped[productKey].push(row);
  });
  return grouped;
}

function selectLatestProductOffer(offers) {
  if (!offers || offers.length === 0) return null;
  return offers.reduce(function(latest, candidate) {
    return !latest || getOfferTimestamp(candidate) > getOfferTimestamp(latest) ? candidate : latest;
  }, null);
}

function selectBestCurrentOffer(offers) {
  if (!offers || offers.length === 0) return {};
  const available = offers.filter(isCurrentSupplierOffer);
  const candidates = available.length ? available : offers;
  return candidates.reduce(function(best, candidate) {
    if (!best) return candidate;
    const candidateCost = getComparableUnitCost(candidate);
    const bestCost = getComparableUnitCost(best);
    if (candidateCost && bestCost && candidateCost !== bestCost) return candidateCost < bestCost ? candidate : best;
    if (candidateCost && !bestCost) return candidate;
    return getOfferTimestamp(candidate) > getOfferTimestamp(best) ? candidate : best;
  }, null) || {};
}

function isConfirmedInventoryRow(row) {
  const status = String(firstValue(row, ['Status']) || '').toLowerCase();
  const extraction = String(firstValue(row, ['Extraction Status']) || '').toLowerCase();
  return status !== 'waiting_confirmation' && extraction.indexOf('waiting') === -1 && extraction.indexOf('failed') === -1 && extraction.indexOf('error') === -1;
}

function isCurrentSupplierOffer(row) {
  if (!row || Object.keys(row).length === 0) return false;
  const status = String(firstValue(row, ['Status', 'Stock Status', 'Availability']) || '').toLowerCase().trim();
  if (!status) return true;
  if (status.indexOf('unavailable') !== -1 || status.indexOf('out of stock') !== -1 || status.indexOf('ناموجود') !== -1 || status.indexOf('تمام') !== -1 || status.indexOf('expired') !== -1) return false;
  return ['for sale', 'available_from_supplier', 'available', 'in stock', 'موجود'].indexOf(status) !== -1;
}

function getOfferTimestamp(row) {
  const value = firstValue(row, ['Import Timestamp', 'Last Updated', 'Timestamp', 'Original Post Date']);
  const timestamp = new Date(value || 0).getTime();
  return isNaN(timestamp) ? 0 : timestamp;
}

function getComparableUnitCost(row) {
  const unitPrice = firstNumber(row, ['Sale Unit Price', 'Unit Price']);
  if (unitPrice) return unitPrice;
  const packagePrice = firstNumber(row, ['Sale Price', 'Package Price']);
  const packageCount = firstNumber(row, ['Count', 'Packaging']);
  return packagePrice && packageCount ? packagePrice / packageCount : packagePrice;
}

function getCanonicalProductKey(row) {
  const explicitId = firstValue(row, ['Canonical Product ID', 'Product ID', 'SKU']);
  if (explicitId) return normalizeStorefrontKey(explicitId);
  const parts = [
    firstValue(row, ['Product Name', 'Name', 'Title', 'Product']),
    firstValue(row, ['Variation Type']),
    firstValue(row, ['Brand']),
    firstValue(row, ['Packaging']),
    firstValue(row, ['Volume']),
    firstValue(row, ['Weight'])
  ].filter(function(value) { return value !== '' && value !== null && value !== undefined; });
  return normalizeStorefrontKey(parts.join(' '));
}

function normalizeStorefrontKey(value) {
  return persianToEnglishNumbers(String(value || '').toLowerCase())
    .replace(/[^a-z0-9\u0600-\u06FF]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function firstValue(row, headers) {
  for (let i = 0; i < headers.length; i++) {
    if (row[headers[i]] !== undefined && row[headers[i]] !== null && row[headers[i]] !== '') return row[headers[i]];
  }
  const normalizedRow = {};
  Object.keys(row || {}).forEach(function(key) {
    normalizedRow[normalizeHeaderKey(key)] = row[key];
  });
  for (let j = 0; j < headers.length; j++) {
    const value = normalizedRow[normalizeHeaderKey(headers[j])];
    if (value !== undefined && value !== null && value !== '') return value;
  }
  return '';
}

function firstNumber(row, headers) {
  const value = firstValue(row, headers);
  if (value === '') return 0;
  const normalized = persianToEnglishNumbers(String(value)).replace(/[^0-9.\-]/g, '');
  const parsed = parseFloat(normalized);
  return isNaN(parsed) ? 0 : parsed;
}

function calculateStorefrontPrice(sourceCost, ownedStock) {
  if (!sourceCost) return '';
  const props = PropertiesService.getScriptProperties();
  const ownedMarkup = parseFloat(props.getProperty('STOREFRONT_OWNED_MARKUP') || '1.20');
  const supplierMarkup = parseFloat(props.getProperty('STOREFRONT_SUPPLIER_MARKUP') || '1.15');
  const rounding = parseInt(props.getProperty('STOREFRONT_PRICE_ROUNDING') || '1000', 10);
  const markup = ownedStock ? ownedMarkup : supplierMarkup;
  return Math.ceil((sourceCost * markup) / rounding) * rounding;
}
