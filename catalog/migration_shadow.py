"""Read-only inventory of persistent pipeline state for domain migration audits."""
from __future__ import annotations

import hashlib
import os
import sqlite3
from urllib.parse import quote


def _table_name(value: str) -> str:
    return '"' + value.replace('"', '""') + '"'


def _digest(rows, fields):
    canonical = sorted('\x1f'.join('' if row.get(field) is None else str(row.get(field))
                                   for field in fields) for row in rows)
    return hashlib.sha256('\n'.join(canonical).encode('utf-8')).hexdigest()


def sqlite_shadow_summary(path: str | None) -> dict:
    """Return bounded table counts and migration keys without opening SQLite for writes."""
    if not isinstance(path, str) or not path.strip() or not os.path.isfile(path):
        return {'available': False, 'file_bytes': 0, 'tables': {}, 'error': 'database_unavailable'}
    absolute = os.path.realpath(path)
    uri = 'file:' + quote(absolute, safe='/') + '?mode=ro'
    connection = sqlite3.connect(uri, uri=True, timeout=5)
    connection.row_factory = sqlite3.Row
    try:
        connection.execute('PRAGMA query_only=ON')
        names = {str(row['name']) for row in connection.execute(
            "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")}
        counts = {}
        for name in sorted(names):
            counts[name] = int(connection.execute(
                f'SELECT COUNT(*) FROM {_table_name(name)}').fetchone()[0])
        page_size = int(connection.execute('PRAGMA page_size').fetchone()[0])
        page_count = int(connection.execute('PRAGMA page_count').fetchone()[0])
        freelist_count = int(connection.execute('PRAGMA freelist_count').fetchone()[0])

        conflicts = []
        if 'sheet_sync_conflicts' in names:
            conflicts = [dict(row) for row in connection.execute(
                '''SELECT conflict_id,product_id,source_revision,state,choice
                   FROM sheet_sync_conflicts ORDER BY conflict_id''')]

        price_commands = []
        if 'supplier_price_verification_commands' in names:
            price_commands = [dict(row) for row in connection.execute(
                '''SELECT command_id,offer_id,source_revision,verified_at
                   FROM supplier_price_verification_commands ORDER BY command_id''')]

        price_evidence = []
        if 'supplier_price_evidence' in names:
            price_evidence = [dict(row) for row in connection.execute(
                '''SELECT offer_id,source_revision,verified_at,method
                   FROM supplier_price_evidence ORDER BY offer_id''')]

        job_states = []
        if 'jobs' in names:
            job_states = [dict(row) for row in connection.execute(
                'SELECT kind,state,COUNT(*) AS row_count FROM jobs GROUP BY kind,state ORDER BY kind,state')]

        conflict_counts = {}
        for row in conflicts:
            state = str(row.get('state') or 'unknown')
            conflict_counts[state] = conflict_counts.get(state, 0) + 1

        return {
            'available': True,
            'file_bytes': os.path.getsize(absolute),
            'page_size': page_size,
            'page_count': page_count,
            'freelist_pages': freelist_count,
            'tables': counts,
            'job_states': job_states,
            'sheet_conflicts': {
                'counts_by_state': conflict_counts,
                'rows': conflicts,
                'digest': _digest(conflicts, ('conflict_id','product_id','source_revision','state','choice')),
            },
            'supplier_price_verification_commands': {
                'count': len(price_commands),
                'rows': price_commands,
                'digest': _digest(price_commands, ('command_id','offer_id','source_revision','verified_at')),
            },
            'supplier_price_evidence': {
                'count': len(price_evidence),
                'rows': price_evidence,
                'digest': _digest(price_evidence, ('offer_id','source_revision','verified_at','method')),
            },
        }
    finally:
        connection.close()


def migration_shadow_report(pipeline_path: str | None, reference_path: str | None) -> dict:
    """Inspect Railway's two durable SQLite files without exposing source payloads."""
    return {
        'pipeline': sqlite_shadow_summary(pipeline_path),
        'reference': sqlite_shadow_summary(reference_path),
    }
