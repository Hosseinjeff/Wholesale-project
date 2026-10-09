import os
import unittest

from catalog.extract import extract_products
from catalog.sync import MemoryStore, finalize_batch, load_service_account_info


BONAKDAR = (
    "کنسرو ماهی ۱۸۰ گرمی تاپ\n"
    "✅\n"
    "قیمت هر باکس: ۱,۲۵۰,۰۰۰ تومان\n"
    "دونه ای: ۵۲,۰۰۰ تومان\n"
    "قیمت مصرف: ۶۵,۰۰۰ تومان\n"
    "تعداد در باکس: ۲۴ عددی\n"
    "موجود ✅"
)

TOP_SHOP = (
    "انرژی زا هایپ اصلی\n"
    "✅در باکس ۲۴عددی\n"
    "✅قیمت هر باکس: ۱,۲۰۰,۰۰۰ تومان\n"
    "✅قیمت مصرف: ۶۵,۰۰۰ تومان"
)

NOBEL = "کاپوچینو گوددی ۳۰ تایی\n: ۷۵/۰۰۰\n\nهات چاکلت ۲۰ تایی\n: ۶۵/۰۰۰"


class ExtractTests(unittest.TestCase):
    def test_bonakdar_uses_unit_price_and_consumer_price(self):
        products = extract_products(BONAKDAR, "@bonakdarjavan")
        self.assertEqual(len(products), 1)
        product = products[0]
        self.assertEqual(product["name"], "کنسرو ماهی ۱۸۰ گرمی تاپ")
        self.assertEqual(product["sale_price"], 52000)
        self.assertEqual(product["price"], 52000)
        self.assertEqual(product["consumer_price"], 65000)
        self.assertIn("۲۴ عددی", product["packaging"])

    def test_top_shop_keeps_consumer_price_and_ignores_box_price(self):
        products = extract_products(TOP_SHOP, "@top_shop_rahimi")
        self.assertEqual(len(products), 1)
        product = products[0]
        self.assertEqual(product["name"], "انرژی زا هایپ اصلی")
        self.assertEqual(product["sale_price"], 0)
        self.assertEqual(product["price"], 65000)
        self.assertEqual(product["consumer_price"], 65000)
        self.assertIn("24", product["packaging"])

    def test_nobelshop_keeps_the_first_priced_product(self):
        products = extract_products(NOBEL, "@nobelshop118")
        self.assertEqual(len(products), 1)
        product = products[0]
        self.assertEqual(product["name"], "کاپوچینو گوددی 30 تایی")
        self.assertEqual(product["price"], 75000)

    def test_unknown_channel_uses_the_universal_parser(self):
        content = "Test Product\nPrice: 125000 toman"
        products = extract_products(content, "@other")
        self.assertEqual(len(products), 1)
        self.assertEqual(products[0]["name"], "Test Product")
        self.assertEqual(products[0]["price"], 125000)
        self.assertEqual(products[0]["currency"], "IRT")


class FailingProductStore(MemoryStore):
    def append(self, name, rows):
        if name == "Products":
            raise RuntimeError("denied")
        super().append(name, rows)


class SyncTests(unittest.TestCase):
    def test_batch_writes_message_product_and_status(self):
        store = MemoryStore()
        result = finalize_batch(store, {
            "batch_id": "batch-1",
            "expected_count": 1,
            "messages": [{
                "id": "m1",
                "channel": "telegram",
                "channel_username": "@bonakdarjavan",
                "author": "shop",
                "content": BONAKDAR,
            }],
        })
        self.assertEqual(result["status"], "success")
        self.assertEqual(result["ack"], "ingestion_complete")
        self.assertEqual(result["processed_messages"], 1)
        self.assertFalse(result["rollback"])

        messages = store.read("MessageData")
        self.assertEqual(messages[1][messages[0].index("ID")], "m1")
        self.assertEqual(messages[1][messages[0].index("Status")], "product_listing")

        products = store.read("Products")
        self.assertEqual(len(products), 2)
        self.assertEqual(products[1][products[0].index("Product Name")], "کنسرو ماهی ۱۸۰ گرمی تاپ")
        self.assertEqual(products[1][products[0].index("Sale Price")], 52000)
        self.assertEqual(products[1][products[0].index("Channel Username")], "@bonakdarjavan")

        status = store.read("BatchStatus")
        self.assertEqual(status[1][status[0].index("Batch ID")], "batch-1")
        self.assertEqual(status[1][status[0].index("Extraction Status")], "success")

    def test_repeat_product_updates_the_existing_row(self):
        store = MemoryStore()
        message = {
            "id": "m1",
            "channel": "telegram",
            "channel_username": "@nobelshop118",
            "content": NOBEL,
        }
        finalize_batch(store, {"batch_id": "b1", "messages": [message]})
        message["id"] = "m2"
        finalize_batch(store, {"batch_id": "b2", "messages": [message]})
        products = store.read("Products")
        self.assertEqual(len(products), 2)
        self.assertEqual(len(store.read("MessageData")), 3)

    def test_count_mismatch_does_not_extract(self):
        store = MemoryStore()
        result = finalize_batch(store, {
            "batch_id": "b",
            "expected_count": 2,
            "messages": [{
                "id": "m1",
                "channel": "telegram",
                "channel_username": "@bonakdarjavan",
                "content": BONAKDAR,
            }],
        })
        self.assertEqual(result["status"], "error")
        self.assertEqual(result["ack"], "ingestion_incomplete")
        self.assertNotIn("Products", store.sheets)

    def test_product_write_failure_rolls_back(self):
        store = FailingProductStore()
        result = finalize_batch(store, {
            "batch_id": "b",
            "messages": [{
                "id": "m1",
                "channel": "telegram",
                "channel_username": "@bonakdarjavan",
                "content": BONAKDAR,
            }],
        })
        self.assertEqual(result["status"], "success")
        self.assertTrue(result["rollback"])
        self.assertEqual(result["processed_messages"], 0)
        self.assertEqual(len(store.read("MessageData")), 2)

    def test_invalid_service_account_json_is_rejected(self):
        previous = os.environ.get("GOOGLE_SERVICE_ACCOUNT_JSON")
        os.environ["GOOGLE_SERVICE_ACCOUNT_JSON"] = '{"type":"not-a-key"}'
        try:
            with self.assertRaises(ValueError):
                load_service_account_info()
        finally:
            if previous is None:
                os.environ.pop("GOOGLE_SERVICE_ACCOUNT_JSON", None)
            else:
                os.environ["GOOGLE_SERVICE_ACCOUNT_JSON"] = previous


if __name__ == "__main__":
    unittest.main()
