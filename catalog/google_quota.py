"""Opt-in, persistent Google free-tier routing. No workers or outbound polling.

Budgets are supplied from the owner's AI Studio project. No model, allowance or
quality qualification is inferred. Reservations count failures conservatively.
"""
from contextlib import contextmanager
import base64
from dataclasses import dataclass
from datetime import datetime, time as day_start, timedelta
import json
import math
import os
import re
import sqlite3
import time
import zlib
from urllib.parse import quote
from uuid import uuid4
from zoneinfo import ZoneInfo
from catalog.state import connect as connect_state, wordpress_state


class QuotaConfigurationError(ValueError):
    pass


class QuotaDeferred(RuntimeError):
    def __init__(self, retry_at, reason='google_free_quota_wait'):
        self.retry_at = float(retry_at)
        if not math.isfinite(self.retry_at):
            raise ValueError('Finite quota retry time required')
        self.reason = reason
        super().__init__(reason)


class GoogleProviderError(ValueError):
    """Intentionally excludes provider response bodies and credentials."""


class GoogleModelUnavailable(GoogleProviderError):
    """The configured model endpoint rejected the project's access."""


@dataclass(frozen=True)
class ModelBudget:
    model: str
    tasks: tuple
    rpm: int
    tpm: int
    rpd: int
    api: str = 'generate'
    bucket: str = ''
    reserve_rpd: int = 0

    @classmethod
    def parse(cls, value):
        if not isinstance(value, dict) or value.get('free_tier_verified') is not True:
            raise QuotaConfigurationError('Every model requires verified free-tier access')
        if value.get('qualified') is not True:
            raise QuotaConfigurationError('Every routed model requires task qualification')
        model = value.get('model')
        tasks = value.get('tasks')
        if (not isinstance(model, str) or not re.fullmatch(r'[A-Za-z0-9._-]{1,100}', model)
                or not isinstance(tasks, list) or not tasks
                or any(task not in {'extract', 'match', 'embed'} for task in tasks)):
            raise QuotaConfigurationError('Invalid model or task configuration')
        api = value.get('api', 'generate')
        if (api not in {'generate', 'embed', 'decide', 'generate_choice'}
                or (api == 'embed' and set(tasks) != {'embed'})
                or (api in {'decide', 'generate_choice'} and set(tasks) != {'match'})
                or (api == 'generate' and 'embed' in tasks)):
            raise QuotaConfigurationError('Model API does not support configured tasks')
        numbers = {}
        for name in ('rpm', 'tpm', 'rpd', 'reserve_rpd'):
            number = value.get(name, 0 if name == 'reserve_rpd' else None)
            if type(number) is not int or number < (0 if name == 'reserve_rpd' else 1):
                raise QuotaConfigurationError('Explicit positive RPM, TPM and RPD required')
            numbers[name] = number
        if numbers['reserve_rpd'] >= numbers['rpd']:
            raise QuotaConfigurationError('Daily reserve must be smaller than the allowance')
        bucket = value.get('bucket', model)
        if not isinstance(bucket, str) or not re.fullmatch(r'[A-Za-z0-9._-]{1,120}', bucket):
            raise QuotaConfigurationError('Invalid quota bucket')
        return cls(model=model, tasks=tuple(tasks), api=api, bucket=bucket, **numbers)


def pacific_day(now):
    local = datetime.fromtimestamp(now, ZoneInfo('America/Los_Angeles'))
    reset = datetime.combine(local.date() + timedelta(days=1), day_start(), local.tzinfo)
    return local.date().isoformat(), reset.timestamp()


def input_token_bound(payload):
    # Conservative text reservation, including JSON framing. Images are deliberately
    # over-reserved rather than silently bypassing TPM accounting.
    return len(json.dumps(payload, ensure_ascii=False, separators=(',', ':')).encode()) + 512


class FreeGoogleRouter:
    def __init__(self, database, project, models, *, clock=time.time):
        if not isinstance(project, str) or not re.fullmatch(r'[A-Za-z0-9._:-]{1,120}', project):
            raise QuotaConfigurationError('Explicit Google quota project identity required')
        if not models or len({model.model for model in models}) != len(models):
            raise QuotaConfigurationError('Unique qualified model configurations required')
        groups = {}
        for model in models:
            limits = (model.rpm, model.tpm, model.rpd, model.reserve_rpd)
            if model.bucket in groups and groups[model.bucket] != limits:
                raise QuotaConfigurationError('Shared quota buckets must use identical limits')
            groups[model.bucket] = limits
        self.database = os.path.realpath(os.fspath(database))
        self.project, self.models, self.clock = project, tuple(models), clock
        if wordpress_state():
            # The provider validates the known store and creates only its empty
            # /tmp marker. No quota or cache database is created on the executor.
            connect_state(self.database).close()
        else:
            fd = os.open(self.database, os.O_RDWR | os.O_CREAT, 0o600)
            os.close(fd)
        os.chmod(self.database, 0o600)
        with self.connect() as db:
            db.executescript('''CREATE TABLE IF NOT EXISTS google_quota_requests (
                id TEXT PRIMARY KEY, project TEXT NOT NULL, bucket TEXT NOT NULL,
                model TEXT NOT NULL, task TEXT NOT NULL, started REAL NOT NULL,
                day TEXT NOT NULL, tokens INTEGER NOT NULL);
                CREATE INDEX IF NOT EXISTS google_quota_usage
                ON google_quota_requests(project,bucket,started);
                CREATE TABLE IF NOT EXISTS google_quota_blocks (
                project TEXT NOT NULL, bucket TEXT NOT NULL, until REAL NOT NULL,
                PRIMARY KEY(project,bucket));
                CREATE TABLE IF NOT EXISTS google_ai_cache (
                project TEXT NOT NULL, key TEXT NOT NULL, value TEXT NOT NULL,
                PRIMARY KEY(project,key));''')
        os.chmod(self.database, 0o600)

    @contextmanager
    def connect(self):
        db = connect_state(self.database, timeout=10)
        db.row_factory = sqlite3.Row
        try:
            with db:
                yield db
        finally:
            db.close()

    def reserve(self, task, tokens, *, api='generate', model=None, background=False, excluded=(),
                excluded_models=(), preferred_model=None):
        if type(tokens) is not int or tokens < 1:
            raise QuotaConfigurationError('Positive input token reservation required')
        eligible = [item for item in self.models if task in item.tasks and item.api == api
                    and (model is None or item.model == model) and item.bucket not in excluded
                    and item.model not in excluded_models]
        if not eligible:
            raise QuotaConfigurationError('No qualified free model configured for this task')
        now = self.clock()
        day, reset = pacific_day(now)
        waits, ready = [], []
        with self.connect() as db:
            db.execute('BEGIN IMMEDIATE')
            # Bound ledger size; older days are unnecessary for budget enforcement.
            db.execute('DELETE FROM google_quota_requests WHERE started<?', (now - 3 * 86400,))
            for position, item in enumerate(eligible):
                if tokens > item.tpm:
                    continue
                rows = db.execute('SELECT started,day,tokens FROM google_quota_requests '
                                  'WHERE project=? AND bucket=? AND (day=? OR started>?) '
                                  'ORDER BY started',
                                  (self.project, item.bucket, day, now - 60)).fetchall()
                today = sum(row['day'] == day for row in rows)
                minute = [row for row in rows if row['started'] > now - 60]
                block = db.execute('SELECT MAX(until) AS until FROM google_quota_blocks '
                                   'WHERE project=? AND bucket IN (?,?)',
                                   (self.project, item.bucket, 'model:' + item.model)).fetchone()
                available = max(now, block['until'] or now)
                cap = item.rpd - (item.reserve_rpd if background else 0)
                if today >= cap:
                    available = max(available, reset)
                count, used = len(minute), sum(row['tokens'] for row in minute)
                for row in minute:
                    if count < item.rpm and used + tokens <= item.tpm:
                        break
                    count -= 1
                    used -= row['tokens']
                    available = max(available, row['started'] + 60.01)
                if available > now:
                    waits.append(available)
                else:
                    # Prefer a task's dedicated capacity, then balance the remaining pool.
                    ready.append((len(set(item.tasks)) != 1, item.model != preferred_model,
                                  today / cap, position, item))
            if not ready:
                if not waits:
                    raise QuotaConfigurationError('Input exceeds every qualified model token budget')
                raise QuotaDeferred(min(waits))
            item = min(ready, key=lambda value: value[:4])[4]
            ticket = uuid4().hex
            db.execute('INSERT INTO google_quota_requests VALUES (?,?,?,?,?,?,?,?)',
                       (ticket, self.project, item.bucket, item.model, task, now, day, tokens))
        return item, ticket

    def _account(self, ticket, data):
        usage = data.get('usageMetadata') or data.get('usage') or {}
        actual = usage.get('promptTokenCount', usage.get('inputTokenCount')) if isinstance(usage, dict) else None
        if type(actual) is int and actual > 0:
            with self.connect() as db:
                db.execute('UPDATE google_quota_requests SET tokens=? WHERE id=?', (actual, ticket))

    def block(self, item, response):
        now = self.clock()
        until = now + (60 if response.status_code == 429 else 30)
        try:
            details = response.json().get('error', {}).get('details', [])
            for detail in details:
                if not isinstance(detail, dict):
                    continue
                for violation in detail.get('violations', []):
                    metric = str(violation.get('quotaMetric', '')) + str(violation.get('quotaId', ''))
                    if re.search(r'per.?day|daily', metric, re.I):
                        until = max(until, pacific_day(now)[1])
                retry = detail.get('retryDelay')
                if isinstance(retry, str) and re.fullmatch(r'\d+(?:\.\d+)?s', retry):
                    delay = float(retry[:-1])
                    if math.isfinite(delay):
                        until = max(until, now + min(delay, 86400))
        except (ValueError, TypeError, AttributeError):
            pass
        self._set_block(item.bucket if response.status_code == 429 else 'model:' + item.model, until)
        return until

    def _set_block(self, key, until):
        with self.connect() as db:
            db.execute('INSERT INTO google_quota_blocks VALUES (?,?,?) '
                       'ON CONFLICT(project,bucket) DO UPDATE SET until=MAX(until,excluded.until)',
                       (self.project, key, until))

    def request(self, task, payload, *, session, api_key, api='generate', model=None,
                background=False, preferred_model=None, validate=None):
        """Try qualified models within their budgets; never sleep or buy capacity."""
        excluded, paused, waits = set(), {}, []
        missing, failure = False, None
        eligible = [item for item in self.models if task in item.tasks and item.api == api
                    and (model is None or item.model == model)]
        tokens = input_token_bound(payload)
        while any(item.bucket not in excluded and item.model not in paused for item in eligible):
            try:
                item, ticket = self.reserve(task, tokens, api=api, model=model,
                                            background=background, excluded=excluded,
                                            excluded_models=paused, preferred_model=preferred_model)
            except QuotaDeferred as exc:
                raise QuotaDeferred(min([exc.retry_at, *waits, *paused.values()])) from None
            except QuotaConfigurationError:
                if waits:
                    raise QuotaDeferred(min([*waits, *paused.values()])) from None
                raise
            body = dict(payload)
            name = quote(item.model, safe='-._')
            if api == 'decide':
                endpoint = 'https://generativelanguage.googleapis.com/v1beta/decisions'
                body['model'] = item.model
            else:
                method = 'embedContent' if api == 'embed' else 'generateContent'
                endpoint = f'https://generativelanguage.googleapis.com/v1beta/models/{name}:{method}'
                if api == 'embed':
                    body['model'] = 'models/' + item.model
            try:
                response = session.post(endpoint, headers={'x-goog-api-key': api_key},
                                        timeout=(10, 30 if api == 'generate_choice' else 60), json=body)
            except Exception:
                # Unknown transport outcome still consumes the reservation.
                failure = GoogleProviderError('Google request transport failed')
            else:
                if response.status_code == 429:
                    waits.append(self.block(item, response))
                    excluded.add(item.bucket)
                    continue
                if response.status_code == 503:
                    paused[item.model] = self.block(item, response)
                    waits.append(paused[item.model])
                    continue
                if response.status_code == 404:
                    missing = True
                    paused[item.model] = self.clock() + 900
                    self._set_block('model:' + item.model, paused[item.model])
                    continue
                if response.status_code != 200:
                    raise GoogleProviderError(f'Google model {item.model} failed (HTTP {response.status_code})')
                try:
                    data = response.json()
                    if not isinstance(data, dict):
                        raise ValueError
                except (ValueError, TypeError):
                    failure = GoogleProviderError('Google returned invalid JSON')
                else:
                    self._account(ticket, data)
                    try:
                        return (validate(data) if validate is not None else data), item.model
                    except GoogleProviderError as exc:
                        failure = exc
            if validate is None:
                raise failure from None
            paused[item.model] = self.clock() + 30
            self._set_block('model:' + item.model, paused[item.model])
        if waits:
            raise QuotaDeferred(min([*waits, *paused.values()]))
        if failure is not None:
            raise failure
        if missing:
            raise GoogleModelUnavailable('Configured Google models unavailable (HTTP 404)')
        raise QuotaConfigurationError('No qualified free model configured for this task')

    def cached(self, key):
        return self.cached_many([key]).get(key)

    def cached_many(self, keys):
        keys = list(dict.fromkeys(keys))
        if not keys:
            return {}
        if len(keys) > 256:
            raise ValueError('Cache lookup is limited to 256 keys')
        placeholders = ','.join('?' for _ in keys)
        with self.connect() as db:
            rows = db.execute(f'SELECT key,value FROM google_ai_cache WHERE project=? AND key IN ({placeholders})',
                              (self.project, *keys)).fetchall()
        decoded = {}
        for row in rows:
            value = row['value']
            if isinstance(value, str) and value.startswith('zlib:'):
                value = zlib.decompress(base64.b64decode(value[5:]))
            elif isinstance(value, bytes):
                value = zlib.decompress(value)
            decoded[row['key']] = json.loads(value)
        return decoded

    def status(self):
        now = self.clock()
        day, reset = pacific_day(now)
        result = []
        with self.connect() as db:
            for item in self.models:
                rows = db.execute('SELECT day,started,tokens FROM google_quota_requests '
                    'WHERE project=? AND bucket=? AND (day=? OR started>?)',
                    (self.project,item.bucket,day,now-60)).fetchall()
                used = sum(row['day'] == day for row in rows)
                minute = [row for row in rows if row['started'] > now-60]
                block = db.execute('SELECT until FROM google_quota_blocks WHERE project=? AND bucket=?',
                    (self.project,item.bucket)).fetchone()
                result.append({'model':item.model,'bucket':item.bucket,'tasks':list(item.tasks),
                    'rpd_limit':item.rpd,'rpd_used':used,'rpd_remaining':max(0,item.rpd-used),
                    'rpm_used':len(minute),'rpm_limit':item.rpm,
                    'tpm_used':sum(row['tokens'] for row in minute),'tpm_limit':item.tpm,
                    'reset_at':reset,'blocked_until':max(now,block['until']) if block else None})
        return result

    def cache(self, key, value):
        with self.connect() as db:
            db.execute('INSERT INTO google_ai_cache VALUES (?,?,?) '
                       'ON CONFLICT(project,key) DO UPDATE SET value=excluded.value',
                       (self.project, key, 'zlib:' + base64.b64encode(zlib.compress(json.dumps(value,
                           ensure_ascii=False, allow_nan=False).encode())).decode('ascii')))
            # Bounded local cache; older evicted results can be recomputed on demand.
            db.execute('DELETE FROM google_ai_cache WHERE project=? AND rowid NOT IN '
                       '(SELECT rowid FROM google_ai_cache WHERE project=? ORDER BY rowid DESC LIMIT 20000)',
                       (self.project, self.project))


def configured_model_budgets():
    """Read the verified configuration without opening or changing the ledger."""
    if os.getenv('GOOGLE_FREE_ROUTING', '').strip() != '1':
        return ()
    try:
        values = json.loads(os.getenv('GOOGLE_FREE_MODELS', ''))
        if not isinstance(values, list):
            raise ValueError
    except ValueError:
        raise QuotaConfigurationError('GOOGLE_FREE_MODELS must be a verified model-budget array') from None
    base = tuple(ModelBudget.parse(value) for value in values)
    try:
        matching = json.loads(os.getenv('GOOGLE_FREE_MATCH_MODELS', '[]'))
        if not isinstance(matching, list):
            raise ValueError
    except ValueError:
        raise QuotaConfigurationError('GOOGLE_FREE_MATCH_MODELS must be a verified model-budget array') from None
    additions = tuple(ModelBudget.parse(value) for value in matching)
    if any(set(item.tasks) != {'match'} for item in additions):
        raise QuotaConfigurationError('Additional Google models must serve catalog matching only')
    existing = {item.model for item in base}
    return base + tuple(item for item in additions if item.model not in existing)


def configured_router():
    if os.getenv('GOOGLE_FREE_ROUTING', '').strip() != '1':
        return None
    path = (os.getenv('PIPELINE_DB_PATH', '') if wordpress_state()
            else os.getenv('GOOGLE_QUOTA_DB_PATH', '')).strip()
    project = os.getenv('GOOGLE_QUOTA_PROJECT', '').strip()
    if not path or not os.path.isabs(path):
        raise QuotaConfigurationError('Persistent absolute GOOGLE_QUOTA_DB_PATH required')
    return FreeGoogleRouter(path, project, configured_model_budgets())
