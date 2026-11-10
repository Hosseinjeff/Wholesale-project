"""Opt-in local/server worker; run only with configured persistent PIPELINE_DB_PATH.

No credentials are created here. Uses normal configured server adapters. For tests,
use the synthetic replay suite, which mocks every external write instead.
"""
import argparse
import os
import sys
import threading
import time
import uuid
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from catalog.durable import compact_pipeline_payloads
from app import (USE_SHEETS, _notice_chat_allowlists, _wake_reference_waiters_if_eligible,
                 build_store, handoff_queue, run_durable_handoff)
from catalog.sheet_projection import observe_sheet_projection
from catalog.wordpress_outbox import drain_wordpress_workflow_outbox
import requests


def run_worker(stop_event: threading.Event | None = None, *, once: bool = False) -> None:
    """Run the durable queue loop until asked to stop or one pass completes."""
    stop_event = stop_event or threading.Event()
    queue = handoff_queue()
    if queue is None:
        raise RuntimeError('PIPELINE_DB_PATH must name persistent private storage')

    worker_id = 'pipeline-worker:' + uuid.uuid4().hex
    queue.start_worker(worker_id)
    heartbeat_stop = threading.Event()

    def heartbeat():
        while not heartbeat_stop.wait(10):
            try:
                queue.heartbeat_worker(worker_id)
            except Exception:
                print('Pipeline worker heartbeat update failed', file=sys.stderr)

    heartbeat_thread = threading.Thread(
        target=heartbeat, name='pipeline-worker-heartbeat', daemon=True
    )
    heartbeat_thread.start()
    outbox_session = requests.Session()

    def compact_payloads():
        try:
            days, compacted = compact_pipeline_payloads(queue)
            if compacted['jobs_compacted'] or compacted['inbox_compacted']:
                print(
                    f"Compacted completed pipeline payloads jobs={compacted['jobs_compacted']} "
                    f"inbox={compacted['inbox_compacted']} retention_days={days}",
                    file=sys.stderr,
                )
        except Exception:
            print('Completed payload compaction failed; source processing remains available', file=sys.stderr)

    try:
        compact_payloads()
        last_compaction = time.monotonic()
        try:
            cancelled_notices = queue.cancel_unroutable_notice_jobs(_notice_chat_allowlists())
            if cancelled_notices:
                print('Cancelled pending operational notices with untrusted recipients', file=sys.stderr)
        except Exception:
            print('Notice recipient cleanup failed; runtime allowlist remains enforced', file=sys.stderr)

        reference_scheduler = None
        reference_path = os.getenv('REFERENCE_STORE_PATH') or ''
        reference_owners = [
            value.strip() for value in (os.getenv('REFERENCE_OWNER_IDS') or '').split(',')
            if value.strip()
        ]
        if reference_path and reference_owners:
            try:
                refresh_hours = int(os.getenv('REFERENCE_REFRESH_INTERVAL_HOURS', '24'))
            except ValueError:
                refresh_hours = 24
            refresh_hours = max(1, min(168, refresh_hours))
            from reference_import.refresh_scheduler import ReferenceRefreshScheduler
            reference_scheduler = ReferenceRefreshScheduler(
                reference_path,
                owner_ids=reference_owners,
                interval_seconds=refresh_hours * 3600,
            )

        try:
            resumed = queue.wake_publication_waiters_if_unblocked()
            if resumed:
                print(f'Resumed publication jobs after restart count={resumed}', file=sys.stderr)
        except Exception:
            print('Publication wait recovery failed; pending jobs remain durable', file=sys.stderr)

        if reference_path and reference_owners:
            try:
                from reference_import.snapshots import ReferenceStore
                reference_store = ReferenceStore(reference_path, owner_ids=reference_owners)
                _wake_reference_waiters_if_eligible(queue, reference_store)
            except Exception:
                print('Reference wait recovery failed; pending batches remain durable', file=sys.stderr)

        site_url = (os.getenv('SHOP_SITE_URL') or '').strip().rstrip('/')
        bridge_token = os.getenv('REFERENCE_BRIDGE_TOKEN') or ''
        outbox_enabled = bool(site_url.startswith('https://') and len(bridge_token) >= 32 and reference_owners)
        if not outbox_enabled:
            print('WordPress workflow outbox consumer disabled; required site URL, bridge token, or owner is missing', file=sys.stderr)
        else:
            print('WordPress workflow outbox consumer enabled', file=sys.stderr)

        try:
            local_api_port = int(os.getenv('PORT', '8080'))
        except ValueError:
            local_api_port = 8080
        local_api_root = f'http://127.0.0.1:{local_api_port}'

        def apply_wordpress_workflow_command(command_type, payload):
            endpoint = {
                'sheet_conflict': '/pipeline/sheet-conflicts',
                'supplier_price': '/pipeline/supplier-price-evidence',
            }.get(command_type)
            if not endpoint:
                raise ValueError('unsupported WordPress workflow command')
            response = outbox_session.post(
                local_api_root + endpoint,
                json=payload,
                timeout=(3, 20),
                headers={
                    'X-Molavi-Reference-Token': bridge_token,
                    'X-Molavi-Actor': reference_owners[0],
                },
            )
            try:
                body = response.json()
            except ValueError:
                body = {}
            return response.status_code, body if isinstance(body, dict) else {}

        try:
            poll_seconds = int(os.getenv('SHEET_SYNC_POLL_SECONDS', '60'))
        except ValueError:
            poll_seconds = 60
        poll_seconds = max(30, min(3600, poll_seconds))
        try:
            outbox_poll_seconds = int(os.getenv('WP_WORKFLOW_OUTBOX_POLL_SECONDS', '60'))
        except ValueError:
            outbox_poll_seconds = 60
        outbox_poll_seconds = max(10, min(300, outbox_poll_seconds))

        last_poll = 0.0
        last_reference_check = 0.0
        last_outbox_poll = 0.0
        outbox_ready_reported = False
        while not stop_event.is_set():
            now = time.monotonic()
            if now - last_compaction >= 24 * 60 * 60:
                compact_payloads()
                last_compaction = time.monotonic()

            if USE_SHEETS and (last_poll == 0 or now - last_poll >= poll_seconds):
                queue.set_worker_state(worker_id, 'polling_sheet')
                try:
                    observe_sheet_projection(queue, build_store())
                except Exception:
                    print('Sheet projection poll failed; will retry on next interval', file=sys.stderr)
                last_poll = time.monotonic()

            if reference_scheduler and (last_reference_check == 0 or now - last_reference_check >= 60):
                queue.set_worker_state(worker_id, 'refreshing_reference')
                try:
                    outcome = reference_scheduler.run_if_due()
                    if outcome.get('state') == 'failed':
                        print('Reference PDF/Gemini refresh failed; owner review incident was recorded', file=sys.stderr)
                    elif outcome.get('state') == 'staged':
                        print('Reference candidate staged for owner review: ' + str(outcome.get('snapshot_id', '')), file=sys.stderr)
                except Exception:
                    print('Reference refresh scheduler failed; persistent incident check will retry', file=sys.stderr)
                last_reference_check = time.monotonic()

            if outbox_enabled and (last_outbox_poll == 0 or now - last_outbox_poll >= outbox_poll_seconds):
                try:
                    outcome = drain_wordpress_workflow_outbox(
                        site_url,
                        bridge_token,
                        apply_wordpress_workflow_command,
                        session=outbox_session,
                        limit=5,
                    )
                    if not outcome['error'] and not outbox_ready_reported:
                        print(
                            'WordPress workflow outbox connected; '
                            f"initial_due_commands={outcome['claimed']}",
                            file=sys.stderr,
                        )
                        outbox_ready_reported = True
                    if outcome['delivered'] or outcome['retried'] or outcome['failed']:
                        print(
                            'WordPress Sheet-conflict outbox: '
                            f"claimed={outcome['claimed']} delivered={outcome['delivered']} "
                            f"retried={outcome['retried']} failed={outcome['failed']}",
                            file=sys.stderr,
                        )
                    elif outcome['error']:
                        print('WordPress workflow outbox poll failed; will retry', file=sys.stderr)
                except Exception:
                    print('WordPress workflow outbox poll failed; will retry', file=sys.stderr)
                last_outbox_poll = time.monotonic()

            queue.set_worker_state(worker_id, 'idle')
            worked = False
            if USE_SHEETS:
                queue.batch()
                worked = run_durable_handoff(queue, worker_id=worker_id)
            if once:
                break
            if not worked:
                stop_event.wait(min(5, poll_seconds))
    finally:
        heartbeat_stop.set()
        try:
            queue.set_worker_state(worker_id, 'stopped')
        except Exception:
            pass
        outbox_session.close()
        heartbeat_thread.join(timeout=2)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--once', action='store_true')
    args = parser.parse_args()
    try:
        run_worker(once=args.once)
    except RuntimeError as exc:
        parser.error(str(exc))


if __name__ == '__main__':
    main()
