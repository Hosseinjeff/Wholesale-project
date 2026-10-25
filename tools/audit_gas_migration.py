import ast,json,logging,subprocess,sys
from pathlib import Path
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
OUT=Path(sys.argv[1]) if len(sys.argv)>1 else Path('/tmp/molavi-migration-audit')
OUT.mkdir(parents=True,exist_ok=True)
sys.path.insert(0,str(ROOT))
logging.disable(logging.CRITICAL)
with patch('requests.sessions.Session.request',side_effect=RuntimeError('Offline audit: no network')):
 import app
 import catalog.sync as sync
 from catalog.extract import extract_products
 from catalog.publish import publish_shop, site_products

 constants={}
 for n in ast.parse((ROOT/'tests/test_catalog.py').read_text()).body:
  if isinstance(n,ast.Assign):
   try:constants[n.targets[0].id]=ast.literal_eval(n.value)
   except Exception:pass
 cases=[{'name':n,'channel':c,'content':constants[n]} for n,c in [('BONAKDAR','@bonakdarjavan'),('TOP_SHOP','@top_shop_rahimi'),('NOBEL','@nobelshop118')]]
 cases += [
  {'name':'universal','channel':'@other','content':'Test Product\nPrice: 125000 toman'},
  {'name':'carton_title','channel':'@bonakdarjavan','content':'نمونه محصول،کارتن ۳۶ عددی\nقیمت فروش ما: ۳۰/۰۰۰ تومان\nقیمت مصرف کننده: ۵۰/۰۰۰ تومان'},
  {'name':'nobel_missing_consumer','channel':'@nobelshop118','content':'محصول نمونه\n: ۷۵/۰۰۰'},
  {'name':'out_of_stock','channel':'@other','content':'نمونه محصول\nناموجود'},
  {'name':'persian_prices','channel':'@other','content':'محصول نمونه\nقیمت فروش: ۱۲۵٬۰۰۰ تومان\nقیمت مصرف: ۱۵۰٬۰۰۰ تومان'},
 ]
 (OUT/'fixtures.json').write_text(json.dumps(cases,ensure_ascii=False))
 gas=subprocess.run(['git','show','5bbe791:google_apps_script.js'],cwd=ROOT,text=True,capture_output=True,check=True).stdout
 (OUT/'historical_gas.js').write_text(gas)
 fields=['name','sale_price','consumer_price','price','price_type','packaging','volume','currency','stock_status','extraction_confidence']
 def relevant(ps):return [{k:p.get(k) for k in fields} for p in ps]
 python_results={c['name']:relevant(extract_products(c['content'],c['channel'])) for c in cases}
 (OUT/'python-extraction.json').write_text(json.dumps(python_results,ensure_ascii=False,indent=2))

 sample=cases[0]
 payload={'batch_id':'audit-b1','messages':[{'id':'audit-m1','channel':'telegram','channel_username':sample['channel'],'content':sample['content'],'chat_id':'audit-private'}]}
 report={}
 # Track whether the current writer even reads the provided ReferencePrices sheet.
 class TraceStore(sync.MemoryStore):
  def __init__(self):super().__init__();self.reads=[]
  def read(self,name):self.reads.append(name);return super().read(name)
 s=TraceStore();s.sheets['ReferencePrices']=[['Product Name','Unit Price'],['Audit reference',1]]
 sync.finalize_batch(s,payload)
 report['reference_prices']={'seeded':True,'read_by_current_pipeline':'ReferencePrices' in s.reads,'sheet_names_read':sorted(set(s.reads)),'reference_schema':'synthetic; actual schema not established'}
 stock=sync.MemoryStore()
 stock.sheets['Products']=[['Product Name','Channel Username','Sale Price','Consumer Price','Status','Stock Status'],['Audit item','@audit',40000,50000,'imported','Out of Stock']]
 sold=site_products(stock)
 report['out_of_stock_publication']={'source_stock_status':'Out of Stock','published_status':sold[0]['status'] if sold else None,'stock_status_forwarded':bool(sold and 'stock_status' in sold[0])}
 sync.finalize_batch(s,payload)
 report['same_batch_replay']={'message_rows':len(s.read('MessageData'))-1,'offer_rows':len(s.read('Offers'))-1,'expected_unique_messages':1}
 sync.finalize_batch(s,{**payload,'batch_id':'audit-b2'})
 report['new_batch_same_message']={'message_rows':len(s.read('MessageData'))-1,'offer_rows':len(s.read('Offers'))-1,'expected_unique_source_message':1}

 with patch('catalog.sync.import_product_data',return_value={'success':False}):
  failed=sync.finalize_batch(sync.MemoryStore(),payload)
 report['failed_extraction_ack']={k:failed.get(k) for k in ['status','ack','extraction_status','processed_messages','rollback']}
 manager=app.BatchManager(None)
 with patch.object(app,'USE_SHEETS',True),patch.object(app,'build_store',return_value=sync.MemoryStore()),patch.object(app,'finalize_batch',return_value=failed),patch.object(app,'_publish_shop_quietly') as publish,patch.object(manager,'_notify_chats'):
  manager._send_finalize('audit-b1',payload['messages'],[])
  report['publish_after_rollback']={'publish_attempted':publish.called}
 with patch.object(app,'USE_SHEETS',False),patch.object(app,'send_chat_text'),patch.object(app,'operator_chat_ids',return_value=['audit-private']):
  app.app.config['TESTING']=True
  with patch.object(app,'_shop_token_ok',return_value=True):
   r=app.app.test_client().post('/shop/order',json={'order':{'id':'audit-order','name':'Audit','lines':[]},'notify':True})
  d=r.get_json()
  report['order_without_sheets']={'http':r.status_code,'status':d.get('status'),'written':d.get('written'),'notified':d.get('notified')}
 with patch('catalog.publish._post_shop',side_effect=[{'ok':True,'created':1,'images_left':1,'pending':[{'product':1,'file':'valid_file_12345'}]}, {'ok':False,'status':503}]),patch('catalog.publish._telegram_photo',return_value=(b'fixture-photo','image/jpeg')):
  result=publish_shop(s,max_rounds=1)
 report['partial_photo_sync']={k:result.get(k) for k in ['ok','pushed','images_left','attached','status']}
 (OUT/'behavior-findings.json').write_text(json.dumps(report,indent=2))
 print(json.dumps(report,indent=2))
