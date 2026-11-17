"""Small event-driven Railway executor; WordPress owns idle scheduling."""
from __future__ import annotations
import hashlib
import hmac
import os
import sqlite3
from catalog.state import connect
import time
from pathlib import Path
import requests

TASKS=frozenset({'sheet_sync','reference_refresh','outbox','housekeeping'})

def bot_capabilities():
    """Publish policy identities, never provider credentials or extracted data."""
    from catalog import gemini
    from reference_import.suggestion_tasks import policy_version
    return {'schema_version':1,'ai_configured':bool(gemini.ai_extraction_configured()),
            'extraction_policy_version':gemini.extraction_policy_version(),
            'suggestion_policy_version':policy_version(),
            'image_extraction_enabled':callable(getattr(gemini,'extract_text_from_image',None))}

def configure_wordpress_connectors():
    """Hand service credentials directly to the protected WordPress host once.

    This owner-only migration operation never returns credential material to its
    caller and never writes a local copy on Railway.
    """
    from urllib.parse import urlparse
    from catalog.sync import load_service_account_info
    site=(os.getenv('SHOP_SITE_URL') or '').rstrip('/')
    parsed=urlparse(site)
    secret=os.getenv('REFERENCE_BRIDGE_TOKEN') or ''
    sheet_id=os.getenv('GOOGLE_SHEET_ID') or ''
    if (parsed.scheme!='https' or parsed.hostname not in {'molavisale.ir','staging.molavisale.ir'}
            or parsed.port not in {None,443} or parsed.username or parsed.password
            or parsed.path or parsed.query or parsed.fragment or len(secret)<32 or not sheet_id):
        raise ValueError('WordPress connector destination is unconfigured')
    payload={'sheet_id':sheet_id,'google_service_account':load_service_account_info()}
    response=requests.post(site+'/wp-json/molavi-workflow/v1/connectors',json=payload,
        headers={'X-Molavi-Connector-Token':hmac.new(secret.encode(),b'molavi-connectors-v1',hashlib.sha256).hexdigest()},timeout=(5,30))
    response.raise_for_status()
    data=response.json()
    if (data.get('ok') is not True or data.get('configured')!=['google_sheets']
            or not isinstance(data.get('configuration_digest'),str)
            or len(data['configuration_digest'])!=64):
        raise ValueError('WordPress connector receipt is invalid')
    return {'ok':True,'configured':['google_sheets'],'configuration_digest':data['configuration_digest']}

def on_demand():
    return os.getenv('PIPELINE_RUNTIME_MODE','continuous')=='on_demand'

def next_work_at(queue, *, now=None):
    now=time.time() if now is None else now
    with queue.connect() as db:
        if db.execute('SELECT 1 FROM inbox WHERE batch_id IS NULL LIMIT 1').fetchone():return now
        row=db.execute("""SELECT MIN(CASE WHEN state='queued' THEN available ELSE expires END) AS due FROM jobs
            WHERE state IN ('queued','running') AND kind NOT IN
            (SELECT kind FROM molavi_state_executors WHERE host='wordpress')""").fetchone()
    return float(row['due']) if row and row['due'] is not None else None

def schedule_on_wordpress(queue):
    site=(os.getenv('SHOP_SITE_URL') or '').rstrip('/')
    secret=os.getenv('REFERENCE_BRIDGE_TOKEN') or ''
    if not site.startswith('https://') or len(secret)<32:return False
    due=next_work_at(queue)
    try:
        from catalog.durable import pipeline_payload_retention_days
        response=requests.post(site+'/wp-json/molavi-runtime/v1/schedule',json={'observed_at':time.time(),'wake_at':due,'retention_days':pipeline_payload_retention_days(),'bot_capabilities':bot_capabilities()},
            headers={'X-Molavi-Runtime-Token':hmac.new(secret.encode(),b'molavi-runtime-v1',hashlib.sha256).hexdigest()},timeout=(5,15))
        return response.status_code==200 and response.json().get('ok') is True
    except (requests.RequestException,ValueError):return False

def enqueue_tasks(queue,tasks,*,now=None):
    if not isinstance(tasks,list) or len(tasks)>4 or any(task not in TASKS for task in tasks):raise ValueError('invalid runtime tasks')
    now=time.time() if now is None else now
    for task in set(tasks):
        period={'sheet_sync':3600,'reference_refresh':86400,'outbox':60,'housekeeping':86400}[task]
        queue.put('maintenance',f'{task}:{int(now//period)}',{'task':task})
    return len(set(tasks))

def run_runtime_task(queue,payload):
    task=payload.get('task')
    if task=='sheet_sync':
        from catalog.sheet_projection import observe_sheet_projection
        from app import build_store
        observe_sheet_projection(queue,build_store())
    elif task=='reference_refresh':
        port=int(os.getenv('PORT','8080'))
        owners=(os.getenv('REFERENCE_OWNER_IDS') or '').split(',')
        response=requests.post(f'http://127.0.0.1:{port}/reference/snapshots',json={'action':'refresh'},
            headers={'X-Molavi-Reference-Token':os.getenv('REFERENCE_BRIDGE_TOKEN') or '', 'X-Molavi-Actor':owners[0].strip()},timeout=(5,180))
        data=response.json()
        if response.status_code!=200 or data.get('state') in {'failed','running'}:raise RuntimeError('reference_refresh_failed')
    elif task=='outbox':
        from catalog.wordpress_outbox import drain_wordpress_workflow_outbox
        owners=[x.strip() for x in (os.getenv('REFERENCE_OWNER_IDS') or '').split(',') if x.strip()]
        secret=os.getenv('REFERENCE_BRIDGE_TOKEN') or ''
        with requests.Session() as session:
            def apply(command_type,body):
                endpoint={'sheet_conflict':'sheet-conflicts','supplier_price':'supplier-price-evidence'}.get(command_type)
                if not endpoint or not owners:raise ValueError('invalid workflow command')
                response=session.post(f'http://127.0.0.1:{int(os.getenv("PORT","8080"))}/pipeline/'+endpoint,json=body,
                    headers={'X-Molavi-Reference-Token':secret,'X-Molavi-Actor':owners[0]},timeout=(3,30))
                return response.status_code,response.json()
            # Bounded, due work only; WordPress retries any remaining commands.
            for _ in range(4):
                result=drain_wordpress_workflow_outbox(os.getenv('SHOP_SITE_URL') or '',secret,apply,session=session,limit=5)
                if result.get('error'):raise RuntimeError('wordpress_outbox_failed')
                if result.get('claimed',0)<5:break
    elif task=='housekeeping':
        from catalog.durable import compact_pipeline_payloads
        compact_pipeline_payloads(queue)
    else:raise ValueError('invalid runtime task')
    return {'ok':True,'task':task}

def storage_report():
    from catalog.memory import memory_report
    from catalog.state import wordpress_state,state_call
    report={'mode':os.getenv('PIPELINE_RUNTIME_MODE','continuous'),'state_host':'wordpress' if wordpress_state() else 'local',
            'native_execution_filter_version':1,
            'native_order_notice_version':1,
            'bot_capabilities':bot_capabilities(),
            'databases':[],'memory':memory_report()}
    try:report['container_memory_bytes']=int(Path('/sys/fs/cgroup/memory.current').read_text())
    except (OSError,ValueError):pass
    data_root=Path('/data')
    if data_root.is_dir():
        files=[]
        for root,dirs,names in os.walk(data_root):
            for name in names:
                path=Path(root)/name
                try:
                    if not path.is_symlink():files.append({'name':str(path.relative_to(data_root)),'bytes':path.stat().st_size})
                except OSError:pass
            if len(files)>5000:break
        report['files']=sorted(files,key=lambda item:item['bytes'],reverse=True)[:20]
    if wordpress_state():
        report['wordpress_state']=state_call('inventory')
        return report
    paths=set(filter(None,[os.getenv('PIPELINE_DB_PATH'),os.getenv('REFERENCE_STORE_PATH')]))
    for root in list(paths):
        if Path(root).is_dir():paths.update(str(p) for p in Path(root).glob('*.sqlite*') if p.is_file() and not p.name.endswith(('-wal','-shm')))
    for value in paths:
        path=Path(value)
        if not path.is_file():continue
        item={'name':path.name,'file_bytes':path.stat().st_size,'wal_bytes':Path(str(path)+'-wal').stat().st_size if Path(str(path)+'-wal').exists() else 0}
        try:
            with connect('file:'+str(path)+'?mode=ro',uri=True) as db:
                item['page_size']=db.execute('PRAGMA page_size').fetchone()[0]
                item['free_pages']=db.execute('PRAGMA freelist_count').fetchone()[0]
                item['tables']=[{'name':name,'bytes':size} for name,size in db.execute('SELECT name,sum(pgsize) FROM dbstat GROUP BY name ORDER BY sum(pgsize) DESC LIMIT 12')]
        except sqlite3.Error:item['table_inventory']='unavailable'
        report['databases'].append(item)
    return report
