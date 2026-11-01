import hashlib
import os
import tempfile
import unittest
from unittest.mock import patch

from catalog.publish import publish_shop, site_products
from catalog.sync import MemoryStore


def _store() -> MemoryStore:
    store = MemoryStore()
    store.sheets["Products"] = [[
        "Product Name",
        "Sale Price",
        "Consumer Price",
        "Actual Price",
        "Channel Username",
        "Status",
        "Min Qty",
        "Image File ID",
        "Packaging",
    ], [
        "آب سودا",
        "50000",
        "88000",
        "",
        "@bonakdarjavan",
        "imported",
        "6",
        "AgACAgQAAxkBAAIB",
        "۶ عددی",
    ], [
        "خراب",
        "1000",
        "",
        "",
        "@top_shop_rahimi",
        "needs_review",
        "",
        "",
        "",
    ], [
        "بدون قیمت",
        "",
        "",
        "",
        "@nobelshop118",
        "imported",
        "",
        "",
        "",
    ]]
    store.sheets["Shop"] = [["Min Margin", "0.12"]]
    return store


class PublishTests(unittest.TestCase):
    def test_site_products_split_sell_and_hold(self):
        products = site_products(_store())
        self.assertIsNotNone(products)
        assert products is not None
        by_name = {product["name"]: product for product in products}
        self.assertEqual(set(by_name), {"آب سودا", "خراب"})
        soda = by_name["آب سودا"]
        self.assertEqual(soda["status"], "sell")
        self.assertEqual(soda["sale"], 50000)
        self.assertEqual(soda["shelf"], 88000)
        self.assertEqual(soda["min"], 6)
        self.assertEqual(soda["supplier"], "بنکدار جوان")
        self.assertEqual(soda["image_file_id"], "AgACAgQAAxkBAAIB")
        self.assertEqual(
            soda["id"],
            hashlib.sha256("@bonakdarjavan|آب سودا".encode()).hexdigest()[:12],
        )
        self.assertEqual(by_name["خراب"]["status"], "hold")

    def test_explicit_supplier_unavailability_is_carried_but_unknown_is_not_stock_proof(self):
        store = _store()
        store.sheets["Products"][0].append("Stock Status")
        store.sheets["Products"][1].append("Out of Stock")
        by_name = {item["name"]: item for item in site_products(store)}
        self.assertEqual(by_name["آب سودا"]["supplier_availability"], "outofstock")
        self.assertEqual(by_name["خراب"]["supplier_availability"], "unknown")

    def test_invalid_product_cells_are_projected_as_holds_instead_of_leaving_old_woo_prices_live(self):
        store = MemoryStore()
        store.sheets['Products'] = [[
            'Product ID','Product Name','Channel Username','Sale Price','Consumer Price',
            'Status','Stock Status','Latest Offer ID','Message Timestamp',
        ], [
            'stable-row-1','Edited item','@bonakdarjavan',50000,'bad amount',
            'imported','Available','offer-1','2026-10-28T12:00:00+00:00',
        ], [
            'stable-row-2','','@bonakdarjavan',50000,80000,
            'imported','Available','offer-2','2026-10-28T12:00:00+00:00',
        ]]
        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, {
                'PIPELINE_DB_PATH': os.path.join(directory, 'pipeline.sqlite')}):
            products = site_products(store)
        self.assertEqual({item['source_record_id']:item['status'] for item in products},
                         {'stable-row-1':'hold','stable-row-2':'hold'})
        self.assertEqual(next(item for item in products if item['source_record_id']=='stable-row-2')['name'],'')

    def test_missing_sheet_is_not_an_empty_catalog(self):
        self.assertIsNone(site_products(MemoryStore()))

    def test_publish_skips_when_unconfigured(self):
        with patch.dict(os.environ, {"SHOP_SITE_URL": "", "SHOP_NOTIFY_SECRET": ""}, clear=False):
            result = publish_shop(_store())
        self.assertFalse(result["pushed"])
        self.assertEqual(result["reason"], "unconfigured")

    def test_publish_posts_catalog_then_drains_images(self):
        store = _store()
        calls = []

        class Response:
            def __init__(self, body):
                self.status_code = 200
                self._body = body

            def json(self):
                return self._body

        def post(url, json, headers, timeout):
            calls.append((url, json, headers, timeout))
            if json.get("mode") == "images":
                return Response({"ok": True, "attached": 1, "images_left": 0, "pending": []})
            return Response({
                "ok": True,
                "created": 1,
                "updated": 0,
                "hidden": 1,
                "attached": 0,
                "images_left": 1,
                "pending": [{"product": 9, "file": "AgACAgQAAxkBAAIB"}],
            })

        env = {"SHOP_SITE_URL": "https://shop.example", "SHOP_NOTIFY_SECRET": "secret"}
        photo = patch("catalog.publish._telegram_photo", return_value=(b"\xff\xd8\xff" + b"x" * 40, "image/jpeg"))
        with patch.dict(os.environ, env, clear=False), patch("catalog.publish.requests.post", side_effect=post), photo:
            result = publish_shop(store)
        self.assertTrue(result["pushed"])
        self.assertEqual(result["created"], 1)
        self.assertEqual(result["attached"], 1)
        self.assertEqual(result["images_left"], 0)
        self.assertEqual(calls[0][0], "https://shop.example/?molavi-sync=1")
        self.assertEqual(calls[0][2]["X-Shop-Token"], "secret")
        self.assertEqual(calls[0][1]["products"][0]["name"], "آب سودا")
        self.assertIn("settings", calls[0][1])
        self.assertEqual(calls[1][1]["mode"], "images")
        self.assertEqual(calls[1][1]["images"][0]["file"], "AgACAgQAAxkBAAIB")
        self.assertEqual(calls[1][1]["images"][0]["product"], 9)


if __name__ == "__main__":
    unittest.main()
