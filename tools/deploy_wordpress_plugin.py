#!/usr/bin/env python3
"""Deploy committed MolaviShop plugin code through cPanel UAPI.

The working tree must be clean and its HEAD must equal the current branch's
Git upstream. Private runtime-config.php and binary assets are never uploaded.
Before the first write, existing remote files are copied to /tmp for rollback.
"""

from __future__ import annotations

import argparse
import base64
from concurrent.futures import ThreadPoolExecutor
import hashlib
import json
import os
from pathlib import Path
import re
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
SITE_DOCUMENT_ROOTS = {
    "production": "public_html",
    "staging": "public_html/staging",
}


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


def plugin_files(since: str | None) -> list[str]:
    tracked = git("ls-files", "--", str(PLUGIN_PREFIX)).splitlines()
    names = tracked if since is None else git("diff", "--name-only", f"{since}..HEAD").splitlines()
    files = []
    for name in names:
        path = Path(name)
        if not name.startswith(f"{PLUGIN_PREFIX}/") or path.suffix.lower() not in DEPLOYABLE_SUFFIXES:
            continue
        if name == PRIVATE_CONFIG:
            if since is not None:
                raise RuntimeError("Refusing to deploy the private runtime configuration")
            continue
        if name not in tracked:
            raise RuntimeError(f"Plugin file is absent from the tracked source: {name}")
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
    for tag in ("head", "header"):
        opening = re.search(r"<" + tag + r"\b[^>]*>", remote, flags=re.IGNORECASE)
        if opening is None or not remote.startswith(marker, opening.end()):
            continue
        normalized = remote[:opening.end()] + remote[opening.end() + len(marker):]
        if file_sha(normalized) == file_sha(source):
            return True, True
    return False, False


def remote_location(remote_root: str, tracked_name: str) -> tuple[str, str]:
    relative = Path(tracked_name).relative_to(PLUGIN_PREFIX)
    directory = remote_root if str(relative.parent) == "." else remote_root + "/" + str(relative.parent)
    return directory, relative.name


def deployment_location(username: str, site: str, name: str) -> tuple[str, str]:
    content = f"/home/{username}/{SITE_DOCUMENT_ROOTS[site]}/wp-content"
    if name.startswith(f"{PLUGIN_PREFIX}/runtime-host/"):
        return f"{content}/mu-plugins", Path(name).name
    return remote_location(f"{content}/plugins/molavi-shop", name)


def read_hosted_files(base_url: str, auth: str, username: str, site: str,
                      files: list[str]) -> dict[str, str | None]:
    def read(name: str) -> tuple[str, str | None]:
        directory, filename = deployment_location(username, site, name)
        params = {"dir": directory, "file": filename}
        try:
            payload = uapi_request(base_url, auth, "get_file_content", params)
        except RuntimeError:
            payload = uapi_request(base_url, auth, "get_file_content", params)
        content = None if payload.get("_missing") else extract_file_content(payload)
        if content is None and not payload.get("_missing"):
            raise RuntimeError(f"cPanel did not return readable content for {site}: {name}")
        return name, content

    with ThreadPoolExecutor(max_workers=4) as pool:
        return dict(pool.map(read, files))


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    scope = parser.add_mutually_exclusive_group()
    scope.add_argument("--since", help="Git ref before the plugin changes to deploy (default: HEAD^)")
    scope.add_argument("--full", action="store_true", help="Reconcile all tracked files in the selected component")
    scope.add_argument("--promote-staging", action="store_true",
                       help="Check the entire hosted Staging release against Git before deploying to production")
    parser.add_argument("--site", choices=sorted(SITE_DOCUMENT_ROOTS),
                        help="cPanel WordPress installation (default: staging, or production for a promotion)")
    parser.add_argument("--component", choices=["shop", "runtime", "all"],
                        help="Store plugin or runtime MU-plugin (default: both for a promotion, otherwise shop)")
    args = parser.parse_args()
    site = args.site or ("production" if args.promote_staging else "staging")
    component = args.component or ("all" if args.promote_staging else "shop")
    if args.promote_staging and (site != "production" or component != "all"):
        parser.error("--promote-staging deploys both components to production")

    try:
        root, revision = require_synced_git_tree()
        since = None if args.full or args.promote_staging else (args.since or "HEAD^")
        files = plugin_files(since)
        if component != "all":
            wants_runtime = component == "runtime"
            files = [name for name in files
                     if name.startswith(f"{PLUGIN_PREFIX}/runtime-host/") == wants_runtime]
        if not files:
            raise RuntimeError("No files for this component")
        source = {name: (root / name).read_text(encoding="utf-8") for name in files}
        base_url = (os.environ.get("MOLAVISALE_CPANEL_URL") or "").strip()
        username = (os.environ.get("MOLAVISALE_CPANEL_USERNAME") or "").strip()
        password = os.environ.get("MOLAVISALE_CPANEL_PASSWORD") or ""
        if not base_url or not username or not password:
            raise RuntimeError("cPanel UAPI environment credentials are unavailable")
        if urllib.parse.urlparse(base_url).scheme != "https":
            raise RuntimeError("MOLAVISALE_CPANEL_URL must use HTTPS")
        auth = "Basic " + base64.b64encode(f"{username}:{password}".encode("utf-8")).decode("ascii")
        if args.promote_staging:
            staging = read_hosted_files(base_url, auth, username, "staging", files)
            drift = [name for name in files if staging[name] is None
                     or not matches_live_source(staging[name], source[name])[0]]
            if drift:
                raise RuntimeError("Hosted Staging differs from Git. Reconcile these files before promotion: "
                                   + ", ".join(drift))
            print(f"Verified {len(files)} hosted Staging files against {revision}", file=sys.stderr, flush=True)

        if component in {"runtime", "all"} and not args.promote_staging:
            # Fileman mkdir is idempotent on the host; verify all file writes below as usual.
            try:
                uapi_request(base_url, auth, "mkdir", {
                    "path": f"/home/{username}/{SITE_DOCUMENT_ROOTS[site]}/wp-content", "name": "mu-plugins",
                })
            except RuntimeError:
                pass
        remote = read_hosted_files(base_url, auth, username, site, files)

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
            local = source[name]
            if remote[name] is not None and matches_live_source(remote[name], local)[0]:
                unchanged.append(name)
                continue
            directory, filename = deployment_location(username, site, name)
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
            "site": site,
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
