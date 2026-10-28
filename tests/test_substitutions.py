import unittest

from catalog.substitutions import suggest_owned_substitutes


class SubstituteSuggestionTests(unittest.TestCase):
    def test_suggests_only_similar_sellable_items_with_sufficient_owned_stock(self):
        result = suggest_owned_substitutes(
            target_line={'sku': 'tuna-a', 'name': 'Tuna 180g', 'category': 'canned fish'},
            shortfall=4,
            products=[
                {'product_id': 'tuna-b', 'name': 'Tuna Premium 180g', 'category': 'canned fish',
                 'floor': 1000, 'ceiling': 1500},
                {'product_id': 'tuna-c', 'name': 'Tuna Small 90g', 'category': 'canned fish',
                 'floor': 500, 'ceiling': 800},
                {'product_id': 'beans', 'name': 'White Beans', 'category': 'canned vegetables',
                 'floor': 500, 'ceiling': 800},
                {'product_id': 'not-priced', 'name': 'Tuna Special', 'category': 'canned fish'},
            ],
            lots=[
                {'sku': 'tuna-a', 'available_units': 100},
                {'sku': 'tuna-b', 'available_units': 4},
                {'sku': 'tuna-c', 'available_units': 3},
                {'sku': 'beans', 'available_units': 100},
                {'sku': 'not-priced', 'available_units': 4},
            ],
            excluded_skus={'tuna-a'},
        )
        self.assertEqual([row['sku'] for row in result], ['tuna-b', 'not-priced'])
        self.assertEqual(result[0]['required_units'], 4)
        self.assertEqual((result[0]['price_floor_toman'], result[0]['price_ceiling_toman']), (1000, 1500))
        self.assertTrue(all(row['review_required'] for row in result))

    def test_invalid_shortfall_has_no_suggestions(self):
        self.assertEqual(suggest_owned_substitutes(target_line={}, shortfall=0, products=[], lots=[]), [])


if __name__ == '__main__':
    unittest.main()
