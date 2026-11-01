#!/usr/bin/env python3
"""Deploy committed MolaviShop plugin code through cPanel UAPI.

The working tree must be clean and its HEAD must equal the current branch's
Git upstream. Private runtime-config.php and binary assets are never uploaded.
Before the first write, existing remote files are copied to /tmp for rollback.
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import urllib.error
import urllib.parse
import urllib.request
import ssl


PLUGIN_PREFIX = Path("shop-wp/molavi-shop")
PRIVATE_CONFIG = "shop-wp/molavi-shop/includes/runtime-config.php"
DEPLOYABLE_SUFFIXES = {".php", ".js", ".css"}


def git(*args: str) -> str:
    return subprocess.check_output(["git", *args], text=True, stderr=subprocess.DEVNULL).strip()


def require_synced_git_tree() -> tuple[Path, str]:
    root = Path(git("rev-parse", "--show-toplevel"))
    head = git("rev-parse", "HEAD")
    if git("status", "--porcelain"):
        raise RuntimeError("Git working tree has uncommitted changes")
    try:
        upstream = git("rev-parse", "--abbrev-ref", "--symbolic-full-name", "@{upstream}")
        upstream_sha = git("rev-parse", upstream)
    except subprocess.CalledProcessError as exc:
        raise RuntimeError("Current branch has no configured Git upstream") from exc
    if head != upstream_sha:
        raise RuntimeError("Current commit must be pushed to its Git upstream before deployment")
    return root, head


def changed_plugin_files(root: Path, since: str) -> list[str]:
    changed = git("diff", "--name-only", f"{since}..HEAD").splitlines()
    files = []
    for name in changed:
        path = Path(name)
        if not name.startswith(f"{PLUGIN_PREFIX}/") or path.suffix.lower() not in DEPLOYABLE_SUFFIXES:
            continue
        if name == PRIVATE_CONFIG:
            raise RuntimeError("Refusing to deploy the private runtime configuration")
        git("ls-files", "--error-unmatch", name)
        files.append(name)
    if not files:
        raise RuntimeError("No tracked plugin PHP, JavaScript or CSS changes were found")
    return sorted(set(files))


def uapi_request(base_url: str, auth: str, method: str, params: dict[str, str], data: dict[str, str] | None = None) -> dict:
    url = base_url.rstrip("/") + "/execute/Fileman/" + method + "?" + urllib.parse.urlencode(params)
    body = urllib.parse.urlencode(data).encode("utf-8") if data is not None else None
    request = urllib.request.Request(
        url,
        data=body,
        method="POST" if body is not None else "GET",
        headers={
            "Authorization": auth,
            "Accept": "application/json",
            "User-Agent": "MolaviSale-Git-Deployment/1.0",
            **({"Content-Type": "application/x-www-form-urlencoded"} if body is not None else {}),
        },
    )
    opener = urllib.request.build_opener(
        urllib.request.ProxyHandler(),
        urllib.request.HTTPSHandler(context=ssl.create_default_context()),
    )
    try:
        with opener.open(request, timeout=45) as response:
            payload = json.loads(response.read().decode("utf-8"))
    except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError, json.JSONDecodeError) as exc:
        raise RuntimeError(f"cPanel UAPI {method} request failed ({type(exc).__name__})") from None
    result = payload.get("result") if isinstance(payload, dict) else None
    status = result.get("status") if isinstance(result, dict) else payload.get("status") if isinstance(payload, dict) else None
    if status != 1:
        serialized = json.dumps(payload, ensure_ascii=False).lower()
        if method == "get_file_content" and any(token in serialized for token in ("not found", "does not exist", "no such file")):
            return {"_missing": True}
        raise RuntimeError(f"cPanel UAPI {method} returned a failure")
    return payload


def extract_file_content(payload: dict) -> str | None:
    def visit(value):
        if isinstance(value, dict):
            for key in ("content", "file_content", "fileContent"):
                if isinstance(value.get(key), str):
                    return value[key]
            for nested in value.values():
                found = visit(nested)
                if found is not None:
                    return found
        elif isinstance(value, list):
            for nested in value:
                found = visit(nested)
                if found is not None:
                    return found
        return None

    return visit(payload)


def file_sha(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def matches_live_source(remote: str, source: str) -> tuple[bool, bool]:
    if file_sha(remote) == file_sha(source):
        return True, False
    marker = '<meta charset="utf-8">'
    opening = remote.find("<header")
    close = remote.find(">", opening) if opening >= 0 else -1
    if close >= 0 and remote.startswith(marker, close + 1):
        normalized = remote[:close + 1] + remote[close + 1 + len(marker):]
        if file_sha(normalized) == file_sha(source):
            return True, True
    return False, False


def remote_location(remote_root: str, tracked_name: str) -> tuple[str, str]:
    relative = Path(tracked_name).relative_to(PLUGIN_PREFIX)
    directory = remote_root if str(relative.parent) == "." else remote_root + "/" + str(relative.parent)
    return directory, relative.name


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--since", default="HEAD^", help="Git ref before the plugin changes to deploy (default: HEAD^)")
    args = parser.parse_args()

    try:
        root, revision = require_synced_git_tree()
        files = changed_plugin_files(root, args.since)
        base_url = (os.environ.get("MOLAVISALE_CPANEL_URL") or "").strip()
        username = (os.environ.get("MOLAVISALE_CPANEL_USERNAME") or "").strip()
        password = os.environ.get("MOLAVISALE_CPANEL_PASSWORD") or ""
        if not base_url or not username or not password:
            raise RuntimeError("cPanel UAPI environment credentials are unavailable")
        if urllib.parse.urlparse(base_url).scheme != "https":
            raise RuntimeError("MOLAVISALE_CPANEL_URL must use HTTPS")
        auth = "Basic " + base64.b64encode(f"{username}:{password}".encode("utf-8")).decode("ascii")
        remote_dir = f"/home/{username}/public_html/wp-content/plugins/molavi-shop"

        remote: dict[str, str | None] = {}
        for name in files:
            directory, filename = remote_location(remote_dir, name)
            payload = uapi_request(base_url, auth, "get_file_content", {"dir": directory, "file": filename})
            remote[name] = None if payload.get("_missing") else extract_file_content(payload)
            if remote[name] is None and not payload.get("_missing"):
                raise RuntimeError("cPanel did not return readable content for a changed plugin file")

        backup_root = Path(tempfile.mkdtemp(prefix=f"molavi-wordpress-{revision[:8]}-", dir="/tmp"))
        for name, content in remote.items():
            if content is None:
                continue
            backup = backup_root / Path(name).relative_to(PLUGIN_PREFIX)
            backup.parent.mkdir(parents=True, exist_ok=True)
            backup.write_text(content, encoding="utf-8")

        uploaded = []
        unchanged = []
        host_normalized = []
        for name in files:
            local = (root / name).read_text(encoding="utf-8")
            if remote[name] == local:
                unchanged.append(name)
                continue
            directory, filename = remote_location(remote_dir, name)
            uapi_request(
                base_url,
                auth,
                "save_file_content",
                {"dir": directory, "file": filename},
                {"content": local},
            )
            check = uapi_request(base_url, auth, "get_file_content", {"dir": directory, "file": filename})
            saved = extract_file_content(check)
            matched, normalized = matches_live_source(saved or "", local)
            if saved is None or not matched:
                raise RuntimeError("Live read-back did not match the committed plugin source")
            if normalized:
                host_normalized.append(name)
            uploaded.append(name)

        print(json.dumps({
            "revision": revision,
            "uploaded": uploaded,
            "unchanged": unchanged,
            "host_normalized": host_normalized,
            "backup_directory": str(backup_root),
            "verified": True,
        }, ensure_ascii=False))
        return 0
    except Exception as exc:
        print(f"Deployment stopped: {type(exc).__name__}: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
