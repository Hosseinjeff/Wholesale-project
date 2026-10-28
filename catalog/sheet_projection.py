"""Change detection for the current Sheets-to-Woo product projection."""
import hashlib
import json


def sheet_projection_digest(store):
    """Hash current Products and Shop values without copying them into job payloads."""
    reader=getattr(store,'read_fresh',None)
    tables=[]
    for name in ('Products','Shop'):
        rows=reader(name) if callable(reader) else store.read(name)
        tables.append([name,rows or []])
    encoded=json.dumps(tables,sort_keys=True,ensure_ascii=False,separators=(',',':'),default=str)
    return hashlib.sha256(encoded.encode()).hexdigest()


def observe_sheet_projection(queue,store):
    """Queue an idempotent publication when current Sheet content is not live in Woo."""
    return queue.enqueue_sheet_projection(sheet_projection_digest(store))
