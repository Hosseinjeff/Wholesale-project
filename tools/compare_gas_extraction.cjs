const fs=require('fs'),vm=require('vm');
const dir=(process.argv[2]||'/tmp/molavi-migration-audit').replace(/\/$/,'')+'/';
const source=fs.readFileSync(dir+'historical_gas.js','utf8');
const fixtures=JSON.parse(fs.readFileSync(dir+'fixtures.json','utf8'));
const expected=JSON.parse(fs.readFileSync(dir+'python-extraction.json','utf8'));
const fields=['name','sale_price','consumer_price','price','price_type','packaging','volume','currency','stock_status','extraction_confidence'];
const context=vm.createContext({Logger:{log(){}},console:{log(){}},Utilities:{sleep(){}},Date,JSON,Math});
vm.runInContext(source,context,{timeout:3000});
const results=[],raw={};
for(const fixture of fixtures){
 context.auditInput=fixture;
 let got;
 try{got=vm.runInContext('extractProducts(auditInput.content,auditInput.channel)',context,{timeout:3000});}
 catch(e){results.push({fixture:fixture.name,error_type:e.name,error:e.message});continue;}
 got=JSON.parse(JSON.stringify(got));
 raw[fixture.name]=got.map(p=>Object.fromEntries(fields.map(k=>[k,p[k]??null])));
 const diffs=[];
 const want=expected[fixture.name];
 if(got.length!==want.length)diffs.push({field:'product_count',gas:got.length,python:want.length});
 for(let i=0;i<Math.min(got.length,want.length);i++){
  for(const field of fields){
   const gas=got[i][field]??null,python=want[i][field]??null;
   if(JSON.stringify(gas)!==JSON.stringify(python))diffs.push({product:i,field,gas,python});
  }
 }
 results.push({fixture:fixture.name,gas_count:got.length,python_count:want.length,differences:diffs});
}
fs.writeFileSync(dir+'gas-extraction.json',JSON.stringify(raw,null,2));
fs.writeFileSync(dir+'extraction-parity.json',JSON.stringify(results,null,2));
console.log(JSON.stringify(results,null,2));
