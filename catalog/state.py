"""SQLite-compatible transaction transport to WordPress's private durable stores.

Each preview rolls back on WP. Commit revalidates observed results and applies the
whole transaction once, so HTTP failures cannot leave half-written business state.
"""
from __future__ import annotations

import gzip
import hashlib
import hmac
import json
import os
import re
import sqlite3
import time
import uuid
import threading
from contextlib import contextmanager
from pathlib import Path
from urllib.parse import unquote

import requests
_locks = threading.local()
_schema_scripts = set()
_schema_columns = {}
_schema_lock = threading.RLock()


def wordpress_state():
    return os.getenv('PIPELINE_STATE_HOST') == 'wordpress'


def state_call(route, body=None):
    site = (os.getenv('SHOP_SITE_URL') or '').rstrip('/')
    secret = os.getenv('REFERENCE_BRIDGE_TOKEN') or ''
    if not site.startswith('https://') or len(secret) < 32:
        raise sqlite3.OperationalError('WordPress state host is unconfigured')
    headers = {'X-Molavi-State-Token': hmac.new(secret.encode(), b'molavi-state-v1', hashlib.sha256).hexdigest()}
    data = None
    if body is not None:
        body = {**body, 'locks': list(getattr(_locks, 'held', {}).values())}
        data = json.dumps(body, ensure_ascii=False, separators=(',', ':')).encode()
        headers['Content-Type'] = 'application/json'
        if len(data) > 32768:
            data = gzip.compress(data)
            headers['X-Molavi-State-Encoding'] = 'gzip'
    # Retried commits retain their transaction ID; WordPress returns its receipt.
    for attempt in range(3):
        try:
            response = requests.request('POST' if body is not None else 'GET',
                site+'/wp-json/molavi-state/v1/'+route, data=data, headers=headers, timeout=(5, 45))
            if response.status_code == 409:
                if response.json().get('code') == 'state_integrity':
                    raise sqlite3.IntegrityError('WordPress state constraint conflict')
                raise sqlite3.OperationalError('WordPress state changed; retry the operation')
            response.raise_for_status()
            result = response.json()
            if result.get('ok') is not True:
                raise sqlite3.OperationalError('WordPress state operation failed')
            return result
        except (requests.RequestException, ValueError):
            if attempt == 2:
                raise sqlite3.OperationalError('WordPress state host unavailable') from None
            time.sleep(.2*(attempt+1))


class Row:
    def __init__(self, columns, values):
        self.columns, self.values = columns, values
    def __getitem__(self, key):
        if isinstance(key, int):
            return self.values[key]
        try:
            return self.values[self.columns.index(key)]
        except ValueError:
            raise KeyError(key) from None
    def __iter__(self):
        return iter(self.values)
    def keys(self):
        return self.columns
    def __len__(self):
        return len(self.columns)


class Cursor:
    def __init__(self, result):
        self.rows = [Row(result['columns'], values) for values in result['rows']]
        self.position = 0
        self.rowcount, self.lastrowid = result['rowcount'], result['lastrowid']
    def fetchone(self):
        if self.position >= len(self.rows):
            return None
        row = self.rows[self.position]
        self.position += 1
        return row
    def fetchall(self):
        rows = self.rows[self.position:]
        self.position = len(self.rows)
        return rows
    def __iter__(self):
        return self
    def __next__(self):
        row = self.fetchone()
        if row is None:
            raise StopIteration
        return row


class PendingCursor:
    def __init__(self, connection, index):
        self.connection, self.index, self.result = connection, index, None
    def _result(self):
        if self.result is None:
            self.result = self.connection._preview(self.index)
        return self.result
    def __getattr__(self, name):
        return getattr(self._result(), name)
    def __iter__(self):
        return iter(self._result())


class Connection:
    def __init__(self, store):
        self.store, self.steps, self.id = store, [], uuid.uuid4().hex
        self.clock, self.writes = time.time(), False
        self.cursors = {}
        self.row_factory = None
    def _execute(self, sql, params=(), *, many=False, script=False):
        if re.fullmatch(r'\s*BEGIN(?:\s+(?:IMMEDIATE|DEFERRED|EXCLUSIVE))?\s*;?\s*', sql, re.I):
            return Cursor({'columns': [], 'rows': [], 'rowcount': -1, 'lastrowid': 0})
        step = {'sql': sql, 'params': list(params), 'many': many, 'script': script}
        write = script or bool(re.match(r'\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b', sql, re.I))
        self.writes |= write
        self.steps.append(step)
        if write:
            cursor = PendingCursor(self, len(self.steps)-1)
            self.cursors[len(self.steps)-1] = cursor
            return cursor
        return self._preview(len(self.steps)-1)

    def _preview(self, index):
        try:
            result = state_call('transaction', {'store': self.store, 'id': self.id, 'observed_at': self.clock, 'mode': 'preview', 'steps': self.steps[:index+1]})
        except BaseException:
            raise
        for item, token in zip(self.steps, result['tokens']):
            item['expected'] = token
        self._cursor_results(result)
        return Cursor(result['result'])

    def _cursor_results(self, response):
        for index, meta in enumerate(response.get('cursors', [])):
            if index in self.cursors:
                self.cursors[index].result = Cursor({'columns': [], 'rows': [], **meta})
    def execute(self, sql, params=()):
        table_info = re.fullmatch(r'\s*PRAGMA\s+table_info\((\w+)\)\s*;?\s*', sql, re.I)
        if table_info and not self.steps:
            key = (self.store, table_info[1])
            with _schema_lock:
                if key not in _schema_columns:
                    cursor = self._execute(sql, params)
                    _schema_columns[key] = (cursor.rows[0].columns if cursor.rows else [], [row.values for row in cursor.rows])
                    self.rollback()
                columns, rows = _schema_columns[key]
                return Cursor({'columns': columns, 'rows': rows, 'rowcount': -1, 'lastrowid': 0})
        if re.match(r'\s*ALTER\s+TABLE', sql, re.I):
            with _schema_lock:
                _schema_columns.clear()
        return self._execute(sql, params)
    def executemany(self, sql, params):
        return self._execute(sql, [list(row) for row in params], many=True)
    def executescript(self, sql):
        self.commit()
        schema = bool(re.match(r'\s*CREATE\s+(?:TABLE|INDEX|TRIGGER)\s+IF\s+NOT\s+EXISTS', sql, re.I))
        key = (self.store, hashlib.sha256(sql.encode()).hexdigest())
        with _schema_lock:
            if schema and key in _schema_scripts:
                return Cursor({'columns': [], 'rows': [], 'rowcount': -1, 'lastrowid': 0})
            self._execute(sql, script=True)
            self.commit()
            if schema:
                _schema_scripts.add(key)
            return Cursor({'columns': [], 'rows': [], 'rowcount': -1, 'lastrowid': 0})
    def commit(self):
        if self.steps and self.writes:
            response = state_call('transaction', {'store': self.store, 'id': self.id, 'observed_at': self.clock, 'mode': 'commit', 'steps': self.steps})
            self._cursor_results(response)
        self.rollback()
    def rollback(self):
        self.steps, self.id = [], uuid.uuid4().hex
        self.clock, self.writes = time.time(), False
        self.cursors = {}
    def close(self):
        self.rollback()
    def __enter__(self):
        return self
    def __exit__(self, error_type, *_):
        if error_type is None:
            self.commit()
        else:
            self.rollback()


def store_key(path):
    value = str(path)
    if value.startswith('file:'):
        value = unquote(value[5:].split('?', 1)[0])
    absolute = os.path.realpath(value)
    for name, key in [('PIPELINE_DB_PATH', 'pipeline'), ('REFERENCE_STORE_PATH', 'reference')]:
        if os.getenv(name) and absolute == os.path.realpath(os.environ[name]):
            return key
    raise sqlite3.OperationalError('Unrecognized WordPress state store')


def connect(path, *args, **kwargs):
    if not wordpress_state():
        return sqlite3.connect(path, *args, **kwargs)
    key = store_key(path)
    # Existing ledgers test configured path availability/chmod. This empty marker
    # is ephemeral metadata, never a SQLite replica or source of business data.
    marker = Path(os.environ['PIPELINE_DB_PATH' if key == 'pipeline' else 'REFERENCE_STORE_PATH'])
    if not marker.is_relative_to('/tmp'):
        raise sqlite3.OperationalError('WordPress state requires ephemeral marker paths')
    marker.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    marker.touch(mode=0o600, exist_ok=True)
    return Connection(key)


def initialize():
    if wordpress_state():
        for name in ('PIPELINE_DB_PATH', 'REFERENCE_STORE_PATH'):
            if os.getenv(name):
                connect(os.environ[name]).close()


@contextmanager
def state_lock(path, name):
    key = store_key(path) + ':' + name
    held = getattr(_locks, 'held', None)
    if held is None:
        held = _locks.held = {}
    if key in held:
        yield
        return
    owner = uuid.uuid4().hex
    lease = {'store': store_key(path), 'name': name, 'owner': owner}
    deadline = time.monotonic()+30
    while not state_call('lease', {**lease, 'action': 'acquire'}).get('acquired'):
        if time.monotonic() >= deadline:
            raise sqlite3.OperationalError('WordPress state lock is busy')
        time.sleep(.25)
    held[key] = lease
    try:
        yield
    finally:
        held.pop(key, None)
        state_call('lease', {**lease, 'action': 'release'})
