#!/usr/bin/env python3
"""Deploy the MolaviSale WordPress plugin to staging via cPanel UAPI.

Runs in GitHub Actions on push to deploy/staging (and on manual dispatch).
Uploads every tracked text file under shop-wp/molavi-shop/ except
includes/runtime-config.php, which must never leave the repo. Binary files
(fonts) are skipped: they are already live on the server and UAPI text
saves cannot carry them.

Auth comes from the environment (GitHub secrets in Actions):
  MOLAVISALE_CPANEL_URL       default https://1496118079.cloudylink.com:2083
  MOLAVISALE_CPANEL_USERNAME  required
  MOLAVISALE_CPANEL_PASSWORD  required

UAPI reads of large files truncate on this host, so this script never reads
back large files: saves return tiny responses, and verification is done over
public HTTPS (shop.js and storefront.css byte-exact, homepage HTTP 200).

Usage:
  python3 tools/deploy_staging_uapi.py [--list-only]
"""
import base64
import hashlib
import json
import os
import re
import ssl
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

DEFAULT_URL = "https://1496118079.cloudylink.com:2083"
PLUGIN_DIR = "shop-wp/molavi-shop"
REMOTE_ROOT = "/home/h434558/public_html/staging/wp-content/plugins/molavi-shop"
PUBLIC = "https://staging.molavisale.ir/wp-content/plugins/molavi-shop"
NEVER_DEPLOY = {PLUGIN_DIR + "/includes/runtime-config.php"}


def list_plugin_files():
    out = subprocess.run(
        ["git", "ls-files", PLUGIN_DIR],
        check=True, capture_output=True, text=True, timeout=60,
    )
    files = [line for line in out.stdout.splitlines() if line.strip()]
    return sorted(f for f in files if f not in NEVER_DEPLOY)


def fresh_opener():
    return urllib.request.build_opener(
        urllib.request.ProxyHandler(),
        urllib.request.HTTPSHandler(context=ssl.create_default_context()),
    )


def uapi(base, auth, module, method, params, data=None, tries=4,
         delays=(2, 5, 10, 20)):
    url = base + "/execute/" + module + "/" + method + "?" + urllib.parse.urlencode(params)
    body = urllib.parse.urlencode(data).encode("utf-8") if data is not None else None
    headers = {
        "Authorization": auth,
        "Accept": "application/json",
        "Connection": "close",
        "User-Agent": "MolaviSale-UAPI-Deploy/1.0",
        **({"Content-Type": "application/x-www-form-urlencoded"} if body is not None else {}),
    }
    payload = None
    for attempt in range(1, tries + 1):
        req = urllib.request.Request(
            url, data=body,
            method="POST" if body is not None else "GET", headers=headers)
        try:
            with fresh_opener().open(req, timeout=60) as resp:
                payload = json.loads(resp.read().decode("utf-8"))
            break
        except Exception as exc:
            print(f"  {module}/{method} attempt {attempt}/{tries} failed: "
                  f"{type(exc).__name__}: {exc}", file=sys.stderr)
            if attempt == tries:
                raise
            time.sleep(delays[min(attempt - 1, len(delays) - 1)])
    result = payload.get("result") if isinstance(payload, dict) else None
    status = None
    if isinstance(result, dict):
        status = result.get("status")
    elif isinstance(payload, dict):
        status = payload.get("status")
    if status != 1:
        raise RuntimeError(
            f"UAPI {method} returned failure: "
            f"{json.dumps(payload, ensure_ascii=False)[:500]}")
    return payload


def sha(text):
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def matches(remote, source):
    if sha(remote) == sha(source):
        return True
    marker = '<meta charset="utf-8">'
    for tag in ("head", "header"):
        m = re.search("<" + tag + r"\b[^>]*>", remote, flags=re.IGNORECASE)
        if m is None or not remote.startswith(marker, m.end()):
            continue
        norm = remote[:m.end()] + remote[m.end() + len(marker):]
        if sha(norm) == sha(source):
            return True
    return False


def public_fetch(path, tries=3):
    url = path + ("&" if "?" in path else "?") + f"v={int(time.time())}"
    for attempt in range(1, tries + 1):
        try:
            req = urllib.request.Request(
                url, headers={"Connection": "close", "User-Agent": "MolaviSale-Verify/1.0"})
            with fresh_opener().open(req, timeout=45) as resp:
                return resp.read().decode("utf-8"), resp.status
        except Exception as exc:
            print(f"  public fetch attempt {attempt}/{tries} failed: {type(exc).__name__}",
                  file=sys.stderr)
            if attempt == tries:
                raise
            time.sleep(3 * attempt)
    raise RuntimeError("unreachable")


def main(argv):
    list_only = "--list-only" in argv
    files = list_plugin_files()
    print(f"Plugin files to deploy ({len(files)}):")
    for name in files:
        print(f"  {name}")
    if NEVER_DEPLOY:
        print("Never deployed:")
        for name in sorted(NEVER_DEPLOY):
            print(f"  {name}")
    if list_only:
        return 0

    base = (os.environ.get("MOLAVISALE_CPANEL_URL") or DEFAULT_URL).rstrip("/")
    user = os.environ.get("MOLAVISALE_CPANEL_USERNAME") or ""
    password = os.environ.get("MOLAVISALE_CPANEL_PASSWORD") or ""
    if not user or not password:
        print("MOLAVISALE_CPANEL_USERNAME and MOLAVISALE_CPANEL_PASSWORD "
              "must be set", file=sys.stderr)
        return 1
    auth = "Basic " + base64.b64encode(
        f"{user}:{password}".encode("utf-8")).decode("ascii")

    root = Path(__file__).resolve().parent.parent
    results = {}
    for name in files:
        raw = (root / name).read_bytes()
        try:
            local = raw.decode("utf-8")
        except UnicodeDecodeError:
            print(f"== {name} ({len(raw)} bytes) ==")
            print("  skipped: binary file, already live on the server")
            results[name] = {"uploaded": False, "verified": False,
                             "how": "skipped-binary"}
            continue
        rel = Path(name).relative_to(PLUGIN_DIR)
        directory = REMOTE_ROOT if str(rel.parent) == "." else REMOTE_ROOT + "/" + str(rel.parent)
        print(f"== {name} ({len(local)} chars) ==")
        uploaded = False
        for up in range(1, 4):
            try:
                uapi(base, auth, "Fileman", "save_file_content",
                     {"dir": directory, "file": rel.name},
                     {"content": local}, tries=4, delays=(2, 5, 10, 20))
                print(f"  upload attempt {up}: save accepted")
                uploaded = True
                break
            except Exception as exc:
                print(f"  upload attempt {up} failed: {type(exc).__name__}",
                      file=sys.stderr)
        results[name] = {"uploaded": uploaded, "verified": False, "how": ""}
        if not uploaded:
            continue
        if rel.name in ("shop.js", "storefront.css"):
            try:
                served, _ = public_fetch(PUBLIC + "/" + rel.name)
                if matches(served, local):
                    results[name]["verified"] = True
                    results[name]["how"] = "public-https sha256"
                    print("  VERIFIED via public-https sha256")
                else:
                    print("  WARNING: public copy does not match local file",
                          file=sys.stderr)
            except Exception as exc:
                print(f"  public verify failed: {type(exc).__name__}",
                      file=sys.stderr)

    print("== storefront health ==")
    try:
        _home, status = public_fetch("https://staging.molavisale.ir/")
        print(f"  homepage HTTP {status}")
        health = status == 200
    except Exception as exc:
        print(f"  homepage check failed: {type(exc).__name__}", file=sys.stderr)
        health, status = False, None

    summary = {
        "target": "staging",
        "files": results,
        "homepage_ok": health,
        "homepage_status": status,
    }
    print(json.dumps(summary, ensure_ascii=False))
    deployable = [r for r in results.values() if r["how"] != "skipped-binary"]
    js_css = [r for n, r in results.items()
              if Path(n).name in ("shop.js", "storefront.css")]
    if (not health or not all(r["uploaded"] for r in deployable)
            or not all(r["verified"] for r in js_css)):
        print("DEPLOY NOT FULLY VERIFIED", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main(sys.argv[1:]))
    except Exception as exc:
        print(f"Deploy stopped: {type(exc).__name__}: {exc}", file=sys.stderr)
        raise SystemExit(1)
