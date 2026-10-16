import unittest

import app
from catalog.pricing import (
    PricingSettings,
    price_band,
    quote_lines,
    settings_from_rows,
    shop_formulas,
)
from catalog.sync import MemoryStore, finalize_batch, shop_catalog


BONAKDAR = (
    "کنسرو ماهی ۱۸۰ گرمی تاپ\n"
    "✅\n"
    "قیمت هر باکس: ۱,۲۵۰,۰۰۰ تومان\n"
    "دونه ای: ۵۲,۰۰۰ تومان\n"
    "قیمت مصرف: ۶۵,۰۰۰ تومان\n"
    "تعداد در باکس: ۲۴ عددی\n"
    "موجود ✅"
)


class BandTests(unittest.TestCase):
    def test_big_bear_splits_the_spread_into_ceiling_and_floor(self):
        band = price_band(30833, 60000)
        self.assertTrue(band["discountable"])
        self.assertEqual(band["floor"], 43367)
        self.assertEqual(band["ceiling"], 53040)
        self.assertAlmostEqual(band["opening_rate"], 0.116)
        self.assertAlmostEqual(band["max_discount"], 0.27721666666666667)
        self.assertGreater(band["floor"] - band["cost"], 5000)
        self.assertGreater(band["floor"], band["cost"] * 1.12)
        self.assertLess(band["ceiling"], band["regular"])
        self.assertGreater(band["ceiling"], band["floor"])

    def test_a_thin_spread_keeps_a_small_opening_and_a_shallow_cart(self):
        band = price_band(40000, 50000)
        self.assertEqual(band["floor"], 46300)
        self.assertEqual(band["ceiling"], 47900)
        self.assertAlmostEqual(band["opening_rate"], 0.042)
        self.assertAlmostEqual(band["depth_rate"], 0.032)

    def test_a_wide_spread_opens_further_and_leaves_more_for_the_cart(self):
        band = price_band(30000, 100000)
        self.assertEqual(band["ceiling"], 82500)
        self.assertEqual(band["floor"], 54500)
        self.assertGreater(band["depth_rate"], band["opening_rate"])
        self.assertAlmostEqual(band["opening_rate"] + band["depth_rate"], band["max_discount"])

    def test_margin_rule_raises_the_floor_and_shrinks_the_opening(self):
        loose = price_band(40000, 50000)
        tight = price_band(40000, 50000, PricingSettings(min_margin=0.20))
        self.assertGreater(tight["floor"], loose["floor"])
        self.assertEqual(tight["floor"], 48000)
        self.assertEqual(tight["ceiling"], 48000)
        self.assertEqual(tight["depth_rate"], 0)
        self.assertAlmostEqual(tight["opening_rate"], 0.04)

    def test_a_spread_that_cannot_cover_the_margin_is_not_sold_at_a_discount(self):
        band = price_band(90000, 100000)
        self.assertFalse(band["discountable"])
        self.assertEqual(band["max_discount"], 0)

    def test_percent_typed_as_a_whole_number_is_read_as_a_rate(self):
        settings = settings_from_rows([["Min Margin", "18"], ["Unlock Target", "250000"]])
        self.assertAlmostEqual(settings.min_margin, 0.18)
        self.assertEqual(settings.unlock_target, 250000)
        self.assertEqual(settings.min_profit, 5000)


class CartTests(unittest.TestCase):
    def test_adding_and_removing_reprices_every_line_and_the_aggregate(self):
        bear = price_band(30833, 60000)
        tuna = price_band(40000, 50000)
        bear_line = {**bear, "product_id": "bear", "name": "بیگ بیر", "qty": 1}
        tuna_line = {**tuna, "product_id": "tuna", "name": "تن", "qty": 1}

        alone = quote_lines([bear_line], 200_000)
        both = quote_lines([bear_line, tuna_line], 200_000)
        removed = quote_lines([bear_line], 200_000)

        self.assertEqual(alone["lines"][0]["discount_amount"], 9862)
        self.assertAlmostEqual(alone["discount_rate"], 0.16436666666666666)
        self.assertGreater(both["lines"][0]["discount_rate"], alone["lines"][0]["discount_rate"])
        self.assertAlmostEqual(both["lines"][0]["discount_rate"], 0.20466666666666666)
        self.assertAlmostEqual(both["lines"][1]["discount_rate"], 0.0596)
        self.assertEqual(both["discount_total"], 15260)
        self.assertEqual(sum(line["discount_amount"] for line in both["lines"]), both["discount_total"])
        self.assertAlmostEqual(both["discount_rate"], both["discount_total"] / both["regular_total"])
        self.assertLess(both["discount_rate"], both["lines"][0]["discount_rate"])
        self.assertEqual(removed, alone)

    def test_a_full_basket_stops_at_the_floor(self):
        bear = price_band(30833, 60000)
        quote = quote_lines([{**bear, "product_id": "bear", "name": "بیگ بیر", "qty": 1}], bear["regular"])
        self.assertEqual(quote["progress"], 1)
        self.assertEqual(quote["lines"][0]["unit_price"], bear["floor"])
        self.assertAlmostEqual(quote["lines"][0]["discount_rate"], bear["max_discount"])

    def test_quantity_changes_the_same_way_as_adding_a_line(self):
        bear = price_band(30833, 60000)
        one = quote_lines([{**bear, "product_id": "bear", "name": "بیگ بیر", "qty": 1}], 2_000_000)
        two = quote_lines([{**bear, "product_id": "bear", "name": "بیگ بیر", "qty": 2}], 2_000_000)
        self.assertGreater(two["lines"][0]["discount_rate"], one["lines"][0]["discount_rate"])
        self.assertEqual(two["lines"][0]["discount_amount"], two["lines"][0]["qty"] * (bear["regular"] - two["lines"][0]["unit_price"]))
        self.assertEqual(two["discount_total"], two["lines"][0]["discount_amount"])


class ShopSheetTests(unittest.TestCase):
    def test_formulas_follow_the_settings_cells(self):
        formulas = shop_formulas(",")
        self.assertEqual(len(formulas), 12)
        self.assertIn("$B$1", formulas[6])
        self.assertIn("ROUNDUP", formulas[6])
        self.assertIn("Products!E2:E2001", formulas[3])
        self.assertIn("Products!J2:J2001", formulas[4])
        persian = shop_formulas(";")
        self.assertNotIn(",", persian[6])
        self.assertIn(";", persian[6])

    def test_an_edited_margin_survives_the_next_import(self):
        store = MemoryStore()
        message = {
            "id": "m1",
            "channel": "telegram",
            "channel_username": "@bonakdarjavan",
            "content": BONAKDAR,
        }
        finalize_batch(store, {"batch_id": "b1", "messages": [message]})
        shop = store.read("Shop")
        self.assertEqual(shop[0], ["Min Margin", 0.12])
        self.assertTrue(str(shop[11][6]).startswith("=MAP"))
        catalog = shop_catalog(store)
        self.assertEqual(catalog["products"][0]["name"], "کنسرو ماهی ۱۸۰ گرمی تاپ")
        self.assertEqual(catalog["products"][0]["floor"], 60190)
        self.assertEqual(catalog["products"][0]["ceiling"], 62270)

        shop[0][1] = 0.20
        store.write_rows("Shop", 1, [shop[0]])
        message["id"] = "m2"
        finalize_batch(store, {"batch_id": "b2", "messages": [message]})
        self.assertEqual(store.read("Shop")[0][1], 0.20)
        revised = shop_catalog(store)
        self.assertEqual(revised["settings"]["min_margin"], 0.20)
        self.assertEqual(revised["products"][0]["floor"], 62400)
        self.assertEqual(revised["products"][0]["ceiling"], 62400)
        self.assertNotIn("cost", revised["products"][0])


class ShopApiTests(unittest.TestCase):
    def setUp(self):
        self.client = app.app.test_client()
        self.sheets = app.USE_SHEETS
        app.USE_SHEETS = False

    def tearDown(self):
        app.USE_SHEETS = self.sheets

    def test_shop_page_and_preview_cart_round_trip(self):
        listed = self.client.get("/api/shop?preview=1").get_json()
        bear = next(product for product in listed["products"] if product["product_id"] == "preview-bear")
        tea = next(product for product in listed["products"] if product["product_id"] == "preview-tea")
        self.assertEqual(bear["floor"], 43367)
        self.assertEqual(bear["ceiling"], 53040)
        self.assertNotIn("cost", bear)

        alone = self.client.post("/api/cart?preview=1", json={"lines": [{"product_id": "preview-bear", "qty": 1}]}).get_json()
        both = self.client.post("/api/cart?preview=1", json={"lines": [
            {"product_id": "preview-bear", "qty": 1},
            {"product_id": "preview-tea", "qty": 1},
        ]}).get_json()
        back = self.client.post("/api/cart?preview=1", json={"lines": [{"product_id": "preview-bear", "qty": 1}]}).get_json()

        self.assertGreater(both["lines"][0]["discount_rate"], alone["lines"][0]["discount_rate"])
        self.assertEqual(sum(line["discount_amount"] for line in both["lines"]), both["discount_total"])
        self.assertAlmostEqual(both["discount_rate"], both["discount_total"] / both["regular_total"])
        self.assertEqual(back["lines"][0]["discount_amount"], alone["lines"][0]["discount_amount"])
        self.assertEqual(back["discount_rate"], alone["discount_rate"])
        self.assertEqual(tea["max_discount"], price_band(30000, 100000)["max_discount"])

    def test_empty_shop_when_the_sheet_is_not_configured(self):
        payload = self.client.get("/api/shop").get_json()
        self.assertEqual(payload["products"], [])


if __name__ == "__main__":
    unittest.main()
