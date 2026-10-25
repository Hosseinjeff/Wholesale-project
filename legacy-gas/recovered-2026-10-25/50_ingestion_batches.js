function validateData(data) {
  const errors = [];

  if (!data) {
    errors.push('No data provided');
    return { valid: false, errors };
  }

  if (!data.id) {
    errors.push('Missing message ID');
  }

  if (!data.content && !data.has_media) {
    errors.push('Missing content and no media');
  }

  if (!data.channel_username && !data.channel) {
    errors.push('Missing channel information');
  }

  return {
    valid: errors.length === 0,
    errors
  };
}

function checkForDuplicates(data) {
  try {
    const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
    const sheet = getOrCreateSheet(spreadsheet, 'MessageData', MESSAGE_HEADERS);
    const dataRange = sheet.getDataRange();
    const values = dataRange.getValues();

    // Skip header row
    for (let i = 1; i < values.length; i++) {
      if (values[i][0] == data.id) {
        // Found same message ID, check if content changed
        const existingContent = values[i][4] || ''; // Content column
        const newContent = data.content || '';
        const contentChanged = existingContent !== newContent;

        return {
          isDuplicate: true,
          contentChanged: contentChanged,
          existingRow: i + 1
        };
      }
    }

    return {
      isDuplicate: false,
      contentChanged: false,
      existingRow: null
    };
  } catch (error) {
    Logger.log(`Duplicate check error: ${error}`);
    return {
      isDuplicate: false,
      contentChanged: false,
      existingRow: null
    }; // Allow import if check fails
  }
}

function isDuplicate(data) {
  // Legacy function for backward compatibility
  return checkForDuplicates(data).isDuplicate;
}

function importMessageData(data, existingRow, status) {
  try {
    const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
    const sheet = getOrCreateSheet(spreadsheet, 'MessageData', MESSAGE_HEADERS);
    ensureSheetHeaders(sheet, MESSAGE_HEADERS);

    const headers = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0];
    const row = new Array(headers.length).fill('');
    
    const dataMap = {
      'ID': data.id || '',
      'Channel': data.channel || '',
      'Channel Username': data.channel_username || '',
      'Author': data.author || '',
      'Content': data.content || '',
      'Timestamp': data.timestamp || '',
      'Edit Date': data.edit_date || data.editDate || '',
      'URL': data.url || '',
      'Forwarded By': data.forwarded_by || '',
      'Forwarded At': data.forwarded_at || '',
      'Chat ID': data.chat_id || data.chatId || '',
      'Chat Type': data.chat_type || data.chatType || '',
      'Chat Title': data.chat_title || data.chatTitle || '',
      'Sender ID': data.sender_id || data.senderId || '',
      'Sender Is Bot': data.sender_is_bot !== undefined ? data.sender_is_bot : (data.senderIsBot !== undefined ? data.senderIsBot : ''),
      'Sender Language': data.sender_language || data.senderLanguage || '',
      'Reply To Message ID': data.reply_to_message_id || data.replyToMessageId || '',
      'Is Topic Message': data.is_topic_message !== undefined ? data.is_topic_message : (data.isTopicMessage !== undefined ? data.isTopicMessage : ''),
      'Message Thread ID': data.message_thread_id || data.messageThreadId || '',
      'Origin Type': data.origin_type || data.originType || '',
      'Original Message ID': data.original_message_id || data.originalMessageId || '',
      'Original Chat ID': data.original_chat_id || data.originalChatId || '',
      'Original Post Date': data.original_post_date || data.originalPostDate || '',
      'Original Edit Date': data.original_edit_date || data.originalEditDate || '',
      'Original Channel Title': data.original_channel_title || data.originalChannelTitle || '',
      'Original Channel Username': data.original_channel_username || data.originalChannelUsername || '',
      'Original Author Signature': data.original_author_signature || data.originalAuthorSignature || '',
      'Original Sender Name': data.original_sender_name || data.originalSenderName || '',
      'Has Media': data.has_media || false,
      'Media Type': data.media_type || '',
      'Media File ID': data.media_file_id || data.mediaFileId || '',
      'Media Unique ID': data.media_unique_id || data.mediaUniqueId || '',
      'Caption': data.caption || '',
      'Sender First Name': data.sender_first_name || data.senderFirstName || '',
      'Sender Last Name': data.sender_last_name || data.senderLastName || '',
      'Sender Username': data.sender_username || data.senderUsername || '',
      'Via Bot': data.via_bot || data.viaBot || '',
      'Is Automatic Forward': data.is_automatic_forward !== undefined ? data.is_automatic_forward : (data.isAutomaticForward !== undefined ? data.isAutomaticForward : ''),
      'Has Protected Content': data.has_protected_content !== undefined ? data.has_protected_content : (data.hasProtectedContent !== undefined ? data.hasProtectedContent : ''),
      'Media Group ID': data.media_group_id || data.mediaGroupId || '',
      'Author Signature': data.author_signature || data.authorSignature || '',
      'Effect ID': data.effect_id || data.effectId || '',
      'Show Caption Above Media': data.show_caption_above_media !== undefined ? data.show_caption_above_media : (data.showCaptionAboveMedia !== undefined ? data.showCaptionAboveMedia : ''),
      'Import Timestamp': new Date().toISOString(),
      'Status': status || (existingRow ? 'updated' : 'imported'),
      'Batch ID': data.batch_id || '',
      'Raw Metadata': data.raw_metadata || data.rawMetadata || ''
    };

    // Fill the row based on headers
    headers.forEach((header, index) => {
      if (dataMap[header] !== undefined) {
        row[index] = dataMap[header];
      }
    });

    let rowNum;
    if (existingRow) {
      // Update existing row
      sheet.getRange(existingRow, 1, 1, row.length).setValues([row]);
      rowNum = existingRow;
      Logger.log(`Updated message ${data.id} in MessageData row ${rowNum}`);
    } else {
      // Append to sheet
      sheet.appendRow(row);
      rowNum = sheet.getLastRow();
      Logger.log(`Imported message ${data.id} to MessageData row ${rowNum}`);
    }

    return {
      success: true,
      row: rowNum
    };

  } catch (error) {
    Logger.log(`Message import error: ${error}`);
    return {
      success: false,
      error: error.toString()
    };
  }
}

function importProductData(data) {
  Logger.log(`=== IMPORT_PRODUCT_DATA START ===`);

  try {
    const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);

    if (!data.channel_username || !data.channel || !data.content) {
      try {
        const messageSheet = getOrCreateSheet(spreadsheet, 'MessageData', MESSAGE_HEADERS);
        const messageData = messageSheet.getDataRange().getValues();
        if (messageData.length > 1) {
          const headers = messageData[0];
          const idIdx = headers.indexOf('ID');
          const channelIdx = headers.indexOf('Channel');
          const channelUsernameIdx = headers.indexOf('Channel Username');
          const contentIdx = headers.indexOf('Content');

          for (let i = 1; i < messageData.length; i++) {
            const row = messageData[i];
            if (idIdx >= 0 && row[idIdx] == data.id) {
              if (!data.channel && channelIdx >= 0) {
                data.channel = row[channelIdx] || '';
              }
              if (!data.channel_username && channelUsernameIdx >= 0) {
                data.channel_username = row[channelUsernameIdx] || '';
              }
              if (!data.content && contentIdx >= 0) {
                data.content = row[contentIdx] || '';
              }
              break;
            }
          }
        }
      } catch (sheetError) {
        Logger.log(`Error reading channel from MessageData sheet: ${sheetError}`);
      }
    }

    const inventorySheet = getOrCreateSheet(spreadsheet, 'Inventory log', INVENTORY_LOG_HEADERS);
    ensureSheetHeaders(inventorySheet, INVENTORY_LOG_HEADERS);
    const inventoryValues = inventorySheet.getDataRange().getValues();
    const inventoryHeaders = inventoryValues[0] || [];
    const existingKeys = buildInventoryMergeKeySet(inventoryValues, inventoryHeaders);

    // Extract products
    const products = extractProducts(data.content || '', data.channel_username);
    Logger.log(`IMPORT: extractProducts returned ${products.length} products for message ${data.id}`);

    if (products.length === 0) {
      return {
        success: true,
        products_found: 0,
        row: null
      };
    }

    let lastAffectedRow = null;

    // Process each product as an Inventory log supplier_offer row
    for (let i = 0; i < products.length; i++) {
      const product = products[i];
      
      try {
      // Perform QA checks before write
      const qa = performQualityChecks({
        name: product.name,
        sale_price: product.sale_price || product.price || 0,
        actual_price: product.actual_price || product.consumer_price || 0,
        extraction_confidence: product.extraction_confidence || product.confidence || 0
      }, spreadsheet);
      if (qa.requires_review) {
        product.status = 'needs_review';
        logExtractionIssue(spreadsheet, 'WARN', data.id, qa.reason);
      } else {
        product.status = 'available_from_supplier';
      }

        const appendResult = appendSupplierOfferToInventoryLog(spreadsheet, product, data, existingKeys);
        if (!appendResult.skipped) {
          lastAffectedRow = appendResult.row;
        }
      } catch (prodError) {
        Logger.log(`Error processing individual product: ${prodError}`);
        // Continue with next product
      }
    }

  // Systemic error alert: check last logs for error rate
  try {
    checkSystemicErrors(spreadsheet);
  } catch (sysErr) {
    // ignore
  }

    return {
      success: true,
      products_found: products.length,
      row: lastAffectedRow
    };

  } catch (error) {
    Logger.log(`Product import error: ${error}`);
    return {
      success: false,
      error: error.toString()
    };
  }
}

function processPendingMessages(reset) {
  const spreadsheet = SpreadsheetApp.openById(CONFIG.SPREADSHEET_ID);
  const messageSheet = getOrCreateSheet(spreadsheet, 'MessageData', MESSAGE_HEADERS);
  const lastRow = messageSheet.getLastRow();
  if (lastRow < 2) {
    return ContentService
      .createTextOutput(JSON.stringify({
        status: 'success',
        message: 'No messages to process',
        processed: 0,
        last_processed_row: 1
      }))
      .setMimeType(ContentService.MimeType.JSON);
  }

  const data = messageSheet.getRange(1, 1, lastRow, messageSheet.getLastColumn()).getValues();
  const headers = data[0];
  const headerMap = {};
  headers.forEach(function(header, index) {
    headerMap[header] = index;
  });

  const idCol = headerMap['ID'];
  const contentCol = headerMap['Content'];
  const channelCol = headerMap['Channel'];
  const channelUsernameCol = headerMap['Channel Username'];
  const statusCol = headerMap['Status'];

  const props = PropertiesService.getScriptProperties();
  if (reset === true) {
    props.setProperty('LAST_PROCESSED_MESSAGE_ROW', '1');
  }
  var lastProcessedRow = parseInt(props.getProperty('LAST_PROCESSED_MESSAGE_ROW') || '1', 10);
  if (isNaN(lastProcessedRow) || lastProcessedRow < 1) {
    lastProcessedRow = 1;
  }

  var processedCount = 0;
  var newLastProcessedRow = lastProcessedRow;
  var maxBatchSize = 50;

  for (var rowIndex = lastProcessedRow + 1; rowIndex <= lastRow && processedCount < maxBatchSize; rowIndex++) {
    var row = data[rowIndex - 1];
    var id = idCol !== undefined ? row[idCol] : null;
    if (!id) {
      newLastProcessedRow = rowIndex;
      continue;
    }

    var messageStatus = statusCol !== undefined ? String(row[statusCol] || '') : '';
    // Allow 'imported' and 'updated' statuses to be processed, effectively processing all messages with content
    // This ensures we don't skip messages that were default-classified as 'imported'
    var isCandidate = !messageStatus || /(?:product_listing|price_update|out_of_stock|imported|updated)/i.test(messageStatus);
    if (!isCandidate) {
      newLastProcessedRow = rowIndex;
      continue;
    }

    var msgData = {
      id: id,
      channel: channelCol !== undefined ? row[channelCol] : '',
      channel_username: channelUsernameCol !== undefined ? row[channelUsernameCol] : '',
      content: contentCol !== undefined ? row[contentCol] : ''
    };

    try {
      var result = importProductData(msgData);
      processedCount++;
    } catch (err) {
      try {
        logExtractionIssue(spreadsheet, 'ERROR', id, 'Background extraction failed: ' + err);
      } catch (logErr) {
        Logger.log('Background extraction logging failed: ' + logErr);
      }
    }

    newLastProcessedRow = rowIndex;
  }

  props.setProperty('LAST_PROCESSED_MESSAGE_ROW', String(newLastProcessedRow));

  return ContentService
    .createTextOutput(JSON.stringify({
      status: 'success',
      message: 'Background product extraction completed',
      processed: processedCount,
      last_processed_row: newLastProcessedRow
    }))
    .setMimeType(ContentService.MimeType.JSON);
}

function batchIngestChunk(spreadsheet, payload) {
  return {
    status: 'batch_ingest_ignored'
  };
}

function _getBatchMessageStatus(classifierStatus) {
  // Check if AI batch mode is active — if so, mark as pending_ai for later extraction
  const rawMode = PropertiesService.getScriptProperties().getProperty('EXTRACTION_MODE') || 'regex';
  const mode = rawMode.trim().toLowerCase();
  if (mode === 'ai' || mode === 'ai_batch') {
    return 'pending_ai';
  }
  return classifierStatus || 'imported';
}

function finalizeBatch(spreadsheet, payload) {
  var batchId = String(payload.batch_id || '');
  var messages = Array.isArray(payload.messages) ? payload.messages : [];
  var expected = parseInt(payload.expected_count || '0', 10) || messages.length || 0;
  var written = 0;

  if (messages.length > 0) {
    var sheet = getOrCreateSheet(spreadsheet, 'MessageData', MESSAGE_HEADERS);
    ensureSheetHeaders(sheet, MESSAGE_HEADERS);
    ensureMessageStatusValidation(sheet, MESSAGE_HEADERS.indexOf('Status') + 1);
    var headers = sheet.getRange(1, 1, 1, sheet.getLastColumn()).getValues()[0];
    var statusHeaderIndex = headers.indexOf('Status');
    var rows = [];

    for (var i = 0; i < messages.length; i++) {
      var m = messages[i] || {};
      m.batch_id = batchId;
      var cls = MessageClassifier.classify(m.content || '', m.channel_username || m.channel || '');
      var status = cls.type;

      var row = new Array(headers.length).fill('');
      var dataMap = {
        'ID': m.id || '',
        'Channel': m.channel || '',
        'Channel Username': m.channel_username || '',
        'Author': m.author || '',
        'Content': m.content || '',
        'Timestamp': m.timestamp || '',
        'Edit Date': m.edit_date || '',
        'URL': m.url || '',
        'Forwarded By': m.forwarded_by || '',
        'Forwarded At': m.forwarded_at || '',
        'Chat ID': m.chat_id || '',
        'Chat Type': m.chat_type || '',
        'Chat Title': m.chat_title || '',
        'Sender ID': m.sender_id || '',
        'Sender Is Bot': m.sender_is_bot !== undefined ? m.sender_is_bot : '',
        'Sender Language': m.sender_language || '',
        'Reply To Message ID': m.reply_to_message_id || '',
        'Is Topic Message': m.is_topic_message !== undefined ? m.is_topic_message : '',
        'Message Thread ID': m.message_thread_id || '',
        'Origin Type': m.origin_type || '',
        'Original Message ID': m.original_message_id || '',
        'Original Chat ID': m.original_chat_id || '',
        'Original Post Date': m.original_post_date || '',
        'Original Edit Date': m.original_edit_date || '',
        'Original Channel Title': m.original_channel_title || '',
        'Original Channel Username': m.original_channel_username || '',
        'Original Author Signature': m.original_author_signature || '',
        'Original Sender Name': m.original_sender_name || '',
        'Has Media': m.has_media || false,
        'Media Type': m.media_type || '',
        'Media File ID': m.media_file_id || '',
        'Media Unique ID': m.media_unique_id || '',
        'Caption': m.caption || '',
        'Sender First Name': m.sender_first_name || m.senderFirstName || '',
        'Sender Last Name': m.sender_last_name || m.senderLastName || '',
        'Sender Username': m.sender_username || m.senderUsername || '',
        'Via Bot': m.via_bot || m.viaBot || '',
        'Is Automatic Forward': m.is_automatic_forward !== undefined ? m.is_automatic_forward : '',
        'Has Protected Content': m.has_protected_content !== undefined ? m.has_protected_content : '',
        'Media Group ID': m.media_group_id || '',
        'Author Signature': m.author_signature || '',
        'Effect ID': m.effect_id || '',
        'Show Caption Above Media': m.show_caption_above_media !== undefined ? m.show_caption_above_media : '',
        'Import Timestamp': new Date().toISOString(),
        'Status': _getBatchMessageStatus(status),
        'Batch ID': m.batch_id || '',
        'Raw Metadata': m.raw_metadata || ''
      };

      for (var h = 0; h < headers.length; h++) {
        var header = headers[h];
        if (dataMap[header] !== undefined) {
          row[h] = dataMap[header];
        }
      }

      rows.push(row);
    }

    if (rows.length > 0) {
      // Use appendRow for each row individually — getLastRow() is unreliable
      // after batch setValues when rows contain empty cells
      for (var r = 0; r < rows.length; r++) {
        sheet.appendRow(rows[r]);
      }
      written = rows.length;
    }
  } else {
    written = countBatchMessages(spreadsheet, batchId);
  }

  if (expected > 0 && written !== expected) {
    recordBatchStatus(spreadsheet, batchId, expected, written, { status: 'error', processed: 0, rollback: false });
    storeLastBatchStatus(batchId, expected, written, { status: 'error', processed: 0, rollback: false });
    return {
      status: 'error',
      ack: 'ingestion_incomplete',
      batch_id: batchId,
      expected_count: expected,
      written_count: written
    };
  }
  recordBatchStatus(spreadsheet, batchId, expected, written, { status: 'success', processed: 0, deferred: true });
  storeLastBatchStatus(batchId, expected, written, { status: 'success', processed: 0, deferred: true });
  return {
    status: 'success',
    ack: 'ingestion_complete',
    batch_id: batchId,
    expected_count: expected,
    written_count: written,
    extraction_status: 'deferred_to_ai_batch',
    processed_messages: 0,
    rollback: false
  };
}

function countBatchMessages(spreadsheet, batchId) {
  var sheet = getOrCreateSheet(spreadsheet, 'MessageData', MESSAGE_HEADERS);
  var values = sheet.getDataRange().getValues();
  if (values.length < 2) return 0;
  var headers = values[0];
  var idx = headers.indexOf('Batch ID');
  if (idx < 0) return 0;
  var count = 0;
  for (var i = 1; i < values.length; i++) {
    if (String(values[i][idx] || '') === batchId) count++;
  }
  return count;
}

function listBatchMessageIds(spreadsheet, batchId) {
  var sheet = getOrCreateSheet(spreadsheet, 'MessageData', MESSAGE_HEADERS);
  var values = sheet.getDataRange().getValues();
  var headers = values[0];
  var idIdx = headers.indexOf('ID');
  var batchIdx = headers.indexOf('Batch ID');
  var result = [];
  for (var i = 1; i < values.length; i++) {
    if (String(values[i][batchIdx] || '') === batchId) {
      result.push(String(values[i][idIdx] || ''));
    }
  }
  return result;
}

function deleteProductsByBatchId(spreadsheet, batchId) {
  var sheet = getOrCreateSheet(spreadsheet, 'Inventory log', INVENTORY_LOG_HEADERS);
  ensureSheetHeaders(sheet, INVENTORY_LOG_HEADERS);
  var values = sheet.getDataRange().getValues();
  if (values.length < 2) return 0;
  var headers = values[0];
  var idx = headers.indexOf('Batch ID');
  if (idx < 0) return 0;
  var deleted = 0;
  for (var i = values.length - 1; i >= 1; i--) {
    if (String(values[i][idx] || '') === batchId) {
      sheet.deleteRow(i + 1);
      deleted++;
    }
  }
  return deleted;
}

function processBatchProducts(spreadsheet, batchId, messages) {
  var processed = 0;

  var rawMode = PropertiesService.getScriptProperties().getProperty('EXTRACTION_MODE') || 'regex';
  var mode = rawMode.trim().toLowerCase();
  var isAiMode = (mode === 'ai' || mode === 'ai_batch');

  if (Array.isArray(messages) && messages.length > 0) {
    if (isAiMode && messages.length >= 1) {
      // BATCH MODE: send ALL messages in one AI API call
      return processBatchProductsAI(spreadsheet, batchId, messages);
    }

    // REGEX MODE: process each message individually (fast, no API cost)
    for (var i = 0; i < messages.length; i++) {
      var msgData = messages[i] || {};
      msgData.batch_id = batchId;
      var ok = false;
      for (var attempt = 0; attempt < 3 && !ok; attempt++) {
        var res = importProductData(msgData);
        ok = !!res && !!res.success;
      }
      if (!ok) {
        deleteProductsByBatchId(spreadsheet, batchId);
        return { status: 'error', processed: processed, rollback: true };
      }
      processed++;
    }
    return { status: 'success', processed: processed };
  }

  // No messages array provided, look them up from sheet
  var ids = listBatchMessageIds(spreadsheet, batchId);

  if (isAiMode && ids.length >= 1) {
    return processBatchProductsAI(spreadsheet, batchId, ids);
  }

  for (var j = 0; j < ids.length; j++) {
    var msg = { id: ids[j], batch_id: batchId };
    var ok2 = false;
    for (var attempt2 = 0; attempt2 < 3 && !ok2; attempt2++) {
      var res2 = importProductData(msg);
      ok2 = !!res2 && !!res2.success;
    }
    if (!ok2) {
      deleteProductsByBatchId(spreadsheet, batchId);
      return { status: 'error', processed: processed, rollback: true };
    }
    processed++;
  }
  return { status: 'success', processed: processed };
}

function processBatchProductsAI(spreadsheet, batchId, messages) {
  // Collect message data from either message objects or from sheet by IDs
  var pendingMessages = [];

  if (Array.isArray(messages) && messages.length > 0) {
    if (typeof messages[0] === 'string' || typeof messages[0] === 'number') {
      // IDs: look up from sheet
      var messageSheet = getOrCreateSheet(spreadsheet, 'MessageData', MESSAGE_HEADERS);
      var data = messageSheet.getDataRange().getValues();
      var headers = data[0];
      var headerMap = {};
      headers.forEach(function(h, i) { headerMap[h] = i; });

      var idIdx = headerMap['ID'];
      var contentIdx = headerMap['Content'];
      var channelIdx = headerMap['Channel'];
      var channelUsernameIdx = headerMap['Channel Username'];
      var timestampIdx = headerMap['Timestamp'];
      var editDateIdx = headerMap['Edit Date'];
      var chatIdIdx = headerMap['Chat ID'];
      var chatTitleIdx = headerMap['Chat Title'];
      var forwardedByIdx = headerMap['Forwarded By'];
      var batchIdIdx = headerMap['Batch ID'];

      var idSet = {};
      for (var k = 0; k < messages.length; k++) {
        idSet[String(messages[k])] = true;
      }

      for (var i = 1; i < data.length; i++) {
        var row = data[i];
        var rowId = String(idIdx >= 0 ? row[idIdx] || '' : '');
        if (idSet[rowId]) {
          pendingMessages.push({
            id: rowId,
            content: contentIdx >= 0 ? row[contentIdx] || '' : '',
            channel: channelIdx >= 0 ? row[channelIdx] || '' : '',
            channel_username: channelUsernameIdx >= 0 ? row[channelUsernameIdx] || '' : '',
            timestamp: timestampIdx >= 0 ? row[timestampIdx] || '' : '',
            edit_date: editDateIdx >= 0 ? row[editDateIdx] || '' : '',
            chat_id: chatIdIdx >= 0 ? row[chatIdIdx] || '' : '',
            chat_title: chatTitleIdx >= 0 ? row[chatTitleIdx] || '' : '',
            forwarded_by: forwardedByIdx >= 0 ? row[forwardedByIdx] || '' : '',
            batch_id: batchIdIdx >= 0 ? row[batchIdIdx] || '' : batchId
          });
        }
      }
    } else {
      // Full message objects
      for (var i = 0; i < messages.length; i++) {
        var m = messages[i] || {};
        pendingMessages.push({
          id: m.id || '',
          content: m.content || '',
          channel: m.channel || '',
          channel_username: m.channel_username || m.channel || '',
          timestamp: m.timestamp || '',
          edit_date: m.edit_date || '',
          chat_id: m.chat_id || '',
          chat_title: m.chat_title || '',
          forwarded_by: m.forwarded_by || '',
          batch_id: m.batch_id || batchId
        });
      }
    }
  }

  if (pendingMessages.length === 0) {
    return { status: 'success', processed: 0, products_stored: 0 };
  }

  var result = _runBatchAI(spreadsheet, pendingMessages, batchId);
  return { status: 'success', processed: result.processed, products_stored: result.products_stored };
}

function recordBatchStatus(spreadsheet, batchId, expected, written, extraction) {
  var sheet = getOrCreateSheet(spreadsheet, 'BatchStatus', BATCH_STATUS_HEADERS);
  var status = extraction && extraction.status ? extraction.status : '';
  var processed = extraction && typeof extraction.processed === 'number' ? extraction.processed : 0;
  var rollback = extraction && extraction.rollback ? true : false;
  sheet.appendRow([
    new Date().toISOString(),
    batchId,
    expected,
    written,
    processed,
    status,
    rollback
  ]);
}

function storeLastBatchStatus(batchId, expected, written, extraction) {
  var status = extraction && extraction.status ? extraction.status : '';
  var processed = extraction && typeof extraction.processed === 'number' ? extraction.processed : 0;
  var rollback = extraction && extraction.rollback ? true : false;
  var props = PropertiesService.getScriptProperties();
  var payload = {
    batch_id: batchId,
    expected_messages: expected,
    written_messages: written,
    processed_messages: processed,
    extraction_status: status,
    rollback: rollback,
    timestamp: new Date().toISOString()
  };
  props.setProperty('LAST_BATCH_STATUS', JSON.stringify(payload));
}

function checkSystemicErrors(spreadsheet) {
  const logSheet = getOrCreateSheet(spreadsheet, 'ExecutionLogs', ['Timestamp','Action','Level','Channel','Content Length','Message','Error Code','Details']);
  const values = logSheet.getDataRange().getValues();
  const windowSize = Math.min(200, values.length - 1); // recent 200 entries
  if (windowSize <= 0) return;
  let errCount = 0;
  let totalCount = 0;
  for (let i = values.length - windowSize; i < values.length; i++) {
    const row = values[i];
    if (row[1] === 'price_extraction') {
      totalCount++;
      if (row[2] === 'ERROR' || row[2] === 'WARN') errCount++;
    }
  }
  if (totalCount > 0) {
    const rate = errCount / totalCount;
    if (rate > 0.05) {
      logSheet.appendRow([
        new Date().toISOString(),
        'alert',
        'ALERT',
        '',
        '',
        `Systemic price extraction issues: ${(rate*100).toFixed(1)}%`,
        0,
        'More than 5% of recent messages affected'
      ]);
    }
  }
}

// --- RECOGNITION SYSTEM ---
