"""Opt-in local/server worker; run only with configured persistent PIPELINE_DB_PATH.

No credentials are created here. Uses normal configured server adapters. For tests,
use the synthetic replay suite, which mocks every external write instead.
"""
import argparse
import os
import sys
import time
from pathlib import Path
sys.path.insert(0,str(Path(__file__).resolve().parent.parent))
from app import USE_SHEETS, build_store, handoff_queue, run_durable_handoff
from catalog.sheet_projection import observe_sheet_projection

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--once',action='store_true')
    args=parser.parse_args();queue=handoff_queue()
    if queue is None:parser.error('PIPELINE_DB_PATH must name persistent private storage')
    reference_scheduler=None
    reference_path=os.getenv('REFERENCE_STORE_PATH') or ''
    reference_owners=[value.strip() for value in (os.getenv('REFERENCE_OWNER_IDS') or '').split(',') if value.strip()]
    if reference_path and reference_owners:
        try:refresh_hours=int(os.getenv('REFERENCE_REFRESH_INTERVAL_HOURS','24'))
        except ValueError:refresh_hours=24
        refresh_hours=max(1,min(168,refresh_hours))
        from reference_import.refresh_scheduler import ReferenceRefreshScheduler
        reference_scheduler=ReferenceRefreshScheduler(reference_path,owner_ids=reference_owners,
            interval_seconds=refresh_hours*3600)
    try:poll_seconds=int(os.getenv('SHEET_SYNC_POLL_SECONDS','60'))
    except ValueError:poll_seconds=60
    poll_seconds=max(30,min(3600,poll_seconds))
    last_poll=0.0;last_reference_check=0.0
    while True:
        if USE_SHEETS and (last_poll==0 or time.monotonic()-last_poll>=poll_seconds):
            try:
                observe_sheet_projection(queue,build_store())
            except Exception:
                print('Sheet projection poll failed; will retry on next interval',file=sys.stderr)
            last_poll=time.monotonic()
        if reference_scheduler and (last_reference_check==0 or time.monotonic()-last_reference_check>=60):
            try:
                outcome=reference_scheduler.run_if_due()
                if outcome.get('state')=='failed':
                    print('Reference PDF/Gemini refresh failed; owner review incident was recorded',file=sys.stderr)
                elif outcome.get('state')=='staged':
                    print('Reference candidate staged for owner review: '+str(outcome.get('snapshot_id','')))
            except Exception:
                print('Reference refresh scheduler failed; persistent incident check will retry',file=sys.stderr)
            last_reference_check=time.monotonic()
        worked=False
        if USE_SHEETS:
            queue.batch()
            worked=run_durable_handoff(queue)
        if args.once:break
        if not worked:time.sleep(min(5,poll_seconds))
