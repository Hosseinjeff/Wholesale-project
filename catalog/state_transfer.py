"""Owner-only, bounded transfer of frozen legacy stores into WordPress custody."""
from __future__ import annotations
import hashlib
import json
import os
import sqlite3
import tempfile
import time
import uuid
from pathlib import Path

FREEZE = Path('/data/.molavi-state-transfer.json')


def frozen():
    from catalog.state import wordpress_state
    if wordpress_state():
        return False
    try:
        return json.loads(FREEZE.read_text())['expires_at'] > time.time()
    except (OSError, ValueError, KeyError):
        return False


def _hash(path):
    digest = hashlib.sha256()
    with open(path, 'rb') as source:
        for chunk in iter(lambda: source.read(1024*1024), b''):
            digest.update(chunk)
    return digest.hexdigest()


def _backup(source, target):
    with sqlite3.connect('file:'+str(source)+'?mode=ro', uri=True) as db:
        dest = sqlite3.connect(target)
        try:
            db.backup(dest)
            if dest.execute('PRAGMA quick_check').fetchone()[0] != 'ok':
                raise RuntimeError('Source integrity check failed')
            names = [row[0] for row in dest.execute("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")]
            tables = {name: dest.execute('SELECT count(*) FROM "'+name.replace('"', '""')+'"').fetchone()[0] for name in names}
        finally:
            dest.close()
    os.chmod(target, 0o600)
    return tables


def transfer_response(body=None, *, store=None):
    from flask import Response, jsonify
    import app
    from catalog import memory
    from catalog.state import wordpress_state, state_call

    if store is not None:
        manifest = json.loads(FREEZE.read_text())
        item = next((x for x in manifest['stores'] if x['store'] == store), None)
        if not item or manifest['expires_at'] <= time.time():
            return jsonify({'ok': False, 'error': 'transfer_unavailable'}), 409
        def chunks():
            with open(item['transfer_path'], 'rb') as source:
                yield from iter(lambda: source.read(65536), b'')
        return Response(chunks(), content_type='application/octet-stream', headers={'Content-Length': str(item['bytes']), 'Cache-Control': 'no-store'})

    action = (body or {}).get('action')
    if action == 'prepare':
        if wordpress_state():
            return jsonify({'ok': False, 'error': 'already_wordpress_hosted'}), 409
        guard = memory._reclaimer
        if guard is None:
            return jsonify({'ok': False, 'error': 'quiescent_guard_required'}), 409
        with guard.lock:
            if guard.active > 1 or (app._pipeline_worker_thread is not None and app._pipeline_worker_thread.is_alive()):
                return jsonify({'ok': False, 'error': 'work_active_retry_later'}), 409
            transfer_id = uuid.uuid4().hex
            root = Path(tempfile.mkdtemp(prefix='molavi-transfer-'))
            manifest = {'ok': True, 'transfer_id': transfer_id, 'expires_at': time.time()+900, 'stores': []}
            FREEZE.write_text(json.dumps(manifest));FREEZE.chmod(0o600)
            try:
                for name, key in [('PIPELINE_DB_PATH', 'pipeline'), ('REFERENCE_STORE_PATH', 'reference')]:
                    path = os.path.realpath(os.environ[name])
                    target = root/(key+'.sqlite3')
                    tables = _backup(path, target)
                    manifest['stores'].append({'store': key, 'source_path': path, 'transfer_path': str(target),
                        'bytes': target.stat().st_size, 'sha256': _hash(target), 'tables': tables})
                FREEZE.write_text(json.dumps(manifest))
            except BaseException:
                FREEZE.unlink(missing_ok=True)
                raise
        return jsonify(manifest)
    if action == 'release':
        current = json.loads(FREEZE.read_text())
        if current['transfer_id'] != (body or {}).get('transfer_id'):
            return jsonify({'ok': False, 'error': 'transfer_changed'}), 409
        FREEZE.unlink(missing_ok=True)
        return jsonify({'ok': True, 'released': True})
    if action == 'clear_legacy':
        if not wordpress_state():
            return jsonify({'ok': False, 'error': 'wordpress_cutover_required'}), 409
        current = json.loads(FREEZE.read_text())
        if current['transfer_id'] != (body or {}).get('transfer_id'):
            return jsonify({'ok': False, 'error': 'transfer_changed'}), 409
        remote = state_call('inventory')
        removed = []
        for item in current['stores']:
            receipt = remote.get('receipts', {}).get(item['store'], {})
            if receipt.get('sha256') != item['sha256'] or receipt.get('bytes') != item['bytes']:
                return jsonify({'ok': False, 'error': 'verified_wordpress_transfer_required'}), 409
        for item in current['stores']:
            source = Path(item['source_path'])
            if not source.is_relative_to('/data'):
                raise ValueError('Legacy source is outside the data mount')
            for suffix in ('', '-wal', '-shm', '.publication.lock', '.activation.lock', '.refresh.lock'):
                path = Path(str(source)+suffix)
                if path.is_file():
                    path.unlink();removed.append(path.name)
        FREEZE.unlink(missing_ok=True)
        return jsonify({'ok': True, 'removed': removed})
    return jsonify({'ok': False, 'error': 'invalid_transfer_action'}), 400
