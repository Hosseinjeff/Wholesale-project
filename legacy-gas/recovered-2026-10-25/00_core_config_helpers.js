// Enhanced Google Apps Script code for Product Catalog Management
// Deploy as Web App to create a webhook endpoint

// Configuration
const CONFIG = {
  SPREADSHEET_ID: '1u5LGXqiEfcPTsopvHOwkh-qvJO5zDK98pVmFhL-xWDs',
  MAX_RETRIES: 3,
  DUPLICATE_CHECK: true, // Re-enabled for production stability
  CONTENT_CHANGE_CHECK: true, // Check for content changes even on duplicate IDs
  // AI Batch Processing
  AI_BATCH_TRIGGER_COUNT: 3, // Trigger batch AI when this many messages are pending
  AI_BATCH_TIMEOUT_MS: 120000, // Max time (ms) to wait before forcing a batch
  AI_BATCH_MAX_TOKENS: 80000 // Approx max tokens for batch prompt
};

const ENDPOINTS = {
  version: { method: 'GET', path: '?action=version', auth: 'public' },
  migrate: { method: 'GET', path: '?action=migrate', auth: 'admin' },
  fix_statuses: { method: 'GET', path: '?action=fix_statuses', auth: 'admin' },
  configure: { method: 'GET', path: '?action=configure', auth: 'admin' },
  debug_properties: { method: 'GET', path: '?action=debug_properties', auth: 'admin' },
  diagnostic: { method: 'GET', path: '?action=diagnostic', auth: 'admin' },
  setup: { method: 'GET', path: '?action=setup', auth: 'admin' },
  debug: { method: 'GET', path: '?action=debug', auth: 'admin' },
  test_extraction: { method: 'GET', path: '?action=test_extraction', auth: 'admin' },
  cleanup_products: { method: 'GET', path: '?action=cleanup_products', auth: 'admin' },
  compact_products_headers: { method: 'GET', path: '?action=compact_products_headers', auth: 'admin' },
  clear_products: { method: 'GET', path: '?action=clear_products', auth: 'admin' },
  pause_ingestion: { method: 'GET', path: '?action=pause_ingestion', auth: 'admin' },
  resume_ingestion: { method: 'GET', path: '?action=resume_ingestion', auth: 'admin' },
  process_pending_messages: { method: 'GET', path: '?action=process_pending_messages', auth: 'admin' },
  process_ai_batch: { method: 'GET', path: '?action=process_ai_batch', auth: 'admin' },
  reset_stuck: { method: 'GET', path: '?action=reset_stuck', auth: 'admin' },
  setup_trigger: { method: 'GET', path: '?action=setup_trigger', auth: 'admin' },
  setup_reference_trigger: { method: 'GET', path: '?action=setup_reference_trigger', auth: 'admin' },
  refresh_reference_prices: { method: 'GET', path: '?action=refresh_reference_prices', auth: 'admin' },
  refresh_reference_prices_pdf_legacy: { method: 'GET', path: '?action=refresh_reference_prices_pdf_legacy', auth: 'admin' },
  count_pending_ai: { method: 'GET', path: '?action=count_pending_ai', auth: 'admin' },
  rebuild_storefront: { method: 'GET', path: '?action=rebuild_storefront', auth: 'admin' },
  merge_channels_to_inventory_log: { method: 'GET', path: '?action=merge_channels_to_inventory_log', auth: 'admin' },
  ingestion_status: { method: 'GET', path: '?action=ingestion_status', auth: 'read' },
  view_logs: { method: 'GET', path: '?action=view_logs', auth: 'read' },
  get_messagedata: { method: 'GET', path: '?action=get_messagedata&limit=50', auth: 'read' },
  get_needs_confirmation: { method: 'GET', path: '?action=get_needs_confirmation&limit=10', auth: 'read' },
  get_products: { method: 'GET', path: '?action=get_products&limit=50', auth: 'read' },
  trace_message: { method: 'GET', path: '?action=trace_message&message_id=12345', auth: 'read' },
  batch_status: { method: 'GET', path: '?action=batch_status', auth: 'read' },
  debug_last_message: { method: 'GET', path: '?action=debug_last_message', auth: 'read' },
  debug_all_messages: { method: 'GET', path: '?action=debug_all_messages&limit=20', auth: 'read' },
  get_execution_logs: { method: 'GET', path: '?action=get_execution_logs', auth: 'read' },
  get_sheet_content: { method: 'GET', path: '?action=get_sheet_content&sheet_name=MessageData', auth: 'read' },
  get_sheet_data: { method: 'GET', path: '?action=get_sheet_data&sheet_name=MessageData', auth: 'read' },
  get_message_data: { method: 'GET', path: '?action=get_message_data', auth: 'read' },
  sheet_mutation: { method: 'POST', path: 'JSON { action: "sheet_mutation", token: "...", operations: [...] }', auth: 'sheet_mutation' },
  ingestion: { method: 'POST', path: 'JSON webhook payload with token', auth: 'ingestion' },
  health: { method: 'GET', path: '/', auth: 'public' }
};

function getAvailableEndpoints() {
  const endpoints = {};
  Object.keys(ENDPOINTS).forEach(function(name) {
    endpoints[name] = ENDPOINTS[name];
  });
  return endpoints;
}

function getRequestToken(e) {
  return e && e.parameter ? (e.parameter.token || e.parameter.admin_token || e.parameter.read_token || '') : '';
}

function validateEndpointAccess(action, e) {
  const endpoint = ENDPOINTS[action];
  if (!endpoint || endpoint.auth === 'public') return null;

  const props = PropertiesService.getScriptProperties();
  const token = getRequestToken(e);
  const adminToken = props.getProperty('ADMIN_ENDPOINT_TOKEN');
  const readToken = props.getProperty('READ_ENDPOINT_TOKEN');

  if (endpoint.auth === 'admin') {
    if (!adminToken) return jsonResponse({ status: 'error', message: 'ADMIN_ENDPOINT_TOKEN script property is not configured' });
    if (token !== adminToken) return jsonResponse({ status: 'error', message: 'Invalid or missing admin token' });
  }

  if (endpoint.auth === 'read') {
    if (!readToken && !adminToken) return jsonResponse({ status: 'error', message: 'READ_ENDPOINT_TOKEN or ADMIN_ENDPOINT_TOKEN script property is not configured' });
    if (token !== readToken && token !== adminToken) return jsonResponse({ status: 'error', message: 'Invalid or missing read token' });
  }

  return null;
}

function validatePostIngestionAccess(data) {
  const token = PropertiesService.getScriptProperties().getProperty('INGESTION_ENDPOINT_TOKEN');
  if (!token) return jsonResponse({ status: 'error', message: 'INGESTION_ENDPOINT_TOKEN script property is not configured' });
  if (!data || data.token !== token) return jsonResponse({ status: 'error', message: 'Invalid or missing ingestion token' });
  return null;
}

// Global trace for diagnostic requests
let CURRENT_TRACE = [];

function logTrace(message, details = {}) {
  const entry = {
    timestamp: new Date().toISOString(),
    message: message,
    details: details
  };
  CURRENT_TRACE.push(entry);
  Logger.log(`[TRACE] ${message} ${JSON.stringify(details)}`);
}

function clearTrace() {
  CURRENT_TRACE = [];
}

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

/**
 * Normalize and standardize a product name from AI extraction.
 * Converts Persian digits, trims, collapses whitespace, removes leading/trailing symbols.
 */
function normalizeProductName(name) {
  if (!name) return '';
  var n = String(name);
  // Persian/Arabic digit conversion (for names with mixed digits like "۱۲۳")
  n = persianToEnglishNumbers(n);
  // Trim whitespace
  n = n.trim();
  // Collapse multiple spaces
  n = n.replace(/\s+/g, ' ');
  // Remove leading symbols: bullets, dashes, emojis
  n = n.replace(/^[✅❌🛑⭕️🚀🔥💎📦✨🌟📣💰🛍️•●▪▶➔➢➤\-–—\s]+/, '');
  // Remove trailing colons, semicolons, dashes
  n = n.replace(/[:;,\-–—]+$/, '');
  // Trim again after removals
  n = n.trim();
  return n;
}

// Channel-specific extraction patterns
const CHANNEL_PATTERNS = {
  // Default patterns for unknown channels
  'default': {
    product_patterns: [
      /(?:^|\n)([^\n$]+?)\s*-\s*\$?(\d+(?:\.\d{2})?)\s*-\s*([^\n$]*)/gi,
      /Product:?\s*([^\n]+?)\s*Price:?\s*\$?(\d+(?:\.\d{2})?)\s*(.+?)(?=\nProduct|$)/gi,
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

  // Example: Wholesale electronics channel
  '@wholesale_electronics': {
    product_patterns: [
      /(?:^|\n)(iPhone|Samsung|MacBook|iPad|AirPods?|iMac|Mac Mini|Apple Watch)\s+(.+?)\s*-\s*\$?(\d+(?:\.\d{2})?)/gi,
      /(\w+\s*\w*)\s*(?:\d+GB|\d+TB|\d+inch)?\s*-\s*\$?(\d+(?:\.\d{2})?)\s*-\s*(.+?)(?=\n\w|\n$|$)/gi
    ],
    contact_patterns: [
      /📞\s*([^\n]+)/,
      /📱\s*([^\n]+)/,
      /DM\s*@?([^\n\s]+)/i
    ],
    location_patterns: [
      /📍\s*([^\n]+)/,
      /Shipping from:?\s*([^\n,]+)/i
    ]
  },

  // Example: Fashion wholesale channel
  '@fashion_wholesale': {
    product_patterns: [
      /(?:^|\n)([^\n$]+?)\s*(?:Size|Color):?\s*([^\n$]+?)\s*-\s*\$?(\d+(?:\.\d{2})?)/gi,
      /([^\n-]+?)\s*\((.+?)\)\s*-\s*\$?(\d+(?:\.\d{2})?)/gi
    ],
    contact_patterns: [
      /Contact\s*@?([^\n\s]+)/i,
      /WhatsApp:?\s*([^\n]+)/i
    ],
    location_patterns: [
      /Made in:?\s*([^\n,]+)/i,
      /Designer:?\s*([^\n,]+)/i
    ]
  },

  // Persian channels - bonakdarjavan (canned food)
  '@bonakdarjavan': {
    product_patterns: [
      /^([^\n]+?)(?=\n|\s*(?:✅|🚀|🔥|💎|📦|✨|🌟|📣|💰|🛍️)?(?:قیمت|تعداد|باکس|کارتن|ورق))/gim,
    ],
    price_patterns: [
      /(?:قیمت|قیمت\s+هر\s+(?:یک\s+)?(?:باکس|کارتن|ورق|شیشه|بسته))(?:\s+\d+\s+عددی)?\s*[:\s]*([\d,.\/\u06F0-\u06F9\u066B]{4,})/gi,
      /قیمت\s+مصرف(?:\s+کننده)?\s*[:\s]*([\d,.\/\u06F0-\u06F9\u066B]{4,})/gi,
      /دونه\s+ای\s*[:\s]*([\d,.\/\u06F0-\u06F9\u066B]{4,})/gi,
      /([\d,.\/\u06F0-\u06F9\u066B]{4,})\s*(?:تومان|تومن|ت)/gi
    ],
    packaging_patterns: [
      /(?:تعداد\s+در\s+(?:باکس|کارتن|ورق)|باکس|کارتن|ورق)\s*[:\s]*(\d+)\s*عددی/i,
      /(\d+)\s*عددی/gi
    ],
    contact_patterns: [/@(\w+)/gi],
    location_patterns: []
  },

  // Persian channels - top_shop_rahimi (energy drinks)
  '@top_shop_rahimi': {
    product_patterns: [
      /^([^\n]+?)(?=\n|\s*(?:✅|🚀|🔥|💎|📦|✨|🌟|📣|💰|🛍️)?(?:قیمت|تعداد|باکس|کارتن|ورق))/gim,
    ],
    price_patterns: [
      /(?:قیمت|قیمت\s+هر\s+(?:یک\s+)?(?:باکس|کارتن|ورق|شیشه|بسته))(?:\s+\d+\s+عددی)?\s*[:\s]*([\d,.\/\u06F0-\u06F9\u066B]{4,})/gi,
      /قیمت\s+مصرف(?:\s+کننده)?\s*[:\s]*([\d,.\/\u06F0-\u06F9\u066B]{4,})/gi,
      /دونه\s+ای\s*[:\s]*([\d,.\/\u06F0-\u06F9\u066B]{4,})/gi,
      /([\d,.\/\u06F0-\u06F9\u066B]{4,})\s*(?:تومان|تومن|ت)/gi
    ],
    packaging_patterns: [
      /(?:تعداد\s+در\s+(?:باکس|کارتن|ورق)|باکس|کارتن|ورق)\s*[:\s]*(\d+)\s*عددی/i,
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

// Channel offer data column headers
const CHANNEL_HEADERS = [
  'Product ID',
  'Product Name',
  'Variation Type',
  'Brand',
  'Category',
  'Description',
  'Sale Price',
  'Consumer Price',
  'Unit Price',
  'Package Price',
  'Packaging',
  'Volume',
  'Weight',
  'Stock Status',
  'Original Message',
  'Channel Username',
  'Message ID (Platform)',
  'Message Link',
  'Import Timestamp',
  'Extraction Confidence Score',
  'Extraction Status',
  'Detected Price',
  'Batch ID'
];

const INVENTORY_LOG_HEADERS = [
  'OK?',
  'Product Name',
  'Import Timestamp',
  'Original Post Date',
  'Product ID',
  'Action',
  'Status',
  'Owner',
  'Exp. span',
  'prod. Date',
  'Count',
  'unit',
  'Variation Type',
  'Brand',
  'Category',
  'Packaging',
  'هزینه (تومان)',
  'Exp. date',
  'Exp. Warning',
  'Sale Price',
  'Sale Unit Price',
  'Regular Package Price',
  'Regular Unit Price',
  'Source Sheet',
  'Channel Username',
  'Message ID (Platform)',
  'Message Link',
  'Original Message',
  'Volume',
  'Weight',
  'Extraction Confidence Score',
  'Extraction Status',
  'Anchor Type',
  'Anchor ID',
  'Anchor Name',
  'Anchor Score',
  'Detected Price',
  'Batch ID'
];

const REFERENCE_PRICE_HEADERS = [
  'Product Name',
  'Clean Product Name',
  'Normalized Key',
  'Reference Package Price',
  'Reference Unit Price',
  'Consumer Unit Price',
  'Package Count',
  'Unit Label',
  'Volume',
  'Weight',
  'Set Count',
  'Variant',
  'Brand',
  'Category',
  'Raw Name Details',
  'PDF Row Number',
  'Product Code',
  'Currency',
  'Source',
  'Source URL',
  'Last Updated'
];

const BATCH_STATUS_HEADERS = [
  'Timestamp',
  'Batch ID',
  'Expected Messages',
  'Written Messages',
  'Processed Messages',
  'Extraction Status',
  'Rollback'
];

// Function to get column index by header name (flexible column positioning)
function getColumnIndexByHeader(sheet, headerName) {
  try {
    const headers = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0];
    for (let i = 0; i < headers.length; i++) {
      if (headers[i] === headerName) {
        return i + 1; // Convert to 1-indexed
      }
    }
    Logger.log(`Header "${headerName}" not found in sheet`);
    return -1; // Not found
  } catch (error) {
    Logger.log(`Error finding header "${headerName}": ${error}`);
    return -1;
  }
}

// Message data column headers (for reference)
const MESSAGE_HEADERS = [
  'ID',
  'Channel',
  'Channel Username',
  'Author',
  'Content',
  'Timestamp',
  'Edit Date',
  'URL',
  'Forwarded By',
  'Forwarded At',
  'Chat ID',
  'Chat Type',
  'Chat Title',
  'Sender ID',
  'Sender Is Bot',
  'Sender Language',
  'Reply To Message ID',
  'Is Topic Message',
  'Message Thread ID',
  'Origin Type',
  'Original Message ID',
  'Original Chat ID',
  'Original Post Date',
  'Original Edit Date',
  'Original Channel Title',
  'Original Channel Username',
  'Original Author Signature',
  'Original Sender Name',
  'Has Media',
  'Media Type',
  'Media File ID',
  'Media Unique ID',
  'Caption',
  'Sender First Name',
  'Sender Last Name',
  'Sender Username',
  'Via Bot',
  'Is Automatic Forward',
  'Has Protected Content',
  'Media Group ID',
  'Author Signature',
  'Effect ID',
  'Show Caption Above Media',
  'Import Timestamp',
  'Status',
  'Batch ID',
  'Raw Metadata'
];

// Test function for GET requests
function debugProperties() {
  const props = PropertiesService.getScriptProperties().getProperties();
  const keys = Object.keys(props);
  const result = {};
  keys.forEach(key => {
    if (key.includes('KEY') || key.includes('TOKEN') || key.includes('URL')) {
      result[key] = props[key] ? (props[key].substring(0, 5) + '...' + props[key].substring(props[key].length - 5)) : 'EMPTY';
    } else {
      result[key] = props[key];
    }
  });
  return result;
}


function jsonResponse(payload) {
  return ContentService
    .createTextOutput(JSON.stringify(payload))
    .setMimeType(ContentService.MimeType.JSON);
}

// #region debug-point messagedata-processing-stuck:reporter
function debugReportMessagedataProcessingStuck(hypothesisId, location, message, data) {
  try {
    const url = PropertiesService.getScriptProperties().getProperty('DEBUG_MESSAGEDATA_STUCK_URL');
    if (!url) return;
    UrlFetchApp.fetch(url, {
      method: 'post',
      contentType: 'application/json',
      payload: JSON.stringify({
        sessionId: 'messagedata-processing-stuck',
        runId: 'pre-fix',
        hypothesisId: hypothesisId,
        location: location,
        msg: '[DEBUG] ' + message,
        data: data || {},
        ts: Date.now()
      }),
      muteHttpExceptions: true
    });
  } catch (e) {}
}
// #endregion
