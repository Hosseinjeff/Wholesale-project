const MessageClassifier = {
  types: {
    PRODUCT_LISTING: 'product_listing',
    PRICE_UPDATE: 'price_update',
    OUT_OF_STOCK: 'out_of_stock',
    NON_PRODUCT: 'non_product'
  },

  classify: function(content, channelUsername) {
    const processed = NormalizationEngine.normalize(content);
    
    // Check for Out of Stock intent
    const isOOS = /(?:تمام شد|ناموجود|🚫)/i.test(content);
    if (isOOS && !/(?:قیمت|تومان)/i.test(content)) {
      return { type: this.types.OUT_OF_STOCK, confidence: 0.9 };
    }

    // Check for Pricing keywords
    const hasPricing = /(?:قیمت|تومان|تومن|ت|ریال|rial|:|[\d]+\/[\d]+)/i.test(processed);
    const hasNumbers = /\d+/.test(processed);
    
    if (hasPricing && hasNumbers) {
      // High confidence if it has specific channel patterns
      const normalizedChannel = (channelUsername || '').toLowerCase();
      if (normalizedChannel.includes('bonakdarjavan') || 
          normalizedChannel.includes('nobelshop118') || 
          normalizedChannel.includes('top_shop_rahimi')) {
        return { type: this.types.PRODUCT_LISTING, confidence: 0.95 };
      }
      return { type: this.types.PRODUCT_LISTING, confidence: 0.7 };
    }

    return { type: this.types.NON_PRODUCT, confidence: 0.8 };
  }
};

const NormalizationEngine = {
  normalize: function(text) {
    if (!text) return '';
    let result = persianToEnglishNumbers(text);
    // Remove zero-width spaces and normalize other whitespace
    result = result.replace(/[\u200B-\u200D\uFEFF]/g, '');
    result = result.replace(/\s+/g, ' ');
    return result.trim();
  },

  parsePrice: function(priceStr) {
      if (!priceStr) return 0;
      logTrace('parsePrice called', { priceStr: priceStr });
      let clean = persianToEnglishNumbers(priceStr.toString());
      // Replace Persian/Arabic decimal separator and other common separators
      clean = clean.replace(/[\/,٫\u066B]/g, '');
      // Treat dot as thousand separator if it looks like one
      clean = clean.replace(/\./g, '');
      // Remove any remaining non-digits
      clean = clean.replace(/\D/g, '');
      const result = parseInt(clean, 10) || 0;
      logTrace('parsePrice result', { result: result });
      return result;
    }
};

function extractChannelBonakdarjavan(content) {
  logTrace('Entering extractChannelBonakdarjavan');
  const products = [];
  
  // Clean content: remove contact info block at the bottom
  // The contact info usually starts with "ثبت سفارش" or "پاسخگو" or "آدرس" or "wa.me"
  const lines = content.split('\n').map(l => l.trim()).filter(l => l);
  
  // Find where the product info likely ends (before contact info)
  let productLines = [];
  for (let i = 0; i < lines.length; i++) {
      const line = lines[i];
      if (/^(?:ثبت سفارش|پاسخگو|آدرس|wa\.me|https?:\/\/|👇)/.test(line)) {
          logTrace('Contact info detected, stopping line collection', { line: line, index: i });
          break; 
      }
      productLines.push(line);
  }
  
  if (productLines.length < 2) {
      logTrace('Not enough lines to extract product', { count: productLines.length });
      Logger.log(`[Bonakdar] Not enough lines: ${productLines.length}`);
      return products;
  }

  // Assuming single product per message for now based on samples
  const name = cleanProductName(productLines[0]);
  logTrace('Extracted name', { name: name });
  Logger.log(`[Bonakdar] Name: ${name}`);
  if (!name || name.length < 3) {
    logTrace('Name too short or invalid', { name: name });
    return products;
  }

  const pricing = analyzePricingForSegment(productLines);
  logTrace('Pricing result', { pricing: pricing });
  Logger.log(`[Bonakdar] Pricing: Sale=${pricing.sale_price}, Consumer=${pricing.actual_price}`);
  
  // Extract packaging
  let packaging = '';
  productLines.forEach((line, idx) => {
      if (/(?:باکس|کارتن|تعداد)/.test(line) && !/قیمت/.test(line)) {
          packaging = line;
          logTrace('Extracted packaging', { packaging: packaging, lineIdx: idx });
      }
  });

  if (pricing.sale_price > 0 || pricing.actual_price > 0) {
      logTrace('Product validated and added', { name: name, sale: pricing.sale_price });
      products.push({
          name: name,
          sale_price: pricing.sale_price,
          unit_price: pricing.price_type === 'package' ? 0 : pricing.sale_price,
          package_price: pricing.price_type === 'package' ? pricing.sale_price : 0,
          consumer_price: pricing.actual_price,
          price: pricing.sale_price > 0 ? pricing.sale_price : pricing.actual_price,
          actual_price: pricing.actual_price,
          packaging: packaging,
          channel: '@bonakdarjavan',
          currency: 'IRT',
          stock_status: 'Available',
          description: content,
          confidence: pricing.extraction_confidence || 0.8,
          extraction_confidence: pricing.extraction_confidence || 0.8
      });
  } else {
    logTrace('Product rejected: no price found', { name: name });
  }

  return products;
}

function extractChannelTopShopRahimi(content) {
  logTrace('Entering extractChannelTopShopRahimi');
  const products = [];
  
  // Split by ✅ or newlines to get chunks
  // Normalize newlines to spaces first to handle split lines like "Price:\n 1000"
  const flatContent = content.replace(/\n/g, ' ');
  const chunks = flatContent.split(/✅|\u2705/).map(c => c.trim()).filter(c => c);

  logTrace('Split into chunks', { count: chunks.length });
  Logger.log(`TopShop Chunks: ${chunks.length}`);
  if (chunks.length < 2) {
    logTrace('Too few chunks to extract', { count: chunks.length });
    return products;
  }

  const name = NormalizationEngine.normalize(chunks[0]).replace(/[:.]/g, '').trim();
  logTrace('Extracted name', { name: name });
  Logger.log(`TopShop Name: ${name}`);
  if (!name || name.length < 3) {
    logTrace('Name too short or invalid', { name: name });
    return products;
  }

  let salePrice = 0;
  let consumerPrice = 0;
  let packaging = '';

  chunks.slice(1).forEach((chunk, idx) => {
      const cleanChunk = NormalizationEngine.normalize(chunk);
      logTrace(`Processing chunk ${idx}`, { chunk: cleanChunk });
      Logger.log(`  Chunk: "${cleanChunk}"`);
      
      // Skip chunks that explicitly say "No Price" or similar
      if (/(?:ندارد|نداره)/.test(cleanChunk)) {
           logTrace('Chunk explicitly says no price', { chunk: cleanChunk });
           Logger.log(`      -> Explicitly no price stated.`);
           return; 
      }

      // Extract packaging info
      // Avoid adding price chunks to packaging
      if (/(?:باکس|کارتن|تعداد)/.test(cleanChunk) && !/قیمت/.test(cleanChunk)) {
          packaging += cleanChunk + ' ';
          logTrace('Added to packaging', { chunk: cleanChunk });
      }

      // Extract prices
      // Look for numbers
      const priceMatch = cleanChunk.match(/([\d,\/\.\u06F0-\u06F9\u066B]{3,})/);
      if (priceMatch) {
          const priceVal = NormalizationEngine.parsePrice(priceMatch[1]);
          logTrace('Price found', { value: priceVal, match: priceMatch[1] });
          Logger.log(`    Price found: ${priceVal} from ${priceMatch[1]}`);
          if (priceVal > 100) {
              if (/(?:مصرف|consumer)/i.test(cleanChunk)) {
                  consumerPrice = priceVal;
                  logTrace('Identified as Consumer Price', { value: consumerPrice });
                  Logger.log(`      -> Consumer Price: ${consumerPrice}`);
              } else if (/(?:هر\s*عدد|دونه|unit|هر\s*کیلو|یک\s*عدد|قیمت\s*تکی)/i.test(cleanChunk)) {
                  salePrice = priceVal;
                  logTrace('Identified as Sale Price', { value: salePrice });
                  Logger.log(`      -> Sale Price: ${salePrice}`);
              } else if (/(?:باکس|box|کارتن)/i.test(cleanChunk) && /قیمت/.test(cleanChunk)) {
                  // Box price
                  logTrace('Ignored as Box Price', { value: priceVal });
                  Logger.log(`      -> Box Price (Ignored)`);
              } else if (!salePrice && !consumerPrice && cleanChunk.includes('قیمت')) {
                  // Fallback
                  salePrice = priceVal;
                  logTrace('Fallback Sale Price', { value: salePrice });
                  Logger.log(`      -> Fallback Sale Price: ${salePrice}`);
              }
          } else {
            logTrace('Price too low to be meaningful', { value: priceVal });
          }
      }
  });

  if (name && (salePrice > 0 || consumerPrice > 0)) {
      logTrace('Product validated and added', { name: name, sale: salePrice, consumer: consumerPrice });
      products.push({
          name: name,
          sale_price: salePrice,
          consumer_price: consumerPrice,
          price: salePrice > 0 ? salePrice : consumerPrice,
          actual_price: consumerPrice,
          packaging: packaging.trim(),
          channel: '@top_shop_rahimi',
          currency: 'IRT',
          stock_status: 'Available',
          description: content,
          confidence: 0.9,
          extraction_confidence: 0.9
      });
  } else {
    logTrace('Product rejected: no price found', { name: name });
  }

  return products;
}

function extractChannelNobelshop118(content) {
  logTrace('Entering extractChannelNobelshop118');
  const products = [];
  // Nobelshop messages often use double newlines within a single product description.
  // We treat the whole message as one segment unless clear separators exist.
  // If needed, we can split by "----------------" or similar later.
  const segments = [content]; 
  
  segments.forEach((segment, segIdx) => {
    logTrace(`Processing segment ${segIdx}`, { length: segment.length });
    // FIX: Split RAW segment first to preserve newlines because NormalizationEngine.normalize() collapses newlines into spaces
    const lines = segment.split('\n').map(l => l.trim()).filter(l => l);
    if (lines.length < 2) {
      logTrace('Segment too short to contain product', { linesCount: lines.length });
      return;
    }

    // Normalize lines individually for processing
    const normalizedLines = lines.map(l => NormalizationEngine.normalize(l));

    // Strategy: 
    // Line 1: Name (usually ending with ✅)
    // Line 2: Packaging (usually ending with ✅)
    // Lines 3+: Prices
    
    // 1. Name Extraction
    let name = normalizedLines[0].replace(/[✅\u2705]/g, '').trim();
    logTrace('Extracted name', { name: name });
    if (!name || name.length < 3) {
      logTrace('Name too short or invalid', { name: name });
      return;
    }
    
    // 2. Packaging Extraction
    let packaging = '';
    // Look for packaging in first 3 lines
    for (let i = 0; i < Math.min(normalizedLines.length, 3); i++) {
      if (/(?:عددی|باکس|کارتن|ورق|شیشه|بسته)/.test(normalizedLines[i])) {
        packaging = normalizedLines[i].replace(/[✅\u2705]/g, '').trim();
        logTrace('Extracted packaging', { packaging: packaging, lineIdx: i });
      }
    }

    // 3. Price Extraction
            let salePrice = 0;     // قیمت خرید (Our/Wholesale Price)
            let consumerPrice = 0; // قیمت مصرف (Consumer/Retail Price)
            
            normalizedLines.forEach((line, lineIdx) => {
              const cleanLine = line.replace(/[✅\u2705]/g, '').trim();
              // Match numbers that look like prices (at least 3 digits)
              const priceMatch = cleanLine.match(/([\d,\/\.\u06F0-\u06F9\u066B]{3,})/);
              
              if (priceMatch) {
                const priceVal = NormalizationEngine.parsePrice(priceMatch[1]);
                if (priceVal > 100) { // meaningful price
                   logTrace(`Price found on line ${lineIdx}`, { value: priceVal, match: priceMatch[1] });
                   Logger.log(`[Nobelshop] Line: ${cleanLine}, PriceVal: ${priceVal}`);
                   
                   if (/(?:مصرف|عمده|فروش ما|همکار|consumer|خرید)/i.test(cleanLine)) {
                       if (/(?:مصرف|consumer)/i.test(cleanLine)) {
                           consumerPrice = priceVal;
                           logTrace('Identified as Consumer Price', { value: consumerPrice });
                           Logger.log(`      -> Consumer Price: ${consumerPrice}`);
                       } else if (/(?:عمده|فروش ما|همکار|خرید)/i.test(cleanLine)) {
                           salePrice = priceVal;
                           logTrace('Identified as Sale Price', { value: salePrice });
                           Logger.log(`      -> Sale Price: ${salePrice}`);
                       }
                   } else if (!salePrice && !consumerPrice) {
                       // Fallback if no keywords found but price exists (less reliable)
                       salePrice = priceVal; 
                       logTrace('Fallback sale price assigned', { value: salePrice });
                   }
                } else {
                  logTrace('Price too low to be meaningful', { value: priceVal });
                }
              }
            });
            
            logTrace('Final pricing for segment', { salePrice, consumerPrice });
            Logger.log(`[Nobelshop] Final - Name: ${name}, Sale: ${salePrice}, Consumer: ${consumerPrice}`);
    
    if (name && (salePrice > 0 || consumerPrice > 0)) {
       logTrace('Product validated and added', { name: name, sale: salePrice, consumer: consumerPrice });
       products.push({
         name: name,
         sale_price: salePrice,
         consumer_price: consumerPrice,
         price: salePrice > 0 ? salePrice : consumerPrice,
         actual_price: consumerPrice, // Map to Actual Price header
         packaging: packaging,
         description: segment,
         confidence: 0.85,
         extraction_confidence: 0.85,
         channel: '@nobelshop118',
         currency: 'IRT',
         stock_status: 'Available',
         variation_type: '',
         original_message: segment
       });
    } else {
      logTrace('Product rejected: no price found', { name: name });
    }
  });
  
  return products;
}

function extractJsonArray(text) {
  if (!text) return null;
  
  // 1. Try to find content between ```json and ```
  const jsonBlockRegex = /```json\s*([\s\S]*?)\s*```/i;
  const blockMatch = text.match(jsonBlockRegex);
  if (blockMatch && blockMatch[1]) {
    return blockMatch[1].trim();
  }
  
  // 2. Try to find the first '[' and last ']'
  const firstBracket = text.indexOf('[');
  const lastBracket = text.lastIndexOf(']');
  
  if (firstBracket !== -1 && lastBracket !== -1 && lastBracket > firstBracket) {
    return text.substring(firstBracket, lastBracket + 1).trim();
  }
  
  return text.trim();
}

function extractJsonObject(text) {
  if (!text) return null;
  
  // 1. Try to find content between ```json and ```
  const jsonBlockRegex = /```json\s*([\s\S]*?)\s*```/i;
  const blockMatch = text.match(jsonBlockRegex);
  if (blockMatch && blockMatch[1]) {
    return blockMatch[1].trim();
  }
  
  // 2. Try to find the first '{' and last '}'
  const firstBrace = text.indexOf('{');
  const lastBrace = text.lastIndexOf('}');
  
  if (firstBrace !== -1 && lastBrace !== -1 && lastBrace > firstBrace) {
    return text.substring(firstBrace, lastBrace + 1).trim();
  }
  
  return text.trim();
}

/**
 * AI-powered extraction fallback.
 * Uses Google Gemini API (via AI Studio) to parse product details from text.
 */
function callAIProvider(prompt, parseMode) {
  const props = PropertiesService.getScriptProperties();
  const provider = (props.getProperty('AI_PROVIDER') || 'gemini').toLowerCase().trim();
  const apiKey = props.getProperty('AI_API_KEY');

  if (!apiKey) {
    logTrace('AI_API_KEY not found', { source: 'PropertiesService' });
    return null;
  }

  if (provider === 'openrouter') {
    return callOpenRouter(prompt, apiKey, parseMode, props);
  }
  return callGemini(prompt, apiKey, parseMode, props);
}

function callGemini(prompt, apiKey, parseMode, props) {
  const model = props.getProperty('AI_MODEL') || 'gemini-3.1-flash-lite';
  const endpoint = `https://generativelanguage.googleapis.com/v1beta/models/${model}:generateContent?key=${apiKey}`;

  const payload = {
    contents: [{ parts: [{ text: prompt }] }],
    generationConfig: {
      temperature: 0,
      response_mime_type: parseMode === 'json' ? 'application/json' : 'text/plain'
    }
  };

  const options = {
    method: 'post',
    contentType: 'application/json',
    payload: JSON.stringify(payload),
    muteHttpExceptions: true,
    timeout: 60000
  };

  const response = UrlFetchApp.fetch(endpoint, options);
  const responseCode = response.getResponseCode();
  const responseText = response.getContentText();

  if (responseCode !== 200) {
    Logger.log(`Gemini API Error (Code: ${responseCode}): ${responseText}`);
    logTrace('Gemini API Error', { code: responseCode, response: responseText });
    return null;
  }

  const result = JSON.parse(responseText);
  if (result.candidates && result.candidates.length > 0) {
    const parts = result.candidates[0].content.parts;
    // Skip "thought" parts and return the first actual text response
    for (let p = 0; p < parts.length; p++) {
      if (parts[p].text && !parts[p].thought) {
        Logger.log(`Gemini response: using parts[${p}] (skipped ${p} thought parts)`);
        return parts[p].text;
      }
    }
    // Fallback: return the last part if all were marked as thought
    Logger.log(`Gemini response: all parts marked as thought, using last part`);
    return parts[parts.length - 1].text;
  }

  Logger.log(`Gemini returned no candidates. Response: ${responseText}`);
  return null;
}

function callOpenRouter(prompt, apiKey, parseMode, props) {
  const model = props.getProperty('AI_MODEL') || 'google/gemini-3.1-flash-lite';
  const endpoint = 'https://openrouter.ai/api/v1/chat/completions';

  const payload = {
    model: model,
    messages: [{ role: 'user', content: prompt }],
    temperature: 0,
    max_tokens: CONFIG.AI_BATCH_MAX_TOKENS
  };

  if (parseMode === 'json') {
    payload.response_format = { type: 'json_object' };
  }

  const options = {
    method: 'post',
    contentType: 'application/json',
    headers: {
      'Authorization': 'Bearer ' + apiKey,
      'HTTP-Referer': 'https://script.google.com',
      'X-Title': 'Wholesale Product Extractor'
    },
    payload: JSON.stringify(payload),
    muteHttpExceptions: true
  };

  const response = UrlFetchApp.fetch(endpoint, options);
  const responseCode = response.getResponseCode();
  const responseText = response.getContentText();

  if (responseCode !== 200) {
    Logger.log(`OpenRouter API Error (Code: ${responseCode}): ${responseText}`);
    logTrace('OpenRouter API Error', { code: responseCode, response: responseText });
    return null;
  }

  const result = JSON.parse(responseText);
  if (result.choices && result.choices.length > 0) {
    return result.choices[0].message.content;
  }

  Logger.log(`OpenRouter returned no choices. Response: ${responseText}`);
  return null;
}

function parseAIProducts(contentStr) {
  const cleanedJson = extractJsonArray(contentStr);
  if (!cleanedJson) {
    Logger.log('No JSON array found in AI response');
    return [];
  }

  try {
    const parsed = JSON.parse(cleanedJson);
    const products = Array.isArray(parsed) ? parsed : (parsed.products || parsed.items || []);

    return products.map(function(p) {
      if (p && p.name) p.name = normalizeProductName(p.name);
      var np = _normalizePrices(p);
      return {
      name: p.name || 'Unknown Product',
      variation_type: p.variation_type || p.variant || '',
      brand: p.brand || '',
      manufacturer: p.manufacturer || '',
      supplier: p.supplier || '',
      category: p.category || '',
      subcategory: p.subcategory || '',
      sku: p.sku || '',
      barcode: p.barcode || '',
      model_number: p.model_number || '',
      sale_price: np.sale_price,
      actual_price: np.actual_price,
      price: np.sale_price,
      consumer_price: np.consumer_price,
      wholesale_price: np.wholesale_price,
      unit_price: np.unit_price,
      package_price: np.package_price,
      consumer_package_price: np.consumer_package_price,
      regular_package_price: np.consumer_package_price,
      double_pack_price: np.package_price,
      currency: p.currency || 'IRT',
      discount_percentage: Number(p.discount_percentage) || 0,
      discount_amount: Number(p.discount_amount) || 0,
      minimum_order_quantity: Number(p.minimum_order_quantity) || 0,
      packaging: p.packaging || '',
      volume: p.volume || '',
      weight: p.weight || '',
      net_weight: p.net_weight || '',
      gross_weight: p.gross_weight || '',
      dimensions: p.dimensions || '',
      color: p.color || '',
      size: p.size || '',
      flavor: p.flavor || '',
      material: p.material || '',
      ingredients: p.ingredients || '',
      country_of_origin: p.country_of_origin || '',
      stock_status: p.stock_status || 'Available',
      available_quantity: Number(p.available_quantity) || 0,
      expiry_date: p.expiry_date || '',
      production_date: p.production_date || '',
      warranty: p.warranty || '',
      rating: Number(p.rating) || 0,
      description: p.description || '',
      location: p.location || '',
      contact_info: p.contact_info || '',
      shipping_cost: Number(p.shipping_cost) || 0,
      delivery_time: p.delivery_time || '',
      payment_terms: p.payment_terms || '',
      extraction_confidence: 0.95
    };
  });
  } catch (parseError) {
    Logger.log(`AI JSON Parse Error: ${parseError.toString()} | Content: ${cleanedJson}`);
    logTrace('AI JSON Parse Error', { error: parseError.toString(), content: cleanedJson });
    return [];
  }
}

/**
 * Extracts product data using AI provider (Gemini or OpenRouter).
 * Falls back to regex extraction if AI fails.
 */
function extractWithAI(content, channelUsername) {
  logTrace('Calling AI API', { provider: (PropertiesService.getScriptProperties().getProperty('AI_PROVIDER') || 'gemini'), contentLength: content.length });

  const prompt = `You extract wholesale product listings from one supplier message.

Return ONLY a valid JSON array. No markdown. No explanations.

Rules:
- Extract only actual product-price listings with a wholesale/sale price.
- sale_price: the listed supplier sale/wholesale price exactly as shown.
- consumer_price: listed regular/retail/consumer price. Treat a plain regular price as unit/per-item regular price unless explicitly labeled as box/carton/package regular price.
- unit_price: sale price per item/unit/kilo only when the message explicitly says unit/per-item/kilo price.
- package_price: sale price per box/carton/package only when explicitly labeled as package/carton/box price.
- Keep Persian/Arabic product names and descriptions exactly as written. Do not translate.
- Normalize Persian/Arabic digits to English digits only in numeric fields.
- Split each variant into a separate product when size, flavor, volume, weight, packaging, model, or pack-count changes.
- Do not guess missing fields. Use null or "" when unclear.
- Do not mark a price as package_price just because packaging/pack count is present. It must be explicitly labeled as box/carton/package price.
- If package count exists, code will calculate missing unit/package counterparts after extraction. Do not invent missing fields when unclear.
- Do not confuse pack count, stock quantity, MOQ, dates, weights, and prices.
- If a product exists but any important extraction is uncertain, set needs_review=true and explain review_reason.

Fields per product:
name, brand, category, variant, sale_price, consumer_price, unit_price, package_price, consumer_package_price, regular_package_price, currency, packaging, volume, weight, minimum_order_quantity, available_quantity, stock_status, description, confidence, needs_review, review_reason.

Text:
"${content}"`;

  logTrace('Calling AI API', { provider: (PropertiesService.getScriptProperties().getProperty('AI_PROVIDER') || 'gemini'), contentLength: content.length });

  try {
    const contentStr = callAIProvider(prompt, 'json');
    if (!contentStr) {
      Logger.log('AI API returned no content. Falling back to regex.');
      return null;
    }

    logTrace('AI content received', { rawLength: contentStr.length });
    const products = parseAIProducts(contentStr);
    logTrace('AI JSON parsed successfully', { count: products.length });
    return products.length > 0 ? products : null;

  } catch (e) {
    Logger.log(`AI Extraction Error: ${e.toString()}`);
    logTrace('AI Extraction Exception', { error: e.toString() });
  }
  return null;
}

function processAIBatch() {
  logTrace('processAIBatch started');
  // #region debug-point H1:process-ai-batch-entry
  debugReportMessagedataProcessingStuck('H1', '60_extraction_ai.js:processAIBatch', 'processAIBatch entered', {});
  // #endregion
  const lock = LockService.getScriptLock();
  let spreadsheet, statusIdx;

  // Phase 1: Lock → Read pending messages (max 15) → Mark as 'processing' → Unlock
  try {
    if (!lock.tryLock(0)) {
      return { status: 'skipped', message: 'Lock busy, will retry later' };
    }
  } catch (e) {
    return { status: 'error', message: 'Could not obtain lock' };
  }

  const MAX = 15;
  let pendingMsgs = [];
  let pendingRows = [];

  try {
    spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
    const sheet = getOrCreateSheet(spreadsheet, 'MessageData', MESSAGE_HEADERS);
    const data = sheet.getDataRange().getValues();
    const hdrs = data[0];
    const hm = {};
    hdrs.forEach(function(h, i) { hm[h] = i; });
    statusIdx = hm['Status'];
    ensureMessageStatusValidation(sheet, statusIdx + 1);
    const ci = hm['Content'], chi = hm['Channel'], chui = hm['Channel Username'];
    const ti = hm['Timestamp'], ebi = hm['Edit Date'], opdi = hm['Original Post Date'];
    const chidi = hm['Chat ID'], chti = hm['Chat Title'];
    const fbi = hm['Forwarded By'], bi = hm['Batch ID'], idi = hm['ID'], rmi = hm['Raw Metadata'], ui = hm['URL'];

    for (let i = 1; i < data.length && pendingMsgs.length < MAX; i++) {
      const row = data[i];
      if (String(row[statusIdx] || '') === 'pending_ai') {
        pendingMsgs.push({
          rowIndex: i + 1,
          id: row[idi], content: row[ci],
          channel: row[chi], channel_username: row[chui],
          timestamp: row[ti], edit_date: row[ebi],
          original_post_date: opdi !== undefined ? row[opdi] : '',
          chat_id: row[chidi], chat_title: row[chti],
          forwarded_by: row[fbi], batch_id: row[bi], url: ui !== undefined ? row[ui] : '', raw_metadata: rmi !== undefined ? row[rmi] : ''
        });
        pendingRows.push(i + 1);
      }
    }

    if (pendingMsgs.length === 0) {
      // #region debug-point H5:no-pending-ai
      debugReportMessagedataProcessingStuck('H5', '60_extraction_ai.js:processAIBatch', 'no pending_ai rows found', { pendingCount: 0 });
      // #endregion
      return { status: 'success', processed: 0, message: 'No pending messages' };
    }

    // #region debug-point H1:selected-pending-rows
    debugReportMessagedataProcessingStuck('H1', '60_extraction_ai.js:processAIBatch', 'pending rows selected before processing mark', {
      pendingCount: pendingMsgs.length,
      pendingRows: pendingRows,
      messageIds: pendingMsgs.map(function(msg) { return msg.id; }),
      batchId: pendingMsgs[0] && pendingMsgs[0].batch_id
    });
    // #endregion

    for (let r = 0; r < pendingRows.length; r++) {
      sheet.getRange(pendingRows[r], statusIdx + 1).setValue('processing');
    }
    // #region debug-point H1:marked-processing
    debugReportMessagedataProcessingStuck('H1', '60_extraction_ai.js:processAIBatch', 'pending rows marked processing', { pendingRows: pendingRows });
    // #endregion
  } finally {
    lock.releaseLock();
  }

  // Phase 2: Process ALL messages via shared core
  var batchResult;
  try {
    // #region debug-point H2:run-batch-ai-start
    debugReportMessagedataProcessingStuck('H2', '60_extraction_ai.js:processAIBatch', '_runBatchAI starting', { messageCount: pendingMsgs.length, batchId: pendingMsgs[0] && pendingMsgs[0].batch_id });
    // #endregion
    batchResult = _runBatchAI(spreadsheet, pendingMsgs, pendingMsgs[0].batch_id);
    // #region debug-point H2:run-batch-ai-returned
    debugReportMessagedataProcessingStuck('H2', '60_extraction_ai.js:processAIBatch', '_runBatchAI returned', {
      processed: batchResult && batchResult.processed,
      productsStored: batchResult && batchResult.products_stored,
      resultCount: batchResult && batchResult.results ? batchResult.results.length : 0,
      confirmationCount: batchResult && batchResult.confirmation_requests ? batchResult.confirmation_requests.length : 0
    });
    // #endregion
  } catch (e) {
    // #region debug-point H1:run-batch-ai-threw
    debugReportMessagedataProcessingStuck('H1', '60_extraction_ai.js:processAIBatch', '_runBatchAI threw before status update', { error: e.toString(), pendingRows: pendingRows });
    // #endregion
    _resetToPending(spreadsheet, pendingRows, statusIdx);
    return { status: 'error', message: e.toString(), reset_to_pending: pendingRows.length, reset_rows: pendingRows, failure_stage: 'run_batch_ai' };
  }

  // Phase 3: Lock → Update status
  try {
    if (!lock.tryLock(5000)) {
      _resetToPending(spreadsheet, pendingRows, statusIdx);
      return { status: 'error', message: 'Lock busy for results' };
    }
  } catch (e) {
    _resetToPending(spreadsheet, pendingRows, statusIdx);
    return { status: 'error', message: e.toString(), reset_to_pending: pendingRows.length, reset_rows: pendingRows, failure_stage: 'phase3_lock_exception' };
  }

  try {
    const sheet = getOrCreateSheet(spreadsheet, 'MessageData', MESSAGE_HEADERS);
    const phase3Headers = sheet.getDataRange().getValues()[0] || [];
    const rawMetadataIdx = phase3Headers.indexOf('Raw Metadata');

    // Mark messages as ai_processed if they had products
    var processedRows = {};
    var confirmationRows = {};
    var notProductRows = [];
    var confirmationRequestsByMessageId = {};
    var requestList = (batchResult && batchResult.confirmation_requests) || [];
    for (var cr = 0; cr < requestList.length; cr++) {
      var req = requestList[cr];
      var mid = String(req.message_id || '');
      if (!mid) continue;
      if (!confirmationRequestsByMessageId[mid]) confirmationRequestsByMessageId[mid] = [];
      confirmationRequestsByMessageId[mid].push(req);
    }
    if (batchResult.results) {
      for (var i = 0; i < batchResult.results.length; i++) {
        var r = batchResult.results[i];
        if (r.needsConfirmation && r.msg.rowIndex) {
          sheet.getRange(r.msg.rowIndex, statusIdx + 1).setValue('needs_confirmation');
          if (rawMetadataIdx >= 0) {
            var existingMetadata = {};
            try { existingMetadata = r.msg.raw_metadata ? JSON.parse(r.msg.raw_metadata) : {}; } catch (e) { existingMetadata = {}; }
            existingMetadata.confirmation_requests = confirmationRequestsByMessageId[String(r.msg.id || '')] || [];
            existingMetadata.confirmation_saved_at = new Date().toISOString();
            sheet.getRange(r.msg.rowIndex, rawMetadataIdx + 1).setValue(JSON.stringify(existingMetadata));
          }
          confirmationRows[r.msg.rowIndex] = true;
        } else if (r.notProduct && r.msg.rowIndex) {
          // Flagged as not a product — set status to not_product
          notProductRows.push(r.msg.rowIndex);
        } else if (r.products.length > 0 && r.msg.rowIndex) {
          sheet.getRange(r.msg.rowIndex, statusIdx + 1).setValue('ai_processed');
          processedRows[r.msg.rowIndex] = true;
        }
      }
      // Mark not_product rows
      for (var i = 0; i < notProductRows.length; i++) {
        sheet.getRange(notProductRows[i], statusIdx + 1).setValue('not_product');
      }
    }

    // Reset unfilled messages back to pending_ai (exclude not_product rows)
    var notProductSet = {};
    for (var n = 0; n < notProductRows.length; n++) {
      notProductSet[notProductRows[n]] = true;
    }
    var failedRows = [];
    for (var i = 0; i < pendingRows.length; i++) {
      if (!processedRows[pendingRows[i]] && !notProductSet[pendingRows[i]] && !confirmationRows[pendingRows[i]]) {
        failedRows.push(pendingRows[i]);
      }
    }
    if (failedRows.length > 0) {
      // #region debug-point H1:unmapped-rows-reset
      debugReportMessagedataProcessingStuck('H1', '60_extraction_ai.js:processAIBatch', 'unmapped rows reset to pending_ai', {
        failedRows: failedRows,
        pendingRows: pendingRows,
        resultCount: batchResult && batchResult.results ? batchResult.results.length : 0,
        processedRows: Object.keys(processedRows),
        confirmationRows: Object.keys(confirmationRows),
        notProductRows: notProductRows
      });
      // #endregion
      _resetToPending(spreadsheet, failedRows, statusIdx);
    }
    // #region debug-point H1:status-update-complete
    debugReportMessagedataProcessingStuck('H1', '60_extraction_ai.js:processAIBatch', 'status update completed', {
      processedRows: Object.keys(processedRows),
      confirmationRows: Object.keys(confirmationRows),
      notProductRows: notProductRows,
      failedRows: failedRows
    });
    // #endregion

    Logger.log('processAIBatch: ' + batchResult.processed + ' msgs, ' + batchResult.products_stored + ' products');
    return {
      status: failedRows.length > 0 ? 'partial' : 'success',
      processed: batchResult.processed,
      products_stored: batchResult.products_stored,
      confirmation_requests: batchResult.confirmation_requests || [],
      reset_to_pending: failedRows.length,
      reset_rows: failedRows,
      failure_stage: failedRows.length > 0 ? 'unmapped_batch_results' : ''
    };

  } catch (error) {
    Logger.log('processAIBatch error: ' + error);
    _resetToPending(spreadsheet, pendingRows, statusIdx);
    return { status: 'error', message: error.toString() };
  } finally {
    lock.releaseLock();
  }
}

/**
 * Shared core: sends ALL messages to AI in ONE batch call, parses the response,
 * writes supplier offers to Inventory log, and returns results.
 */
function _runBatchAI(spreadsheet, messages, batchId) {
  // #region debug-point H2:run-batch-ai-entry
  debugReportMessagedataProcessingStuck('H2', '60_extraction_ai.js:_runBatchAI', '_runBatchAI entered', { messageCount: messages ? messages.length : 0, batchId: batchId });
  // #endregion
  // Build one conservative batch prompt for ALL messages without changing Inventory log headers
  var batchPrompt = 'You extract wholesale product listings from ' + messages.length + ' Telegram-style supplier messages.\n\n';
  batchPrompt += 'Return ONLY valid JSON. No markdown. No explanations.\n\n';
  batchPrompt += 'For each message, decide:\n';
  batchPrompt += '- type = "product" only if the message contains one or more actual products with an advertised wholesale/sale price.\n';
  batchPrompt += '- type = "not_product" for ads, greetings, contact-only messages, announcements, or messages without clear product-price pairs.\n\n';
  batchPrompt += 'Extraction rules:\n';
  batchPrompt += '1. These are wholesale supplier messages. sale_price is the listed supplier sale price exactly as shown. Do not infer whether a plain listed price is unit or package unless the message explicitly says so.\n';
  batchPrompt += '2. Do not guess. If a field is not clearly stated, use null or "".\n';
  batchPrompt += '3. Keep Persian/Arabic product names and descriptions exactly as written. Do not translate.\n';
  batchPrompt += '4. Normalize Persian/Arabic digits to English digits only in numeric fields.\n';
  batchPrompt += '5. Split variants into separate products when size, flavor, volume, weight, packaging, model, or pack-count changes.\n';
  batchPrompt += '6. consumer_price is regular/retail/consumer price. Treat plain regular prices as unit/per-item regular prices unless explicitly labeled as package/carton/box regular price.\n';
  batchPrompt += '7. unit_price is sale price per item/unit/kilo. Use sale_price here when the sale price is not explicitly a package/carton/box price.\n';
  batchPrompt += '8. package_price is sale price per box/carton/package only when explicitly labeled as package/carton/box price.\n';
  batchPrompt += '9. packaging is carton/box/pack/unit description, not a price.\n';
  batchPrompt += '10. available_quantity is only stock quantity, not pack count.\n';
  batchPrompt += '11. minimum_order_quantity is only minimum order requirement, not carton size.\n';
  batchPrompt += '12. Do not mark a price as package_price just because a carton contains multiple boxes/packages. If wording says price of each/one box or package, that is unit_price; package_price is the outer carton total. Code will infer missing counterparts.\n';
  batchPrompt += '13. If a number could be price, quantity, pack count, weight, or date and the label is unclear, do not extract it as price.\n';
  batchPrompt += '14. If product exists but important extraction is uncertain, include it with needs_review=true and explain review_reason.\n\n';
  batchPrompt += 'Product fields: name, brand, category, variant, sale_price, consumer_price, unit_price, package_price, consumer_package_price, regular_package_price, currency, packaging, volume, weight, minimum_order_quantity, available_quantity, stock_status, description, confidence, needs_review, review_reason.\n';
  batchPrompt += 'The code maps these fields into existing Inventory log headers; do not invent extra fields.\n\n';
  batchPrompt += 'Return this exact JSON object shape:\n';
  batchPrompt += '{\n  "1": {"type": "product", "products": [{"name": "", "brand": "", "category": "", "variant": "", "sale_price": null, "consumer_price": null, "unit_price": null, "package_price": null, "consumer_package_price": null, "regular_package_price": null, "currency": "IRT", "packaging": "", "volume": "", "weight": "", "minimum_order_quantity": null, "available_quantity": null, "stock_status": "", "description": "", "confidence": 0.0, "needs_review": false, "review_reason": ""}]},\n  "2": {"type": "not_product", "products": []}\n}\n\n';

  for (var i = 0; i < messages.length; i++) {
    var msg = messages[i];
    var channelInfo = msg.channel_username || msg.channel || 'Unknown';
    batchPrompt += 'Message ' + (i + 1) + ' (Channel: ' + channelInfo + '):\n';
    var priceHint = getPriceConfirmationHint(msg);
    if (priceHint) batchPrompt += 'Operator confirmation: the ambiguous sale price is ' + priceHint + '. Follow this confirmation.\n';
    batchPrompt += (msg.content || '') + '\n\n---\n\n';
  }

  logTrace('Batch AI extraction', { messageCount: messages.length, promptLength: batchPrompt.length });
  // #region debug-point H2:provider-call-before
  debugReportMessagedataProcessingStuck('H2', '60_extraction_ai.js:_runBatchAI', 'calling AI provider', { messageCount: messages.length, promptLength: batchPrompt.length });
  // #endregion

  var contentStr = callAIProvider(batchPrompt, 'json');
  // #region debug-point H2:provider-call-after
  debugReportMessagedataProcessingStuck('H2', '60_extraction_ai.js:_runBatchAI', 'AI provider returned', { hasContent: !!contentStr, contentLength: contentStr ? String(contentStr).length : 0 });
  // #endregion
  if (!contentStr) {
    Logger.log('Batch AI returned no content');
    return { processed: 0, products_stored: 0, results: [] };
  }

  var cleanedJson = extractJsonObject(contentStr);
  if (!cleanedJson) {
    cleanedJson = extractJsonArray(contentStr);
  }
  if (!cleanedJson) {
    Logger.log('Batch AI: no JSON found in response');
    return { processed: 0, products_stored: 0, results: [] };
  }

  var parsedResults = {};
  try {
    parsedResults = JSON.parse(cleanedJson);
    // #region debug-point H2:json-parse-success
    debugReportMessagedataProcessingStuck('H2', '60_extraction_ai.js:_runBatchAI', 'AI JSON parsed', { keyCount: parsedResults && typeof parsedResults === 'object' ? Object.keys(parsedResults).length : 0 });
    // #endregion
  } catch (parseError) {
    // #region debug-point H2:json-parse-error
    debugReportMessagedataProcessingStuck('H2', '60_extraction_ai.js:_runBatchAI', 'AI JSON parse error', { error: parseError.toString(), cleanedLength: cleanedJson ? cleanedJson.length : 0 });
    // #endregion
    Logger.log('Batch AI JSON parse error: ' + parseError + ' | Content: ' + cleanedJson);
    return { processed: 0, products_stored: 0, results: [] };
  }

  if (typeof parsedResults !== 'object' || Array.isArray(parsedResults)) {
    Logger.log('Batch AI: parsed result is not an object');
    return { processed: 0, products_stored: 0, results: [] };
  }

  // Build results: for each message, extract its products
  var results = [];
  for (var i = 0; i < messages.length; i++) {
    var msg = messages[i];
    var key = String(i + 1);
    var entry = parsedResults[key];
    
    var isNotProduct = false;
    var msgProducts = [];

    if (entry && typeof entry === 'object') {
      if (entry.type === 'not_product' || entry.type === 'non_product' || entry.type === 'skip') {
        isNotProduct = true;
      } else if (entry.type === 'product' && Array.isArray(entry.products)) {
        msgProducts = entry.products;
      } else if (Array.isArray(entry)) {
        // Old format fallback: direct array
        msgProducts = entry;
      }
    } else if (Array.isArray(entry)) {
      msgProducts = entry;
    }

    // Also check by message ID as fallback
    if (msgProducts.length === 0 && !isNotProduct) {
      var fallbackEntry = parsedResults[String(msg.id)] || parsedResults[msg.id];
      if (fallbackEntry && typeof fallbackEntry === 'object') {
        if (fallbackEntry.type === 'not_product') {
          isNotProduct = true;
        } else if (Array.isArray(fallbackEntry)) {
          msgProducts = fallbackEntry;
        } else if (Array.isArray(fallbackEntry.products)) {
          msgProducts = fallbackEntry.products;
        }
      }
    }

    // If AI responded for this message key but found no products, flag as not_product
    if (entry !== undefined && msgProducts.length === 0 && !isNotProduct) {
      isNotProduct = true;
    }

    // Filter valid products
    var validProducts = [];
    for (var p = 0; p < msgProducts.length; p++) {
      if (msgProducts[p] && msgProducts[p].name) {
        msgProducts[p].name = normalizeProductName(msgProducts[p].name);
        validProducts.push(msgProducts[p]);
      }
    }
    results.push({ msg: msg, products: validProducts, notProduct: isNotProduct });
  }

  // Write supplier offers to Inventory log
  var inventorySheet = getOrCreateSheet(spreadsheet, 'Inventory log', INVENTORY_LOG_HEADERS);
  ensureSheetHeaders(inventorySheet, INVENTORY_LOG_HEADERS);
  var inventoryValues = inventorySheet.getDataRange().getValues();
  var inventoryHeaders = inventoryValues[0] || [];
  var existingKeys = buildInventoryMergeKeySet(inventoryValues, inventoryHeaders);
  var referenceRows = readReferencePriceRows(spreadsheet);
  var totalStored = 0;
  var processedCount = 0;
  var confirmationRequests = [];

  for (var i = 0; i < results.length; i++) {
    var r = results[i];
    var msg = r.msg;
    if (r.products.length === 0) {
      processedCount++;
      continue;
    }

    for (var p = 0; p < r.products.length; p++) {
      var prod = r.products[p];
      var operatorPriceHint = getPriceConfirmationHint(r.msg);
      applyPriceConfirmationHint(r.msg, prod);
      var explicitPriceRole = prod.confirmed_price_role || '';
      if (!operatorPriceHint) {
        explicitPriceRole = applyExplicitMessagePrices(msg.content, prod) || detectExplicitSalePriceRole(msg.content, prod);
      }
      var np = _normalizePrices(prod);
      prod.detected_price = prod.detected_price || prod.sale_price || prod.price || prod.wholesale_price || prod.unit_price || prod.package_price || np.sale_price || np.package_price;
      var msgData = {
        id: msg.id, channel: msg.channel,
        channel_username: msg.channel_username, content: msg.content,
        timestamp: msg.timestamp, edit_date: msg.edit_date, original_post_date: msg.original_post_date,
        chat_id: msg.chat_id, chat_title: msg.chat_title,
        forwarded_by: msg.forwarded_by, batch_id: msg.batch_id,
        url: msg.url || ''
      };
      // Check references first before checking price certainty
      var scaleAssessment = assessHistoricalPriceScale(prod, msgData, inventoryValues, inventoryHeaders, { allowAutoCorrect: false, minHistory: 1, strictPackConfirmation: true, referenceRows: referenceRows, explicitPriceRole: explicitPriceRole });
      var alreadyResolvedByReference = false;
      var extractionStatus = operatorPriceHint ? 'user_confirmed' : (explicitPriceRole ? 'message_confirmed' : 'ai_confirmed');
      var priceConfirmationMethod = operatorPriceHint ? 'user_confirmed' : (explicitPriceRole ? ('message_explicit_' + explicitPriceRole) : 'direct_ai_confirmed');
      var usedReferenceMatch = scaleAssessment && scaleAssessment.referenceMatch;
      var referenceMetadata = {};
      if (usedReferenceMatch) {
        referenceMetadata = {
          matched_reference_product: usedReferenceMatch.name || usedReferenceMatch.clean_name || '',
          match_confidence: usedReferenceMatch.match_score || 0,
          match_components: usedReferenceMatch.match_components || null,
          reference_source: scaleAssessment.reference_source || ''
        };
      }
      if (scaleAssessment && scaleAssessment.action === 'reference_unit_price') {
        referenceMetadata.price_anchor_type = 'reference';
        referenceMetadata.price_anchor_id = usedReferenceMatch ? (usedReferenceMatch.reference_id || usedReferenceMatch.product_code || usedReferenceMatch.key || '') : (scaleAssessment.reference_anchor_id || '');
        referenceMetadata.price_anchor_name = usedReferenceMatch ? (usedReferenceMatch.name || usedReferenceMatch.clean_name || '') : (scaleAssessment.reference_anchor_name || '');
        referenceMetadata.price_anchor_match_score = usedReferenceMatch ? (usedReferenceMatch.match_score || '') : (scaleAssessment.reference_anchor_score || '');
        prod.unit_price = scaleAssessment.suggested_unit_price;
        prod.sale_price = scaleAssessment.suggested_unit_price;
        prod.package_price = prod.package_price || scaleAssessment.suggested_package_price;
        np = _normalizePrices(prod);
        alreadyResolvedByReference = true;
        extractionStatus = 'reference_confirmed';
        priceConfirmationMethod = 'reference_unit_price';
      } else if (scaleAssessment && scaleAssessment.action === 'reference_package_price') {
        referenceMetadata.price_anchor_type = 'reference';
        referenceMetadata.price_anchor_id = usedReferenceMatch ? (usedReferenceMatch.reference_id || usedReferenceMatch.product_code || usedReferenceMatch.key || '') : (scaleAssessment.reference_anchor_id || '');
        referenceMetadata.price_anchor_name = usedReferenceMatch ? (usedReferenceMatch.name || usedReferenceMatch.clean_name || '') : (scaleAssessment.reference_anchor_name || '');
        referenceMetadata.price_anchor_match_score = usedReferenceMatch ? (usedReferenceMatch.match_score || '') : (scaleAssessment.reference_anchor_score || '');
        prod.package_price = scaleAssessment.suggested_package_price;
        prod.double_pack_price = scaleAssessment.suggested_package_price;
        prod.unit_price = scaleAssessment.suggested_unit_price;
        prod.sale_price = scaleAssessment.suggested_unit_price;
        np = _normalizePrices(prod);
        alreadyResolvedByReference = true;
        extractionStatus = 'reference_confirmed';
        priceConfirmationMethod = 'reference_package_price';
      } else if (scaleAssessment && scaleAssessment.action === 'history_package_price') {
        referenceMetadata.price_anchor_type = 'history';
        referenceMetadata.price_anchor_id = scaleAssessment.history_anchor_id || '';
        referenceMetadata.price_anchor_name = scaleAssessment.history_anchor_name || 'Confirmed Inventory Log history';
        referenceMetadata.price_anchor_match_score = scaleAssessment.history_match_score || '';
        prod.package_price = scaleAssessment.suggested_package_price;
        prod.double_pack_price = scaleAssessment.suggested_package_price;
        prod.unit_price = scaleAssessment.suggested_unit_price;
        prod.sale_price = scaleAssessment.suggested_unit_price;
        np = _normalizePrices(prod);
        alreadyResolvedByReference = true;
        extractionStatus = 'history_confirmed';
        priceConfirmationMethod = 'history_confirmed_package';
      } else if (scaleAssessment && scaleAssessment.action === 'history_unit_price') {
        referenceMetadata.price_anchor_type = 'history';
        referenceMetadata.price_anchor_id = scaleAssessment.history_anchor_id || '';
        referenceMetadata.price_anchor_name = scaleAssessment.history_anchor_name || 'Confirmed Inventory Log history';
        referenceMetadata.price_anchor_match_score = scaleAssessment.history_match_score || '';
        extractionStatus = 'history_confirmed';
        priceConfirmationMethod = 'history_confirmed';
      } else if (scaleAssessment && !scaleAssessment.certain) {
        var waitingProduct = buildInventoryProductFromExtraction(prod, np, 'waiting_confirmation', 'ai_extracted_waiting_confirmation', 'waiting_user_confirmation', referenceMetadata);
        appendSupplierOfferToInventoryLog(spreadsheet, waitingProduct, msgData, existingKeys);
        var scaleRequest = buildPriceConfirmationRequest(msg, prod, np, scaleAssessment.reason, scaleAssessment, usedReferenceMatch);
        scaleRequest.request_index = p;
        confirmationRequests.push(scaleRequest);
        r.needsConfirmation = true;
        continue;
      }
      // Now check price certainty, but skip ambiguity check if reference resolved it
      var certainty = assessPriceCertainty(prod, np, { alreadyResolvedByReference: alreadyResolvedByReference, explicitPriceRole: explicitPriceRole });
      if (!certainty.certain) {
        var waitingProduct2 = buildInventoryProductFromExtraction(prod, np, 'waiting_confirmation', 'ai_extracted_waiting_confirmation', 'waiting_user_confirmation', referenceMetadata);
        appendSupplierOfferToInventoryLog(spreadsheet, waitingProduct2, msgData, existingKeys);
        var certaintyRequest = buildPriceConfirmationRequest(msg, prod, np, certainty.reason, null, usedReferenceMatch);
        certaintyRequest.request_index = p;
        confirmationRequests.push(certaintyRequest);
        r.needsConfirmation = true;
        continue;
      }
      if (!operatorPriceHint && !alreadyResolvedByReference && scaleAssessment && scaleAssessment.confirmation_method) {
        extractionStatus = scaleAssessment.confirmation_method;
        priceConfirmationMethod = scaleAssessment.confirmation_method;
      }
      var finalStatus = prod.needs_review ? 'needs_review' : 'available_from_supplier';
      var product = buildInventoryProductFromExtraction(prod, np, finalStatus, extractionStatus, priceConfirmationMethod, referenceMetadata);

      var appendResult = appendSupplierOfferToInventoryLog(spreadsheet, product, msgData, existingKeys);
      if (!appendResult.skipped) totalStored++;
    }
    processedCount++;
  }

  Logger.log('Batch AI processed ' + processedCount + ' msgs, stored ' + totalStored + ' products');
  // #region debug-point H1:run-batch-ai-complete
  debugReportMessagedataProcessingStuck('H1', '60_extraction_ai.js:_runBatchAI', '_runBatchAI completed', { processedCount: processedCount, totalStored: totalStored, confirmationRequests: confirmationRequests.length });
  // #endregion
  return { processed: processedCount, products_stored: totalStored, results: results, confirmation_requests: confirmationRequests };
}

function buildInventoryProductFromExtraction(prod, np, status, extractionStatus, priceConfirmationMethod, referenceMetadata) {
  referenceMetadata = referenceMetadata || {};
  return {
    name: prod.name || 'Unknown',
    variation_type: prod.variation_type || prod.variant || '',
    brand: prod.brand || '',
    manufacturer: prod.manufacturer || '',
    supplier: prod.supplier || '',
    category: prod.category || '',
    subcategory: prod.subcategory || '',
    sku: prod.sku || '',
    barcode: prod.barcode || '',
    model_number: prod.model_number || '',
    sale_price: np.sale_price,
    actual_price: np.actual_price,
    price: np.sale_price,
    consumer_price: np.consumer_price,
    wholesale_price: np.wholesale_price,
    unit_price: np.unit_price,
    package_price: np.package_price,
    consumer_package_price: np.consumer_package_price,
    regular_package_price: np.consumer_package_price,
    double_pack_price: np.package_price,
    currency: prod.currency || 'IRT',
    discount_percentage: Number(prod.discount_percentage) || 0,
    discount_amount: Number(prod.discount_amount) || 0,
    minimum_order_quantity: Number(prod.minimum_order_quantity) || 0,
    packaging: prod.packaging || '',
    volume: prod.volume || '',
    weight: prod.weight || '',
    net_weight: prod.net_weight || '',
    gross_weight: prod.gross_weight || '',
    dimensions: prod.dimensions || '',
    color: prod.color || '',
    size: prod.size || '',
    flavor: prod.flavor || '',
    material: prod.material || '',
    ingredients: prod.ingredients || '',
    country_of_origin: prod.country_of_origin || '',
    stock_status: prod.stock_status || 'Available',
    available_quantity: Number(prod.available_quantity) || 0,
    expiry_date: prod.expiry_date || '',
    production_date: prod.production_date || '',
    warranty: prod.warranty || '',
    rating: Number(prod.rating) || 0,
    description: prod.description || '',
    location: prod.location || '',
    contact_info: prod.contact_info || '',
    shipping_cost: Number(prod.shipping_cost) || 0,
    delivery_time: prod.delivery_time || '',
    payment_terms: prod.payment_terms || '',
    extraction_confidence: Number(prod.confidence) || 0.95,
    confidence: Number(prod.confidence) || 0.95,
    status: status || (prod.needs_review ? 'needs_review' : 'available_from_supplier'),
    extraction_status: resolveExtractionStatusPhrase(extractionStatus || '', priceConfirmationMethod || ''),
    price_anchor_type: referenceMetadata.price_anchor_type || '',
    price_anchor_id: referenceMetadata.price_anchor_id || '',
    price_anchor_name: referenceMetadata.price_anchor_name || '',
    price_anchor_match_score: referenceMetadata.price_anchor_match_score || '',
    detected_price: prod.detected_price || '',
    raw_metadata: JSON.stringify(Object.assign(
      prod.needs_review ? { needs_review: true, review_reason: prod.review_reason || 'AI marked extraction uncertain' } : {},
      referenceMetadata
    )),
    extraction_warning: prod.needs_review ? (prod.review_reason || 'AI marked extraction uncertain') : ''
  };
}

function _resetToPending(spreadsheet, pendingRows, statusIdx) {
  if (!spreadsheet || statusIdx < 0 || !pendingRows || pendingRows.length === 0) return;
  try {
    const sheet = getOrCreateSheet(spreadsheet, 'MessageData', MESSAGE_HEADERS);
    ensureMessageStatusValidation(sheet, statusIdx + 1);
    for (let r = 0; r < pendingRows.length; r++) {
      sheet.getRange(pendingRows[r], statusIdx + 1).setValue('pending_ai');
    }
    Logger.log('Reset ' + pendingRows.length + ' messages from processing back to pending_ai');
  } catch (e) {
    Logger.log('Error resetting to pending_ai: ' + e);
  }
}

function countPendingAIMessages() {
  try {
    const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
    const messageSheet = getOrCreateSheet(spreadsheet, 'MessageData', MESSAGE_HEADERS);
    const data = messageSheet.getDataRange().getValues();
    const headers = data[0];
    const statusIdx = headers.indexOf('Status');
    if (statusIdx < 0) return 0;
    let count = 0;
    for (let i = 1; i < data.length; i++) {
      if (String(data[i][statusIdx] || '') === 'pending_ai') count++;
    }
    return count;
  } catch (e) {
    Logger.log('countPendingAIMessages error: ' + e);
    return 0;
  }
}

/**
 * Called by the time-based trigger every 5 minutes.
 * Processes pending_ai messages in batches to stay within GAS 6-min timeout.
 * If more messages remain, it leaves them for the next trigger cycle.
 */
function processPendingTrigger() {
  Logger.log('processPendingTrigger fired at ' + new Date().toISOString());
  var startTime = Date.now();
  var maxRunTimeMs = 300000; // 5 minutes max to stay under 6-min GAS limit
  var totalProcessed = 0;
  var totalProducts = 0;
  
  while (true) {
    // Check if we're running out of time
    if (Date.now() - startTime > maxRunTimeMs) {
      Logger.log('processPendingTrigger: approaching timeout, stopping early');
      break;
    }
    
    var result = processAIBatch();
    totalProcessed += result.processed || 0;
    totalProducts += result.products_stored || 0;
    
    // Stop if no more pending or API error
    if (result.status === 'success' && result.processed === 0) {
      break;
    }
    if (result.status === 'error' || result.status === 'api_error') {
      Logger.log('processPendingTrigger: batch error, stopping: ' + JSON.stringify(result));
      break;
    }
  }
  
  Logger.log('processPendingTrigger completed: ' + totalProcessed + ' msgs, ' + totalProducts + ' products');
}

/**
 * Normalize product prices from AI/message extraction while preserving explicit roles.
 * Rules:
 *   - sale_price is the listed supplier price exactly as extracted
 *   - unit_price is trusted only when explicitly labeled or deterministically inferred before normalization
 *   - package_price is explicit package/carton/box sale price
 *   - consumer_price is regular/retail unit price unless explicitly package/carton/box in a dedicated field
 *   - downstream certainty checks decide whether a plain sale price is accepted or needs confirmation
 */
function _normalizePrices(p) {
  if (!p) {
    return { sale_price: 0, consumer_price: 0, actual_price: 0, wholesale_price: 0, unit_price: 0, package_price: 0, consumer_package_price: 0 };
  }

  function asNumber(value) {
    if (value === null || value === undefined || value === '') return 0;
    if (typeof value === 'string') {
      value = persianToEnglishNumbers(value).replace(/[^0-9.\-]/g, '');
    }
    var n = Number(value);
    return isNaN(n) || n < 0 ? 0 : n;
  }

  var unitPrice = asNumber(p.unit_price);
  var packagePrice = asNumber(p.package_price || p.carton_price || p.double_pack_price);
  var salePrice = unitPrice || packagePrice || asNumber(p.sale_price || p.price || p.wholesale_price);
  var consumerPrice = asNumber(p.consumer_price || p.actual_price || p.regular_price || p.retail_price);
  var consumerPackagePrice = asNumber(p.consumer_package_price || p.regular_package_price || p.retail_package_price);

  if (unitPrice <= 0 && packagePrice <= 0 && salePrice > 0) unitPrice = salePrice;

  return {
    sale_price: salePrice,
    consumer_price: consumerPrice,
    actual_price: consumerPrice || 0,
    wholesale_price: salePrice,
    unit_price: unitPrice,
    package_price: packagePrice,
    consumer_package_price: consumerPackagePrice
  };
}

function extractProducts(content, channelUsername) {
  // Check for extraction mode: 'ai' or 'regex' (default)
  const rawMode = PropertiesService.getScriptProperties().getProperty('EXTRACTION_MODE') || 'regex';
  const mode = rawMode.trim().toLowerCase();
  
  Logger.log(`Extraction mode check: raw="${rawMode}", trimmed="${mode}"`);
  
  if (mode === 'ai') {
    logTrace('AI mode active: attempting AI extraction', { channelUsername: channelUsername });
    const aiProducts = extractWithAI(content, channelUsername);
    if (aiProducts && aiProducts.length > 0) {
      logTrace('AI extraction successful', { count: aiProducts.length });
      return aiProducts;
    }
    Logger.log('AI extraction failed or returned no results, falling back to regex');
    logTrace('AI extraction failed/empty, falling back to regex');
  }

  if (channelUsername === '@bonakdarjavan') return extractChannelBonakdarjavan(content);
  if (channelUsername === '@top_shop_rahimi') return extractChannelTopShopRahimi(content);
  if (channelUsername === '@nobelshop118') return extractChannelNobelshop118(content);
  return extractUniversalProducts(content, channelUsername);
}

/**
 * A robust, line-by-line extractor that works for list-style and block-style messages.
 */
function extractUniversalProducts(content, channelUsername) {
  logTrace('Entering extractUniversalProducts', { channelUsername: channelUsername });
  const lines = content.split('\n').map(l => l.trim()).filter(l => l);
  const products = [];
  
  let currentProduct = null;
  
  // Regex helpers
  // Matches: 125,000 or 125/000 or 125.000
  const priceRegex = /[:\s]([\d,]+(?:\/[\d]{3})?|[\d,]+)(?:\s*(?:تومان|تومن|ت|T))?/i; 
  // Matches: "Consumer Price" keywords
  const consumerLabelRegex = /(?:مصرف|عمده|فروش ما|همکار)/i;
  // Matches: "Out of stock" keywords
  const oosRegex = /(?:تمام|ناموجود|❌)/i;

  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    const contactLine = /(wa\.me|https?:\/\/|@|📞|تماس|واتساپ|خرید\s*آنلاین|خرید\s*انلاین|لینک)/i.test(line);
    const hasPrice = priceRegex.test(line) && /\d/.test(line);
    const hasPriceLabel = /(?:قیمت|Price|فی|Value|Amount|تومان|تومن|ت)/i.test(line);
    const isSimplePrice = line.trim().length < 20 && hasPrice;
    const isPriceLine = !contactLine && ((hasPrice && hasPriceLabel) || isSimplePrice);
    
    // DECISION: Is this a new product or details for the current one?
    // It's a NEW product if:
    // 1. It doesn't look like a price line AND
    // 2. It's not a small detail (like "24 count") AND
    // 3. We either have no product yet, or the previous line was a price (end of previous block)
    const isNewProductStart = !isPriceLine && 
                              line.length > 3 && 
                              !line.match(/^(?:تعداد|باکس|کارتن|لینک|آدرس|شعبه|تماس|واتساپ|wa\.me|https?:\/\/|@|📞)/i) &&
                              (!currentProduct || (currentProduct.price > 0));

    if (isNewProductStart) {
      logTrace('Detected new product start', { line: line, index: i });
      // Save previous product if valid
      if (currentProduct) {
        if (currentProduct.price > 0 || currentProduct.stock_status === 'Out of Stock') {
          logTrace('Finalizing previous product', { name: currentProduct.name, price: currentProduct.price });
          products.push(finalizeProduct(currentProduct, channelUsername));
        } else {
          logTrace('Skipping previous product (no price/oos)', { name: currentProduct.name });
        }
      }

      // Start new product
      currentProduct = {
        raw_name: line,
        name: cleanProductName(line),
        sale_price: 0,
        actual_price: 0,
        price: 0,
        consumer_price: 0,
        packaging: extractPackaging(line),
        volume: extractVolume(line),
        stock_status: oosRegex.test(line) ? 'Out of Stock' : 'Available',
        description: line,
        extraction_confidence: 0.8
      };
      logTrace('Started new product object', { name: currentProduct.name });
    } else if (currentProduct) {
      // We are inside a product block, parse details
      currentProduct.description += '\n' + line;
      
      // Update Stock Status
      if (oosRegex.test(line)) {
        logTrace('Updated stock status to Out of Stock', { line: line });
        currentProduct.stock_status = 'Out of Stock';
      }

      // Update Packaging/Volume if found in detail lines
      if (!currentProduct.packaging) {
        const pkg = extractPackaging(line);
        if (pkg) {
          logTrace('Extracted packaging from detail line', { packaging: pkg, line: line });
          currentProduct.packaging = pkg;
        }
      }
      if (!currentProduct.volume) {
        const vol = extractVolume(line);
        if (vol) {
          logTrace('Extracted volume from detail line', { volume: vol, line: line });
          currentProduct.volume = vol;
        }
      }

      // Extract Prices
      if (isPriceLine) {
        logTrace('Processing price line', { line: line });
        const prices = extractPricesFromLine(line);
        if (prices.sale > 0) {
          currentProduct.sale_price = prices.sale;
          currentProduct.price = prices.sale;
        }
        if (prices.consumer > 0) {
          currentProduct.actual_price = prices.consumer;
          currentProduct.consumer_price = prices.consumer;
        }
        currentProduct.extraction_confidence = Math.max(currentProduct.extraction_confidence, 0.9);
        
        // Fallback: If we found a price but didn't know if it was sale/consumer
        // usually the smaller number is 'Our Price' and larger is 'Consumer'
        if (currentProduct.price > 0 && currentProduct.consumer_price > 0) {
           if (currentProduct.price > currentProduct.consumer_price) {
             logTrace('Swapping prices: sale > consumer', { sale: currentProduct.price, consumer: currentProduct.consumer_price });
             // Swap if sale price is accidentally higher than consumer price
             const temp = currentProduct.price;
             currentProduct.price = currentProduct.consumer_price;
             currentProduct.consumer_price = temp;
             const t2 = currentProduct.sale_price;
             currentProduct.sale_price = currentProduct.actual_price;
             currentProduct.actual_price = t2;
           }
        }
        logTrace('Updated product prices', { sale: currentProduct.price, consumer: currentProduct.consumer_price });
      }
    } else {
      // No current product and not a new start
      if (line.length > 0) {
        logTrace('Ignoring line (no active product)', { line: line });
      }
    }
  }

  // Push the very last product found
  if (currentProduct) {
    if (currentProduct.price > 0 || currentProduct.stock_status === 'Out of Stock') {
      logTrace('Finalizing last product', { name: currentProduct.name, price: currentProduct.price });
      products.push(finalizeProduct(currentProduct, channelUsername));
    } else {
      logTrace('Skipping last product (no price/oos)', { name: currentProduct.name });
    }
  }

  logTrace('Finished extractUniversalProducts', { productsFound: products.length });
  return products;
}

// --- Helper Functions for the Universal Extractor ---

function extractPricesFromLine(line) {
  logTrace('extractPricesFromLine called', { line: line });
  // Logic to distinguish between "Our Price" and "Consumer Price" on a single line
  const result = { sale: 0, consumer: 0 };
  
  // Normalize: 125/000 -> 125000
  const cleanLine = persianToEnglishNumbers(line).replace(/(\d+)\/(\d+)/g, '$1$2').replace(/[,\.\u066B]/g, '');
  const numbers = cleanLine.match(/\d+/g);
  
  if (!numbers) {
    logTrace('No numbers found in price line', { cleanLine: cleanLine });
    return result;
  }
  
  const vals = numbers
    .filter(n => n.length >= 4 && n.length <= 8)
    .map(n => parseInt(n, 10))
    .filter(n => n > 1000);
  
  logTrace('Extracted values', { vals: vals });

  if (line.match(/(?:مصرف|روی جلد)/)) {
    result.consumer = vals[0] || 0;
    logTrace('Identified as consumer price', { consumer: result.consumer });
  } else if (line.match(/(?:فروش|خرید|ما|همکار)/)) {
    result.sale = vals[0] || 0;
    logTrace('Identified as sale price', { sale: result.sale });
  } else {
    // If ambiguous, assume it's the sale price
    result.sale = vals[0] || 0;
    logTrace('Ambiguous line: assuming sale price', { sale: result.sale });
  }
  
  // Special Case: "Consumer: 275000 Our Price: 253000" on same line
  if (vals.length >= 2) {
     result.sale = Math.min(...vals);
     result.consumer = Math.max(...vals);
     logTrace('Multiple values found: assigning min to sale, max to consumer', { sale: result.sale, consumer: result.consumer });
  }
  
  return result;
}

function cleanProductName(raw) {
  if (!raw) return '';
  logTrace('cleanProductName called', { raw: raw });
  // Remove common emojis and specific prefixes
  let clean = raw.replace(/[✅❌🛑⭕️🚀🔥💎📦✨🌟📣💰🛍️•●▪]/g, '')
            .replace(/^(?:نام)?\s*محصول[:\s]*/, '')
            .replace(/^[-+*○◦‣▪■□➔➢➤]+/, '') // Remove leading symbols
            .replace(/[:]+$/, '') // Remove trailing colons
            .trim();
            
  // If name is just a price/info label or starts with one followed by price-like content
  const labels = ['قیمت فروش ما', 'قیمت مصرف کننده', 'قیمت مصرف', 'قیمت', 'قیمت هر یک ورق', 'قیمت هر ورق', 'قیمت هر عدد', 'قیمت هر شیشه', 'فی', 'تعداد', 'باکس', 'کارتن', 'دونه ای', 'مصرف', 'موجود', 'خرید'];
  
  const lowerClean = clean.toLowerCase();
  if (labels.some(label => lowerClean === label || lowerClean.startsWith(label + ':') || lowerClean.startsWith(label + ' '))) {
    // If it starts with a label, check if it has numbers or price-like symbols (likely a price line, not a name)
    if (/[\d\u06F0-\u06F9]/.test(clean) || clean.includes('٫') || clean.includes('/')) {
      logTrace('Rejected as price/info label', { clean: clean });
      return '';
    }
  }

  // If name contains common price/packaging labels or emojis followed by info, it's likely not just a name
  const splitPattern = /(?:قیمت|تعداد|باکس|کارتن|دونه ای|مصرف|فی|موجود|✅|🚀|🔥|💎|📦|✨|🌟|📣|💰|🛍️)/i;
  if (splitPattern.test(clean)) {
    const parts = clean.split(splitPattern);
    if (parts[0].trim().length > 2) {
      const result = parts[0].trim().replace(/[:\s-]+$/, '').trim();
      logTrace('Split name and took first part', { original: clean, result: result });
      return result;
    }
  }
  if (/(آدرس|میدان|خیابان|پاساژ|پلاک|بازار|wa\.me|https?:\/\/|@|واتساپ|تماس|اتمام|ناموجود|تمام شد)/i.test(clean)) {
    logTrace('Rejected as contact/location info', { clean: clean });
    return '';
  }
  if (!/[\u0600-\u06FFA-Za-z]/.test(clean)) {
    logTrace('Rejected as non-alphabetic', { clean: clean });
    return '';
  }
  if (/^\s*[\d\u06F0-\u06F9\-\.\,\/\s]+$/.test(clean)) {
    logTrace('Rejected as numeric/symbolic only', { clean: clean });
    return '';
  }
  logTrace('ProductName cleaned successfully', { result: clean });
  return clean;
}

function finalizeProduct(p, channel) {
  const channelKey = (channel || '').toLowerCase();
  const salePrice = p.sale_price || p.price || 0;
  let consumerPrice = p.actual_price || p.consumer_price || null;

  // Channel-specific normalization:
  // For @nobelshop118, if consumer price is missing, mirror the sale price
  if (!consumerPrice && channelKey.includes('nobelshop118') && salePrice > 0) {
    consumerPrice = salePrice;
  }

  return {
    name: p.name,
    // Standardized pricing fields
    sale_price: salePrice,
    actual_price: consumerPrice,
    price_type: p.price_type || inferPriceTypeFromPackaging(p.packaging),
    // Backward-compatible fields
    price: salePrice,
    consumer_price: consumerPrice,
    currency: 'IRT',
    packaging: p.packaging,
    volume: p.volume,
    stock_status: p.stock_status,
    variation_type: p.variation_type || '',
    channel_username: channel,
    description: p.description,
    category: extractCategory(p.name, p.description, channel),
    confidence: p.confidence || 0.95,
    extraction_confidence: p.extraction_confidence || p.confidence || 0.95
  };
}

// Reuse your existing extractPackaging/Volume logic but wrap nicely
function extractPackaging(text) {
  const m = text.match(/(?:باکس|کارتن|شیرینگ)\s*(\d+\s*(?:عددی|تایی|عدد))/i);
  return m ? m[0] : '';
}

function extractVolume(text) {
  const m = text.match(/(\d+\s*(?:گرم|gr|ml|لیتر|میلی))/i);
  return m ? m[0] : '';
}

// --- Pricing Configuration System ---
function inferPriceTypeFromPackaging(packaging) {
  if (!packaging) return 'single';
  if (/باکس|کارتن|شیرینگ|ورق/i.test(packaging)) return 'pack';
  return 'single';
}

function detectPresentationMethod(segment) {
  const s = segment || '';
  const hasSingle = /(?:دونه\s*ای|تک\s*فروش|فی\s*هر\s*عدد)/i.test(s);
  const hasPack = /(?:باکس|کارتن|شیرینگ|ورق)/i.test(s);
  if (hasSingle && hasPack) return 'both';
  if (hasPack) return 'pack_only';
  if (hasSingle) return 'single_only';
  // Heuristic: if only one price and packaging mentions counts later, mark pack_only
  return 'single_only';
}

function extractVariationType(text) {
  if (!text) return '';
  // Look for flavor/model indicators commonly used
  const m1 = text.match(/طعم\s*([^\n،,]+)/i);
  if (m1 && m1[1]) return m1[1].trim();
  const m2 = text.match(/مدل\s*([^\n،,]+)/i);
  if (m2 && m2[1]) return m2[1].trim();
  const m3 = text.match(/رنگ\s*([^\n،,]+)/i);
  if (m3 && m3[1]) return m3[1].trim();
  return '';
}

function analyzePricingForSegment(lines) {
  logTrace('analyzePricingForSegment called', { linesCount: lines.length });
  let sale_price = 0;
  let actual_price = 0;
  let price_type = 'unit';
  let extraction_confidence = 0;

  lines.forEach((line, idx) => {
    const norm = NormalizationEngine.normalize(line);
    
    // Check for consumer price
    if (/(?:مصرف|consumer)/i.test(line)) {
      const m = norm.match(/[\d,\/\.]{4,}/);
      if (m) {
        actual_price = NormalizationEngine.parsePrice(m[0]);
        logTrace(`Found consumer price on line ${idx}`, { value: actual_price });
      }
    }
    
    // Check for sale price
    if (/(?:فروش|خرید|\bما\b|همکار|دونه\s*ای|فی|هر\s*عدد|هر\s*کیلو|قیمت\s*تکی|یک\s*باکس|قیمت\s*باکس|قیمت\s*کارتن|قیمت\s*جعبه)/i.test(line)) {
      const m = norm.match(/[\d,\/\.]{4,}/);
      if (m) {
        sale_price = NormalizationEngine.parsePrice(m[0]);
        const explicitlyPackagePrice = /(?:یک\s*باکس|قیمت\s*باکس|قیمت\s*کارتن|قیمت\s*جعبه|قیمت\s*پک|box\s*price|carton\s*price|package\s*price)/i.test(line);
        price_type = explicitlyPackagePrice ? 'package' : 'unit';
        extraction_confidence = 0.9;
        logTrace(`Found sale price on line ${idx}`, { value: sale_price, price_type: price_type });
      }
    } else if (!sale_price) {
        // Fallback: standalone price check
        const m = norm.match(/^\s*[\d,\/]{4,}\s*(?:تومان|ت|ریال)?\s*$/);
        if (m) {
            sale_price = NormalizationEngine.parsePrice(m[0]);
            extraction_confidence = 0.6;
            logTrace(`Found fallback sale price on line ${idx}`, { value: sale_price });
        }
    }
    
    // Pack hints are packaging only; they do not make the price package-level unless the price line explicitly says package/carton/box price.
    if (/(?:باکس|کارتن|شیرینگ|ورق)/i.test(line)) {
      logTrace(`Detected packaging hint on line ${idx}`);
    }
  });

  logTrace('analyzePricingForSegment finished', { sale_price, actual_price, price_type });
  return { sale_price, actual_price, price_type, extraction_confidence };
}

function performQualityChecks(product, spreadsheet) {
  const qa = { requires_review: false, reason: '' };
  let discountPct = null;
  if (product.actual_price && product.sale_price) {
    discountPct = 1 - (product.sale_price / product.actual_price);
    if (discountPct > 0.8 || discountPct < 0.05) {
      qa.requires_review = true;
      qa.reason = `Unusual discount ${Math.round(discountPct * 100)}%`;
    }
  }
  if (product.extraction_confidence < 0.85) {
    qa.requires_review = true;
    qa.reason = qa.reason ? (qa.reason + '; low confidence') : 'Low confidence';
  }
  return qa;
}

function logExtractionIssue(spreadsheet, severity, messageId, description) {
  try {
    const logSheet = getOrCreateSheet(spreadsheet, 'ExecutionLogs', ['Timestamp','Action','Level','Channel','Content Length','Message','Error Code','Details']);
    logSheet.appendRow([
      new Date().toISOString(),
      'price_extraction',
      severity,
      '',
      '',
      `Message ${messageId}`,
      0,
      description
    ]);
  } catch (e) {
    // noop
  }
}

// HELPER FUNCTIONS FOR ENHANCED EXTRACTION

function extractConsumerPrice(content, productName) {
  // Try to find consumer price related to a product name in a larger content
  const lines = content.split('\n');
  for (let i = 0; i < lines.length; i++) {
    if (lines[i].includes(productName)) {
      // Look ahead a few lines for consumer price
      for (let j = i; j < Math.min(i + 5, lines.length); j++) {
        const consMatch = lines[j].match(/(?:مصرف|مصرف\s+کننده):?\s*([\d,\/]+)/i);
        if (consMatch) return NormalizationEngine.parsePrice(consMatch[1]);
      }
    }
  }
  return null;
}

function extractStockStatus(content) {
  const stockIndicators = {
    'Out of Stock': ['sold out', 'out of stock', 'unavailable', 'discontinued', 'ناموجود', 'اتمام', 'تمام شد', 'تمام'],
    'Limited': ['limited', 'few left', 'last pieces', 'running low', 'محدود', 'تعداد محدود'],
    'Pre-order': ['pre-order', 'coming soon', 'available soon', 'به زودی', 'پیش خرید'],
    'Available': ['available', 'in stock', 'ready to ship', 'موجود', 'در انبار']
  };

  const lowerContent = (content || '').toLowerCase();

  for (const [status, keywords] of Object.entries(stockIndicators)) {
    if (keywords.some(keyword => lowerContent.includes(keyword))) {
      return status;
    }
  }

  return 'Available'; // Default assumption
}

function extractCategory(name, description, channelUsername) {
  const text = (name + ' ' + description).toLowerCase();

  // Channel-specific category hints
  const channelHints = {
    '@wholesale_electronics': ['electronics'],
    '@fashion_wholesale': ['clothing', 'fashion'],
    '@beauty_wholesale': ['beauty', 'cosmetics'],
    '@home_decor': ['home', 'furniture'],
    '@bonakdarjavan': ['food', 'canned', 'conserves', 'کنسرو'],
    '@top_shop_rahimi': ['beverages', 'drinks', 'energy', 'نوشیدنی', 'انرژی'],
    '@nobelshop118': ['beverages', 'coffee', 'cappuccino', 'نوشیدنی', 'قهوه']
  };

  // Check channel-specific hints first
  if (channelUsername && channelHints[channelUsername]) {
    const hint = channelHints[channelUsername][0];
    if (text.includes(hint) || channelHints[channelUsername].some(keyword => text.includes(keyword))) {
      return hint.charAt(0).toUpperCase() + hint.slice(1);
    }
  }

  // Persian keywords for categorization
  const persianCategories = {
    'food': ['کنسرو', 'خوراک', 'غذا', 'میوه', 'سبزی', 'لبنیات'],
    'beverages': ['نوشیدنی', 'قهوه', 'چای', 'انرژی', 'مایع', 'جوشان'],
    'electronics': ['گوشی', 'موبایل', 'لپ تاپ', 'تبلت', 'شارژر', 'هدفون'],
    'clothing': ['لباس', 'شلوار', 'پیراهن', 'کفش', 'کلاه', 'تیشرت'],
    'home': ['خانه', 'دکور', 'مبلمان', 'آشپزخانه', 'حمام', 'رختخواب'],
    'beauty': ['آرایشی', 'پوست', 'مو', 'کرم', 'لوسیون', 'ماسک']
  };

  // Check Persian keywords
  for (const [category, keywords] of Object.entries(persianCategories)) {
    if (keywords.some(keyword => text.includes(keyword))) {
      return category.charAt(0).toUpperCase() + category.slice(1);
    }
  }

  const categories = {
    'electronics': ['phone', 'iphone', 'samsung', 'laptop', 'computer', 'tablet', 'charger', 'cable', 'headphone', 'airpods', 'macbook', 'ipad'],
    'clothing': ['shirt', 'pants', 'dress', 'jacket', 'shoe', 'boot', 'hat', 'jeans', 't-shirt', 'hoodie'],
    'food': ['canned', 'food', 'conserves', 'fruit', 'vegetable', 'dairy'],
    'beverages': ['drink', 'coffee', 'tea', 'energy', 'beverage', 'cappuccino'],
    'home': ['furniture', 'decoration', 'kitchen', 'bathroom', 'bedding', 'sofa', 'table', 'chair'],
    'beauty': ['cosmetic', 'skincare', 'makeup', 'perfume', 'hair', 'cream', 'lotion', 'mask'],
    'sports': ['equipment', 'fitness', 'sport', 'gym', 'workout', 'bicycle', 'ball', 'racket'],
    'automotive': ['car', 'auto', 'vehicle', 'tire', 'part', 'engine', 'wheel']
  };

  for (const [category, keywords] of Object.entries(categories)) {
    if (keywords.some(keyword => text.includes(keyword))) {
      return category.charAt(0).toUpperCase() + category.slice(1);
    }
  }

  return 'General';
}

// Function to add custom patterns for a specific channel
function addChannelPatterns(channelUsername, patterns) {
  CHANNEL_PATTERNS[channelUsername] = patterns;
  Logger.log(`Added custom patterns for channel: ${channelUsername}`);
}

// Function to get available channels and their patterns
function getChannelPatterns() {
  return Object.keys(CHANNEL_PATTERNS);
}

function extractLocation(content, customPatterns) {
  // Common locations in Wholesale project messages
  const marketKeywords = [
    'میدان محمدیه',
    'خیابان خیام',
    'پاساژ برلیان',
    'اعدام',
    'پلاک',
    'بازار تهران'
  ];

  // If content contains specific market keywords, try to extract the whole sentence/line
  for (const keyword of marketKeywords) {
    if (content.includes(keyword)) {
      // Find the line containing the keyword
      const lines = content.split('\n');
      for (const line of lines) {
        if (line.includes(keyword)) {
          return line.trim();
        }
      }
    }
  }

  // Use custom patterns if provided, otherwise use defaults
  const locationPatterns = customPatterns || [
    /location:?\s*([^\n,]+)/i,
    /📍\s*([^\n]+)/,
    /آدرس:?\s*([^\n]+)/,
    /based in:?\s*([^\n,]+)/i,
    /from:?\s*([^\n,]+)/i,
    /shipping from:?\s*([^\n,]+)/i,
    /made in:?\s*([^\n,]+)/i
  ];

  for (const pattern of locationPatterns) {
    const match = content.match(pattern);
    if (match && match[1]) return match[1].trim();
  }

  return '';
}

function extractContactInfo(content, customPatterns) {
  // Use custom patterns if provided, otherwise use defaults
  const contactPatterns = customPatterns || [
    /contact:?\s*([^\n]+)/i,
    /call:?\s*([^\n]+)/i,
    /whatsapp:?\s*([^\n]+)/i,
    /telegram:?\s*@?([^\n\s]+)/i,
    /dm:?\s*@?([^\n\s]+)/i,
    /📞\s*([^\n]+)/,
    /📱\s*([^\n]+)/
  ];

  for (const pattern of contactPatterns) {
    const match = content.match(pattern);
    if (match && match[1]) return match[1].trim();
  }

  return '';
}
