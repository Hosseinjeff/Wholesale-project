import unittest

from catalog.media import fetch_telegram_image


class Response:
    status_code = 200

    def __init__(self, *, body=None, chunks=None, headers=None):
        self._body = body or {}
        self._chunks = list(chunks or [])
        self.headers = headers or {}
        self.closed = False

    def json(self):
        return self._body

    def iter_content(self, chunk_size):
        assert chunk_size == 64 * 1024
        yield from self._chunks

    def close(self):
        self.closed = True


class Session:
    def __init__(self, path='photos/file_1.jpg', chunks=None, headers=None):
        self.path = path
        self.chunks = chunks if chunks is not None else [b'\xff\xd8\xffimage']
        self.headers = headers or {}
        self.calls = []
        self.download = None

    def get(self, url, **kwargs):
        self.calls.append((url, kwargs))
        if len(self.calls) == 1:
            return Response(body={'ok': True, 'result': {'file_path': self.path}})
        self.download = Response(chunks=self.chunks, headers=self.headers)
        return self.download


class TelegramMediaTests(unittest.TestCase):
    def test_fetches_an_image_with_a_streaming_size_limit(self):
        image = b'\xff\xd8\xffimage-bytes'
        session = Session(chunks=[image[:4], image[4:]])

        result, mime_type = fetch_telegram_image(
            'AgACAgQAAxkBAAIB', bot_token='test-token', session=session, max_bytes=100)

        self.assertEqual(result, image)
        self.assertEqual(mime_type, 'image/jpeg')
        self.assertTrue(session.calls[1][1]['stream'])
        self.assertEqual(session.calls[1][1]['timeout'], (10, 20))
        self.assertTrue(session.download.closed)

    def test_rejects_an_oversized_image_during_streaming(self):
        session = Session(chunks=[b'12345', b'67890', b'not-read'])

        with self.assertRaisesRegex(ValueError, 'telegram_media_unavailable'):
            fetch_telegram_image('AgACAgQAAxkBAAIB', bot_token='test-token',
                session=session, max_bytes=8)

        self.assertEqual(len(session.calls), 2)
        self.assertTrue(session.download.closed)

    def test_rejects_unsafe_telegram_paths_and_mismatched_image_content(self):
        unsafe = Session(path='../outside.jpg')
        with self.assertRaisesRegex(ValueError, 'telegram_media_unavailable'):
            fetch_telegram_image('AgACAgQAAxkBAAIB', bot_token='test-token', session=unsafe)
        self.assertEqual(len(unsafe.calls), 1)

        mismatch = Session(chunks=[b'not-a-jpeg'])
        with self.assertRaisesRegex(ValueError, 'telegram_media_unsupported'):
            fetch_telegram_image('AgACAgQAAxkBAAIB', bot_token='test-token', session=mismatch)


if __name__ == '__main__':
    unittest.main()
