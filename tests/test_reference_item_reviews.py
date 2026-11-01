import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from catalog.extract import OFFER_HEADERS, PRODUCT_HEADERS
from catalog.sync import MemoryStore, _restore_current_book
from reference_import.importer import HEADERS
from reference_import.item_reviews import (ItemReviewConflict, ItemReviewLedger,
    offer_attributes, review_case_id, review_cases)
from reference_import.matching import candidate_match, product_signature, ranked_candidates
from reference_import.snapshots import ReferenceStore


def reference(name='چای سبز', *, code='R-1', brand='الف', category='نوشیدنی',
              variant='', count=12, unit=40000, package=480000):
    row=['']*len(HEADERS)
    row[0]=row[1]=row[2]=name
    row[3]=package; row[4]=unit; row[6]=count; row[11]=variant
    row[12]=brand; row[13]=category; row[16]=code
    return row


class ReferenceItemReviewTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.path=Path(self.tmp.name)/'reference.sqlite'
        self.ref_store=ReferenceStore(self.path,owner_ids=['wp:7'])
        self.snapshot={'snapshot_id':'snapshot-A','rows':[reference()], 'extracted_at':'2026-10-28T00:00:00+00:00'}
        with self.ref_store._connect() as db:
            db.execute('INSERT INTO reference_active VALUES (1,?)',('snapshot-A',))
        self.ledger=ItemReviewLedger(self.path,owner_ids=['wp:7'])
        self.sheet=MemoryStore()
        self.sheet.ensure('Offers',OFFER_HEADERS)
        self.sheet.ensure('Products',PRODUCT_HEADERS)
        offer={
            'Offer ID':'batch:message:0','Product Name':'چای سبز', 'Channel Username':'@supplier',
            'Sale Price':450000,'Price':450000,'Consumer Price':600000,'Actual Price':600000,
            'Status':'needs_review','Review Reason':'reference: multiple_candidates',
            'Reference Snapshot ID':'snapshot-A','Reference Match State':'multiple_candidates',
            'Reference Price Role Hint':'needs_review','Brand':'الف','Category':'نوشیدنی',
            'Package Count':12,'Packaging':'باکس 12 عددی','Import Timestamp':'2026-10-28T01:00:00+00:00',
        }
        from catalog.sync import _row_from_map
        self.sheet.append('Offers',[_row_from_map(OFFER_HEADERS,offer)])
        product={'Product Name':'چای سبز','Channel Username':'@supplier','Latest Offer ID':'batch:message:0',
                 'Sale Price':450000,'Consumer Price':600000,'Status':'needs_review'}
        self.sheet.append('Products',[_row_from_map(PRODUCT_HEADERS,product)])

    def test_ranked_suggestions_are_review_only_and_use_recovered_weighting(self):
        attrs=offer_attributes({'Product Name':'چای سبز','Brand':'الف','Category':'نوشیدنی',
                               'Package Count':12,'Packaging':'باکس 12 عددی'})
        ranked=ranked_candidates(attrs,self.snapshot['rows'])
        self.assertEqual(ranked[0]['reference_id'],'R-1')
        self.assertGreaterEqual(ranked[0]['score'],.99)
        self.assertEqual(candidate_match(attrs,self.snapshot['rows'])['state'],'candidate')

    def test_owner_decision_is_durable_snapshot_bound_and_replayed_to_projection(self):
        cases=review_cases(self.sheet,self.snapshot,self.ledger)
        self.assertEqual(len(cases),1)
        case=cases[0]
        decision=self.ledger.decide(case_id=case['case_id'],offer_id=case['offer_id'],
            snapshot_id=case['snapshot_id'],signature=case['signature'],choice='match',
            reference_id='R-1',price_role='package',actor_id='wp:7',note='Verified product and carton price')
        self.assertEqual(self.ledger.get(case['case_id'])['decision_id'],decision['decision_id'])
        overrides=self.ledger.overrides('snapshot-A')
        self.assertEqual(overrides[case['signature']]['price_role'],'package')
        attrs=offer_attributes({'Product Name':'چای سبز','Brand':'الف','Category':'نوشیدنی',
                               'Package Count':12,'Packaging':'باکس 12 عددی'})
        pinned=candidate_match(attrs,self.snapshot['rows'],overrides=overrides)
        self.assertEqual(pinned['reason'],'owner_approved_mapping')
        self.assertEqual(pinned['candidates'][0]['owner_price_role'],'package')

        with patch.dict(os.environ,{'REFERENCE_STORE_PATH':str(self.path),'REFERENCE_OWNER_IDS':'wp:7',
                                    'PIPELINE_DB_PATH':''}):
            _restore_current_book(self.sheet,[('چای سبز','@supplier')])
        product_headers=self.sheet.read('Products')[0]
        product=self.sheet.read('Products')[1]
        self.assertEqual(product[product_headers.index('Status')],'imported')
        self.assertEqual(product[product_headers.index('Reference Match ID')],'R-1')
        self.assertEqual(product[product_headers.index('Reference Price Role')],'package')
        self.assertEqual(product[product_headers.index('Reference Decision ID')],decision['decision_id'])
        offers=self.sheet.read('Offers')
        offer_headers=offers[0]
        self.assertEqual(offers[1][offer_headers.index('Review Reason')],'reference: multiple_candidates')
        self.assertEqual(offers[1][offer_headers.index('Status')],'needs_review')

    def test_no_match_closes_case_without_unblocking_and_bad_actor_or_revision_rejected(self):
        case=review_cases(self.sheet,self.snapshot,self.ledger)[0]
        with self.assertRaises(PermissionError):
            self.ledger.decide(case_id=case['case_id'],offer_id=case['offer_id'],
                snapshot_id=case['snapshot_id'],signature=case['signature'],choice='no_match',
                reference_id='',price_role='unresolved',actor_id='wp:8',note='No reliable match')
        decision=self.ledger.decide(case_id=case['case_id'],offer_id=case['offer_id'],
            snapshot_id=case['snapshot_id'],signature=case['signature'],choice='no_match',
            reference_id='',price_role='unresolved',actor_id='wp:7',note='No reliable match')
        self.assertEqual(decision['choice'],'no_match')
        self.assertEqual(review_cases(self.sheet,self.snapshot,self.ledger),[])
        self.assertEqual(self.ledger.overrides('snapshot-A'),{})
        with self.assertRaises(ItemReviewConflict):
            self.ledger.decide(case_id=case['case_id'],offer_id=case['offer_id'],
                snapshot_id=case['snapshot_id'],signature=case['signature'],choice='match',
                reference_id='R-1',price_role='unit',actor_id='wp:7',note='Try again')

    def test_case_identity_changes_with_snapshot_and_current_offer_only(self):
        first=review_cases(self.sheet,self.snapshot,self.ledger)[0]
        self.assertEqual(first['case_id'],review_case_id(first['offer_id'],'snapshot-A'))
        new=dict(self.snapshot,snapshot_id='snapshot-B')
        self.assertEqual(review_cases(self.sheet,new,self.ledger),[])


if __name__=='__main__':
    unittest.main()
