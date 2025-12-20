// Google Apps Script code for automatic Telegram import
// Deploy as Web App to create a webhook endpoint

// Test function for GET requests
function doGet(e) {
  return ContentService
    .createTextOutput(JSON.stringify({
      status: 'success',
      message: 'Google Apps Script is working!',
      test: e.parameter.test || 'no test param'
    }))
    .setMimeType(ContentService.MimeType.JSON);
}

function doPost(e) {
  try {
    const data = JSON.parse(e.postData.contents);

    // Open the spreadsheet
    const spreadsheetId = '1u5LGXqiEfcPTsopvHOwkh-qvJO5zDK98pVmFhL-xWDs'; // Your sheet ID
    const spreadsheet = SpreadsheetApp.openById(spreadsheetId);
    const sheet = spreadsheet.getSheets()[0]; // First sheet

    // Prepare row data
    const rowData = [
      data.id,
      data.channel,
      data.author,
      data.content,
      data.timestamp,
      data.url,
      data.forwarded_by,
      data.forwarded_at,
      new Date().toISOString() // Import timestamp
    ];

    // Append to sheet
    sheet.appendRow(rowData);

    // Return success
    return ContentService
      .createTextOutput(JSON.stringify({status: 'success', message: 'Data imported'}))
      .setMimeType(ContentService.MimeType.JSON);

  } catch (error) {
    // Return error
    return ContentService
      .createTextOutput(JSON.stringify({status: 'error', message: error.toString()}))
      .setMimeType(ContentService.MimeType.JSON);
  }
}

// Test function (run this to check if script works)
function testImport() {
  const testData = {
    id: '123',
    channel: '@test_channel',
    author: 'Test User',
    content: 'Test message content',
    timestamp: new Date().toISOString(),
    url: 'https://t.me/test',
    forwarded_by: 'TestUser',
    forwarded_at: new Date().toISOString()
  };

  const e = {
    postData: {
      contents: JSON.stringify(testData)
    }
  };

  const result = doPost(e);
  Logger.log(result.getContent());
}
