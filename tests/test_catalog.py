import os
import unittest

from catalog.extract import PRODUCT_HEADERS, extract_products
from catalog.sync import (
    MemoryStore,
    extend_header_row,
    finalize_batch,
    grid_expansion_requests,
    load_service_account_info,
    parse_prompt_answer,
    refresh_buying_desk,
    resolve_prompt,
)


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
BONAKDAR_REPRICE = BONAKDAR.replace("۵۲,۰۰۰", "۶۰,۰۰۰").replace("۶۵,۰۰۰", "۷۰,۰۰۰")


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


class FailProductUpdates(MemoryStore):
    def __init__(self, times):
        super().__init__()
        self.remaining = times

    def write_rows(self, name, start_row, rows):
        if name == "Products" and start_row > 1 and self.remaining:
            self.remaining -= 1
            raise RuntimeError("denied")
        super().write_rows(name, start_row, rows)


class FailFirstProductUpdate(FailProductUpdates):
    def __init__(self):
        super().__init__(1)


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

        offers = store.read("Offers")
        self.assertEqual(len(offers), 2)
        self.assertEqual(offers[1][offers[0].index("Offer ID")], "batch-1:m1:0")
        self.assertEqual(offers[1][offers[0].index("Sale Price")], 52000)
        self.assertEqual(products[1][products[0].index("Offer Count")], 1)
        self.assertEqual(products[1][products[0].index("Previous Sale Price")], "")
        self.assertEqual(products[1][products[0].index("Latest Offer ID")], "batch-1:m1:0")

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
        self.assertEqual(len(store.read("Offers")), 3)
        self.assertEqual(products[1][products[0].index("Offer Count")], 2)
        self.assertEqual(products[1][products[0].index("Previous Sale Price")], "")
        self.assertEqual(products[1][products[0].index("Latest Offer ID")], "b2:m2:0")

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
        self.assertNotIn("Offers", store.sheets)

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
        self.assertEqual(len(store.read("Offers")), 1)
        self.assertEqual(len(store.read("Products")), 1)

    def test_full_sheet_grows_before_the_next_write(self):
        requests = grid_expansion_requests(7, 757, 47, 761, 47)
        self.assertEqual(requests, [{
            "appendDimension": {
                "sheetId": 7,
                "dimension": "ROWS",
                "length": 4,
            }
        }])

    def test_grid_growth_is_skipped_when_the_sheet_already_fits(self):
        self.assertEqual(grid_expansion_requests(7, 800, 47, 761, 47), [])

    def test_new_price_keeps_the_previous_offer(self):
        store = MemoryStore()
        finalize_batch(store, {"batch_id": "b1", "messages": [{
            "id": "m1",
            "channel": "telegram",
            "channel_username": "@bonakdarjavan",
            "content": BONAKDAR,
        }]})
        finalize_batch(store, {"batch_id": "b2", "messages": [{
            "id": "m2",
            "channel": "telegram",
            "channel_username": "@bonakdarjavan",
            "content": BONAKDAR_REPRICE,
        }]})

        products = store.read("Products")
        offers = store.read("Offers")
        self.assertEqual(len(products), 2)
        self.assertEqual(len(offers), 3)
        row = products[1]
        headers = products[0]
        self.assertEqual(row[headers.index("Sale Price")], 60000)
        self.assertEqual(row[headers.index("Consumer Price")], 70000)
        self.assertEqual(row[headers.index("Previous Sale Price")], 52000)
        self.assertEqual(row[headers.index("Previous Consumer Price")], 65000)
        self.assertEqual(row[headers.index("Offer Count")], 2)
        self.assertEqual(row[headers.index("Latest Offer ID")], "b2:m2:0")
        self.assertEqual(row[headers.index("Price Changed At")], offers[2][offers[0].index("Import Timestamp")])
        self.assertEqual(offers[1][offers[0].index("Sale Price")], 52000)
        self.assertEqual(offers[2][offers[0].index("Sale Price")], 60000)

    def test_same_name_from_another_supplier_stays_separate(self):
        store = MemoryStore()
        content = "Test Product\nPrice: 125000 toman"
        finalize_batch(store, {"batch_id": "b1", "messages": [{
            "id": "m1",
            "channel": "telegram",
            "channel_username": "@one",
            "content": content,
        }]})
        finalize_batch(store, {"batch_id": "b2", "messages": [{
            "id": "m2",
            "channel": "telegram",
            "channel_username": "@two",
            "content": "Test Product\nPrice: 90000 toman",
        }]})
        products = store.read("Products")
        self.assertEqual(len(products), 3)
        self.assertEqual(len(store.read("Offers")), 3)

    def test_existing_sheet_price_becomes_the_previous_price(self):
        store = MemoryStore()
        store.sheets["Products"] = [[
            "Product Name",
            "Channel Username",
            "Sale Price",
            "Consumer Price",
            "Product ID",
            "Import Timestamp",
            "Last Updated",
            "Buyer Note",
        ], [
            "کنسرو ماهی ۱۸۰ گرمی تاپ",
            "@bonakdarjavan",
            40000,
            50000,
            "keep-me",
            "2020-01-01T00:00:00.000Z",
            "2020-06-01T00:00:00.000Z",
            "call supplier",
        ]]
        finalize_batch(store, {"batch_id": "b1", "messages": [{
            "id": "m1",
            "channel": "telegram",
            "channel_username": "@bonakdarjavan",
            "content": BONAKDAR,
        }]})
        finalize_batch(store, {"batch_id": "b2", "messages": [{
            "id": "m2",
            "channel": "telegram",
            "channel_username": "@bonakdarjavan",
            "content": BONAKDAR,
        }]})

        products = store.read("Products")
        offers = store.read("Offers")
        headers = products[0]
        row = products[1]
        self.assertEqual(len(products), 2)
        self.assertIn("Previous Sale Price", headers)
        self.assertEqual(headers[0], "Product Name")
        self.assertEqual(row[headers.index("Product ID")], "keep-me")
        self.assertEqual(row[headers.index("Import Timestamp")], "2020-01-01T00:00:00.000Z")
        self.assertEqual(row[headers.index("Sale Price")], 52000)
        self.assertEqual(row[headers.index("Previous Sale Price")], 40000)
        self.assertEqual(row[headers.index("Previous Consumer Price")], 50000)
        self.assertEqual(offers[1][offers[0].index("Batch ID")], "sheet")
        self.assertEqual(offers[1][offers[0].index("Sale Price")], 40000)
        self.assertEqual(offers[1][offers[0].index("Import Timestamp")], "2020-06-01T00:00:00.000Z")
        self.assertEqual(row[headers.index("Price Changed At")], offers[2][offers[0].index("Import Timestamp")])
        self.assertEqual(row[headers.index("Buyer Note")], "call supplier")
        self.assertEqual(row[headers.index("Offer Count")], 3)

    def test_header_extension_appends_missing_catalog_columns(self):
        extended, changed = extend_header_row(["Product Name", "Buyer Note"], PRODUCT_HEADERS)
        self.assertTrue(changed)
        self.assertEqual(extended[0], "Product Name")
        self.assertEqual(extended[1], "Buyer Note")
        self.assertIn("Previous Sale Price", extended)

        store = MemoryStore()
        store.ensure("Products", ["Product Name"])
        store.ensure("Products", PRODUCT_HEADERS)
        self.assertEqual(store.read("Products")[0][0], "Product Name")
        self.assertIn("Offer Count", store.read("Products")[0])

    def test_failed_update_retries_without_a_duplicate_offer(self):
        store = FailFirstProductUpdate()
        finalize_batch(store, {"batch_id": "b1", "messages": [{
            "id": "m1",
            "channel": "telegram",
            "channel_username": "@bonakdarjavan",
            "content": BONAKDAR,
        }]})
        result = finalize_batch(store, {"batch_id": "b2", "messages": [{
            "id": "m2",
            "channel": "telegram",
            "channel_username": "@bonakdarjavan",
            "content": BONAKDAR_REPRICE,
        }]})
        products = store.read("Products")
        self.assertEqual(result["status"], "success")
        self.assertFalse(result["rollback"])
        self.assertEqual(len(store.read("Offers")), 3)
        self.assertEqual(products[1][products[0].index("Sale Price")], 60000)
        self.assertEqual(products[1][products[0].index("Previous Sale Price")], 52000)

    def test_failed_batch_restores_the_previous_current_price(self):
        store = FailProductUpdates(3)
        finalize_batch(store, {"batch_id": "b1", "messages": [{
            "id": "m1",
            "channel": "telegram",
            "channel_username": "@bonakdarjavan",
            "content": BONAKDAR,
        }]})
        result = finalize_batch(store, {"batch_id": "b2", "messages": [{
            "id": "m2",
            "channel": "telegram",
            "channel_username": "@bonakdarjavan",
            "content": BONAKDAR_REPRICE,
        }]})
        products = store.read("Products")
        offers = store.read("Offers")
        self.assertTrue(result["rollback"])
        self.assertEqual(result["processed_messages"], 0)
        self.assertEqual(len(offers), 2)
        self.assertEqual(offers[1][offers[0].index("Batch ID")], "b1")
        self.assertEqual(products[1][products[0].index("Sale Price")], 52000)
        self.assertEqual(products[1][products[0].index("Previous Sale Price")], "")
        self.assertEqual(len(store.read("MessageData")), 3)

    def test_message_without_a_price_does_not_open_the_offer_log(self):
        store = MemoryStore()
        result = finalize_batch(store, {"batch_id": "b1", "messages": [{
            "id": "m1",
            "channel": "telegram",
            "channel_username": "@bonakdarjavan",
            "content": "سلام، سفارش بسته است",
        }]})
        self.assertEqual(result["status"], "success")
        self.assertNotIn("Offers", store.sheets)
        self.assertNotIn("Products", store.sheets)

    def test_clear_price_is_saved_without_a_prompt(self):
        store = MemoryStore()
        result = finalize_batch(store, {"batch_id": "b1", "messages": [{
            "id": "m1",
            "chat_id": 42,
            "channel": "telegram",
            "channel_username": "@bonakdarjavan",
            "content": BONAKDAR,
        }]})
        self.assertEqual(len(store.read("Decisions")), 1)
        self.assertEqual(len(store.read("Prompts")), 1)
        self.assertEqual(result["prompts"], [])
        self.assertEqual(result["saved"][0]["sale"], 52000)
        self.assertEqual(store.read("Items")[1][store.read("Items")[0].index("Last Sale Price")], 52000)

    def test_confirmed_sell_price_is_kept_and_a_quiet_item_leaves_the_list(self):
        store = MemoryStore()
        message = {
            "id": "m1",
            "channel": "telegram",
            "channel_username": "@bonakdarjavan",
            "content": BONAKDAR,
        }
        finalize_batch(store, {"batch_id": "b1", "messages": [message]})
        items = store.sheets["Items"]
        headers = items[0]
        items[1][headers.index("Confirmed Item Name")] = "Tuna 180g"
        items[1][headers.index("Our Sell Price")] = 58000
        items[0].append("Buyer Note")
        items[1].append("weekly")
        message["id"] = "m2"
        finalize_batch(store, {"batch_id": "b2", "messages": [message]})

        items = store.read("Items")
        headers = items[0]
        self.assertEqual(len(store.read("Decisions")), 1)
        self.assertEqual(items[1][headers.index("Confirmed Item Name")], "Tuna 180g")
        self.assertEqual(items[1][headers.index("Our Sell Price")], 58000)
        self.assertEqual(items[1][headers.index("Status")], "confirmed")
        self.assertEqual(items[1][headers.index("Buyer Note")], "weekly")

        result = refresh_buying_desk(store)
        self.assertEqual(result["decisions"], 0)
        self.assertEqual(result["items"], 1)

    def test_missing_unit_price_asks_in_the_bot_and_a_number_clears_it(self):
        store = MemoryStore()
        result = finalize_batch(store, {"batch_id": "b1", "messages": [{
            "id": "m1",
            "chat_id": 42,
            "channel": "telegram",
            "channel_username": "@top_shop_rahimi",
            "content": TOP_SHOP,
        }]})
        decisions = store.read("Decisions")
        prompts = store.read("Prompts")
        self.assertEqual(decisions[1][decisions[0].index("Reasons")], "missing unit price")
        self.assertEqual(prompts[1][prompts[0].index("Status")], "asked")
        self.assertIn("قیمت واحد پیدا نشد", prompts[1][prompts[0].index("Question")])
        self.assertEqual(result["prompts"][0]["chat_id"], "42")
        self.assertEqual(result["saved"], [])

        outcome = resolve_prompt(store, 42, "۳۰۸۳۳ تومان")
        products = store.read("Products")
        prompts = store.read("Prompts")
        self.assertTrue(outcome["understood"])
        self.assertEqual(outcome["action"], "correct")
        self.assertEqual(products[1][products[0].index("Sale Price")], 30833)
        self.assertEqual(prompts[1][prompts[0].index("Status")], "corrected")
        self.assertEqual(len(store.read("Decisions")), 1)

    def test_ok_and_skip_settle_a_doubt_without_asking_again(self):
        store = MemoryStore()
        finalize_batch(store, {"batch_id": "b1", "messages": [{
            "id": "m1",
            "chat_id": 7,
            "channel": "telegram",
            "channel_username": "@top_shop_rahimi",
            "content": TOP_SHOP,
        }]})
        accepted = resolve_prompt(store, "7", "باشه")
        self.assertEqual(accepted["action"], "accept")
        self.assertEqual(len(store.read("Decisions")), 1)
        refresh_buying_desk(store)
        self.assertEqual(len(store.read("Prompts")), 2)
        self.assertEqual(store.read("Prompts")[1][store.read("Prompts")[0].index("Status")], "accepted")

        store = MemoryStore()
        finalize_batch(store, {"batch_id": "b1", "messages": [{
            "id": "m1",
            "chat_id": 7,
            "channel": "telegram",
            "channel_username": "@top_shop_rahimi",
            "content": TOP_SHOP,
        }]})
        skipped = resolve_prompt(store, 7, "رد")
        self.assertEqual(skipped["action"], "skip")
        self.assertEqual(store.read("Products")[1][store.read("Products")[0].index("Status")], "skipped")
        self.assertEqual(len(store.read("Decisions")), 1)

    def test_a_clear_price_change_does_not_ask(self):
        store = MemoryStore()
        finalize_batch(store, {"batch_id": "b1", "messages": [{
            "id": "m1",
            "chat_id": 1,
            "channel": "telegram",
            "channel_username": "@bonakdarjavan",
            "content": BONAKDAR,
        }]})
        result = finalize_batch(store, {"batch_id": "b2", "messages": [{
            "id": "m2",
            "chat_id": 1,
            "channel": "telegram",
            "channel_username": "@bonakdarjavan",
            "content": BONAKDAR_REPRICE,
        }]})
        self.assertEqual(len(store.read("Decisions")), 1)
        self.assertEqual(result["prompts"], [])
        self.assertEqual(result["saved"][0]["sale"], 60000)

    def test_prompt_answers(self):
        self.assertEqual(parse_prompt_answer("باشه"), ("accept", None))
        self.assertEqual(parse_prompt_answer("رد"), ("skip", None))
        self.assertEqual(parse_prompt_answer("52,000"), ("correct", 52000))
        self.assertIsNone(parse_prompt_answer("این قیمت عجیبه"))

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
