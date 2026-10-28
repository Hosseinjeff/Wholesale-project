"""Three-way reconciliation for user-editable normalized fields.

Pure decision layer: callers must verify observed values again before performing a
write. Sheets has no cross-system compare-and-swap; this module alone cannot make
that multi-system operation atomic. Source posts and inventory movements are not
editable through this layer.
"""
from dataclasses import dataclass
from decimal import Decimal, InvalidOperation
import hashlib
import json


class InvalidEdit(ValueError):
    pass


def positive_integer(value):
    if isinstance(value, bool): raise InvalidEdit('boolean is not a quantity')
    try: amount = Decimal(str(value).strip().translate(str.maketrans('۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩','01234567890123456789')).replace(',','').replace('٬',''))
    except (InvalidOperation, ValueError): raise InvalidEdit('invalid quantity or amount') from None
    if not amount.is_finite() or amount <= 0 or amount != amount.to_integral_value():
        raise InvalidEdit('positive whole value required')
    return int(amount)


@dataclass(frozen=True)
class Result:
    action: str
    base: object
    server: object
    sheet: object
    target: object = None


def reconcile(base, server, sheet, *, normalize=positive_integer):
    """Classify one editable field without dropping either conflicting value."""
    try:
        b, a, c = normalize(base), normalize(server), normalize(sheet)
    except InvalidEdit:
        return Result('needs_review',base,server,sheet)
    if a == c:
        return Result('in_sync',b,a,c,a)
    if a == b:
        return Result('sheet_to_server',b,a,c,c)
    if c == b:
        return Result('server_to_sheet',b,a,c,a)
    return Result('conflict',b,a,c)


def resolve_conflict(conflict, choice, *, observed_server, observed_sheet, normalize=positive_integer):
    """Return selected value only if neither side changed since review began."""
    if conflict.action != 'conflict' or choice not in ('server','sheet'):
        raise InvalidEdit('invalid conflict choice')
    if normalize(observed_server) != conflict.server or normalize(observed_sheet) != conflict.sheet:
        raise InvalidEdit('stale conflict; reload both values')
    return conflict.server if choice == 'server' else conflict.sheet


_TEXT_FIELDS={
    'Product Name','Variation Type','Price Type','Currency','Packaging','Category','Description',
    'Stock Status','Location','Contact Info','Original Message','Channel','Channel Username',
    'Forwarded By','Image File ID','Image File Unique ID','Status',
}


def normalize_field(field,value):
    """Compare every editable cell without changing the raw value written back."""
    if value is None or value=='': return ''
    if field in _TEXT_FIELDS: return str(value).strip()
    if isinstance(value,bool):
        if field in {'Sale Price','Actual Price','Price','Consumer Price','Double Pack Price',
                     'Double Pack Consumer Price','Min Qty','Offer Count','Confidence',
                     'Extraction Confidence Score'}:
            raise InvalidEdit('boolean is not a numeric cell')
        return value
    raw=str(value).strip().translate(str.maketrans('۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩','01234567890123456789'))
    raw=raw.replace(',','').replace('٬','')
    try:
        number=Decimal(raw)
        if not number.is_finite(): raise InvalidEdit('non-finite numeric cell')
        return number.normalize()
    except InvalidOperation:
        if field in {'Sale Price','Actual Price','Price','Consumer Price','Double Pack Price',
                     'Double Pack Consumer Price','Min Qty','Offer Count','Confidence',
                     'Extraction Confidence Score'}:
            raise InvalidEdit('invalid numeric cell') from None
        return str(value).strip()


def reconcile_field(field,base,server,sheet):
    try: b,a,c=(normalize_field(field,value) for value in (base,server,sheet))
    except InvalidEdit: return Result('needs_review',base,server,sheet)
    if a==c:return Result('in_sync',base,server,sheet,server)
    if a==b:return Result('sheet_to_server',base,server,sheet,sheet)
    if c==b:return Result('server_to_sheet',base,server,sheet,server)
    return Result('conflict',base,server,sheet)


def field_values_equal(field,left,right):
    """Compare normalized cells, falling back to exact raw equality for invalid edits."""
    try:
        return normalize_field(field,left)==normalize_field(field,right)
    except InvalidEdit:
        return type(left) is type(right) and left==right


def stable_conflict_id(row_id,field,source_revision,base,server,sheet):
    material=json.dumps([str(row_id),str(field),str(source_revision),base,server,sheet],
                        sort_keys=True,ensure_ascii=False,separators=(',',':'),default=str)
    return 'sheet:'+hashlib.sha256(material.encode()).hexdigest()
