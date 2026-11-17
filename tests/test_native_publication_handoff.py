import os
import unittest
from unittest.mock import Mock, patch

from catalog.publish import publish_shop


class NativePublicationHandoffTests(unittest.TestCase):
    def test_wordpress_publication_never_reads_sheets_or_calls_the_shop_from_railway(self):
        queue = Mock()
        queue.job_host.return_value = 'wordpress'
        queue.put.return_value = 'publish:synthetic-receipt'
        with patch.dict(os.environ, {'PIPELINE_DB_PATH': '/synthetic/pipeline.sqlite3'}), \
                patch('catalog.state.wordpress_state', return_value=True), \
                patch('catalog.durable.Handoffs', return_value=queue), \
                patch('catalog.publish.site_products') as products, \
                patch('catalog.publish._post_shop') as shop:
            result = publish_shop(Mock(), max_rounds=12)
        products.assert_not_called()
        shop.assert_not_called()
        self.assertTrue(result['queued'])
        self.assertFalse(result['pushed'])
        self.assertEqual(result['receipt_id'], 'publish:synthetic-receipt')
        self.assertEqual(queue.put.call_args.args[0], 'publish')
        self.assertEqual(queue.put.call_args.args[2], {'images_only': False, 'max_rounds': 12})

    def test_images_only_is_queued_without_a_callback_to_the_busy_bot_worker(self):
        queue = Mock()
        queue.job_host.return_value = 'wordpress'
        with patch.dict(os.environ, {'PIPELINE_DB_PATH': '/synthetic/pipeline.sqlite3'}), \
                patch('catalog.state.wordpress_state', return_value=True), \
                patch('catalog.durable.Handoffs', return_value=queue), \
                patch('catalog.publish._telegram_photo') as photo:
            publish_shop(Mock(), max_rounds=100, images_only=True)
        photo.assert_not_called()
        self.assertEqual(queue.put.call_args.args[2], {'images_only': True, 'max_rounds': 40})


if __name__ == '__main__':
    unittest.main()
