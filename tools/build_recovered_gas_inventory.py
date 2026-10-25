"""Inventory the recovered user export without executing its source."""
from __future__ import annotations

import hashlib
import json
import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "legacy-gas/recovered-2026-10-25"
FUNCTION = re.compile(r"^function\s+(\w+)\s*\(", re.M)

GROUPS = {
    "00": ("Configuration, schema and access helpers", "Different configuration/auth contract; map explicitly. Preserve schema meaning and do not copy property values."),
    "10": ("HTTP dispatch and operator/admin actions", "Different endpoints/acknowledgements. Reference and price-role actions lack equivalent Python handlers; B-04/B-07/B-08."),
    "20": ("Inventory mapping, identity and price confirmation", "Inventory-log contract differs from Offers/Products/Items. Preserve provenance and correct B-04/B-06 before selecting ownership."),
    "30": ("Reference acquisition, matching and price-role inference", "No equivalent reference integration found in current Python. Preserve or deliberately retain with upstream-server audit; B-01/B-02/B-03."),
    "40": ("Owned stock, offer selection and storefront pricing", "Different authority and fixed-markup pricing. Reconcile with basket discounts; fix selected-offer inconsistency B-05."),
    "50": ("Message ingestion, batches, retries and status", "Related Python ingestion exists, but storage, AI queues and replies differ. Compare contracts; historical parser tests do not prove this export equivalent."),
    "60": ("AI extraction, regex parsers and quality checks", "Python has regex/quality helpers but no equivalent AI/reference queue. Existing historical fixtures did not validate this newer export's parsers."),
    "70": ("Sheet setup, maintenance and diagnostics", "Classify needed operations separately from tests/debug/destructive maintenance. Do not auto-port or run setup/replay functions."),
}


def build() -> None:
    old = set(FUNCTION.findall((ROOT / "google_apps_script.js").read_text()))
    objects = {
        line.split(" ", 1)[0]
        for line in subprocess.check_output(
            ["git", "rev-list", "--objects", "--all"], cwd=ROOT, text=True
        ).splitlines()
    }
    manifest = json.loads((SOURCE / "manifest.json").read_text())
    expected = {entry["path"]: entry["sha256"] for entry in manifest["files"]}
    all_names: set[str] = set()
    sections: list[str] = []
    file_evidence: list[dict] = []
    for file in sorted(SOURCE.glob("*.js")):
        data = file.read_bytes()
        sha256 = hashlib.sha256(data).hexdigest()
        if sha256 != expected[file.name]:
            raise ValueError(f"Uploaded source hash changed: {file.name}")
        text = data.decode("utf-8")
        functions = [
            (m[1], text.count("\n", 0, m.start()) + 1)
            for m in FUNCTION.finditer(text)
        ]
        names = {name for name, _ in functions}
        if all_names & names:
            raise ValueError("Duplicate top-level function names")
        all_names.update(names)
        blob = hashlib.sha1(b"blob " + str(len(data)).encode() + b"\0" + data).hexdigest()
        title, disposition = GROUPS[file.name[:2]]
        file_evidence.append({
            "file": file.name, "bytes": len(data), "sha256": sha256,
            "function_count": len(functions), "exact_blob_in_fetched_history": blob in objects,
        })
        lines = [f"## {file.name}", "", title + ".", "", disposition, "",
                 "| Function | Source line | Name present in root GAS |", "|---|---|---|"]
        lines += [f"| `{name}` | {line} | {'Yes; semantics may differ' if name in old else 'No'} |"
                  for name, line in functions]
        sections.append("\n".join(lines))
    intro = f"""# Recovered GAS structural inventory

Revision 4.0. Received/inspected 2026-10-25, Asia/Tehran.

Eight original files, {len(all_names)} unique named top-level functions. This is a
complete declaration inventory with feature-level dispositions, **not** proof that
every function or externally bound job has semantic parity with Python. Source line
numbers are local to each original file in `legacy-gas/recovered-2026-10-25/`.

The root GAS has {len(old)} named functions: {len(old & all_names)} names overlap,
{len(all_names - old)} are additional in this export, and {len(old - all_names)} old
names are absent. A name appearing in both versions says nothing about live use or
equivalence. Those additional names are not automatically that many missing business
features. No exact uploaded file blob exists in the fetched Git objects.

Removed/replaced old declarations: {', '.join('`'+n+'`' for n in sorted(old-all_names))}.
Do not infer lost behavior solely from a renamed or replaced function.

See `recovered-gas-audit.md` for the reference schema, behavior, migration map,
18 offline observation groups and remaining closure. Refer to the separate historical
inventory for the 67 root GAS functions. Neither inventory proves installed triggers,
Script Properties values, deployment access or the external refresh server.
"""
    (ROOT / "docs/recovered-gas-inventory.md").write_text(intro + "\n\n" + "\n\n".join(sections) + "\n")
    evidence = {
        "function_count": len(all_names), "root_function_count": len(old),
        "shared_names": len(old & all_names), "additional_names": len(all_names - old),
        "old_names_absent": sorted(old - all_names), "files": file_evidence,
    }
    (ROOT / "docs/recovered-gas-source-evidence.json").write_text(json.dumps(evidence, indent=2) + "\n")
    print(json.dumps({"files": len(file_evidence), "functions": len(all_names), "hashes_verified": True}))


if __name__ == "__main__":
    build()
