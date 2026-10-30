"""Project the owner-activated immutable reference snapshot to ReferencePrices."""
from .importer import HEADERS, LEGACY_PDF_HEADERS, ImportFailure


def project_reference_prices(sheet_store, snapshot):
    if (not isinstance(snapshot, dict) or snapshot.get('status')!='staged'
            or list(snapshot.get('headers') or [])!=list(HEADERS)):
        raise ImportFailure('Active reference snapshot contract is invalid')
    rows=snapshot.get('rows')
    if not isinstance(rows,list) or not rows or any(not isinstance(row,list) or len(row)!=len(HEADERS) for row in rows):
        raise ImportFailure('Active reference rows are incomplete')
    title='ReferencePrices'
    exists=getattr(sheet_store,'sheet_exists',None)
    if not callable(exists) or not exists(title):
        sheet_store.ensure(title,list(HEADERS))
    existing=sheet_store.read_fresh(title)
    if not existing:
        existing=[list(HEADERS)]
    current_headers=[str(value) for value in existing[0]]
    if current_headers[:len(HEADERS)] not in (list(HEADERS),list(LEGACY_PDF_HEADERS)):
        raise ImportFailure('ReferencePrices columns differ from the managed snapshot schema')
    row_count=max(len(existing),len(rows)+1)
    table=[list(HEADERS),*[list(row) for row in rows]]
    table.extend([['']*len(HEADERS) for _ in range(row_count-len(table))])
    # One values.update request replaces the managed 21-column table and clears a
    # longer prior snapshot without touching columns outside the recovered schema.
    sheet_store.write_rows(title,1,table)
    verified=sheet_store.read_fresh(title)
    if not verified or [str(value) for value in verified[0][:len(HEADERS)]]!=list(HEADERS):
        raise RuntimeError('ReferencePrices header receipt did not match')
    if len(verified)<len(rows)+1:
        raise RuntimeError('ReferencePrices row receipt is short')
    for index,expected in enumerate(rows,1):
        actual=list(verified[index])+['']*max(0,len(HEADERS)-len(verified[index]))
        if actual[:len(HEADERS)]!=expected:
            raise RuntimeError('ReferencePrices row receipt differs from the active snapshot')
    return {'sheet':'ReferencePrices','row_count':len(rows),'verified':True,
            'snapshot_id':snapshot.get('snapshot_id') or ''}
