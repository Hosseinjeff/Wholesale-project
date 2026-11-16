"""Small event-driven Railway executor; WordPress owns idle scheduling."""
from __future__ import annotations
import hashlib
import hmac
import os
import sqlite3
import time
from pathlib import Path
import requests

TASKS=frozenset({'sheet_sync','reference_refresh','outbox','housekeeping'})

def on_demand():
    return os.getenv('PIPELINE_RUNTIME_MODE','continuous')=='on_demand'

def next_work_at(queue, *, now=None):
    now=time.time() if now is None else now
    with queue.connect() as db:
        if db.execute('SELECT 1 FROM inbox WHERE batch_id IS NULL LIMIT 1').fetchone():return now
        row=db.execute("SELECT MIN(CASE WHEN state='queued' THEN available ELSE expires END) AS due FROM jobs WHERE state IN ('queued','running')").fetchone()
    return float(row['due']) if row and row['due'] is not None else None

def schedule_on_wordpress(queue):
    site=(os.getenv('SHOP_SITE_URL') or '').rstrip('/')
    secret=os.getenv('REFERENCE_BRIDGE_TOKEN') or ''
    if not site.startswith('https://') or len(secret)<32:return False
    due=next_work_at(queue)
    try:
        response=requests.post(site+'/wp-json/molavi-runtime/v1/schedule',json={'observed_at':time.time(),'wake_at':due},
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
    report={'mode':os.getenv('PIPELINE_RUNTIME_MODE','continuous'),'databases':[],'memory':memory_report()}
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
    paths=set(filter(None,[os.getenv('PIPELINE_DB_PATH'),os.getenv('REFERENCE_STORE_PATH')]))
    for root in list(paths):
        if Path(root).is_dir():paths.update(str(p) for p in Path(root).glob('*.sqlite*') if p.is_file() and not p.name.endswith(('-wal','-shm')))
    for value in paths:
        path=Path(value)
        if not path.is_file():continue
        item={'name':path.name,'file_bytes':path.stat().st_size,'wal_bytes':Path(str(path)+'-wal').stat().st_size if Path(str(path)+'-wal').exists() else 0}
        try:
            with sqlite3.connect('file:'+str(path)+'?mode=ro',uri=True) as db:
                item['page_size']=db.execute('PRAGMA page_size').fetchone()[0]
                item['free_pages']=db.execute('PRAGMA freelist_count').fetchone()[0]
                item['tables']=[{'name':name,'bytes':size} for name,size in db.execute('SELECT name,sum(pgsize) FROM dbstat GROUP BY name ORDER BY sum(pgsize) DESC LIMIT 12')]
        except sqlite3.Error:item['table_inventory']='unavailable'
        report['databases'].append(item)
    return report
