"""Compatibility transport for old clients of the bot URL.

WordPress is the sole commerce executor in WordPress-hosted mode. The old URLs
authenticate as before and forward to the same protected handlers as the site.
"""
from __future__ import annotations

import hashlib
import hmac
import os
from urllib.parse import urlparse

import requests


_OWNER_METHODS = {
    '/pipeline/operations-dashboard': {'GET'},
    '/pipeline/migration-shadow': {'GET'},
    '/pipeline/telegram-operator-chats': {'POST'},
    '/pipeline/reconcile-publication-backlog': {'POST'},
    '/pipeline/reconciliation': {'GET'},
    '/pipeline/product-status-snapshots': {'GET', 'POST'},
    '/pipeline/paid-orders': {'GET', 'POST'},
    '/pipeline/stock-lots': {'GET', 'POST'},
    '/pipeline/stock-lots/purchase-date': {'POST'},
    '/pipeline/stock-lot-actions': {'GET', 'POST'},
    '/pipeline/clearance-proposals': {'GET', 'POST'},
    '/pipeline/sheet-conflicts': {'GET', 'POST'},
    '/pipeline/supplier-price-evidence': {'GET', 'POST'},
    '/pipeline/ai-confirmations': {'GET', 'POST'},
    '/pipeline/publication-holds': {'GET', 'POST'},
    '/reference/decisions': {'GET', 'POST'},
    '/reference/snapshots': {'GET', 'POST'},
    '/reference/item-reviews': {'GET', 'POST'},
}
_OPERATIONS_SCOPE = {'/pipeline/reconcile-publication-backlog', '/pipeline/reconciliation'}
_BRIDGE_ONLY = {
    '/pipeline/paid-orders', '/pipeline/stock-lots', '/pipeline/stock-lots/purchase-date',
    '/pipeline/stock-lot-actions', '/pipeline/clearance-proposals',
}
_COMMERCE = {
    ('GET', '/api/shop'): 'catalog',
    ('POST', '/api/cart'): 'cart',
    ('POST', '/shop/publish'): 'publish',
    ('POST', '/shop/order'): 'order',
}


def _relay(endpoint, payload):
    from flask import jsonify

    site = (os.getenv('SHOP_SITE_URL') or '').rstrip('/')
    token = os.getenv('REFERENCE_BRIDGE_TOKEN') or ''
    parsed = urlparse(site)
    if (parsed.scheme != 'https' or parsed.hostname not in {'molavisale.ir', 'staging.molavisale.ir'}
            or parsed.port not in {None, 443} or parsed.username or parsed.password or parsed.path
            or parsed.params or parsed.query or parsed.fragment or len(token) < 32):
        return jsonify({'ok': False, 'error': 'wordpress_workflow_unconfigured'}), 503
    mac = hmac.new(token.encode(), b'molavi-runtime-v1', hashlib.sha256).hexdigest()
    try:
        reply = requests.post(
            site+'/wp-json/molavi-workflow/v1/'+endpoint, json=payload,
            headers={'X-Molavi-Runtime-Token': mac}, timeout=(5, 130),
            allow_redirects=False,
        )
        result = reply.json()
        if (reply.status_code < 200 or 300 <= reply.status_code < 400
                or not isinstance(result, dict)
                or reply.headers.get('X-Molavi-Executor') != 'wordpress'):
            raise ValueError('WordPress response was not a workflow receipt')
    except (ValueError, requests.RequestException):
        return jsonify({'ok': False, 'error': 'wordpress_workflow_unavailable'}), 503
    return jsonify(result), reply.status_code, {'Cache-Control': 'no-store', 'X-Molavi-Executor': 'wordpress'}


def wordpress_workflow_request():
    from flask import jsonify, request
    from catalog.state import wordpress_state

    if not wordpress_state():
        return None
    path = request.path
    method = 'GET' if request.method == 'HEAD' else request.method
    commerce = _COMMERCE.get((method, path))
    if commerce:
        from app import _shop_token_ok

        if commerce in {'order', 'publish'} and not _shop_token_ok():
            body = ({'status': 'error', 'message': 'unauthorized'} if commerce == 'order'
                    else {'ok': False, 'error': 'unauthorized'})
            return jsonify(body), 401
        return _relay('legacy-commerce', {'route': commerce, 'body': request.get_json(silent=True)})
    if path == '/desk/refresh' and method == 'POST':
        return jsonify({'status': 'retired', 'workflow': '/wp-admin/admin.php?page=molavi-operations'}), 410
    if method not in _OWNER_METHODS.get(path, ()):
        return None

    from app import _owner_api_actor, _reference_browser_actor

    token = os.getenv('REFERENCE_BRIDGE_TOKEN') or ''
    owners = {part.strip() for part in (os.getenv('REFERENCE_OWNER_IDS') or '').split(',') if part.strip()}
    if len(token) < 32 or not owners:
        return jsonify({'ok': False, 'error': 'unconfigured'}), 503
    sent = request.headers.get('X-Molavi-Reference-Token', '')
    if path in _BRIDGE_ONLY or (path == '/reference/snapshots' and hmac.compare_digest(sent, token)):
        if not sent or not hmac.compare_digest(sent, token):
            return jsonify({'ok': False, 'error': 'unauthorized'}), 401
        actor = request.headers.get('X-Molavi-Actor', '')
        error = None if actor in owners else 403
    elif path == '/reference/snapshots':
        actor = _reference_browser_actor(request.headers.get('Authorization', ''), token, owners)
        error = None if actor else 401
    else:
        scope = '/pipeline/operations-dashboard' if path in _OPERATIONS_SCOPE else path
        actor, error = _owner_api_actor('owner:'+scope, token, owners)
    if error:
        return jsonify({'ok': False, 'error': 'owner_required' if error == 403 else 'unauthorized'}), error
    query = request.args.to_dict()
    if 'channel' in query:
        query['channel'] = request.args.getlist('channel')
    return _relay('request', {'method': method, 'path': path, 'actor': actor,
                             'query': query, 'body': request.get_json(silent=True)})
