function doGet(e) {
  // Debug parameter parsing extensively (avoid JSON.stringify on event object)
  Logger.log(`doGet called - checking parameters...`);
  Logger.log(`e.parameter exists: ${!!e.parameter}`);
  if (e.parameter) {
    Logger.log(`e.parameter keys: ${Object.keys(e.parameter)}`);
    Logger.log(`e.parameter.action: ${e.parameter.action}`);
  }
  Logger.log(`e.queryString: ${e.queryString || 'undefined'}`);

  // Try multiple ways to get the action parameter
  let action = null;

  // Method 1: Standard GAS way
  if (e.parameter && e.parameter.action) {
    action = e.parameter.action;
    Logger.log(`Action from e.parameter.action: "${action}"`);
  }

  // Method 2: Check query string parsing
  if (!action && e.queryString) {
    const params = e.queryString.split('&');
    for (const param of params) {
      const [key, value] = param.split('=');
      if (key === 'action') {
        action = decodeURIComponent(value);
        Logger.log(`Action from query string: "${action}"`);
        break;
      }
    }
  }

  Logger.log(`Final parsed action: "${action}"`);

  const accessError = validateEndpointAccess(action, e);
  if (accessError) return accessError;

  // Migrate sheet headers to add any missing columns (e.g. new origin fields)
  if (action === 'migrate') {
    try {
      const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
      const msgSheet = getOrCreateSheet(spreadsheet, 'MessageData', MESSAGE_HEADERS);
      ensureSheetHeaders(msgSheet, MESSAGE_HEADERS);
      const inventorySheet = getOrCreateSheet(spreadsheet, 'Inventory log', INVENTORY_LOG_HEADERS);
      ensureSheetHeaders(inventorySheet, INVENTORY_LOG_HEADERS);
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'success',
          message: 'Sheet headers migrated successfully',
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    } catch (err) {
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'error',
          message: err.toString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    }
  }

  // Fix existing messages: set empty/imported Status to pending_ai
  if (action === 'fix_statuses') {
    try {
      const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
      const sheet = getOrCreateSheet(spreadsheet, 'MessageData', MESSAGE_HEADERS);
      const data = sheet.getDataRange().getValues();
      const headers = data[0];
      const statusIdx = headers.indexOf('Status');
      if (statusIdx < 0) throw new Error('Status column not found');

      let updated = 0;
      for (let i = 1; i < data.length; i++) {
        const status = String(data[i][statusIdx] || '').trim();
        if (status === '' || status === 'imported') {
          sheet.getRange(i + 1, statusIdx + 1).setValue('pending_ai');
          updated++;
        }
      }
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'success',
          updated_rows: updated,
          message: updated + ' rows set to pending_ai',
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    } catch (error) {
      return ContentService
        .createTextOutput(JSON.stringify({ status: 'error', message: error.toString() }))
        .setMimeType(ContentService.MimeType.JSON);
    }
  }

  // Configure AI settings via URL parameters (no changes to ingestion flow)
  if (action === 'configure') {
    const props = PropertiesService.getScriptProperties();
    const apiKey = e.parameter.api_key;
    const provider = e.parameter.provider;
    const model = e.parameter.model;
    const extractionMode = e.parameter.extraction_mode;
    const referenceAlertChatId = e.parameter.reference_alert_chat_id;
    const adminChatId = e.parameter.admin_chat_id;
    const telegramBotToken = e.parameter.telegram_bot_token;
    const baleBotToken = e.parameter.bale_bot_token;
    var configured = [];
    if (apiKey) { props.setProperty('AI_API_KEY', apiKey); configured.push('AI_API_KEY'); }
    if (provider) { props.setProperty('AI_PROVIDER', provider); configured.push('AI_PROVIDER'); }
    if (model) { props.setProperty('AI_MODEL', model); configured.push('AI_MODEL'); }
    if (extractionMode) { props.setProperty('EXTRACTION_MODE', extractionMode); configured.push('EXTRACTION_MODE'); }
    if (referenceAlertChatId) { props.setProperty('REFERENCE_ALERT_CHAT_ID', referenceAlertChatId); configured.push('REFERENCE_ALERT_CHAT_ID'); }
    if (adminChatId) { props.setProperty('ADMIN_CHAT_ID', adminChatId); configured.push('ADMIN_CHAT_ID'); }
    if (telegramBotToken) { props.setProperty('TELEGRAM_BOT_TOKEN', telegramBotToken); configured.push('TELEGRAM_BOT_TOKEN'); }
    if (baleBotToken) { props.setProperty('BALE_BOT_TOKEN', baleBotToken); configured.push('BALE_BOT_TOKEN'); }
    return ContentService
      .createTextOutput(JSON.stringify({
        status: 'success',
        message: 'Properties configured: ' + configured.join(', '),
        note: 'Ingestion flow is unchanged. AI extraction will now use these settings.',
        current_config: {
          AI_PROVIDER: props.getProperty('AI_PROVIDER') || 'gemini',
          AI_MODEL: props.getProperty('AI_MODEL') || 'gemini-3.1-flash-lite',
          EXTRACTION_MODE: props.getProperty('EXTRACTION_MODE') || 'ai',
          AI_API_KEY_SET: !!props.getProperty('AI_API_KEY'),
          REFERENCE_ALERT_CHAT_ID_SET: !!props.getProperty('REFERENCE_ALERT_CHAT_ID'),
          ADMIN_CHAT_ID_SET: !!props.getProperty('ADMIN_CHAT_ID'),
          TELEGRAM_BOT_TOKEN_SET: !!props.getProperty('TELEGRAM_BOT_TOKEN'),
          BALE_BOT_TOKEN_SET: !!props.getProperty('BALE_BOT_TOKEN')
        }
      }))
      .setMimeType(ContentService.MimeType.JSON);
  }

  if (action === 'debug_properties') {
    return ContentService
      .createTextOutput(JSON.stringify({
        status: 'success',
        properties: debugProperties(),
        timestamp: new Date().toISOString()
      }))
      .setMimeType(ContentService.MimeType.JSON);
  }

  if (action === 'diagnostic') {
    Logger.log('Action recognized: diagnostic');
    try {
      const content = e.parameter.content || '';
      const channel = e.parameter.channel || 'default';
      
      clearTrace();
      logTrace('Starting diagnostic extraction', { content: content, channel: channel });
      
      const products = extractProducts(content, channel);
      
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'diagnostic_complete',
          version: 'v2.1.0-fixes-applied',
          results: products,
          trace: CURRENT_TRACE,
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    } catch (error) {
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'error',
          message: error.toString(),
          trace: CURRENT_TRACE,
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    }
  }

  if (action === 'setup') {
    Logger.log('Action recognized: setup');
    return setupSheet();
  }

  if (action === 'debug') {
    Logger.log('Action recognized: debug');
    try {
      const result = debugPersianPatterns();
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'debug_complete',
          message: 'Debug function executed. Check ExecutionLogs sheet for complete results.',
          timestamp: new Date().toISOString(),
          debug_result: result
        }))
        .setMimeType(ContentService.MimeType.JSON);
    } catch (error) {
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'error',
          message: error.toString(),
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    }
  }

  if (action === 'test_extraction') {
    Logger.log('Action recognized: test_extraction');
    try {
      // Multiple test cases
      const testCases = [
        {
          name: "Bonakdar Javan (Multi-product)",
          channel: "@bonakdarjavan",
          content: "کنسرو ماهی ۱۸۰ گرمی تاپ\n✅\nقیمت هر باکس: ۱,۲۵۰,۰۰۰ تومان\nدونه ای: ۵۲,۰۰۰ تومان\nقیمت مصرف: ۶۵,۰۰۰ تومان\nتعداد در باکس: ۲۴ عددی\nموجود ✅"
        },
        {
          name: "Top Shop Rahimi (Energy Drink)",
          channel: "@top_shop_rahimi",
          content: "انرژی زا هایپ اصلی\n✅در باکس ۲۴عددی\n✅قیمت هر باکس: ۱,۲۰۰,۰۰۰ تومان\n✅قیمت مصرف: ۶۵,۰۰۰ تومان"
        },
        {
          name: "Nobel Shop (List Format)",
          channel: "@nobelshop118",
          content: "کاپوچینو گوددی ۳۰ تایی\n: ۷۵/۰۰۰\n\nهات چاکلت ۲۰ تایی\n: ۶۵/۰۰۰"
        }
      ];

      const results = testCases.map(tc => {
        const products = extractProducts(tc.content, tc.channel);
        return {
          test_name: tc.name,
          channel: tc.channel,
          products_found: products.length,
          products: products.map(p => ({
            name: p.name,
            price: p.price,
            consumer_price: p.consumer_price,
            packaging: p.packaging
          }))
        };
      });

      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'extraction_test_complete',
          results: results,
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    } catch (error) {
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'error',
          message: error.toString(),
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    }
  }

  if (action === 'view_logs') {
    Logger.log('Action recognized: view_logs');
    try {
      const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
      const logSheet = spreadsheet.getSheetByName('ExecutionLogs');

      if (!logSheet) {
        return ContentService
          .createTextOutput(JSON.stringify({
            status: 'error',
            message: 'ExecutionLogs sheet not found. Run debug first.',
            timestamp: new Date().toISOString()
          }))
          .setMimeType(ContentService.MimeType.JSON);
      }

      const data = logSheet.getDataRange().getValues();
      const logs = data.slice(-20).map(row => ({ // Last 20 entries
        timestamp: row[0],
        function: row[1],
        level: row[2],
        channel: row[3],
        content_length: row[4],
        message: row[5],
        products_found: row[6],
        details: row[7]
      }));

      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'logs_retrieved',
          message: `Retrieved ${logs.length} recent log entries`,
          logs: logs,
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    } catch (error) {
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'error',
          message: error.toString(),
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    }
  }

  if (action === 'cleanup_products') {
    try {
      return cleanupProductsSheet();
    } catch (error) {
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'error',
          message: error.toString(),
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    }
  }

  if (action === 'compact_products_headers') {
    try {
      return compactProductsHeaders(e.parameter || {});
    } catch (error) {
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'error',
          message: error.toString(),
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    }
  }

  if (action === 'clear_products') {
    try {
      return clearProductsSheet();
    } catch (error) {
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'error',
          message: error.toString(),
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    }
  }

  if (action === 'pause_ingestion') {
    return setIngestionEnabled(false);
  }
  if (action === 'resume_ingestion') {
    return setIngestionEnabled(true);
  }
  if (action === 'ingestion_status') {
    const props = PropertiesService.getScriptProperties();
    let lastBatch = null;
    try {
      const raw = props.getProperty('LAST_BATCH_STATUS');
      if (raw) {
        lastBatch = JSON.parse(raw);
      }
    } catch (e2) {
      lastBatch = null;
    }
    return ContentService
      .createTextOutput(JSON.stringify({
        status: 'success',
        ingestion_enabled: getIngestionEnabled(),
        timestamp: new Date().toISOString(),
        last_batch_status: lastBatch
      }))
      .setMimeType(ContentService.MimeType.JSON);
  }

  if (action === 'get_needs_confirmation') {
    try {
      const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
      const sheet = getOrCreateSheet(spreadsheet, 'MessageData', MESSAGE_HEADERS);
      ensureSheetHeaders(sheet, MESSAGE_HEADERS);
      const values = sheet.getDataRange().getValues();
      const headers = values[0] || [];
      const statusIdx = headers.indexOf('Status');
      const limit = Math.max(1, Math.min(25, parseInt(e.parameter.limit || '10', 10)));
      const rows = [];
      if (statusIdx >= 0) {
        for (let i = values.length - 1; i >= 1 && rows.length < limit; i--) {
          if (String(values[i][statusIdx] || '') !== 'needs_confirmation') continue;
          const row = {};
          for (let j = 0; j < headers.length; j++) row[headers[j]] = values[i][j];
          try {
            const metadata = row['Raw Metadata'] ? JSON.parse(row['Raw Metadata']) : {};
            row.confirmation_requests = metadata.confirmation_requests || [];
            row.confirmation_saved_at = metadata.confirmation_saved_at || '';
          } catch (metadataError) {
            row.confirmation_requests = [];
            row.confirmation_saved_at = '';
          }
          rows.push(row);
        }
      }
      return jsonResponse({
        status: 'success',
        sheet: 'MessageData',
        returned_rows: rows.length,
        rows: rows,
        timestamp: new Date().toISOString()
      });
    } catch (error) {
      return jsonResponse({ status: 'error', message: error.toString(), timestamp: new Date().toISOString() });
    }
  }

  // Read MessageData sheet as JSON
  if (action === 'get_messagedata') {
    try {
      const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
      const sheet = getOrCreateSheet(spreadsheet, 'MessageData', MESSAGE_HEADERS);
      const values = sheet.getDataRange().getValues();
      const headers = values[0] || [];
      const maxRows = Math.min(values.length - 1, parseInt(e.parameter.limit || '50'));
      const rows = [];
      for (let i = 1; i <= maxRows; i++) {
        const row = {};
        for (let j = 0; j < headers.length; j++) {
          row[headers[j]] = values[i][j];
        }
        rows.push(row);
      }
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'success',
          sheet: 'MessageData',
          total_rows: values.length - 1,
          returned_rows: rows.length,
          rows: rows,
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    } catch (error) {
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'error',
          message: error.toString(),
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    }
  }

  // Read Inventory log as JSON, kept under get_products for compatibility
  if (action === 'get_products') {
    try {
      const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
      const sheet = getOrCreateSheet(spreadsheet, 'Inventory log', INVENTORY_LOG_HEADERS);
      ensureSheetHeaders(sheet, INVENTORY_LOG_HEADERS);
      const values = sheet.getDataRange().getValues();
      const headers = values[0] || [];
      const maxRows = Math.min(values.length - 1, parseInt(e.parameter.limit || '50'));
      const rows = [];
      for (let i = 1; i <= maxRows; i++) {
        const row = {};
        for (let j = 0; j < headers.length; j++) {
          row[headers[j]] = values[i][j];
        }
        rows.push(row);
      }
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'success',
          sheet: 'Inventory log',
          total_rows: values.length - 1,
          returned_rows: rows.length,
          rows: rows,
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    } catch (error) {
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'error',
          message: error.toString(),
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    }
  }

  // Trace a message: given a Message ID, show the MessageData record + all Inventory log supplier offers linked to it
  if (action === 'trace_message') {
    try {
      const messageId = e.parameter.message_id || '';
      if (!messageId) {
        return ContentService
          .createTextOutput(JSON.stringify({ status: 'error', message: 'message_id parameter is required' }))
          .setMimeType(ContentService.MimeType.JSON);
      }

      const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);

      // 1. Find the message in MessageData
      const msgSheet = getOrCreateSheet(spreadsheet, 'MessageData', MESSAGE_HEADERS);
      const msgData = msgSheet.getDataRange().getValues();
      const msgHeaders = msgData[0] || [];
      const idIdx = msgHeaders.indexOf('ID');
      let messageRecord = null;
      let messageRow = null;
      for (let i = 1; i < msgData.length; i++) {
        if (String(msgData[i][idIdx]) === String(messageId)) {
          const row = {};
          for (let j = 0; j < msgHeaders.length; j++) {
            row[msgHeaders[j]] = msgData[i][j];
          }
          messageRecord = row;
          messageRow = i;
          break;
        }
      }

      if (!messageRecord) {
        return ContentService
          .createTextOutput(JSON.stringify({
            status: 'not_found',
            message: 'No message found with ID: ' + messageId,
            timestamp: new Date().toISOString()
          }))
          .setMimeType(ContentService.MimeType.JSON);
      }

      // 2. Find all Inventory log supplier offers linked to this message ID
      const prodSheet = getOrCreateSheet(spreadsheet, 'Inventory log', INVENTORY_LOG_HEADERS);
      ensureSheetHeaders(prodSheet, INVENTORY_LOG_HEADERS);
      const prodData = prodSheet.getDataRange().getValues();
      const prodHeaders = prodData[0] || [];
      const msgIdIdx = prodHeaders.indexOf('Message ID (Platform)');
      const relatedProducts = [];
      for (let i = 1; i < prodData.length; i++) {
        if (String(prodData[i][msgIdIdx]) === String(messageId)) {
          const row = {};
          for (let j = 0; j < prodHeaders.length; j++) {
            row[prodHeaders[j]] = prodData[i][j];
          }
          relatedProducts.push(row);
        }
      }

      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'success',
          message_id: messageId,
          message_record: messageRecord,
          product_count: relatedProducts.length,
          products: relatedProducts,
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    } catch (error) {
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'error',
          message: error.toString(),
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    }
  }

  // Always include debug info in response
  const debugInfo = {
    received_parameters: e,
    parsed_action: action,
    parameter_keys: e.parameter ? Object.keys(e.parameter) : [],
    query_string: e.queryString || 'none',
    all_parameters: e.parameter || {},
    action_recognized: action ? 'YES' : 'NO'
  };

  // Version check endpoint
  if (action === 'version') {
    return ContentService
      .createTextOutput(JSON.stringify({
        version: '4.0',
        deployment_id: 'PERSIAN_PATTERN_REFINEMENT_4_0',
        timestamp: new Date().toISOString(),
        status: 'version_check_passed'
      }))
      .setMimeType(ContentService.MimeType.JSON);
  }

  if (action === 'batch_status') {
    try {
      const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
      const sheet = getOrCreateSheet(spreadsheet, 'BatchStatus', BATCH_STATUS_HEADERS);
      const values = sheet.getDataRange().getValues();
      const headers = values[0] || [];
      const rows = [];
      for (let i = 1; i < values.length; i++) {
        const row = {};
        for (let j = 0; j < headers.length; j++) {
          row[headers[j]] = values[i][j];
        }
        rows.push(row);
      }
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'success',
          batches: rows.slice(-50),
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    } catch (error) {
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'error',
          message: error.toString(),
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    }
  }

  if (action === 'debug_last_message') {
    try {
      const result = debugLastMessage();
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'success',
          result: result,
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    } catch (error) {
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'error',
          message: error.toString(),
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    }
  }

  if (action === 'debug_all_messages') {
    try {
      var limit = 20;
      if (e.parameter && e.parameter.limit) {
        var parsed = parseInt(e.parameter.limit, 10);
        if (!isNaN(parsed) && parsed > 0 && parsed <= 500) {
          limit = parsed;
        }
      }
      const result = debugAllMessages(limit);
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'success',
          limit: limit,
          messages: result,
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    } catch (error) {
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'error',
          message: error.toString(),
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    }
  }

  if (action === 'get_execution_logs') {
    Logger.log('Action recognized: get_execution_logs');
    try {
      const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
      const logSheet = spreadsheet.getSheetByName('ExecutionLogs');

      if (!logSheet) {
        return ContentService
          .createTextOutput(JSON.stringify({
            status: 'error',
            message: 'ExecutionLogs sheet not found'
          }))
          .setMimeType(ContentService.MimeType.JSON);
      }

      const data = logSheet.getDataRange().getValues();

      // Convert to array of objects (skip header row)
      const logs = [];
      for (let i = 1; i < data.length; i++) {
        const row = data[i];
        if (row[0]) { // Only if timestamp exists
          logs.push({
            Timestamp: row[0],
            Function: row[1] || '',
            Level: row[2] || '',
            Channel: row[3] || '',
            ContentLength: row[4] || 0,
            Message: row[5] || '',
            ProductsFound: row[6] || 0,
            Details: row[7] || ''
          });
        }
      }

      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'success',
          message: `Retrieved ${logs.length} execution log entries`,
          timestamp: new Date().toISOString(),
          data: logs.slice(-50) // Last 50 entries to avoid size limits
        }))
        .setMimeType(ContentService.MimeType.JSON);

    } catch (error) {
      Logger.log(`get_execution_logs error: ${error}`);
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'error',
          message: `Failed to retrieve execution logs: ${error}`,
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    }
  }

  if (action === 'get_sheet_content') {
    Logger.log('Action recognized: get_sheet_content');
    try {
      const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
      let sheet;
      
      if (e.parameter.sheet_name) {
        sheet = spreadsheet.getSheetByName(e.parameter.sheet_name);
      } else if (e.parameter.gid) {
        const gid = parseInt(e.parameter.gid, 10);
        const sheets = spreadsheet.getSheets();
        for (const s of sheets) {
          if (s.getSheetId() === gid) {
            sheet = s;
            break;
          }
        }
      }

      if (!sheet) {
        // Fallback: list all available sheets
        const allSheets = spreadsheet.getSheets().map(s => ({
          name: s.getName(),
          id: s.getSheetId()
        }));
        
        return ContentService
          .createTextOutput(JSON.stringify({
            status: 'error',
            message: 'Sheet not found',
            available_sheets: allSheets
          }))
          .setMimeType(ContentService.MimeType.JSON);
      }

      const data = sheet.getDataRange().getValues();
      const headers = data[0] || [];
      const rows = [];
      const limit = e.parameter.limit ? parseInt(e.parameter.limit, 10) : 100;
      
      // Convert to array of objects if headers exist
      if (headers.length > 0) {
        for (let i = 1; i < data.length; i++) {
          const row = {};
          for (let j = 0; j < headers.length; j++) {
            row[headers[j]] = data[i][j];
          }
          rows.push(row);
        }
      }

      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'success',
          sheet_name: sheet.getName(),
          sheet_id: sheet.getSheetId(),
          total_rows: rows.length,
          data: rows.slice(-limit) // Return last N rows
        }))
        .setMimeType(ContentService.MimeType.JSON);

    } catch (error) {
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'error',
          message: error.toString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    }
  }

  if (action === 'get_sheet_data') {
    Logger.log('Action recognized: get_sheet_data');
    try {
      const sheetName = e.parameter.sheet_name || 'MessageData';
      const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
      const sheet = spreadsheet.getSheetByName(sheetName);

      if (!sheet) {
        return ContentService
          .createTextOutput(JSON.stringify({
            status: 'error',
            message: `Sheet "${sheetName}" not found`
          }))
          .setMimeType(ContentService.MimeType.JSON);
      }

      const data = sheet.getDataRange().getValues();
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'success',
          message: `Retrieved ${data.length} rows from ${sheetName}`,
          timestamp: new Date().toISOString(),
          data: data
        }))
        .setMimeType(ContentService.MimeType.JSON);

    } catch (error) {
      Logger.log(`get_sheet_data error: ${error}`);
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'error',
          message: `Failed to retrieve sheet data: ${error}`,
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    }
  }

  if (action === 'get_products') {
    Logger.log('Action recognized: get_products');
    try {
      const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
      const productsSheet = getOrCreateSheet(spreadsheet, 'Inventory log', INVENTORY_LOG_HEADERS);
      ensureSheetHeaders(productsSheet, INVENTORY_LOG_HEADERS);

      if (!productsSheet) {
        return ContentService
          .createTextOutput(JSON.stringify({
            status: 'error',
            message: 'Inventory log sheet not found'
          }))
          .setMimeType(ContentService.MimeType.JSON);
      }

      const data = productsSheet.getDataRange().getValues();
      const headers = data[0];
      
      // Map header names to indices
      const headerMap = {};
      headers.forEach((header, index) => {
        headerMap[header] = index;
      });

      // Convert to array of objects (skip header row)
      const products = [];
      for (let i = 1; i < data.length; i++) {
        const row = data[i];
        if (row[headerMap['Product Name']] || row[headerMap['Product ID']]) { 
          products.push({
            id: row[headerMap['Product ID']] || '',
            name: row[headerMap['Product Name']] || '',
            price: row[headerMap['Price']] || 0,
            currency: row[headerMap['Currency']] || '',
            consumer_price: row[headerMap['Consumer Price']] || 0,
            packaging: row[headerMap['Packaging']] || '',
            volume: row[headerMap['Volume']] || '',
            channel: row[headerMap['Channel Username']] || '',
            timestamp: row[headerMap['Message Timestamp']] || '',
            last_updated: row[headerMap['Last Updated']] || '',
            status: row[headerMap['Status']] || ''
          });
        }
      }

      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'success',
          message: `Retrieved ${products.length} products`,
          timestamp: new Date().toISOString(),
          data: products.slice(-100) // Last 100 products
        }))
        .setMimeType(ContentService.MimeType.JSON);

    } catch (error) {
      Logger.log(`get_products error: ${error}`);
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'error',
          message: `Failed to retrieve products: ${error}`,
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    }
  }

  if (action === 'get_message_data') {
    Logger.log('Action recognized: get_message_data');
    try {
      const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
      const messageSheet = spreadsheet.getSheetByName('MessageData');

      if (!messageSheet) {
        return ContentService
          .createTextOutput(JSON.stringify({
            status: 'error',
            message: 'MessageData sheet not found'
          }))
          .setMimeType(ContentService.MimeType.JSON);
      }

      const data = messageSheet.getDataRange().getValues();
      const headers = data[0];
      
      // Map header names to indices
      const headerMap = {};
      headers.forEach((header, index) => {
        headerMap[header] = index;
      });

      // Convert to array of objects (skip header row)
      const messages = [];
      for (let i = 1; i < data.length; i++) {
        const row = data[i];
        if (row[headerMap['ID']]) { 
          messages.push({
            id: row[headerMap['ID']] || '',
            channel: row[headerMap['Channel Username']] || '',
            content: row[headerMap['Content']] || '',
            timestamp: row[headerMap['Timestamp']] || '',
            status: row[headerMap['Status']] || '',
            import_timestamp: row[headerMap['Import Timestamp']] || ''
          });
        }
      }

      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'success',
          message: `Retrieved ${messages.length} message records`,
          timestamp: new Date().toISOString(),
          data: messages.slice(-100) // Last 100 messages
        }))
        .setMimeType(ContentService.MimeType.JSON);

    } catch (error) {
      Logger.log(`get_message_data error: ${error}`);
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'error',
          message: `Failed to retrieve message data: ${error}`,
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    }
  }

  if (action === 'process_pending_messages') {
    var reset = e.parameter.reset === 'true';
    return processPendingMessages(reset);
  }

  if (action === 'process_ai_batch') {
    Logger.log('Action recognized: process_ai_batch');
    try {
      const result = processAIBatch();
      return ContentService
        .createTextOutput(JSON.stringify(result))
        .setMimeType(ContentService.MimeType.JSON);
    } catch (error) {
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'error',
          message: error.toString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    }
  }

  if (action === 'reset_stuck') {
    try {
      const sheet = getOrCreateSheet(SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID), 'MessageData', MESSAGE_HEADERS);
      const data = sheet.getDataRange().getValues();
      const headers = data[0];
      const statusIdx = headers.indexOf('Status');
      let resetCount = 0;
      for (let i = 1; i < data.length; i++) {
        const status = statusIdx >= 0 ? String(data[i][statusIdx] || '') : '';
        if (status === 'processing' || status === 'stuck') {
          sheet.getRange(i + 1, statusIdx + 1).setValue('pending_ai');
          resetCount++;
        }
      }
      return ContentService
        .createTextOutput(JSON.stringify({ status: 'success', reset_count: resetCount, message: 'Reset ' + resetCount + ' stuck messages to pending_ai' }))
        .setMimeType(ContentService.MimeType.JSON);
    } catch (error) {
      return ContentService
        .createTextOutput(JSON.stringify({ status: 'error', message: error.toString() }))
        .setMimeType(ContentService.MimeType.JSON);
    }
  }

  if (action === 'setup_trigger') {
    try {
      // Remove existing triggers to avoid duplicates
      var triggers = ScriptApp.getProjectTriggers();
      for (var t = 0; t < triggers.length; t++) {
        if (triggers[t].getHandlerFunction() === 'processPendingTrigger') {
          ScriptApp.deleteTrigger(triggers[t]);
        }
      }
      // Create a new time-based trigger every 5 minutes
      ScriptApp.newTrigger('processPendingTrigger')
        .timeBased()
        .everyMinutes(5)
        .create();
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'success',
          message: 'Trigger created: processPendingTrigger will run every 5 minutes',
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    } catch (error) {
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'error',
          message: error.toString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    }
  }

  if (action === 'refresh_reference_prices') {
    try {
      return refreshReferencePrices();
    } catch (error) {
      return jsonResponse({
        status: 'error',
        message: error.toString(),
        timestamp: new Date().toISOString()
      });
    }
  }

  if (action === 'refresh_reference_prices_pdf_legacy') {
    try {
      return refreshReferencePricesFromPdfLegacy();
    } catch (error) {
      return jsonResponse({
        status: 'error',
        message: error.toString(),
        timestamp: new Date().toISOString()
      });
    }
  }

  if (action === 'setup_reference_trigger') {
    try {
      return setupReferencePriceTrigger();
    } catch (error) {
      return jsonResponse({
        status: 'error',
        message: error.toString(),
        timestamp: new Date().toISOString()
      });
    }
  }

  if (action === 'count_pending_ai') {
    try {
      const count = countPendingAIMessages();
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'success',
          pending_count: count,
          timestamp: new Date().toISOString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    } catch (error) {
      return ContentService
        .createTextOutput(JSON.stringify({
          status: 'error',
          message: error.toString()
        }))
        .setMimeType(ContentService.MimeType.JSON);
    }
  }

  if (action === 'rebuild_storefront') {
    try {
      return rebuildStorefront();
    } catch (error) {
      return jsonResponse({
        status: 'error',
        message: error.toString(),
        timestamp: new Date().toISOString()
      });
    }
  }

  if (action === 'merge_channels_to_inventory_log') {
    try {
      return mergeChannelsToInventoryLog();
    } catch (error) {
      return jsonResponse({
        status: 'error',
        message: error.toString(),
        timestamp: new Date().toISOString()
      });
    }
  }

  return ContentService
    .createTextOutput(JSON.stringify({
      status: 'success',
      message: `Action "${action || 'none'}" received - Persian Product Extraction System`,
      timestamp: new Date().toISOString(),
      debug_info: debugInfo,
      available_endpoints: getAvailableEndpoints(),
      system_status: action ? `Processing action: ${action}` : 'Persian product extraction ready'
    }))
    .setMimeType(ContentService.MimeType.JSON);
}

function doPost(e) {
  Logger.log(`DO_POST_START`);
  Logger.log(`=== DEPLOYMENT TEST 3.9 - REDEPLOYMENT WORKING ===`);
  
  const lock = LockService.getScriptLock();
  let shouldCheckAIBatch = false;
  let resultResponse;

  try {
    lock.waitLock(5000);
  } catch (e) {
    Logger.log('Could not obtain lock after 5 seconds');
    return ContentService
      .createTextOutput(JSON.stringify({
        status: 'error',
        message: 'Server busy, please try again later (Lock timeout)'
      }))
      .setMimeType(ContentService.MimeType.JSON);
  }

  try {
    const requestData = e.postData && e.postData.contents ? JSON.parse(e.postData.contents) : null;
    if (requestData && requestData.action === 'replace_reference_prices') {
      const replaceResponse = handleReplaceReferencePrices(requestData);
      lock.releaseLock();
      return replaceResponse;
    }
    if (requestData && requestData.action === 'append_reference_prices') {
      const appendResponse = handleAppendReferencePrices(requestData);
      lock.releaseLock();
      return appendResponse;
    }
    if (requestData && requestData.action === 'sheet_mutation') {
      const mutationResponse = handleSheetMutationRequest(requestData);
      lock.releaseLock();
      return mutationResponse;
    }
    if (requestData && requestData.action === 'confirm_price_role') {
      lock.releaseLock();
      try {
        return handlePriceRoleConfirmation(requestData);
      } catch (confirmationError) {
        return jsonResponse({ status: 'error', message: confirmationError.toString(), action: 'confirm_price_role' });
      }
    }
    if (requestData && requestData.action === 'confirm_reference_match') {
      lock.releaseLock();
      try {
        return handleReferenceMatchConfirmation(requestData);
      } catch (referenceError) {
        return jsonResponse({ status: 'error', message: referenceError.toString(), action: 'confirm_reference_match' });
      }
    }
  } catch (preflightError) {
    Logger.log(`Sheet mutation preflight skipped: ${preflightError}`);
  }

  if (!getIngestionEnabled()) {
    lock.releaseLock();
    return ContentService
      .createTextOutput(JSON.stringify({
        status: 'ingestion_disabled',
        message: 'Ingestion is paused',
        timestamp: new Date().toISOString()
      }))
      .setMimeType(ContentService.MimeType.JSON);
  }

  let retryCount = 0;

  try {
    while (retryCount < CONFIG.MAX_RETRIES) {
      try {
        const data = JSON.parse(e.postData.contents);
        Logger.log(`JSON parsed successfully`);

        const ingestionAccessError = validatePostIngestionAccess(data);
        if (ingestionAccessError) {
          resultResponse = ingestionAccessError;
          break;
        }

        if (data && (data.mode === 'batch_ingest' || (data.messages && data.batch_id))) {
          const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
          if (data.transmission_complete) {
            const result = finalizeBatch(spreadsheet, data);
            const aiResult = processAIBatch();
            result.ai_result = aiResult;
            result.confirmation_requests = aiResult.confirmation_requests || [];
            shouldCheckAIBatch = false;
            resultResponse = ContentService.createTextOutput(JSON.stringify(result)).setMimeType(ContentService.MimeType.JSON);
            break;
          } else {
            const result = batchIngestChunk(spreadsheet, data);
            resultResponse = ContentService.createTextOutput(JSON.stringify(result)).setMimeType(ContentService.MimeType.JSON);
            break;
          }
        }

        // Log webhook reception to spreadsheet (ONLY ONCE PER MESSAGE)
        if (retryCount === 0) {
          try {
            const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
            const logSheet = getOrCreateSheet(spreadsheet, 'ExecutionLogs', [
              'Timestamp', 'Function', 'Level', 'Channel', 'ContentLength', 'Message', 'ProductsFound', 'Details'
            ]);

            logSheet.appendRow([
              new Date().toISOString(),
              'doPost',
              'INFO',
              data.channel_username || 'Unknown',
              (data.content || '').length,
              'Webhook received',
              0,
              `ID: ${data.id} - Content preview: ${(data.content || '').substring(0, 50)}`
            ]);
          } catch (logError) {
            Logger.log(`Logging error: ${logError}`);
          }
        }

        // Validate data
        const validation = validateData(data);
        if (!validation.valid) {
          resultResponse = ContentService
            .createTextOutput(JSON.stringify({
              status: 'error',
              message: `Validation failed: ${validation.errors.join(', ')}`,
              received: data
            }))
            .setMimeType(ContentService.MimeType.JSON);
          break; // Exit retry loop
        }

        // Determine processing mode: 'full' (message + products) or 'message_only'
        const classification = MessageClassifier.classify(data.content || '', data.channel_username);
        const processingMode = (data.processing_mode || '').toLowerCase() || 'full';
        const messageStatus = classification.type;

        // For performance, only run duplicate checks in full processing mode
        let duplicateCheck = { isDuplicate: false, contentChanged: false, existingRow: null };
        if (processingMode === 'full') {
          duplicateCheck = checkForDuplicates(data);
          if (CONFIG.DUPLICATE_CHECK && duplicateCheck.isDuplicate && !duplicateCheck.contentChanged) {
            Logger.log(`Duplicate found for message ${data.id}`);
            resultResponse = ContentService
              .createTextOutput(JSON.stringify({
                status: 'duplicate',
                message: 'Message already exists with same content',
                id: data.id
              }))
              .setMimeType(ContentService.MimeType.JSON);
            break; // Exit retry loop
          }
        }

        Logger.log('Processing message');

        const messageResult = importMessageData(data, duplicateCheck.existingRow, messageStatus);
        let productResult = { success: true, products_found: 0, row: null };
        
        // Check if AI batch mode is active
        const rawMode = PropertiesService.getScriptProperties().getProperty('EXTRACTION_MODE') || 'regex';
        const mode = rawMode.trim().toLowerCase();
        const isAiBatchMode = (mode === 'ai' || mode === 'ai_batch');
        
        if (processingMode === 'full' && !isAiBatchMode) {
          // Regular extraction mode: process products immediately
          productResult = importProductData(data);
        } else if (processingMode === 'full' && isAiBatchMode) {
          // AI batch mode: just update message status to pending_ai (products extracted later by batch)
          try {
            const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
            const msgSheet = getOrCreateSheet(spreadsheet, 'MessageData', MESSAGE_HEADERS);
            const msgHeaders = msgSheet.getRange(1, 1, 1, msgSheet.getLastColumn()).getValues()[0];
            const statusCol = msgHeaders.indexOf('Status') + 1;
            if (statusCol > 0 && messageResult.row) {
              msgSheet.getRange(messageResult.row, statusCol).setValue('pending_ai');
            }
            productResult = { success: true, products_found: 0, row: messageResult.row, deferred: true };
            shouldCheckAIBatch = true;
          } catch (batchError) {
            Logger.log(`AI batch trigger error: ${batchError}`);
          }
        }

        if (messageResult.success && productResult.success) {
          const debugInfo = {
            channel: data.channel_username,
            content_length: data.content ? data.content.length : 0,
            products_found: productResult.products_found,
            classification: classification.type,
            confidence: classification.confidence,
            processing_mode: processingMode
          };

          Logger.log(`DO_POST_END - Returning success for message ${data.id}`);
          resultResponse = ContentService
            .createTextOutput(JSON.stringify({
              status: 'success',
              message: 'Data imported successfully',
              message_row: messageResult.row,
              product_row: productResult.row,
              products_found: productResult.products_found,
              id: data.id,
              debug_info: debugInfo
            }))
            .setMimeType(ContentService.MimeType.JSON);
          break; // Success, exit retry loop
        } else {
          if (retryCount < CONFIG.MAX_RETRIES - 1) {
            retryCount++;
            Utilities.sleep(1000 * retryCount);
            continue;
          }

          resultResponse = ContentService
            .createTextOutput(JSON.stringify({
              status: 'error',
              message: `Message: ${messageResult.error || 'OK'}, Product: ${productResult.error || 'OK'}`,
              retry_count: retryCount
            }))
            .setMimeType(ContentService.MimeType.JSON);
          break;
        }

      } catch (error) {
        if (retryCount < CONFIG.MAX_RETRIES - 1) {
          retryCount++;
          Utilities.sleep(1000 * retryCount);
          continue;
        }

        resultResponse = ContentService
          .createTextOutput(JSON.stringify({
            status: 'error',
            message: error.toString(),
            retry_count: retryCount
          }))
          .setMimeType(ContentService.MimeType.JSON);
        break;
      }
    }
  } finally {
    lock.releaseLock();
    Logger.log('Lock released in doPost');
  }

  // Trigger AI batch processing OUTSIDE the lock to avoid deadlock
  if (shouldCheckAIBatch) {
    try {
      const pendingCount = countPendingAIMessages();
      Logger.log('AI batch pending count: ' + pendingCount + ', trigger at: ' + CONFIG.AI_BATCH_TRIGGER_COUNT);
      if (pendingCount >= CONFIG.AI_BATCH_TRIGGER_COUNT) {
        Logger.log('Triggering AI batch processing');
        const aiResult = processAIBatch();
        Logger.log('AI batch result: ' + JSON.stringify(aiResult));
      }
    } catch (aiErr) {
      Logger.log('AI batch trigger error (non-fatal): ' + aiErr);
    }
  }

  return resultResponse || ContentService.createTextOutput(JSON.stringify({status: 'error', message: 'Unknown error'})).setMimeType(ContentService.MimeType.JSON);
}

function handleReferenceMatchConfirmation(data) {
  const ingestionAccessError = validatePostIngestionAccess(data);
  if (ingestionAccessError) return ingestionAccessError;
  const messageId = String(data.message_id || '');
  const requestIndex = Number(data.request_index);
  if (!messageId || !Number.isInteger(requestIndex) || requestIndex < 0 || String(data.choice || '') !== 'keep') return jsonResponse({ status: 'error', message: 'Invalid message_id, request_index, or choice' });

  const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
  const sheet = getOrCreateSheet(spreadsheet, 'MessageData', MESSAGE_HEADERS);
  ensureSheetHeaders(sheet, MESSAGE_HEADERS);
  const values = sheet.getDataRange().getValues();
  const headers = values[0] || [];
  const idIdx = headers.indexOf('ID');
  const rawIdx = headers.indexOf('Raw Metadata');
  if (idIdx < 0 || rawIdx < 0) return jsonResponse({ status: 'error', message: 'MessageData required headers missing' });

  for (let i = values.length - 1; i >= 1; i--) {
    if (String(values[i][idIdx] || '') !== messageId) continue;
    let metadata = {};
    try { metadata = values[i][rawIdx] ? JSON.parse(values[i][rawIdx]) : {}; } catch (e) { metadata = {}; }
    const requests = metadata.confirmation_requests || [];
    let context = null;
    let selectedRequest = null;
    for (let r = 0; r < requests.length; r++) {
      if (Number(requests[r] && requests[r].request_index) !== requestIndex) continue;
      const candidate = requests[r] && requests[r].scale_context;
      if (candidate && candidate.reference_id && candidate.product_match_key) {
        context = candidate;
        selectedRequest = requests[r];
        break;
      }
    }
    if (!context) return jsonResponse({ status: 'error', message: 'No matched reference is saved for this message' });
    saveReferenceMatchOverride(context.product_match_key, context.reference_id);
    metadata.reference_confirmation = {
      choice: 'keep',
      product_match_key: context.product_match_key,
      reference_id: context.reference_id,
      matched_reference_product: context.matched_reference_product || '',
      confirmed_at: new Date().toISOString()
    };
    sheet.getRange(i + 1, rawIdx + 1).setValue(JSON.stringify(metadata));
    const anchorRowsUpdated = applyPriceAnchorToInventoryRows(spreadsheet, messageId, {
      type: 'reference',
      id: context.reference_id,
      name: context.matched_reference_product || (selectedRequest && selectedRequest.product_name) || '',
      match_score: context.match_confidence || '',
      product_name: (selectedRequest && selectedRequest.product_name) || ''
    });
    SpreadsheetApp.flush();
    return jsonResponse({
      status: 'success',
      message_id: messageId,
      reference_id: context.reference_id,
      matched_reference_product: context.matched_reference_product || ''
    });
  }
  return jsonResponse({ status: 'not_found', message_id: messageId });
}

function handlePriceRoleConfirmation(data) {
  const ingestionAccessError = validatePostIngestionAccess(data);
  if (ingestionAccessError) return ingestionAccessError;

  const messageId = String(data.message_id || '');
  const choice = String(data.choice || '');
  if (!messageId || !/^(sale_unit|sale_package)$/.test(choice)) {
    return jsonResponse({ status: 'error', message: 'Invalid message_id or choice' });
  }

  const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
  const sheet = getOrCreateSheet(spreadsheet, 'MessageData', MESSAGE_HEADERS);
  ensureSheetHeaders(sheet, MESSAGE_HEADERS);
  const values = sheet.getDataRange().getValues();
  const headers = values[0] || [];
  const idIdx = headers.indexOf('ID');
  const statusIdx = headers.indexOf('Status');
  const rawIdx = headers.indexOf('Raw Metadata');
  if (idIdx < 0 || statusIdx < 0 || rawIdx < 0) return jsonResponse({ status: 'error', message: 'MessageData required headers missing' });

  for (let i = values.length - 1; i >= 1; i--) {
    if (String(values[i][idIdx] || '') === messageId) {
      let metadata = {};
      try { metadata = values[i][rawIdx] ? JSON.parse(values[i][rawIdx]) : {}; } catch (e) { metadata = {}; }
      metadata.price_confirmation = { choice: choice, confirmed_at: new Date().toISOString() };
      sheet.getRange(i + 1, rawIdx + 1).setValue(JSON.stringify(metadata));
      const inventoryUpdate = applyPriceConfirmationToWaitingInventory(spreadsheet, messageId, choice);
      if (inventoryUpdate.updated > 0) {
        sheet.getRange(i + 1, statusIdx + 1).setValue('ai_processed');
        SpreadsheetApp.flush();
        return jsonResponse({
          status: 'success',
          message: 'Price role confirmed and waiting inventory row updated',
          message_id: messageId,
          choice: choice,
          processed: 1,
          products_stored: inventoryUpdate.updated,
          inventory_rows_updated: inventoryUpdate.updated,
          confirmation_requests: []
        });
      }
      return jsonResponse({
        status: 'error',
        message: 'Price role confirmed, but no Inventory log row matched this Message ID',
        message_id: messageId,
        choice: choice,
        processed: 0,
        products_stored: 0,
        inventory_rows_updated: 0,
        inventory_rows_matched: inventoryUpdate.matched || 0,
        confirmation_requests: []
      });
    }
  }
  return jsonResponse({ status: 'not_found', message_id: messageId });
}

function handleSheetMutationRequest(data) {
  try {
    const configuredToken = PropertiesService.getScriptProperties().getProperty('SHEET_MUTATION_TOKEN');
    if (!configuredToken) {
      return jsonResponse({
        status: 'error',
        message: 'SHEET_MUTATION_TOKEN script property is not configured'
      });
    }

    if (!data.token || data.token !== configuredToken) {
      return jsonResponse({
        status: 'error',
        message: 'Invalid or missing token'
      });
    }

    if (!Array.isArray(data.operations) || data.operations.length === 0) {
      return jsonResponse({
        status: 'error',
        message: 'operations must be a non-empty array'
      });
    }

    const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
    const results = [];

    for (let i = 0; i < data.operations.length; i++) {
      const operation = data.operations[i] || {};
      results.push(applySheetMutationOperation(spreadsheet, operation, i));
    }

    return jsonResponse({
      status: 'success',
      operation_count: results.length,
      results: results,
      timestamp: new Date().toISOString()
    });
  } catch (error) {
    return jsonResponse({
      status: 'error',
      message: error.toString(),
      timestamp: new Date().toISOString()
    });
  }
}

function applySheetMutationOperation(spreadsheet, operation, index) {
  const type = operation.type;
  const sheetName = operation.sheet;
  let sheet = sheetName ? spreadsheet.getSheetByName(sheetName) : null;

  if (type === 'addSheet') {
    if (!operation.name) throw new Error('Operation ' + index + ': name is required');
    if (spreadsheet.getSheetByName(operation.name)) throw new Error('Operation ' + index + ': sheet already exists');
    sheet = spreadsheet.insertSheet(operation.name);
    if (Array.isArray(operation.headers) && operation.headers.length > 0) {
      sheet.getRange(1, 1, 1, operation.headers.length).setValues([operation.headers]);
    }
    return { index: index, type: type, status: 'success', sheet: sheet.getName() };
  }

  if (!sheet) throw new Error('Operation ' + index + ': sheet not found');

  switch (type) {
    case 'setValue':
      requireRange(operation, index).setValue(operation.value);
      return { index: index, type: type, status: 'success', range: operation.range };

    case 'setValues':
      if (!Array.isArray(operation.values)) throw new Error('Operation ' + index + ': values must be a 2D array');
      requireRange(operation, index).setValues(operation.values);
      return { index: index, type: type, status: 'success', range: operation.range };

    case 'clearRange':
      requireRange(operation, index).clearContent();
      return { index: index, type: type, status: 'success', range: operation.range };

    case 'appendRow':
      if (!Array.isArray(operation.values)) throw new Error('Operation ' + index + ': values must be an array');
      sheet.appendRow(operation.values);
      return { index: index, type: type, status: 'success', row: sheet.getLastRow() };

    case 'insertRow':
      sheet.insertRowsBefore(requirePositiveNumber(operation.row, 'row', index), operation.count || 1);
      return { index: index, type: type, status: 'success', row: operation.row, count: operation.count || 1 };

    case 'deleteRow':
      sheet.deleteRows(requirePositiveNumber(operation.row, 'row', index), operation.count || 1);
      return { index: index, type: type, status: 'success', row: operation.row, count: operation.count || 1 };

    case 'insertColumn':
      sheet.insertColumnsBefore(requirePositiveNumber(operation.column, 'column', index), operation.count || 1);
      return { index: index, type: type, status: 'success', column: operation.column, count: operation.count || 1 };

    case 'deleteColumn':
      sheet.deleteColumns(requirePositiveNumber(operation.column, 'column', index), operation.count || 1);
      return { index: index, type: type, status: 'success', column: operation.column, count: operation.count || 1 };

    case 'renameSheet':
      if (!operation.name) throw new Error('Operation ' + index + ': name is required');
      sheet.setName(operation.name);
      return { index: index, type: type, status: 'success', sheet: operation.name };

    case 'deleteSheet':
      if (spreadsheet.getSheets().length <= 1) throw new Error('Operation ' + index + ': cannot delete the only sheet');
      spreadsheet.deleteSheet(sheet);
      return { index: index, type: type, status: 'success', sheet: sheetName };

    default:
      throw new Error('Operation ' + index + ': unsupported type "' + type + '"');
  }

  function requireRange(op, opIndex) {
    if (!op.range) throw new Error('Operation ' + opIndex + ': range is required');
    return sheet.getRange(op.range);
  }
}

function requirePositiveNumber(value, field, index) {
  const parsed = parseInt(value, 10);
  if (isNaN(parsed) || parsed < 1) {
    throw new Error('Operation ' + index + ': ' + field + ' must be a positive number');
  }
  return parsed;
}
