import unittest
from unittest.mock import patch
from catalog.media import safe_file_id, share_album_images


class AttachmentTests(unittest.TestCase):
    def test_ocr_download_helper_is_removed(self):
        from catalog import media
        self.assertFalse(hasattr(media, 'fetch_telegram_image'))

    def test_attachment_file_ids_remain_available(self):
        self.assertEqual(safe_file_id('AgACAgQAAxkBAAIB'), 'AgACAgQAAxkBAAIB')
        self.assertEqual(safe_file_id('../invalid'), '')

    def test_album_photo_can_still_be_attached_to_its_caption(self):
        messages=[{'content':'supplier caption','media_group_id':'album'},
                  {'content':'','media_group_id':'album','image_file_id':'AgACAgQAAxkBAAIB',
                   'image_file_unique_id':'unique-image'}]
        share_album_images(messages)
        shared=messages
        self.assertEqual(shared[0]['content'],'supplier caption')
        self.assertEqual(shared[0]['image_file_id'],'AgACAgQAAxkBAAIB')

    def test_photo_only_intake_finishes_without_ai_or_a_product(self):
        from catalog.sync import MemoryStore, finalize_batch, _records
        store=MemoryStore()
        photo={'id':'photo-only','channel':'telegram','channel_username':'@supplier',
            'content':'','has_media':True,'media_type':'photo',
            'image_file_id':'AgACAgQAAxkBAAIB','image_file_unique_id':'unique-image'}
        with patch('catalog.sync.extract_message_products') as model:
            result=finalize_batch(store,{'batch_id':'photo-batch','messages':[photo],'expected_count':1})
        model.assert_not_called()
        self.assertEqual(result['extraction_status'],'success')
        _,messages=_records(store.read('MessageData'))
        self.assertEqual(messages[0]['AI Status'],'attachment_only')
        self.assertEqual(messages[0]['Image File Unique ID'],'unique-image')
        _,products=_records(store.read('Products'))
        self.assertEqual(products,[])
