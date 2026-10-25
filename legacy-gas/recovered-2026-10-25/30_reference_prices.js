function writeReferencePriceRows(spreadsheet, refs, sourceType, sourceUrl, clearSheet = true) {
  const sheet = getOrCreateSheet(spreadsheet, 'ReferencePrices', REFERENCE_PRICE_HEADERS);
  if (clearSheet) {
    sheet.clear();
    sheet.getRange(1, 1, 1, REFERENCE_PRICE_HEADERS.length).setValues([REFERENCE_PRICE_HEADERS]);
    sheet.getRange(1, 1, 1, REFERENCE_PRICE_HEADERS.length).setFontWeight('bold');
  }

  const now = new Date().toISOString();
  const rows = refs.map(function(ref) {
    return [
      ref.name || ref.raw_name || '',
      ref.clean_name || ref.clean_product_name || ref.name || ref.raw_name || '',
      ref.key || normalizeReferenceProductName(ref.clean_name || ref.clean_product_name || ref.name || ref.raw_name || ''),
      ref.package_price || '',
      ref.unit_price || '',
      ref.consumer_unit_price || '',
      ref.package_count || '',
      ref.unit_label || '',
      ref.volume || '',
      ref.weight || '',
      ref.set_count || '',
      ref.variant || '',
      ref.brand || '',
      ref.category || '',
      ref.raw_name_details || '',
      ref.pdf_row_number || '',
      ref.product_code || '',
      'TOMAN',
      sourceType,
      sourceUrl,
      now
    ];
  });
  if (rows.length > 0) {
    const lastRow = sheet.getLastRow();
    sheet.getRange(lastRow + 1, 1, rows.length, REFERENCE_PRICE_HEADERS.length).setValues(rows);
  }
  return rows.length;
}

function handleReplaceReferencePrices(data) {
  const adminToken = PropertiesService.getScriptProperties().getProperty('ADMIN_ENDPOINT_TOKEN');
  if (!adminToken) return jsonResponse({ status: 'error', message: 'ADMIN_ENDPOINT_TOKEN script property is not configured' });
  if (!data || data.token !== adminToken) return jsonResponse({ status: 'error', message: 'Invalid or missing admin token' });
  const refs = Array.isArray(data.references) ? data.references : [];
  if (refs.length === 0) return jsonResponse({ status: 'error', message: 'No reference rows supplied' });
  const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
  const count = writeReferencePriceRows(spreadsheet, refs, data.source_type || 'external_reference_extractor', data.source_url || '', true);
  return jsonResponse({ status: 'success', reference_count: count, source_type: data.source_type || '', timestamp: new Date().toISOString() });
}

function handleAppendReferencePrices(data) {
  const adminToken = PropertiesService.getScriptProperties().getProperty('ADMIN_ENDPOINT_TOKEN');
  if (!adminToken) return jsonResponse({ status: 'error', message: 'ADMIN_ENDPOINT_TOKEN script property is not configured' });
  if (!data || data.token !== adminToken) return jsonResponse({ status: 'error', message: 'Invalid or missing admin token' });
  const refs = Array.isArray(data.references) ? data.references : [];
  if (refs.length === 0) return jsonResponse({ status: 'error', message: 'No reference rows supplied' });
  const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
  const count = writeReferencePriceRows(spreadsheet, refs, data.source_type || 'external_reference_extractor', data.source_url || '', false);
  return jsonResponse({ status: 'success', reference_count: count, source_type: data.source_type || '', timestamp: new Date().toISOString() });
}

function refreshReferencePrices() {
  const props = PropertiesService.getScriptProperties();
  const serverUrl = props.getProperty('REFERENCE_REFRESH_SERVER_URL');
  const adminToken = props.getProperty('ADMIN_ENDPOINT_TOKEN');
  if (!serverUrl) {
    return jsonResponse({
      status: 'error',
      message: 'REFERENCE_REFRESH_SERVER_URL script property is not configured',
      timestamp: new Date().toISOString()
    });
  }
  if (!adminToken) {
    return jsonResponse({
      status: 'error',
      message: 'ADMIN_ENDPOINT_TOKEN script property is not configured',
      timestamp: new Date().toISOString()
    });
  }

  const separator = serverUrl.indexOf('?') === -1 ? '?' : '&';
  const refreshUrl = serverUrl + separator + 'source=store&sync=true&token=' + encodeURIComponent(adminToken);
  const response = UrlFetchApp.fetch(refreshUrl, {
    method: 'get',
    muteHttpExceptions: true,
    followRedirects: true
  });
  const statusCode = response.getResponseCode();
  const body = response.getContentText();
  if (statusCode < 200 || statusCode >= 300) {
    return jsonResponse({
      status: 'error',
      message: 'Server Store API reference refresh failed',
      http_status: statusCode,
      response_preview: body.substring(0, 1000),
      timestamp: new Date().toISOString()
    });
  }
  try {
    const parsed = JSON.parse(body);
    parsed.trigger_source = 'gas_reference_daily_trigger';
    return jsonResponse(parsed);
  } catch (e) {
    return jsonResponse({
      status: 'success',
      message: 'Server Store API reference refresh completed but response was not JSON',
      http_status: statusCode,
      response_preview: body.substring(0, 1000),
      timestamp: new Date().toISOString()
    });
  }
}

function refreshReferencePricesFromPdfLegacy() {
  const pdfUrl = 'https://rezvanpakhsh.com/pdf/price-list.pdf';
  const sourceType = 'rezvanpakhsh_pdf_ai';
  var diagnostics = {};
  var refs = refreshReferencePricesFromPdf(pdfUrl, diagnostics);

  if (refs.length === 0) {
    const errorPayload = {
      status: 'error',
      message: 'Reference PDF AI extraction failed or returned zero valid rows',
      source_url: pdfUrl,
      source_type: sourceType,
      diagnostics: diagnostics,
      timestamp: new Date().toISOString()
    };
    notifyReferenceRefreshFailure(errorPayload);
    return jsonResponse(errorPayload);
  }

  const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
  const sheet = getOrCreateSheet(spreadsheet, 'ReferencePrices', REFERENCE_PRICE_HEADERS);
  sheet.clear();
  sheet.getRange(1, 1, 1, REFERENCE_PRICE_HEADERS.length).setValues([REFERENCE_PRICE_HEADERS]);
  sheet.getRange(1, 1, 1, REFERENCE_PRICE_HEADERS.length).setFontWeight('bold');

  const now = new Date().toISOString();
  const rows = refs.map(function(ref) {
    return [
      ref.name,
      ref.clean_name || ref.name,
      ref.key,
      ref.package_price,
      ref.unit_price || '',
      ref.consumer_unit_price || '',
      ref.package_count || '',
      ref.unit_label || '',
      ref.volume || '',
      ref.weight || '',
      ref.set_count || '',
      ref.variant || '',
      ref.brand || '',
      ref.category || '',
      ref.raw_name_details || '',
      ref.pdf_row_number || '',
      ref.product_code || '',
      'TOMAN',
      sourceType,
      pdfUrl,
      now
    ];
  });
  sheet.getRange(2, 1, rows.length, REFERENCE_PRICE_HEADERS.length).setValues(rows);

  return jsonResponse({
    status: 'success',
    source_url: pdfUrl,
    source_type: sourceType,
    reference_count: rows.length,
    timestamp: now
  });
}

function notifyReferenceRefreshFailure(errorPayload) {
  try {
    const props = PropertiesService.getScriptProperties();
    const chatId = props.getProperty('REFERENCE_ALERT_CHAT_ID') || props.getProperty('ADMIN_CHAT_ID');
    if (!chatId) return;

    const text = [
      'Reference price refresh failed',
      'Source: ' + (errorPayload.source_url || ''),
      'Reason: ' + (errorPayload.message || ''),
      'Diagnostics: ' + JSON.stringify(errorPayload.diagnostics || {}).substring(0, 1200)
    ].join('\n');

    const telegramToken = props.getProperty('TELEGRAM_BOT_TOKEN');
    if (telegramToken) {
      UrlFetchApp.fetch('https://api.telegram.org/bot' + telegramToken + '/sendMessage', {
        method: 'post',
        contentType: 'application/json',
        payload: JSON.stringify({ chat_id: chatId, text: text }),
        muteHttpExceptions: true
      });
    }

    const baleToken = props.getProperty('BALE_BOT_TOKEN');
    if (baleToken) {
      UrlFetchApp.fetch('https://tapi.bale.ai/bot' + baleToken + '/sendMessage', {
        method: 'post',
        contentType: 'application/json',
        payload: JSON.stringify({ chat_id: chatId, text: text }),
        muteHttpExceptions: true
      });
    }
  } catch (e) {
    Logger.log('notifyReferenceRefreshFailure failed: ' + e);
  }
}

function refreshReferencePricesFromPdf(pdfUrl, diagnostics) {
  diagnostics = diagnostics || {};
  try {
    const props = PropertiesService.getScriptProperties();
    const apiKey = props.getProperty('AI_API_KEY');
    diagnostics.api_key_set = !!apiKey;
    if (!apiKey) return [];

    const pdfResponse = UrlFetchApp.fetch(pdfUrl, {
      muteHttpExceptions: true,
      followRedirects: true,
      headers: { 'User-Agent': 'WholesaleBotReferenceRefresh/1.0' }
    });
    diagnostics.pdf_status_code = pdfResponse.getResponseCode();
    diagnostics.pdf_bytes = pdfResponse.getBlob().getBytes().length;
    if (pdfResponse.getResponseCode() < 200 || pdfResponse.getResponseCode() >= 300) return [];

    const pdfBase64 = Utilities.base64Encode(pdfResponse.getBlob().getBytes());
    const prompt = [
      'Extract structured reference product data from this Persian wholesale price-list PDF.',
      'Return ONLY a JSON array. No markdown.',
      'Important PDF layout rules:',
      '1) Each physical table row may contain TWO independent product blocks: one on the left and one on the right. Treat them as separate products.',
      '2) For each block, the far price column in that block is package/carton price. The nearby product text column is the raw product name/details. The nearby numeric code column is product_code. The row index is pdf_row_number.',
      '3) Do not mix columns between the left and right product blocks. Never use the other block price/code/row-number as package_count.',
      '4) Do not treat patterns like (45ف) or (۴۵ف) as package count. ف means فروش and indicates individual retail/selling price. Example: (45ف) means 45,000 toman individual selling price, stored as consumer_unit_price=45000.',
      '5) Product names may contain attributes. Extract them into separate fields instead of keeping everything inside clean_name.',
      '6) Package count is the number of sellable units in carton/package. Only set package_count when explicitly shown in the product text, like 12ع, 24ع, 6عددی, کارتن 12, or a clearly labeled count column for the SAME product block.',
      '7) If package_count is known, derive unit_price = round(package_price / package_count). If package_count is unknown, leave unit_price 0. Never copy package_price into unit_price.',
      'Each item must have these keys:',
      'product_code: numeric/string product code from the same product block, otherwise empty string,',
      'pdf_row_number: row number shown for the same product block, otherwise empty string,',
      'raw_name: full product text exactly as shown,',
      'clean_name: product name without pack count, retail فروش hint, volume, weight, or set-count attributes,',
      'package_price: main PDF package/carton price in toman as number,',
      'unit_price: derived package_price/package_count in toman, or 0 if package_count is unknown,',
      'consumer_unit_price: individual فروش/consumer selling price in toman if shown by patterns like (45ف), otherwise 0,',
      'package_count: units per package/carton as number, or 0 if unknown,',
      'unit_label: textual unit marker such as عدد, ع, بسته, کارتن if clear,',
      'volume: volume like 1ل, 250میل, 300سی‌سی if present, otherwise empty string,',
      'weight: weight like 100گ, 5ک if present, otherwise empty string,',
      'set_count: multi-piece or set count for one sellable product, such as razor/blade set elements, otherwise 0,',
      'variant: flavor/color/type descriptors if present, otherwise empty string,',
      'brand: brand if clear, otherwise empty string,',
      'category: broad category if clear, otherwise empty string,',
      'raw_name_details: any remaining encoded details that were removed from clean_name.'
    ].join('\n');

    const aiText = callGeminiWithInlinePdf(prompt, pdfBase64, apiKey, props, diagnostics);
    diagnostics.ai_text_length = aiText ? String(aiText).length : 0;
    diagnostics.ai_preview = aiText ? String(aiText).substring(0, 1000) : '';
    const parsed = parseAiJsonResponse(aiText);
    const items = getReferenceItemsFromParsedAi(parsed);
    diagnostics.ai_items = items.length;
    const refs = items.map(function(item) {
      var rawName = item.raw_name || item.name || item.product_name || item.product || item.title || item['نام کالا'] || item['نام محصول'] || '';
      var cleanName = item.clean_name || item.clean_product_name || rawName;
      var packagePrice = parseReferenceNumber(item.package_price || item.reference_package_price || item.carton_price || item.total_price || item.pack_price || item.box_price || item['قیمت کل'] || item['قیمت کارتن'] || 0);
      var packageCount = parseReferenceNumber(item.package_count || item.count_per_package || item.carton_count || item.pack_count || item.qty_per_pack || item['تعداد در بسته'] || 0);
      var unitPrice = parseReferenceNumber(item.unit_price || item.reference_unit_price || item.price_per_unit || item.single_price || item.each_price || item['قیمت هر عدد'] || 0);
      var consumerUnitPrice = extractRetailHintPriceFromName(rawName) || parseReferenceNumber(item.consumer_unit_price || item.retail_unit_price || item.sale_hint_price || item.consumer_price || item['قیمت فروش'] || 0);
      if ((!unitPrice || unitPrice === packagePrice) && packagePrice > 0 && packageCount > 1) unitPrice = Math.round(packagePrice / packageCount);
      if (packageCount === 1 && unitPrice === packagePrice) unitPrice = 0;
      return {
        name: rawName,
        clean_name: cleanName,
        key: normalizeReferenceProductName(cleanName || rawName),
        unit_price: unitPrice,
        package_price: packagePrice,
        consumer_unit_price: consumerUnitPrice,
        package_count: packageCount,
        unit_label: item.unit_label || item.unit || '',
        volume: item.volume || '',
        weight: item.weight || '',
        set_count: parseReferenceNumber(item.set_count || item.piece_count || item.elements_count || 0),
        variant: item.variant || item.flavor || item.color || item.type || '',
        brand: item.brand || '',
        category: item.category || '',
        raw_name_details: item.raw_name_details || item.details || '',
        pdf_row_number: item.pdf_row_number || item.row_number || item.row || '',
        product_code: item.product_code || item.code || item.sku || ''
      };
    }).filter(function(ref) {
      return ref.name && ref.key && ref.package_price > 0;
    });
    diagnostics.valid_refs = refs.length;
    return refs;
  } catch (e) {
    diagnostics.error = String(e);
    Logger.log('refreshReferencePricesFromPdf failed: ' + e);
    return [];
  }
}

function parseAiJsonResponse(text) {
  if (!text) return null;
  var raw = String(text).trim();
  raw = raw.replace(/^```(?:json)?\s*/i, '').replace(/```$/i, '').trim();
  try {
    return JSON.parse(raw);
  } catch (e) {
    var balancedArray = extractBalancedJson(raw, '[', ']');
    if (balancedArray) {
      try { return JSON.parse(balancedArray); } catch (ignored) {}
    }
    var balancedObject = extractBalancedJson(raw, '{', '}');
    if (balancedObject) {
      try { return JSON.parse(balancedObject); } catch (ignoredObj) {}
    }
    return extractJsonArray(raw) || extractJsonObject(raw);
  }
}

function extractBalancedJson(text, openChar, closeChar) {
  var start = text.indexOf(openChar);
  if (start < 0) return null;
  var depth = 0;
  var inString = false;
  var escape = false;
  for (var i = start; i < text.length; i++) {
    var ch = text.charAt(i);
    if (escape) {
      escape = false;
      continue;
    }
    if (ch === '\\') {
      escape = true;
      continue;
    }
    if (ch === '"') {
      inString = !inString;
      continue;
    }
    if (inString) continue;
    if (ch === openChar) depth++;
    if (ch === closeChar) depth--;
    if (depth === 0) return text.substring(start, i + 1);
  }
  return null;
}

function parseReferenceNumber(value) {
  if (typeof value === 'number') return value;
  var normalized = persianToEnglishNumbers(String(value || ''));
  var digits = normalized.replace(/[^\d.]/g, '');
  return Number(digits) || 0;
}

function extractRetailHintPriceFromName(value) {
  var text = persianToEnglishNumbers(String(value || ''));
  var match = text.match(/[\(（]\s*(\d{1,4})\s*ف\s*[\)）]/);
  if (!match) return 0;
  var tomanThousands = parseInt(match[1], 10);
  if (!tomanThousands || tomanThousands <= 0) return 0;
  return tomanThousands * 1000;
}

function getReferenceItemsFromParsedAi(parsed) {
  if (!parsed) return [];
  if (Array.isArray(parsed)) return parsed;
  var keys = ['items', 'products', 'references', 'reference_prices', 'rows', 'data', 'price_list'];
  for (var i = 0; i < keys.length; i++) {
    if (Array.isArray(parsed[keys[i]])) return parsed[keys[i]];
  }
  for (var key in parsed) {
    if (Array.isArray(parsed[key])) return parsed[key];
  }
  if (parsed.name || parsed.product_name) return [parsed];
  return [];
}

function callGeminiWithInlinePdf(prompt, pdfBase64, apiKey, props, diagnostics) {
  diagnostics = diagnostics || {};
  const model = props.getProperty('AI_MODEL') || 'gemini-3.1-flash-lite';
  diagnostics.model = model;
  const endpoint = `https://generativelanguage.googleapis.com/v1beta/models/${model}:generateContent?key=${apiKey}`;
  const payload = {
    contents: [{
      parts: [
        { text: prompt },
        { inline_data: { mime_type: 'application/pdf', data: pdfBase64 } }
      ]
    }],
    generationConfig: {
      temperature: 0,
      response_mime_type: 'application/json'
    }
  };
  const response = UrlFetchApp.fetch(endpoint, {
    method: 'post',
    contentType: 'application/json',
    payload: JSON.stringify(payload),
    muteHttpExceptions: true,
    timeout: 120000
  });
  diagnostics.gemini_status_code = response.getResponseCode();
  if (response.getResponseCode() !== 200) {
    diagnostics.gemini_error_preview = response.getContentText().substring(0, 500);
    Logger.log('Gemini PDF reference extraction failed: ' + response.getContentText());
    return null;
  }
  const result = JSON.parse(response.getContentText());
  const parts = result.candidates && result.candidates[0] && result.candidates[0].content && result.candidates[0].content.parts;
  if (!parts) return null;
  for (var i = 0; i < parts.length; i++) {
    if (parts[i].text && !parts[i].thought) return parts[i].text;
  }
  return parts.length ? parts[parts.length - 1].text : null;
}

function referencePriceDailyTrigger() {
  refreshReferencePrices();
}

function setupReferencePriceTrigger() {
  const triggers = ScriptApp.getProjectTriggers();
  for (var i = 0; i < triggers.length; i++) {
    if (triggers[i].getHandlerFunction() === 'referencePriceDailyTrigger') {
      ScriptApp.deleteTrigger(triggers[i]);
    }
  }
  ScriptApp.newTrigger('referencePriceDailyTrigger')
    .timeBased()
    .everyDays(1)
    .atHour(4)
    .create();
  return jsonResponse({
    status: 'success',
    message: 'Reference price trigger created: daily around 04:00',
    timestamp: new Date().toISOString()
  });
}

function readReferencePriceRows(spreadsheet) {
  try {
    const sheet = getOrCreateSheet(spreadsheet, 'ReferencePrices', REFERENCE_PRICE_HEADERS);
    ensureSheetHeaders(sheet, REFERENCE_PRICE_HEADERS);
    const values = sheet.getDataRange().getValues();
    if (values.length < 2) return [];
    const headers = values[0];
    const map = headerIndexMap(headers);
    const rows = [];
    for (var i = 1; i < values.length; i++) {
      var row = values[i];
      var name = row[map['Product Name']];
      var cleanName = row[map['Clean Product Name']] || name;
      var key = row[map['Normalized Key']];
      if (!name && !key) continue;
      rows.push({
        name: name || '',
        clean_name: cleanName || '',
        key: key || normalizeReferenceProductName(cleanName || name),
        reference_id: String(row[map['Product Code']] || row[map['Normalized Key']] || ''),
        product_code: String(row[map['Product Code']] || ''),
        pdf_row_number: row[map['PDF Row Number']] || '',
        unit_label: row[map['Unit Label']] || '',
        variant: row[map['Variant']] || '',
        brand: row[map['Brand']] || '',
        category: row[map['Category']] || '',
        raw_name_details: row[map['Raw Name Details']] || '',
        unit_price: Number(row[map['Reference Unit Price']] || 0),
        package_price: Number(row[map['Reference Package Price']] || 0),
        consumer_unit_price: Number(row[map['Consumer Unit Price']] || 0),
        package_count: Number(row[map['Package Count']] || 0),
        volume: row[map['Volume']] || '',
        weight: row[map['Weight']] || '',
        set_count: Number(row[map['Set Count']] || 0),
        source: row[map['Source']] || '',
        last_updated: row[map['Last Updated']] || ''
      });
    }
    return rows;
  } catch (e) {
    Logger.log('readReferencePriceRows failed: ' + e);
    return [];
  }
}

function extractProductAttributes(text) {
  text = persianToEnglishNumbers(String(text || ''));
  var volume = '';
  var weight = '';
  var brand = '';
  
  // Extract volume patterns (e.g., "1ل", "250میل", "300سی‌سی")
  var volMatch = text.match(/(\d+(?:\.\d+)?)\s*(?:ل|لیتر|mil|ml|سی‌سی|cc)/i);
  if (volMatch) volume = volMatch[0].toLowerCase();
  
  // Extract weight patterns (e.g., "100گ", "5ک", "500گرم")
  var weightMatch = text.match(/(\d+(?:\.\d+)?)\s*(?:گ|گرم|k|kg|کیلو|کیلوگرم)/i);
  if (weightMatch) weight = weightMatch[0].toLowerCase();
  
  return {
    volume: volume,
    weight: weight
  };
}

function normalizeReferenceProductName(value) {
  return normalizeProductMatchText(String(value || '')
    .replace(/\([^)]*\)/g, ' ')
    .replace(/\d+\s*(?:ع|عدد|عددی|تایی|ف)/g, ' ')
    .replace(/[\d.,\/\\*×x]+/gi, ' '));
}

function referenceNameSimilarity(a, b) {
  a = normalizeReferenceProductName(a);
  b = normalizeReferenceProductName(b);
  if (!a || !b) return 0;
  if (a === b) return 1;
  if (a.indexOf(b) >= 0 || b.indexOf(a) >= 0) return 0.9;
  var aTokens = a.split(' ').filter(function(token) { return token.length > 1; });
  var bTokens = b.split(' ').filter(function(token) { return token.length > 1; });
  if (aTokens.length === 0 || bTokens.length === 0) return 0;
  var bSet = {};
  bTokens.forEach(function(token) { bSet[token] = true; });
  var overlap = 0;
  aTokens.forEach(function(token) { if (bSet[token]) overlap++; });
  return overlap / Math.max(aTokens.length, bTokens.length);
}

function attributeMatchScore(a, b) {
  a = String(a || '').toLowerCase();
  b = String(b || '').toLowerCase();
  if (!a || !b) return 0;
  if (a === b) return 1;
  if (a.indexOf(b) >= 0 || b.indexOf(a) >= 0) return 0.7;
  return 0;
}

function computeCompositeMatchScore(product, ref) {
  var productText = product.name || product.product_name || '';
  var productAttrs = extractProductAttributes([productText, product.description || '', product.packaging || ''].join(' '));
  var productVolume = product.volume || productAttrs.volume;
  var productWeight = product.weight || product.net_weight || productAttrs.weight;
  var productVariant = product.variant || product.variation_type || '';
  var productPackageCount = Number(extractPackageCountFromProduct(product) || 0);
  var refPackageCount = Number(ref.package_count || 0);

  var components = {
    name: { score: referenceNameSimilarity(productText, [ref.clean_name || ref.name || '', ref.variant || '', ref.raw_name_details || ''].join(' ')), weight: 0.45, available: true },
    category: { score: attributeMatchScore(product.category || '', ref.category || ''), weight: 0.12, available: !!(product.category && ref.category) },
    brand: { score: attributeMatchScore(product.brand || '', ref.brand || ''), weight: 0.14, available: !!(product.brand && ref.brand) },
    variant: { score: attributeMatchScore(productVariant, ref.variant || ''), weight: 0.10, available: !!(productVariant && ref.variant) },
    volume: { score: attributeMatchScore(productVolume, ref.volume || ''), weight: 0.06, available: !!(productVolume && ref.volume) },
    weight: { score: attributeMatchScore(productWeight, ref.weight || ''), weight: 0.06, available: !!(productWeight && ref.weight) },
    package_count: { score: productPackageCount && refPackageCount ? (productPackageCount === refPackageCount ? 1 : 0) : 0, weight: 0.07, available: !!(productPackageCount && refPackageCount) }
  };
  var weighted = 0;
  var availableWeight = 0;
  Object.keys(components).forEach(function(key) {
    var component = components[key];
    if (component.available) {
      weighted += component.score * component.weight;
      availableWeight += component.weight;
    }
  });
  var totalScore = availableWeight > 0 ? weighted / availableWeight : 0;
  return {
    total: totalScore,
    name: components.name.score,
    category: components.category.score,
    brand: components.brand.score,
    variant: components.variant.score,
    volume: components.volume.score,
    weight: components.weight.score,
    package_count: components.package_count.score
  };
}

function getReferenceMatchOverrideKey(product) {
  return normalizeReferenceProductName([
    product && (product.name || product.product_name) || '',
    product && (product.brand || '') || '',
    product && (product.variant || product.variation_type || '') || '',
    product && (product.volume || '') || '',
    product && (product.weight || product.net_weight || '') || ''
  ].join(' '));
}

function readReferenceMatchOverrides() {
  try {
    return JSON.parse(PropertiesService.getScriptProperties().getProperty('REFERENCE_MATCH_OVERRIDES') || '{}');
  } catch (e) {
    return {};
  }
}

function saveReferenceMatchOverride(productKey, referenceId) {
  if (!productKey || !referenceId) return false;
  var overrides = readReferenceMatchOverrides();
  overrides[productKey] = String(referenceId);
  PropertiesService.getScriptProperties().setProperty('REFERENCE_MATCH_OVERRIDES', JSON.stringify(overrides));
  return true;
}

function findReferencePriceMatch(product, referenceRows) {
  if (!product || !referenceRows || referenceRows.length === 0) return null;
  var overrideId = readReferenceMatchOverrides()[getReferenceMatchOverrideKey(product)];
  if (overrideId) {
    for (var o = 0; o < referenceRows.length; o++) {
      var overrideRef = referenceRows[o];
      if (String(overrideRef.reference_id || overrideRef.product_code || overrideRef.key || '') === String(overrideId)) {
        overrideRef.match_score = 1;
        overrideRef.match_components = { persisted_reference: 1 };
        overrideRef.persisted_reference = true;
        return overrideRef;
      }
    }
  }
  var best = null;
  var bestScore = 0;
  
  for (var i = 0; i < referenceRows.length; i++) {
    var ref = referenceRows[i];
    var scoreData = computeCompositeMatchScore(product, ref);
    
    if (scoreData.total > bestScore) {
      bestScore = scoreData.total;
      best = ref;
      best.match_score = bestScore;
      best.match_components = scoreData;
    }
  }
  
  // Lower threshold since we have composite matching (was 0.45)
  if (best && bestScore >= 0.35) {
    return best;
  }
  return null;
}

function findCategoryReferenceProducts(product, referenceRows) {
  var productCategory = normalizeProductMatchText(product.category || '');
  var matches = [];
  
  if (!productCategory || !referenceRows) return matches;
  
  for (var i = 0; i < referenceRows.length; i++) {
    var ref = referenceRows[i];
    var refCategory = normalizeProductMatchText(ref.category || '');
    if (refCategory && (productCategory.indexOf(refCategory) >= 0 || refCategory.indexOf(productCategory) >= 0)) {
      matches.push(ref);
    }
  }
  return matches;
}

function assessHistoricalPriceScale(product, messageData, inventoryValues, inventoryHeaders, options) {
  options = options || {};
  if (!product) return { certain: true, action: 'none' };

  var referenceMatch = options.referenceRows ? findReferencePriceMatch(product, options.referenceRows) : null;
  var packageCount = extractPackageCountFromProduct(product) || (referenceMatch ? Number(referenceMatch.package_count || 0) : 0);

  var history = getRecentUnitPriceHistory(product, messageData, inventoryValues, inventoryHeaders, 8);

  var explicitRole = options.explicitPriceRole || product.confirmed_price_role || '';
  var rawUnit = Number(product.unit_price || product.sale_price || product.price || product.wholesale_price || 0);
  var rawPackage = Number(product.package_price || product.box_price || product.carton_price || product.double_pack_price || 0);
  var hasExplicitUnit = (explicitRole === 'unit' || explicitRole === 'both') && rawUnit > 0;
  var hasExplicitPackage = (explicitRole === 'package' || explicitRole === 'both') && (rawPackage > 0 || rawUnit > 0);
  var plainSaleValue = Number(product.sale_price || product.price || product.wholesale_price || product.unit_price || product.package_price || 0);
  var hasPlainSale = plainSaleValue > 0;
  var candidateUnit = rawUnit > 0 ? rawUnit : (rawPackage > 0 && packageCount > 1 ? rawPackage / packageCount : rawPackage);
  if (candidateUnit <= 0) return { certain: true, action: 'none', referenceMatch: referenceMatch };

  function computeCategoryPriceStats(categoryRefs) {
    var unitPrices = [];
    var packagePrices = [];
    var anchorIds = [];
    var anchorNames = [];
    var bestCategoryScore = 0;
    for (var j = 0; j < categoryRefs.length; j++) {
      var ref = categoryRefs[j];
      if (ref.unit_price > 0) unitPrices.push(ref.unit_price);
      if (ref.package_price > 0) packagePrices.push(ref.package_price);
      var anchorId = ref.reference_id || ref.product_code || ref.key || '';
      if (anchorId && anchorIds.indexOf(String(anchorId)) < 0) anchorIds.push(String(anchorId));
      var anchorName = ref.name || ref.clean_name || '';
      if (anchorName && anchorNames.indexOf(String(anchorName)) < 0) anchorNames.push(String(anchorName));
      bestCategoryScore = Math.max(bestCategoryScore, computeCompositeMatchScore(product, ref).total);
    }
    unitPrices.sort(function(a, b) { return a - b; });
    packagePrices.sort(function(a, b) { return a - b; });
    return {
      medianUnit: unitPrices.length > 0 ? unitPrices[Math.floor(unitPrices.length / 2)] : 0,
      medianPackage: packagePrices.length > 0 ? packagePrices[Math.floor(packagePrices.length / 2)] : 0,
      count: categoryRefs.length,
      anchor_id: anchorIds.join(','),
      anchor_name: 'Category median: ' + String(product.category || '') + (anchorNames.length ? ' [' + anchorNames.join(' | ') + ']' : ''),
      anchor_score: bestCategoryScore
    };
  }

  // Check 1: Direct product reference match
  if (referenceMatch && hasPlainSale && !hasExplicitUnit && !hasExplicitPackage) {
    var referenceUnit = Number(referenceMatch.unit_price || 0);
    var referencePackage = Number(referenceMatch.package_price || 0);
    var unitDistance = referenceUnit > 0 ? priceDistanceRatio(plainSaleValue, referenceUnit) : 999;
    var packageDistance = referencePackage > 0 ? priceDistanceRatio(plainSaleValue, referencePackage) : 999;
    
    // Check if unit price matches reference unit (accept if only referenceUnit available)
    if (referenceUnit > 0 && unitDistance <= 0.35) {
      if (!referencePackage || unitDistance + 0.15 < packageDistance) {
        return {
          certain: true,
          action: 'reference_unit_price',
          reason: 'Detected price matches reference unit price',
          detected_price: plainSaleValue,
          suggested_unit_price: plainSaleValue,
          suggested_package_price: packageCount > 1 ? Math.round(plainSaleValue * packageCount) : 0,
          package_count: packageCount || '',
          reference_unit_price: referenceUnit,
          reference_package_price: referencePackage,
          reference_source: referenceMatch.source || '',
          referenceMatch: referenceMatch
        };
      }
    }
    
    // Check if package price matches reference package when package count is known
    if (referencePackage > 0 && packageCount > 1 && packageDistance <= 0.35) {
      if (!referenceUnit || packageDistance + 0.15 < unitDistance) {
        return {
          certain: true,
          action: 'reference_package_price',
          reason: 'Detected price matches reference package price',
          detected_price: plainSaleValue,
          suggested_unit_price: packageCount > 1 ? Math.round(plainSaleValue / packageCount) : 0,
          suggested_package_price: plainSaleValue,
          package_count: packageCount,
          reference_unit_price: referenceUnit,
          reference_package_price: referencePackage,
          reference_source: referenceMatch.source || '',
          referenceMatch: referenceMatch
        };
      }
    }
  }

  // Check 2: Category benchmark fallback if we have enough category references
  var categoryRefs = findCategoryReferenceProducts(product, options.referenceRows || []);
  if (hasPlainSale && !hasExplicitUnit && !hasExplicitPackage && categoryRefs.length >= 3) {
    var stats = computeCategoryPriceStats(categoryRefs);
    var unitDistance = stats.medianUnit > 0 ? priceDistanceRatio(plainSaleValue, stats.medianUnit) : 999;
    var packageDistance = stats.medianPackage > 0 ? priceDistanceRatio(plainSaleValue, stats.medianPackage) : 999;
    
    if (stats.medianUnit > 0 && unitDistance <= 0.40) {
      if (!stats.medianPackage || unitDistance + 0.15 < packageDistance) {
        return {
          certain: true,
          action: 'reference_unit_price',
          reason: 'Detected price matches category median unit price',
          detected_price: plainSaleValue,
          suggested_unit_price: plainSaleValue,
          suggested_package_price: packageCount > 1 ? Math.round(plainSaleValue * packageCount) : 0,
          package_count: packageCount || '',
          reference_unit_price: stats.medianUnit,
          reference_package_price: stats.medianPackage,
          reference_source: 'category_median',
          reference_anchor_id: stats.anchor_id,
          reference_anchor_name: stats.anchor_name,
          reference_anchor_score: stats.anchor_score,
          referenceMatch: null
        };
      }
    }
    
    if (stats.medianPackage > 0 && packageCount > 1 && packageDistance <= 0.40) {
      if (!stats.medianUnit || packageDistance + 0.15 < unitDistance) {
        return {
          certain: true,
          action: 'reference_package_price',
          reason: 'Detected price matches category median package price',
          detected_price: plainSaleValue,
          suggested_unit_price: packageCount > 1 ? Math.round(plainSaleValue / packageCount) : 0,
          suggested_package_price: plainSaleValue,
          package_count: packageCount,
          reference_unit_price: stats.medianUnit,
          reference_package_price: stats.medianPackage,
          reference_source: 'category_median',
          reference_anchor_id: stats.anchor_id,
          reference_anchor_name: stats.anchor_name,
          reference_anchor_score: stats.anchor_score,
          referenceMatch: null
        };
      }
    }
  }

  if (hasExplicitUnit || hasExplicitPackage) {
    return { certain: true, action: 'none', referenceMatch: referenceMatch };
  }

  if (history.count < (options.minHistory || 2) || history.median <= 0) {
    if (!hasExplicitUnit && !hasExplicitPackage && rawUnit > 0) {
      return {
        certain: false,
        action: 'confirm_price_scale',
        reason: 'Pack count is detected but sale price role is not explicit',
        detected_price: rawUnit,
        suggested_unit_price: Math.round(rawUnit / packageCount),
        suggested_package_price: rawUnit,
        package_count: packageCount,
        historical_median_unit_price: '',
        referenceMatch: referenceMatch
      };
    }
    return { certain: true, action: 'none', referenceMatch: referenceMatch };
  }

  if (!packageCount || packageCount <= 1) return { certain: true, action: 'none', referenceMatch: referenceMatch };

  var dividedUnit = candidateUnit / packageCount;
  var currentRatio = priceDistanceRatio(candidateUnit, history.median);
  var dividedRatio = priceDistanceRatio(dividedUnit, history.median);
  var likelyPackAsUnit = candidateUnit >= history.median * 2.5 && dividedRatio <= 0.35 && dividedRatio < currentRatio * 0.5;
  var suspicious = currentRatio > 1.5;

  if (likelyPackAsUnit) {
    return {
      certain: true,
      action: 'history_package_price',
      confirmation_method: 'history_confirmed_package',
      reason: 'Detected sale price matches the package total implied by confirmed unit-price history',
      detected_price: candidateUnit,
      suggested_unit_price: Math.round(dividedUnit),
      suggested_package_price: candidateUnit,
      package_count: packageCount,
      historical_median_unit_price: history.median,
      history_anchor_id: (history.anchor_ids || []).join(','),
      history_anchor_name: (history.anchor_names || []).join(' | ') || 'Confirmed Inventory Log history',
      history_match_score: history.best_match_score || '',
      current_ratio: currentRatio,
      divided_ratio: dividedRatio,
      referenceMatch: referenceMatch
    };
  }

  if (suspicious) {
    return {
      certain: false,
      action: 'confirm_price_scale',
      reason: 'Unit price deviates strongly from recent history',
      detected_price: candidateUnit,
      suggested_unit_price: Math.round(candidateUnit),
      suggested_package_price: Math.round(candidateUnit * packageCount),
      package_count: packageCount,
      historical_median_unit_price: history.median,
      current_ratio: currentRatio,
      divided_ratio: dividedRatio,
      referenceMatch: referenceMatch
    };
  }

  return {
    certain: true,
    action: 'history_unit_price',
    confirmation_method: 'history_confirmed',
    history_anchor_id: (history.anchor_ids || []).join(','),
    history_anchor_name: (history.anchor_names || []).join(' | ') || 'Confirmed Inventory Log history',
    history_match_score: history.best_match_score || '',
    referenceMatch: referenceMatch
  };
}

function validateProductPriceScale(product, messageData, inventoryValues, inventoryHeaders) {
  var assessment = assessHistoricalPriceScale(product, messageData, inventoryValues, inventoryHeaders, { allowAutoCorrect: true });
  if (!product || !assessment || assessment.certain) return product;

  if (assessment.action === 'auto_correct_pack_as_unit') {
    var originalPrice = assessment.detected_price;
    product.unit_price = assessment.suggested_unit_price;
    product.sale_price = product.unit_price;
    product.price = product.unit_price;
    product.wholesale_price = product.unit_price;
    if (!Number(product.package_price || product.box_price || product.carton_price || product.double_pack_price || 0)) {
      product.package_price = originalPrice;
      product.double_pack_price = originalPrice;
    }
    product.extraction_confidence = Math.min(Number(product.extraction_confidence || product.confidence || 0.95), 0.88);
    product.status = 'needs_review';
    product.raw_metadata = JSON.stringify({
      needs_review: true,
      review_reason: 'Auto-corrected likely package price used as unit price',
      original_unit_candidate: originalPrice,
      corrected_unit_price: product.unit_price,
      package_count: assessment.package_count,
      historical_median_unit_price: assessment.historical_median_unit_price
    });
    return product;
  }

  product.status = 'needs_review';
  var existingMetadata = product.raw_metadata ? String(product.raw_metadata) : '';
  product.raw_metadata = existingMetadata || JSON.stringify({
    needs_review: true,
    review_reason: assessment.reason,
    unit_candidate: assessment.detected_price,
    package_count: assessment.package_count,
    historical_median_unit_price: assessment.historical_median_unit_price
  });

  return product;
}

function getRecentUnitPriceHistory(product, messageData, inventoryValues, inventoryHeaders, limit) {
  var headerMap = headerIndexMap(inventoryHeaders || []);
  var nameIdx = headerMap['Product Name'];
  var unitIdx = headerMap['Sale Unit Price'];
  var channelIdx = headerMap['Channel Username'];
  var actionIdx = headerMap['Action'];
  var statusIdx = headerMap['Status'];
  var extractionStatusIdx = headerMap['Extraction Status'];
  var brandIdx = headerMap['Brand'];
  var categoryIdx = headerMap['Category'];
  var variantIdx = headerMap['Variation Type'];
  var packageIdx = headerMap['Packaging'];
  var productIdIdx = headerMap['Product ID'];
  var targetName = product.name || product.product_name || '';
  var targetBrand = normalizeProductMatchText(product.brand || '');
  var targetCategory = normalizeProductMatchText(product.category || '');
  var targetVariant = normalizeProductMatchText(product.variant || product.variation_type || '');
  var targetPackageCount = Number(extractPackageCountFromProduct(product) || 0);
  var targetChannel = normalizeProductMatchText((messageData && (messageData.channel_username || messageData.channel)) || '');
  var prices = [];
  var anchorIds = [];
  var anchorNames = [];
  var bestMatchScore = 0;

  if (!targetName || nameIdx === undefined || unitIdx === undefined) {
    return { count: 0, median: 0, prices: [] };
  }

  for (var i = inventoryValues.length - 1; i >= 1 && prices.length < (limit || 8); i--) {
    var row = inventoryValues[i];
    if (actionIdx !== undefined && String(row[actionIdx] || '').toLowerCase() !== 'supplier_offer') continue;
    var lifecycleStatus = statusIdx !== undefined ? String(row[statusIdx] || '').trim().toLowerCase() : '';
    var extractionStatus = extractionStatusIdx !== undefined ? String(row[extractionStatusIdx] || '').trim().toLowerCase() : '';
    if (lifecycleStatus !== 'for sale' && lifecycleStatus !== 'available_from_supplier') continue;
    if (extractionStatus === 'waiting_user_confirmation' || extractionStatus.indexOf('waiting_confirmation') !== -1) continue;
    var nameScore = referenceNameSimilarity(targetName, row[nameIdx]);
    if (nameScore < 0.72) continue;
    if (targetBrand && brandIdx !== undefined && row[brandIdx] && attributeMatchScore(targetBrand, row[brandIdx]) === 0) continue;
    if (targetCategory && categoryIdx !== undefined && row[categoryIdx] && attributeMatchScore(targetCategory, row[categoryIdx]) === 0) continue;
    if (targetVariant && variantIdx !== undefined && row[variantIdx] && attributeMatchScore(targetVariant, row[variantIdx]) === 0) continue;
    if (targetPackageCount && packageIdx !== undefined) {
      var rowPackageCount = Number(extractPackageCount(row[packageIdx]) || 0);
      if (rowPackageCount && rowPackageCount !== targetPackageCount) continue;
    }
    if (targetChannel && channelIdx !== undefined) {
      var rowChannel = normalizeProductMatchText(row[channelIdx]);
      if (rowChannel && rowChannel !== targetChannel && nameScore < 0.9) continue;
    }
    var unit = Number(row[unitIdx]);
    if (!isNaN(unit) && unit > 0) {
      prices.push(unit);
      if (productIdIdx !== undefined && row[productIdIdx]) anchorIds.push(String(row[productIdIdx]));
      if (row[nameIdx]) anchorNames.push(String(row[nameIdx]));
      if (nameScore > bestMatchScore) bestMatchScore = nameScore;
    }
  }

  prices.sort(function(a, b) { return a - b; });
  var mid = Math.floor(prices.length / 2);
  var median = prices.length % 2 ? prices[mid] : (prices[mid - 1] + prices[mid]) / 2;
  return {
    count: prices.length,
    median: median || 0,
    prices: prices,
    anchor_ids: anchorIds,
    anchor_names: anchorNames,
    best_match_score: bestMatchScore
  };
}

function priceDistanceRatio(value, baseline) {
  value = Number(value) || 0;
  baseline = Number(baseline) || 0;
  if (value <= 0 || baseline <= 0) return 999;
  return Math.abs(value - baseline) / baseline;
}
