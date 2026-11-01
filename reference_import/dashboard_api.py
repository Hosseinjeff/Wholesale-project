"""Private WSGI bridge for a trusted WordPress backend; not a browser-facing API."""
import hmac
import json
from .decisions import DecisionConflict


class DashboardAPI:
    def __init__(self, gate, *, bridge_token):
        if not isinstance(bridge_token, str) or len(bridge_token) < 32:
            raise ValueError('Dedicated bridge token of at least 32 characters required')
        self.gate = gate
        self.bridge_token = bridge_token

    def handle(self, method, *, token, actor, body=None):
        if not isinstance(token, str) or not hmac.compare_digest(token, self.bridge_token):
            return 401, {'ok': False, 'error': 'unauthorized'}
        if actor not in self.gate.owner_ids:
            return 403, {'ok': False, 'error': 'owner_required'}
        if method == 'GET':
            return 200, {'ok': True, 'events': self.gate.pending()}
        if method != 'POST':
            return 405, {'ok': False, 'error': 'method'}
        if not isinstance(body, dict) or set(body) != {'event_id', 'snapshot_id', 'choice'}:
            return 400, {'ok': False, 'error': 'invalid_body'}
        if (not isinstance(body['event_id'], str) or not 0 < len(body['event_id']) <= 300
                or body['choice'] not in ('pause_review', 'use_last_snapshot')
                or (body['snapshot_id'] is not None and
                    (not isinstance(body['snapshot_id'], str) or not 0 < len(body['snapshot_id']) <= 300))):
            return 400, {'ok': False, 'error': 'invalid_fields'}
        try:
            self.gate.choose(body['event_id'], actor_id=actor, choice=body['choice'],
                             expected_snapshot_id=body['snapshot_id'])
        except DecisionConflict:
            return 409, {'ok': False, 'error': 'stale_or_conflicting_decision'}
        except ValueError:
            return 400, {'ok': False, 'error': 'invalid_choice'}
        return 200, {'ok': True, 'event_id': body['event_id'], 'choice': body['choice'],
                     'snapshot_id': body['snapshot_id']}

    def __call__(self, environ, start_response):
        status, result = 404, {'ok': False, 'error': 'not_found'}
        if environ.get('PATH_INFO') in ('/decisions', '/decisions/'):
            try:
                size = int(environ.get('CONTENT_LENGTH') or '0')
                if not 0 <= size <= 4096:
                    raise ValueError('Body limit')
                method = environ.get('REQUEST_METHOD', '')
                raw = environ['wsgi.input'].read(size) if size else b''
                body = json.loads(raw) if raw else None
                status, result = self.handle(method,
                    token=environ.get('HTTP_X_MOLAVI_REFERENCE_TOKEN', ''),
                    actor=environ.get('HTTP_X_MOLAVI_ACTOR', ''), body=body)
            except (ValueError, UnicodeDecodeError):
                status, result = 400, {'ok': False, 'error': 'invalid_request'}
            except Exception:
                # Never expose credentials, database paths or internal exceptions.
                status, result = 503, {'ok': False, 'error': 'decision_store_unavailable'}
        names = {200:'OK', 400:'Bad Request', 401:'Unauthorized', 403:'Forbidden',
                 404:'Not Found', 405:'Method Not Allowed', 409:'Conflict', 503:'Service Unavailable'}
        payload = json.dumps(result, ensure_ascii=False).encode()
        start_response(f'{status} {names[status]}', [('Content-Type','application/json; charset=utf-8'),
                       ('Cache-Control','no-store'), ('Content-Length',str(len(payload)))])
        return [payload]
