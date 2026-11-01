import os
import tempfile
import unittest
from datetime import datetime,timedelta
from pathlib import Path
from unittest.mock import patch
from zoneinfo import ZoneInfo

from catalog.inventory_lots import InventoryLots


class StockLotActionsRouteTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.path=str(Path(self.temp.name)/'pipeline.sqlite')
        self.today=datetime.now(ZoneInfo('Asia/Tehran')).date()
        self.inventory=InventoryLots(self.path)
        self.inventory.receive(lot_id='lot-a',sku='sku-a',supplier='Supplier A',
            purchase_date=(self.today-timedelta(days=2)).isoformat(),received_at=self.today.isoformat(),
            production_date='',expiry_date=(self.today+timedelta(days=100)).isoformat(),
            units=10,unit_cost=25_000)
        self.headers={'X-Molavi-Reference-Token':'synthetic-stock-token-0123456789',
            'X-Molavi-Actor':'wp:7'}
        self.env={'PIPELINE_DB_PATH':self.path,'REFERENCE_OWNER_IDS':'wp:7',
            'REFERENCE_BRIDGE_TOKEN':self.headers['X-Molavi-Reference-Token']}
        self.payload={'lot_id':'lot-a','expected_revision':self.inventory.get_lot('lot-a')['revision'],
            'action':'adjust','delta_units':-2,'reason':'damage','note':'Two cans damaged.'}

    def test_auth_revision_retry_and_availability(self):
        import app
        with patch.dict(os.environ,self.env):
            client=app.app.test_client()
            self.assertEqual(client.get('/pipeline/stock-lot-actions').status_code,401)
            denied=client.get('/pipeline/stock-lot-actions',headers={**self.headers,'X-Molavi-Actor':'wp:9'})
            self.assertEqual(denied.status_code,403)
            saved=client.post('/pipeline/stock-lot-actions',headers=self.headers,json=self.payload)
            self.assertEqual(saved.status_code,200,saved.get_json())
            self.assertEqual(saved.headers.get('Cache-Control'),'no-store')
            self.assertEqual(saved.get_json()['units'],8)
            self.assertEqual(saved.get_json()['state'],'ready')
            self.assertEqual(saved.get_json()['previous_revision'],self.payload['expected_revision'])
            self.assertEqual(saved.get_json()['action'],'adjust')
            retry=client.post('/pipeline/stock-lot-actions',headers=self.headers,json=self.payload)
            self.assertTrue(retry.get_json()['idempotent'])
            stale=client.post('/pipeline/stock-lot-actions',headers=self.headers,
                json={**self.payload,'delta_units':-1,'note':'Different stale correction.'})
            self.assertEqual(stale.status_code,409)
            events=client.get('/pipeline/stock-lot-actions',headers=self.headers).get_json()['events']
        self.assertEqual(len(events),1)
        self.assertEqual(events[0]['reason'],'damage')
        self.assertEqual(self.inventory.list_lots(today=self.today.isoformat())[0]['available_units'],8)


if __name__=='__main__':
    unittest.main()
