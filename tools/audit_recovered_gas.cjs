/* Offline audit of the user's recovered GAS snapshot. No GAS function is called
 * against Google, suppliers, AI providers, bots, or the production site.
 * All inputs are synthetic; Sheets/Properties/HTTP are local doubles.
 * This records observed behavior, including defects, not migration sign-off.
 */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const root = path.resolve(__dirname, '..');
const source = path.resolve(process.argv[2] || path.join(root, 'legacy-gas/recovered-2026-10-25'));
const output = path.resolve(process.argv[3] || '/tmp/molavi-recovered-gas-audit.json');
const names = fs.readdirSync(source).filter(n => n.endsWith('.js')).sort();
assert.equal(names.length, 8, 'Expected the eight-file user export');
const code = names.map(n => fs.readFileSync(path.join(source, n), 'utf8')).join('\n');
const ctx = vm.createContext(Object.create(null), {codeGeneration: {strings: false, wasm: false}});
const run = code => vm.runInContext(code, ctx, {timeout: 3000});
// These doubles are constructed inside the VM. No host callbacks, require,
// process, environment, credentials, fetch, or timers are exposed to source.
run(`
  var mockProperties = Object.create(null), mockHttp = [], mockHttpStatus = 200,
      mockHttpBody = '{}', mockTriggerEvents = [], mockTables = Object.create(null);
  var Logger = {log() {}};
  var PropertiesService = {getScriptProperties() {return {
    getProperty(k) {return Object.hasOwn(mockProperties,k) ? mockProperties[k] : null},
    setProperty(k,v) {mockProperties[k]=String(v)},
    getProperties() {return {...mockProperties}}
  }}};
  var ContentService = {MimeType:{JSON:'json'},createTextOutput(s) {return {
    text:s,setMimeType() {return this},getContent() {return this.text}
  }}};
  var UrlFetchApp = {fetch(url,options) {mockHttp.push({url,options});return {
    getResponseCode() {return mockHttpStatus}, getContentText() {return mockHttpBody}
  }}};
  var ScriptApp = {
    getProjectTriggers() {return []},
    deleteTrigger() {throw Error('unexpected delete')},
    newTrigger(handler) {var event={handler}; mockTriggerEvents.push(event); return {
      timeBased() {return this}, everyDays(n) {event.days=n;return this},
      atHour(n) {event.hour=n;return this},create() {return event}
    }}
  };
  var Utilities = {getUuid() {return '00000000-0000-4000-8000-000000000001'}};
  class FakeSheet {
    constructor(name,rows) {this.name=name;this.rows=rows.map(r=>r.slice())}
    getName() {return this.name}
    getLastRow() {return this.rows.length}
    getLastColumn() {return Math.max(0,...this.rows.map(r=>r.length))}
    getDataRange() {return this.getRange(1,1,this.getLastRow(),this.getLastColumn())}
    getRange(row,col,nrows=1,ncols=1) {const sheet=this;return {
      getValues() {return Array.from({length:nrows},(_,r)=>Array.from({length:ncols},(_,c)=>sheet.rows[row-1+r]?.[col-1+c] ?? ''))},
      setValues(values) {for(let r=0;r<values.length;r++) {sheet.rows[row-1+r] ||= [];for(let c=0;c<values[r].length;c++) sheet.rows[row-1+r][col-1+c]=values[r][c]}return this},
      setValue(v) {return this.setValues([[v]])},setFontWeight() {return this},
      setNumberFormat() {return this},setDataValidation() {return this},
      clearDataValidations() {return this}
    }}
    appendRow(row) {this.rows.push(row.slice())}
    clear() {this.rows=[]}
    clearContents() {this.rows=[]}
    setFrozenRows() {}
    autoResizeColumns() {}
  }
  var fakeSpreadsheet = {getSheetByName(n) {return mockTables[n] || null},
    insertSheet(n) {return mockTables[n]=new FakeSheet(n,[])}};
  var SpreadsheetApp = {openById() {return fakeSpreadsheet},flush() {},
    newDataValidation() {return {requireValueInList() {return this},setAllowInvalid() {return this},build() {return this}}}
  };
`);
run(code);
const cases = [];
function observe(id, expression, check) {
  const value = JSON.parse(run(`JSON.stringify((()=>{${expression}})())`));
  check(value);
  cases.push({id, observed: value});
}
run(`
  var syntheticProduct={name:'Sample tea',sale_price:600000,packaging:'12 عدد',category:'tea'};
  var syntheticRef={name:'Sample tea',clean_name:'Sample tea',reference_id:'SYN-TEA',
    package_count:12,unit_price:50000,package_price:600000,category:'tea',
    source:'synthetic',last_updated:'2026-10-25'};
  function assessment(product,refs,options={}) {return assessHistoricalPriceScale(product,{},[],[],
    {referenceRows:refs,...options})}
`);
observe('R01-package-price-role', `return assessment({...syntheticProduct},[{...syntheticRef}]);`,
  v => {assert.equal(v.action,'reference_package_price');assert.equal(v.suggested_unit_price,50000)});
observe('R02-unit-price-role', `return assessment({...syntheticProduct,sale_price:50000},[{...syntheticRef}]);`,
  v => assert.equal(v.action,'reference_unit_price'));
observe('R03-explicit-price-takes-precedence', `return assessment({...syntheticProduct},[{...syntheticRef}],{explicitPriceRole:'unit'});`,
  v => {assert.equal(v.certain,true);assert.equal(v.action,'none')});
observe('R04-no-reference-needs-confirmation', `return assessment({...syntheticProduct},[]);`,
  v => {assert.equal(v.certain,false);assert.equal(v.action,'confirm_price_scale')});
observe('R05-stale-reference-still-used', `return assessment({...syntheticProduct},[{...syntheticRef,last_updated:'2000-01-01'}]);`,
  v => assert.equal(v.action,'reference_package_price'));
observe('R06-brand-conflict-not-vetoed', `return assessment({...syntheticProduct,brand:'Brand A'},[{...syntheticRef,brand:'Brand B'}]);`,
  v => {assert.equal(v.action,'reference_package_price');assert.equal(v.referenceMatch.match_components.brand,0)});
observe('R07-persisted-match', `
  saveReferenceMatchOverride(getReferenceMatchOverrideKey(syntheticProduct),'SYN-OTHER');
  var chosen=findReferencePriceMatch(syntheticProduct,[{...syntheticRef,reference_id:'SYN-OTHER',name:'Unrelated item',clean_name:'Unrelated item'}]);
  mockProperties.REFERENCE_MATCH_OVERRIDES='{}';return chosen;`,
  v => {assert.equal(v.persisted_reference,true);assert.equal(v.match_score,1)});
observe('R08-category-median-fallback', `
  var refs=['Alpha','Beta','Gamma'].map(name=>({...syntheticRef,name,clean_name:name,unit_price:50000,package_price:600000}));
  return assessment({...syntheticProduct,name:'Unrelated'},refs);`,
  v => {assert.equal(v.reference_source,'category_median');assert.equal(v.action,'reference_package_price')});
observe('R09-confirmed-history-fallback', `
  var headers=['Product Name','Sale Unit Price','Channel Username','Action','Status','Extraction Status','Product ID'];
  var history=[headers,['Sample tea',50000,'@sample','supplier_offer','for sale','user_confirmed','SYN-HISTORY']];
  return assessHistoricalPriceScale({...syntheticProduct},{channel_username:'@sample'},history,headers,{minHistory:1,referenceRows:[]});`,
  v => {assert.equal(v.action,'history_package_price');assert.equal(v.suggested_unit_price,50000)});
observe('R10-refresh-delegates-to-server', `
  mockProperties.REFERENCE_REFRESH_SERVER_URL='https://reference.invalid/refresh';
  mockProperties.ADMIN_ENDPOINT_TOKEN='synthetic-test-token';
  mockHttpStatus=200;mockHttpBody='{"status":"success","reference_count":2}';
  var result=JSON.parse(refreshReferencePrices().text);
  return {result,request:mockHttp.at(-1),sheetWrites:Object.keys(mockTables).length};`,
  v => {assert.match(v.request.url,/source=store&sync=true/);assert.equal(v.sheetWrites,0)});
observe('R11-refresh-html-acknowledges-success', `
  mockHttpBody='<html>not a refresh receipt</html>';
  return JSON.parse(refreshReferencePrices().text);`,
  v => assert.equal(v.status,'success'));
observe('R12-reference-trigger-definition', `
  setupReferencePriceTrigger();return mockTriggerEvents.at(-1);`,
  v => {assert.equal(v.handler,'referencePriceDailyTrigger');assert.equal(v.days,1);assert.equal(v.hour,4)});
observe('R13-reference-sheet-roundtrip', `
  var count=writeReferencePriceRows(fakeSpreadsheet,[{name:'Sample tea',product_code:'SYN-TEA',package_price:600000,unit_price:50000,package_count:12}],
    'synthetic','https://reference.invalid/source',true);
  return {count,headers:mockTables.ReferencePrices.rows[0],read:readReferencePriceRows(fakeSpreadsheet)};`,
  v => {assert.equal(v.count,1);assert.equal(v.headers.length,21);assert.equal(v.read[0].unit_price,50000);assert.equal(v.read[0].reference_id,'SYN-TEA')});
observe('R14-confirmation-affects-all-message-products', `
  mockTables['Inventory log']=new FakeSheet('Inventory log',[INVENTORY_LOG_HEADERS,
    ...['Sample tea','Sample rice'].map((name,i)=>INVENTORY_LOG_HEADERS.map(h=>({
      'Product Name':name,'Message ID (Platform)':'SYN-MULTI','Packaging':12,
      'Status':i===0?'waiting_confirmation':'for sale','Extraction Status':i===0?'waiting_user_confirmation':'message_confirmed',
      'Detected Price':600000,'Sale Unit Price':600000,'Sale Price':7200000
    }[h] ?? '')))]);
  var result=applyPriceConfirmationToWaitingInventory(fakeSpreadsheet,'SYN-MULTI','sale_package');
  return {result,rows:readSheetObjects(mockTables['Inventory log'])};`,
  v => {assert.equal(v.result.updated,2);assert.equal(v.rows[1]['Sale Unit Price'],50000)});
observe('R15-storefront-price-does-not-follow-selected-supplier', `
  mockTables['Inventory log']=new FakeSheet('Inventory log',[INVENTORY_LOG_HEADERS,
    ...[{supplier:'A',unit:50000,time:'2026-10-24T10:00:00Z'},
      {supplier:'B',unit:80000,time:'2026-10-25T10:00:00Z'}].map(offer=>INVENTORY_LOG_HEADERS.map(h=>({
        'Product ID':'SYN-SAME','Product Name':'Sample tea','Action':'supplier_offer','Status':'for sale',
        'Owner':offer.supplier,'Channel Username':offer.supplier,'Sale Unit Price':offer.unit,
        'Sale Price':offer.unit*12,'Packaging':12,'Import Timestamp':offer.time,
        'Extraction Status':'user_confirmed'
      }[h] ?? '')))]);
  rebuildStorefront();return readSheetObjects(mockTables.storefront)[0];`,
  v => {assert.equal(v['Selected Supplier'],'A');assert.equal(v['Source Unit Cost'],80000);assert.equal(v['Storefront Unit Price'],92000)});
observe('R16-waiting-status-lost-in-inventory-mapping', `
  var channel=createChannelObjectFromProduct({name:'Sample tea',sale_price:600000,packaging:'12 عدد',
    status:'waiting_confirmation',extraction_status:'waiting_user_confirmation',confidence:0.95},{id:'SYN-WAIT'});
  var row=createInventoryLogRowFromChannelRow(channel,INVENTORY_LOG_HEADERS);
  var record=Object.fromEntries(INVENTORY_LOG_HEADERS.map((h,i)=>[h,row[i]]));
  return {status:record.Status,extraction:record['Extraction Status'],storefrontEligible:isConfirmedInventoryRow(record)};`,
  v => {assert.equal(v.status,'for sale');assert.equal(v.storefrontEligible,false)});
observe('R17-recovered-ingestion-requires-token', `
  mockProperties.INGESTION_ENDPOINT_TOKEN='synthetic-ingest-token';
  var payload={mode:'batch_ingest',batch_id:'SYN-BATCH',messages:[],transmission_complete:true};
  var rejected=JSON.parse(validatePostIngestionAccess(payload).text);
  var accepted=validatePostIngestionAccess({...payload,token:'synthetic-ingest-token'});
  return {rejected,accepted};`,
  v => {assert.equal(v.rejected.status,'error');assert.equal(v.accepted,null)});
observe('R18-review-warning-does-not-block-storefront-helper', `
  var channel=createChannelObjectFromProduct({name:'Sample tea',sale_price:600000,packaging:'12 عدد',
    status:'needs_review',extraction_status:'ai_confirmed',extraction_warning:'Synthetic unresolved concern',confidence:0.95},{id:'SYN-REVIEW'});
  var row=createInventoryLogRowFromChannelRow(channel,INVENTORY_LOG_HEADERS);
  var record=Object.fromEntries(INVENTORY_LOG_HEADERS.map((h,i)=>[h,row[i]]));
  return {status:record.Status,ok:record['OK?'],warning:record['Exp. Warning'],storefrontEligible:isConfirmedInventoryRow(record)};`,
  v => {assert.equal(v.status,'for sale');assert.equal(v.ok,false);assert.equal(v.storefrontEligible,true)});
const result = {
  schema_version: 1, source: 'user gas.rar, received 2026-10-25 Asia/Tehran',
  method: 'Compiled eight original files together; isolated VM with local-only doubles and synthetic fixtures',
  live_calls: 0, production_changes: false, signoff: false,
  files: names.map(name => ({name,sha256:crypto.createHash('sha256').update(fs.readFileSync(path.join(source,name))).digest('hex')})),
  assertions_completed: cases.length, cases
};
fs.mkdirSync(path.dirname(output), {recursive:true});
fs.writeFileSync(output, JSON.stringify(result,null,2)+'\n');
console.log(JSON.stringify({output,observation_groups:cases.length,live_calls:0,signoff:false}));
