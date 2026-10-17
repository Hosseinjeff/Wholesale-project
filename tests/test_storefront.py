import unittest

import app
from catalog.extract import minimum_purchase, pack_count
from catalog.media import share_album_images, stamp_inbound
from catalog.orders import format_order_notice, upsert_shop_order
from catalog.status import command_name, orders_status, products_status, reply_for_command, store_status
from catalog.sync import MemoryStore, import_product_data
from app import inbound_payload


TUNA = (
    "تن ۱۸۰ گرم درب شفاف شیان،۲۴عدد\n"
    "قیمت فروش ما: ۲۵۵/۰۰۰ تومان\n"
    "قیمت مصرف کننده: ۳۴۵/۰۰۰ تومان"
)


class MinimumPurchaseTests(unittest.TestCase):
    def test_shells_of_6_12_and_24_start_at_six(self):
        self.assertEqual(minimum_purchase("در کارتن6 تایی", "آب شلغم"), 6)
        self.assertEqual(minimum_purchase("در کارتن 12تایی", ""), 6)
        self.assertEqual(minimum_purchase("در کارتن 24تایی", "آنتارس"), 6)
        self.assertEqual(minimum_purchase("", "آب سودا یک لیتری سون،۶ عددی"), 6)
        self.assertEqual(minimum_purchase("بسته ۲۵ عدد و کارتن ۲۴ بسته", "چای"), 6)

    def test_other_packs_start_at_one(self):
        self.assertEqual(pack_count("کارتن ۳۶ عددی", ""), 36)
        self.assertEqual(minimum_purchase("کارتن ۳۶ عددی", "کروسان"), 1)
        self.assertEqual(minimum_purchase("", "قند شکسته،۵ کیلویی"), 1)
        self.assertEqual(minimum_purchase("", "آب شلغم تخمیری"), 1)


class AlbumImageTests(unittest.TestCase):
    def test_caption_message_receives_the_album_photo(self):
        messages = [
            {"id": "1", "media_group_id": "55", "content": TUNA, "image_file_id": ""},
            {
                "id": "2",
                "media_group_id": "55",
                "content": "",
                "image_file_id": "AgACAgQAAxkBAAIB",
                "image_file_unique_id": "uniq",
            },
        ]
        share_album_images(messages)
        self.assertEqual(messages[0]["image_file_id"], "AgACAgQAAxkBAAIB")
        self.assertEqual(messages[0]["media_type"], "photo")

    def test_inbound_photo_is_stamped_on_the_payload(self):
        data, source = inbound_payload({
            "message_id": 8,
            "caption": "catalog",
            "date": 2,
            "photo": [
                {"file_id": "small", "width": 40, "height": 40},
                {"file_id": "AgACAgQAAxkBAAIB", "width": 800, "height": 800, "file_unique_id": "uniq"},
            ],
            "media_group_id": "9",
            "from": {"username": "me"},
            "chat": {"id": 99, "type": "private"},
            "forward_origin": {
                "type": "channel",
                "date": 1,
                "message_id": 3,
                "chat": {"title": "Shop", "username": "shop"},
            },
        }, "telegram")
        self.assertEqual(source, "forward")
        self.assertEqual(data["image_file_id"], "AgACAgQAAxkBAAIB")
        self.assertEqual(data["image_file_unique_id"], "uniq")
        self.assertEqual(data["media_group_id"], "9")
        self.assertEqual(data["chat_type"], "private")

    def test_product_row_keeps_the_post_photo_and_the_minimum(self):
        store = MemoryStore()
        message = {
            "id": "1",
            "batch_id": "b1",
            "content": TUNA,
            "channel_username": "@bonakdarjavan",
            "channel": "telegram",
            "image_file_id": "AgACAgQAAxkBAAIB",
            "image_file_unique_id": "uniq",
        }
        result = import_product_data(store, message)
        self.assertTrue(result["success"])
        products = store.read("Products")
        headers = products[0]
        row = products[1]
        self.assertEqual(row[headers.index("Min Qty")], 6)
        self.assertEqual(row[headers.index("Image File ID")], "AgACAgQAAxkBAAIB")
        offers = store.read("Offers")
        self.assertEqual(offers[1][offers[0].index("Image File ID")], "AgACAgQAAxkBAAIB")


class OrderSheetTests(unittest.TestCase):
    def test_order_is_one_row_and_lines_follow_the_offer_shape(self):
        store = MemoryStore()
        order = {
            "id": "20261017-010203-abc123",
            "at": "2026-10-17T01:02:03Z",
            "name": "آزاده",
            "username": "azade",
            "phone": "09120000000",
            "email": "azade@example.com",
            "address": "تهران",
            "note": "عصر",
            "progress": 0.5,
            "total": 120000,
            "status": "new",
            "lines": [{
                "id": "db5a98ea9ee9",
                "name": "تن",
                "packaging": "۲۴ عدد",
                "qty": 6,
                "min": 6,
                "sale": 20000,
                "shelf": 30000,
                "unit": 25000,
                "total": 150000,
                "sku": "MS-DB5A98",
                "supplier": "بنکدار جوان",
            }],
        }
        upsert_shop_order(store, order)
        upsert_shop_order(store, {**order, "status": "confirmed"})
        orders = store.read("Orders")
        self.assertEqual(len(orders), 2)
        self.assertEqual(orders[1][orders[0].index("Status")], "confirmed")
        self.assertEqual(orders[1][orders[0].index("Email")], "azade@example.com")
        self.assertEqual(orders[1][orders[0].index("Currency")], "IRT")
        lines = store.read("OrderLines")
        self.assertEqual(len(lines), 2)
        self.assertEqual(lines[1][lines[0].index("Quantity")], 6)
        self.assertEqual(lines[1][lines[0].index("Min Qty")], 6)
        notice = format_order_notice(order)
        self.assertIn("آزاده", notice)
        self.assertIn("09120000000", notice)
        self.assertIn("تن", notice)
        self.assertIn("حداقل 6", notice)


class CommandTests(unittest.TestCase):
    def test_commands_are_not_catalog_posts(self):
        self.assertEqual(command_name("/orders"), "orders")
        self.assertEqual(command_name("/store@molavisale_bot"), "store")
        self.assertEqual(command_name("وضعیت کالاها"), "products")
        self.assertIsNone(command_name(TUNA))

    def test_reports_read_the_same_sheets(self):
        store = MemoryStore()
        upsert_shop_order(store, {
            "id": "1",
            "at": "2026-10-17T00:00:00Z",
            "name": "ندا",
            "total": 10,
            "status": "new",
            "lines": [{"name": "چای", "qty": 1, "total": 10}],
        })
        store.ensure("Products", ["Product Name", "Packaging", "Min Qty", "Image File ID", "Category", "Status"])
        store.append("Products", [["تن", "۲۴ عدد", 6, "AgACAgQAAxkBAAIB", "Food", "imported"]])
        self.assertIn("ندا", orders_status(store))
        self.assertIn("حداقل خرید ۶: 1", products_status(store))
        self.assertIn("کالاها: 1", store_status(store))
        self.assertIn("برگه هنوز وصل نیست", reply_for_command("store", None))

    def test_command_route_does_not_queue_the_post(self):
        queued = []
        sent = []
        original = app.enqueue_message
        original_send = app.send_chat_text
        sheets = app.USE_SHEETS
        app.enqueue_message = lambda data: queued.append(data) or True
        app.send_chat_text = lambda chat_id, text: sent.append((chat_id, text))
        app.USE_SHEETS = False
        try:
            response = app.app.test_client().post("/webhook", json={
                "update_id": 2,
                "message": {
                    "message_id": 4,
                    "text": "/orders",
                    "chat": {"id": 7, "type": "private"},
                    "from": {"username": "owner"},
                },
            })
        finally:
            app.enqueue_message = original
            app.send_chat_text = original_send
            app.USE_SHEETS = sheets
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.get_json()["command"], "orders")
        self.assertEqual(queued, [])
        self.assertIn("برگه هنوز وصل نیست", sent[0][1])


if __name__ == "__main__":
    unittest.main()
