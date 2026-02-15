
const fs = require('fs');
const path = require('path');

// Mock GAS globals
const Logger = {
    log: (msg) => console.log(`[GAS LOG] ${msg}`)
};

const Utilities = {
    sleep: (ms) => new Promise(resolve => setTimeout(resolve, ms))
};

// Persian number conversion helper
function persianToEnglishNumbers(text) {
  if (!text) return '';
  const persianNumbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
  const englishNumbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

  return text.split('').map(char => {
    const index = persianNumbers.indexOf(char);
    return index !== -1 ? englishNumbers[index] : char;
  }).join('');
}

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
      // Handle formats like 75/000 or 1,200,000 or 1.200.000
      let clean = priceStr.toString().replace(/[\/,]/g, '');
      
      // If it's something like 75/000, the regex above makes it 75000
      // If it's something like 1.200.000, we might need to handle dots too
      if (clean.includes('.') && clean.split('.').length > 2) {
        clean = clean.replace(/\./g, '');
      }
      
      return parseFloat(clean) || 0;
    }
};

// Channel-specific extraction patterns
const CHANNEL_PATTERNS = {
  // Default patterns for unknown channels
  'default': {
    product_patterns: [
      /(?:^|\n)([^\n$]+?)\s*-\s*\$?(\d+(?:\.\d{2})?)\s*-\s*([^\n$]*)/gi,
      /Product:?\s*([^\n]+?)\s*Price:?\s*\$?(\d+(?:\.\d.2})?)\s*(.+?)(?=\nProduct|$)/gi,
      /#([^\n$]+?)\s*\$(\d+(?:\.\d{2})?)/gi,
      /([^\n-]+?)\s*-\s*\$?(\d+(?:\.\d{2})?)\s*-\s*([^\n-]*)/gi
    ],
    contact_patterns: [
      /contact:?\s*([^\n]+)/i,
      /call:?\s*([^\n]+)/i,
      /whatsapp:?\s*([^\n]+)/i,
      /telegram:?\s*@?([^\n\s]+)/i
    ],
    location_patterns: [
      /location:?\s*([^\n,]+)/i,
      /based in:?\s*([^\n,]+)/i,
      /from:?\s*([^\n,]+)/i
    ]
  },

  // Persian channels - bonakdarjavan (canned food)
  '@bonakdarjavan': {
    product_patterns: [
      /^([^\n]+?)(?=\n|\s*(?:✅)?(?:قیمت|تعداد|باکس|کارتن))/gim,
    ],
    price_patterns: [
      /(?:قیمت\s+هر\s+(?:یک\s+)?باکس|قیمت\s+هر\s+کارتن)\s*[:\s]*([\d,\/\u06F0-\u06F9]+)/gi,
      /قیمت\s+مصرف(?:\s+کننده)?\s*[:\s]*([\d,\/\u06F0-\u06F9]+)/gi,
      /دونه\s+ای\s*[:\s]*([\d,\/\u06F0-\u06F9]+)/gi,
      /([\d,\/\u06F0-\u06F9]+)\s*\u062a\u0648\u0645\u0627\u0646/gi
    ],
    packaging_patterns: [
      /(?:تعداد\s+در\s+باکس|باکس)\s*[:\s]*(\d+)\s*عددی/i,
      /(\d+)\s*عددی/gi,
      /(\d+)\s*عددی/i
    ],
    contact_patterns: [/@(\w+)/gi],
    location_patterns: []
  },

  // Persian channels - top_shop_rahimi (energy drinks)
  '@top_shop_rahimi': {
    product_patterns: [
      /^([^\n]+?)(?=\n|\s*(?:✅)?(?:قیمت|تعداد|باکس|کارتن))/gim,
    ],
    price_patterns: [
      /(?:قیمت\s+هر\s+(?:یک\s+)?باکس|قیمت\s+هر\s+کارتن)\s*[:\s]*([\d,\/\u06F0-\u06F9]+)/gi,
      /قیمت\s+مصرف(?:\s+کننده)?\s*[:\s]*([\d,\/\u06F0-\u06F9]+)/gi,
      /دونه\s+ای\s*[:\s]*([\d,\/\u06F0-\u06F9]+)/gi,
      /([\d,\/\u06F0-\u06F9]+)\s*\u062a\u0648\u0645\u0627\u0646/gi
    ],
    packaging_patterns: [
      /(?:تعداد\s+در\s+باکس|باکس)\s*[:\s]*(\d+)\s*عددی/i,
      /(\d+)\s*عددی/i,
      /(\d+)\s*عددی/gi
    ],
    contact_patterns: [/@(\w+)/gi],
    location_patterns: []
  },

  // Persian channels - nobelshop118 (coffee/cappuccino)
  '@nobelshop118': {
    product_patterns: [
      /([^\n:]+)\n\s*:\s*([\d\/\u06F0-\u06F9,]+)/gi,
      /:\s*(\d+\/\d+)\s*\n+\s*:\s*(\d+\/\d+)/gi,  // Two prices: : 75/000\n: 64/500
      /:\s*(\d+\/\d+)/gi,                          // Single price: : 315/000
    ],
    contact_patterns: [],
    location_patterns: [/📍\s*([^\n]+)/i]
  }
};

/**
 * Extracts products based on channel-specific patterns or universal logic.
 */
function extractProducts(content, channelUsername) {
  const products = [];
  const patterns = CHANNEL_PATTERNS[channelUsername] || CHANNEL_PATTERNS['default'];
  
  if (channelUsername === '@bonakdarjavan' || channelUsername === '@top_shop_rahimi') {
    // Handle multiple products in one message for these channels
    const segments = content.split(/\n\s*\n/);
    segments.forEach(segment => {
      if (segment.trim().length < 5) return;
      
      const normalizedSegment = NormalizationEngine.normalize(segment);
      const lines = segment.trim().split('\n').map(l => l.trim()).filter(l => l);
      if (lines.length === 0) return;

      const product = {
        name: cleanProductName(lines[0]),
        price: 0,
        consumer_price: 0,
        packaging: '',
        confidence: 0.7,
        raw_name: lines[0],
        description: segment,
        stock_status: /(?:تمام|ناموجود|❌)/i.test(segment) ? 'Out of Stock' : 'Available',
        currency: 'IRT'
      };

      if (patterns.price_patterns) {
        patterns.price_patterns.forEach(p => {
          let match;
          const regex = new RegExp(p.source, p.flags.includes('g') ? p.flags : p.flags + 'g');
          while ((match = regex.exec(normalizedSegment)) !== null) {
            const val = NormalizationEngine.parsePrice(match[1]);
            if (p.source.includes('باکس') || p.source.includes('کارتن') || p.source.includes('تومان')) {
              if (product.price === 0) product.price = val;
            }
            else if (p.source.includes('مصرف')) product.consumer_price = val;
          }
        });
      }

      if (patterns.packaging_patterns) {
        patterns.packaging_patterns.forEach(p => {
          let match;
          const regex = new RegExp(p.source, p.flags.includes('g') ? p.flags : p.flags + 'g');
          while ((match = regex.exec(normalizedSegment)) !== null) {
            product.packaging = match[1] + ' عددی';
          }
        });
      }

      // Fallback for simple "Price: Value" if no specific box price found
      if (product.price === 0) {
        const fallbackMatch = normalizedSegment.match(/(?:قیمت|فی)\s*:\s*([\d,\/\u06F0-\u06F9]+)/i);
        if (fallbackMatch) product.price = NormalizationEngine.parsePrice(fallbackMatch[1]);
      }

      if (product.price > 0 || product.name.length > 0) {
        products.push(product);
      }
    });
    return products;
  } else if (channelUsername === '@nobelshop118') {
    const segments = content.split(/\n\s*\n/);
    segments.forEach(segment => {
      const normalizedSegment = NormalizationEngine.normalize(segment);
      const match = normalizedSegment.match(/([^\n:]+)\s*:\s*([\d\/\u06F0-\u06F9,]+)/);
      if (match) {
        products.push({
          name: cleanProductName(match[1].trim()),
          price: NormalizationEngine.parsePrice(match[2]),
          confidence: 0.9,
          raw_name: match[1].trim(),
          description: segment,
          stock_status: 'Available',
          currency: 'IRT',
          packaging: ''
        });
      }
    });
    return products;
  }

  // Fallback to universal extractor for other channels
  return extractUniversalProducts(content, channelUsername);
}

/**
 * A robust, line-by-line extractor that works for list-style and block-style messages.
 */
function extractUniversalProducts(content, channelUsername) {
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
    const isPriceLine = priceRegex.test(line) && /\d/.test(line);
    
    // DECISION: Is this a new product or details for the current one?
    // It's a NEW product if:
    // 1. It doesn't look like a price line AND
    // 2. It's not a small detail (like "24 count") AND
    // 3. We either have no product yet, or the previous line was a price (end of previous block)
    const isNewProductStart = !isPriceLine && 
                              line.length > 3 && 
                              !line.match(/^(?:تعداد|باکس|کارتن|لینک|آدرس|شعبه)/) &&
                              (!currentProduct || (currentProduct.price > 0));

    if (isNewProductStart) {
      // Save previous product if valid
      if (currentProduct && (currentProduct.price > 0 || currentProduct.stock_status === 'Out of Stock')) {
        products.push(finalizeProduct(currentProduct, channelUsername));
      }

      // Start new product
      currentProduct = {
        raw_name: line,
        name: cleanProductName(line),
        price: 0,
        consumer_price: 0,
        packaging: extractPackaging(line),
        volume: extractVolume(line),
        stock_status: oosRegex.test(line) ? 'Out of Stock' : 'Available',
        description: line
      };
    } else if (currentProduct) {
      // We are inside a product block, parse details
      currentProduct.description += '\n' + line;
      
      // Update Stock Status
      if (oosRegex.test(line)) currentProduct.stock_status = 'Out of Stock';

      // Update Packaging/Volume if found in detail lines
      if (!currentProduct.packaging) currentProduct.packaging = extractPackaging(line);
      if (!currentProduct.volume) currentProduct.volume = extractVolume(line);

      // Extract Prices
      if (isPriceLine) {
        const prices = extractPricesFromLine(line);
        if (prices.sale > 0) currentProduct.price = prices.sale;
        if (prices.consumer > 0) currentProduct.consumer_price = prices.consumer;
        
        // Fallback: If we found a price but didn't know if it was sale/consumer
        // usually the smaller number is 'Our Price' and larger is 'Consumer'
        if (currentProduct.price > 0 && currentProduct.consumer_price > 0) {
           if (currentProduct.price > currentProduct.consumer_price) {
             // Swap if sale price is accidentally higher than consumer price
             const temp = currentProduct.price;
             currentProduct.price = currentProduct.consumer_price;
             currentProduct.consumer_price = temp;
           }
        }
      }
    }
  }

  // Push the very last product found
  if (currentProduct && (currentProduct.price > 0 || currentProduct.stock_status === 'Out of Stock')) {
    products.push(finalizeProduct(currentProduct, channelUsername));
  }

  return products;
}

function extractPricesFromLine(line) {
  // Logic to distinguish between "Our Price" and "Consumer Price" on a single line
  const result = { sale: 0, consumer: 0 };
  
  // Normalize: 125/000 -> 125000
  const cleanLine = line.replace(/(\d+)\/(\d+)/g, '$1$2').replace(/,/g, '');
  const numbers = cleanLine.match(/\d+/g);
  
  if (!numbers) return result;
  
  const vals = numbers.map(n => parseInt(n)).filter(n => n > 1000); // Filter out small numbers like counts
  
  if (line.match(/(?:مصرف|روی جلد)/)) {
    result.consumer = vals[0] || 0;
  } else if (line.match(/(?:فروش|خرید|ما|همکار)/)) {
    result.sale = vals[0] || 0;
  } else {
    // If ambiguous, assume it's the sale price
    result.sale = vals[0] || 0;
  }
  
  // Special Case: "Consumer: 275000 Our Price: 253000" on same line
  if (vals.length >= 2) {
     result.sale = Math.min(...vals);
     result.consumer = Math.max(...vals);
  }
  
  return result;
}

function cleanProductName(raw) {
  return raw.replace(/✅|❌|🛑|⭕️/g, '')
            .replace(/^(?:نام)?\s*محصول[:\s]*/, '')
            .trim();
}

function finalizeProduct(p, channel) {
  return {
    name: p.name,
    price: p.price,
    consumer_price: p.consumer_price || null,
    currency: 'IRT',
    packaging: p.packaging,
    volume: p.volume,
    stock_status: p.stock_status,
    description: p.description,
    channel_username: channel,
    confidence: 0.8
  };
}

function extractPackaging(text) {
  const match = text.match(/(\d+)\s*(?:عددی|باکس|کارتن)/i);
  return match ? match[0] : '';
}

function extractVolume(text) {
  const match = text.match(/(\d+(?:\.\d+)?)\s*(?:گرم|میلی|لیتر|gr|ml|l)/i);
  return match ? match[0] : '';
}

// Test Suite
const testCases = [
    {
        name: "Bonakdar Javan Canned Fish",
        channel: "@bonakdarjavan",
        content: "کنسرو ماهی ۱۸۰ گرمی تاپ\n✅\nقیمت هر باکس: ۱,۲۵۰,۰۰۰ تومان\nدونه ای: ۵۲,۰۰۰ تومان\nقیمت مصرف: ۶۵,۰۰۰ تومان\nتعداد در باکس: ۲۴ عددی\nموجود ✅",
        expected: {
            name: "کنسرو ماهی ۱۸۰ گرمی تاپ",
            price: 1250000,
            consumer_price: 65000,
            packaging: "24 عددی"
        }
    },
    {
        name: "Top Shop Rahimi Energy Drink",
        channel: "@top_shop_rahimi",
        content: "انرژی زا هایپ اصلی\n✅در باکس ۲۴عددی\n✅قیمت هر باکس: ۱,۲۰۰,۰۰۰ تومان\n✅قیمت مصرف: ۶۵,۰۰۰ تومان",
        expected: {
            name: "انرژی زا هایپ اصلی",
            price: 1200000,
            consumer_price: 65000,
            packaging: "24 عددی"
        }
    },
    {
        name: "Nobel Shop List",
        channel: "@nobelshop118",
        content: "کاپوچینو گوددی ۳۰ تایی\n: ۷۵/۰۰۰\n\nهات چاکلت ۲۰ تایی\n: ۶۵/۰۰۰\n\n📍 میدان محمدیه پلاک ۱۰",
        expectedCount: 2
    },
    {
        name: "Mixed Format - Multiple Products",
        channel: "@bonakdarjavan",
        content: "کنسرو تن ماهی ۱۸۰ گرمی شیلتون\nقیمت: ۱,۱۰۰,۰۰۰ تومان\n\nکنسرو تن ماهی ۱۸۰ گرمی طبیعت\nقیمت: ۱,۲۰۰,۰۰۰ تومان",
        expectedCount: 2
    }
];

testCases.forEach(test => {
    console.log(`\n--- Testing: ${test.name} ---`);
    const results = extractProducts(test.content, test.channel);
    console.log(`Results Found: ${results.length}`);
    console.log(JSON.stringify(results, null, 2));
    
    if (test.expectedCount && results.length !== test.expectedCount) {
        console.error(`FAILED: Expected ${test.expectedCount} products, got ${results.length}`);
    } else if (test.expected) {
        const res = results[0];
        if (res.name !== test.expected.name || res.price !== test.expected.price || res.consumer_price !== test.expected.consumer_price) {
            console.error(`FAILED: Result mismatch for ${test.name}`);
        } else {
            console.log(`PASSED: ${test.name}`);
        }
    }
});
