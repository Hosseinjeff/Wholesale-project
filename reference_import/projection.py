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
    read_fresh=getattr(sheet_store,'read_fresh_unformatted',None)
    if not callable(read_fresh):
        read_fresh=sheet_store.read_fresh
    exists=getattr(sheet_store,'sheet_exists',None)
    if not callable(exists) or not exists(title):
        sheet_store.ensure(title,list(HEADERS))
    existing=read_fresh(title)
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
    verified=read_fresh(title)
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


_NODE_COLUMNS={
    'name':('Product Name','Clean Product Name','Normalized Key','Reference Unit Price',
            'Consumer Unit Price','Package Count','Unit Label','Volume','Weight','Set Count'),
    'prices':('Reference Package Price','Reference Unit Price'),
    'sku':('Product Code',),
    'categories':('Category',),
    'attributes':('Variant','Brand'),
}


def project_reference_delta(sheet_store,snapshot,previous_snapshot,reference_store):
    """Write only changed product cells when the active sheet matches its prior version."""
    summary=snapshot.get('change_summary') or {}
    if (not snapshot.get('source_product_ids')
            or not previous_snapshot
            or not previous_snapshot.get('source_product_ids')
            or summary.get('metadata_upgrade')
            or reference_store.projection_snapshot_id()!=previous_snapshot.get('snapshot_id')
            or summary.get('added_count') or summary.get('removed_count')
            or summary.get('order_changed')):
        result=project_reference_prices(sheet_store,snapshot)
        reference_store.mark_projected(snapshot.get('snapshot_id') or '')
        return {**result,'mode':'full'}

    rows=snapshot.get('rows') or []
    source_ids=snapshot.get('source_product_ids') or []
    if len(source_ids)!=len(rows) or any(not isinstance(row,list) or len(row)!=len(HEADERS) for row in rows):
        raise ImportFailure('Incremental reference snapshot is incomplete')
    row_by_id={str(source_id):index for index,source_id in enumerate(source_ids)}
    updates=[]
    changed_count=0
    for change in summary.get('changed') or []:
        source_id=str(change.get('source_product_id') or '')
        index=row_by_id.get(source_id)
        if index is None:
            raise ImportFailure('Changed reference product is missing from snapshot')
        row=rows[index]
        fields={}
        for node in change.get('nodes') or []:
            for header in _NODE_COLUMNS.get(node,()):
                fields[header]=row[HEADERS.index(header)]
        fields['Last Updated']=row[HEADERS.index('Last Updated')]
        if fields:
            updates.append((index+2,fields))
            changed_count+=1

    if updates:
        write_batch=getattr(sheet_store,'write_cells_batch',None)
        if callable(write_batch):
            write_batch('ReferencePrices',list(HEADERS),updates)
        else:
            write_one=getattr(sheet_store,'write_cells',None)
            if not callable(write_one):
                raise ImportFailure('ReferencePrices incremental update is unavailable')
            for row_number,fields in updates:
                write_one('ReferencePrices',row_number,list(HEADERS),fields)
    reference_store.mark_projected(snapshot.get('snapshot_id') or '')
    return {'sheet':'ReferencePrices','row_count':len(rows),'verified':True,
            'snapshot_id':snapshot.get('snapshot_id') or '',
            'mode':'incremental','updated_products':changed_count}
