"""Photos that arrive with a supplier post.

A Telegram album is several messages that share one media_group_id. The
caption, and therefore the products, may be on a different message from the
photo. The file id is kept on the product row so the shop can ask this
service for the bytes without exposing the bot token.
"""

from __future__ import annotations

import hashlib
import json
import os
import re
from typing import Any

_FILE_ID = re.compile(r"^[A-Za-z0-9_-]{8,300}$")
_TELEGRAM_PATH = re.compile(r"^[A-Za-z0-9_./-]{1,512}$")


def safe_file_id(value: Any) -> str:
    text = str(value or "")
    if _FILE_ID.fullmatch(text):
        return text
    return ""


def largest_photo(message: dict[str, Any]) -> dict[str, Any] | None:
    """The biggest photo on a message, or an image sent as a document."""
    best: dict[str, Any] | None = None
    best_size = -1
    for photo in message.get("photo") or []:
        if not isinstance(photo, dict) or not photo.get("file_id"):
            continue
        size = int(photo.get("file_size") or 0) or int(photo.get("width") or 0) * int(photo.get("height") or 0)
        if size >= best_size:
            best = photo
            best_size = size
    if best:
        return {
            "file_id": str(best.get("file_id") or ""),
            "file_unique_id": str(best.get("file_unique_id") or ""),
        }
    document = message.get("document") or {}
    if isinstance(document, dict) and str(document.get("mime_type") or "").startswith("image/"):
        file_id = str(document.get("file_id") or "")
        if file_id:
            return {
                "file_id": file_id,
                "file_unique_id": str(document.get("file_unique_id") or ""),
            }
    return None


def stamp_inbound(data: dict[str, Any], message: dict[str, Any]) -> dict[str, Any]:
    """Copy the post photo and the chat kind onto the sheet payload."""
    chat = message.get("chat") or {}
    if isinstance(chat, dict):
        data["chat_type"] = chat.get("type") or data.get("chat_type") or ""
        if not data.get("chat_id"):
            data["chat_id"] = chat.get("id")
    group = message.get("media_group_id")
    if group:
        data["media_group_id"] = str(group)
    photo = largest_photo(message)
    if photo and safe_file_id(photo["file_id"]):
        data["image_file_id"] = photo["file_id"]
        data["image_file_unique_id"] = photo["file_unique_id"]
        data["has_media"] = True
        if not data.get("media_type"):
            data["media_type"] = "photo"
    return data


def share_album_images(messages: list[Any]) -> None:
    """Give every message in an album the photo from that album."""
    found: dict[str, dict[str, str]] = {}
    for message in messages:
        if not isinstance(message, dict):
            continue
        group = str(message.get("media_group_id") or "")
        file_id = safe_file_id(message.get("image_file_id"))
        if group and file_id and group not in found:
            found[group] = {
                "image_file_id": file_id,
                "image_file_unique_id": str(message.get("image_file_unique_id") or ""),
            }
    for message in messages:
        if not isinstance(message, dict):
            continue
        group = str(message.get("media_group_id") or "")
        if not group or group not in found or safe_file_id(message.get("image_file_id")):
            continue
        message.update(found[group])
        message["has_media"] = True
        if not message.get("media_type"):
            message["media_type"] = "photo"


def album_media_bundle(messages: list[dict[str, Any]]) -> dict[str, Any] | None:
    """Build a stable, bounded source bundle for every image in one Telegram album."""
    rows = [dict(message) for message in messages if isinstance(message, dict)]
    if not rows:
        return None
    group_id = str(rows[0].get("media_group_id") or "").strip()
    channel = str(rows[0].get("channel_username") or "").strip()
    if not group_id or any(str(row.get("media_group_id") or "").strip() != group_id
                           or str(row.get("channel_username") or "").strip() != channel
                           for row in rows):
        return None

    def order(row):
        message_id = str(row.get("message_id") or "")
        try:
            numeric_id = int(message_id)
        except (TypeError, ValueError, OverflowError):
            numeric_id = None
        return (0 if numeric_id is not None else 1,
                numeric_id if numeric_id is not None else str(row.get("timestamp") or ""),
                str(row.get("batch_id") or ""))

    ordered = sorted(rows, key=order)
    unique_rows = []
    seen_messages = set()
    for row in ordered:
        message_id = str(row.get("message_id") or "")
        if message_id and message_id not in seen_messages:
            seen_messages.add(message_id)
            unique_rows.append(row)
    if not unique_rows:
        return None

    images = []
    seen_images = set()
    identity_rows = []
    missing_image_count = 0
    for row in unique_rows:
        file_id = safe_file_id(row.get("image_file_id"))
        unique_id = str(row.get("image_file_unique_id") or "").strip()
        if not file_id:
            missing_image_count += 1
        image_identity = unique_id or file_id
        content = str(row.get("content") or "")
        identity_rows.append([
            str(row.get("message_id") or ''), str(row.get("batch_id") or ''),
            str(row.get("timestamp") or ''), hashlib.sha256(content.encode()).hexdigest(),
            image_identity,
        ])
        if not file_id or image_identity in seen_images:
            continue
        seen_images.add(image_identity)
        images.append({'file_id':file_id, 'unique_id':unique_id or file_id})

    captions = []
    seen_captions = set()
    for row in unique_rows:
        caption = str(row.get("content") or "").strip()
        if caption and caption not in seen_captions:
            seen_captions.add(caption)
            captions.append(caption)
    canonical = next((row for row in unique_rows if str(row.get("content") or "").strip()),
                     unique_rows[0])
    media_identity = 'album:' + hashlib.sha256(json.dumps(
        [group_id, channel, identity_rows], ensure_ascii=False, separators=(',', ':')).encode()).hexdigest()
    return {
        'message_id':str(canonical.get('message_id') or ''),
        'batch_id':str(canonical.get('batch_id') or ''),
        'channel_username':channel,
        'channel':str(canonical.get('channel') or ''),
        'timestamp':str(canonical.get('timestamp') or ''),
        'source_post_url':str(canonical.get('source_post_url') or ''),
        'content':'\n'.join(captions),
        'media_type':'photo_album',
        'media_group_id':group_id,
        'media_identity':media_identity,
        'message_ids':[str(row.get('message_id') or '') for row in unique_rows],
        'group_members':[{
            'message_id':str(row.get('message_id') or ''),
            'batch_id':str(row.get('batch_id') or ''),
            'channel':str(row.get('channel') or ''),
            'channel_username':str(row.get('channel_username') or ''),
        } for row in unique_rows],
        'image_file_ids':[item['file_id'] for item in images],
        'image_file_unique_ids':[item['unique_id'] for item in images],
        'image_count':len(images),
        'missing_image_count':missing_image_count,
    }


def fetch_telegram_image(file_id: Any, *, bot_token=None, session=None, max_bytes=8_000_000):
    """Fetch a bounded Telegram image for server-side OCR without exposing the bot token."""
    safe_id=safe_file_id(file_id)
    token=str(bot_token or os.getenv('TELEGRAM_BOT_TOKEN') or '').strip()
    if not safe_id or not token:
        raise ValueError('telegram_media_unavailable')
    if session is None:
        import requests
        session=requests.Session()
    try:
        info=session.get(f'https://api.telegram.org/bot{token}/getFile',
            params={'file_id':safe_id},timeout=(10,15))
        body=info.json() if info.status_code==200 else {}
        path=str(((body.get('result') or {}).get('file_path') or '') if body.get('ok') else '')
        if (not path or not _TELEGRAM_PATH.fullmatch(path) or path.startswith('/')
                or any(part in {'.','..'} for part in path.split('/'))):
            raise ValueError('telegram_media_unavailable')
        lower=path.lower()
        mime_type=next((mime for suffix,mime in (('.jpg','image/jpeg'),('.jpeg','image/jpeg'),
            ('.png','image/png'),('.webp','image/webp')) if lower.endswith(suffix)), '')
        if not mime_type:
            raise ValueError('telegram_media_unsupported')
        response=session.get(f'https://api.telegram.org/file/bot{token}/{path}',
            timeout=(10,20),stream=True)
        try:
            if response.status_code!=200:
                raise ValueError('telegram_media_unavailable')
            try:
                announced=int(response.headers.get('Content-Length') or 0)
            except (TypeError,ValueError,OverflowError):
                announced=0
            if announced>int(max_bytes):
                raise ValueError('telegram_media_unavailable')
            chunks=[]
            size=0
            for chunk in response.iter_content(chunk_size=64*1024):
                if not chunk:
                    continue
                size+=len(chunk)
                if size>int(max_bytes):
                    raise ValueError('telegram_media_unavailable')
                chunks.append(chunk)
            if not size:
                raise ValueError('telegram_media_unavailable')
            image=b''.join(chunks)
            if mime_type=='image/jpeg' and not image.startswith(b'\xff\xd8\xff'):
                raise ValueError('telegram_media_unsupported')
            if mime_type=='image/png' and not image.startswith(b'\x89PNG\r\n\x1a\n'):
                raise ValueError('telegram_media_unsupported')
            if mime_type=='image/webp' and not (image.startswith(b'RIFF') and image[8:12]==b'WEBP'):
                raise ValueError('telegram_media_unsupported')
            return image,mime_type
        finally:
            close=getattr(response,'close',None)
            if callable(close):
                close()
    except ValueError:
        raise
    except Exception as exc:
        raise ValueError('telegram_media_unavailable') from exc
