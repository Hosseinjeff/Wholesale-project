// Pinned recovered file from 2e32dea. Network/storage are unavailable in this VM.
const vm = require('node:vm');
const fs = require('node:fs');
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const context = vm.createContext({
  PropertiesService: {getScriptProperties: () => ({getProperty: () => 'synthetic-test-key'})},
  UrlFetchApp: {fetch: () => ({getResponseCode: () => 200,
    getBlob: () => ({getBytes: () => []})})},
  Utilities: {base64Encode: () => ''}, Logger: {log: () => {}},
  persianToEnglishNumbers: value => String(value).replace(/[۰-۹٠-٩]/g,
    char => String('۰۱۲۳۴۵۶۷۸۹'.includes(char) ? '۰۱۲۳۴۵۶۷۸۹'.indexOf(char) : '٠١٢٣٤٥٦٧٨٩'.indexOf(char)))
});
vm.runInContext(fs.readFileSync(__dirname + '/../legacy-gas/recovered-2026-10-25/30_reference_prices.js', 'utf8'), context);
// Key normalization is excluded from this price/parser comparison, not asserted equivalent.
context.normalizeReferenceProductName = value => String(value);
context.callGeminiWithInlinePdf = () => JSON.stringify(input);
const refs = context.refreshReferencePricesFromPdf('synthetic-pdf', {});
process.stdout.write(JSON.stringify(refs.map(r => ({package_price:r.package_price,
  unit_price:r.unit_price, consumer_unit_price:r.consumer_unit_price,
  package_count:r.package_count, product_code:r.product_code}))));
