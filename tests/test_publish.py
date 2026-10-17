import hashlib
import os
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
