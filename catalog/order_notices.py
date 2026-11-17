"""Bot-only dispatch of order notices produced by the native WordPress worker."""
from __future__ import annotations
import re
from .orders import format_order_notice


def dispatch_order_notice(payload, *, emit, allowed_chats, telegram_chats,
                          telegram_enabled, bale_enabled):
    order = payload.get('order')
    key = payload.get('dispatch_key')
    if (payload.get('type') != 'order_notice' or not isinstance(order, dict)
            or not str(order.get('id') or '').strip() or not isinstance(key, str)
            or not re.fullmatch(r'[a-f0-9]{64}', key)):
        raise ValueError('invalid order notice dispatch')
    text = format_order_notice(order)
    count = 0
    for channel, enabled, recipients in (
            ('telegram', telegram_enabled, telegram_chats),
            ('bale', bale_enabled, allowed_chats.get('bale', set()))):
        if not enabled:
            continue
        trusted = allowed_chats.get(channel, set())
        for chat in sorted(set(map(str, recipients))):
            if chat in trusted and emit('order:' + key, chat, text, channel):
                count += 1
    return {'delivered': True, 'queued': count}
