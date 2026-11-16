"""Photos that arrive with a supplier post.

A Telegram album is several messages that share one media_group_id. The
caption, and therefore the products, may be on a different message from the
photo. The file id is kept on the product row so the shop can ask this
service for the bytes without exposing the bot token.
"""

from __future__ import annotations

import re
from typing import Any

_FILE_ID = re.compile(r"^[A-Za-z0-9_-]{8,300}$")


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
