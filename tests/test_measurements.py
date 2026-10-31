import unittest

from catalog.measurements import (
    extract_measurement,
    measurement_signature,
    normalize_measurement_tokens,
)


class PersianMeasurementTests(unittest.TestCase):
    def test_compact_gram_suffix_and_adjacent_retail_and_pack_markers(self):
        title = 'آبلیمو مجید کوچک180گ(138ف)20ع***'
        self.assertEqual(extract_measurement(title, 'weight'), '180 گرم')
        self.assertEqual(extract_measurement(title, 'volume'), '')

    def test_persian_decimal_slash_liter_suffix_and_arabic_digits(self):
        title = 'آبلیمو دست چین١/٢ل (٣٠٤ف)٦ع***'
        self.assertEqual(extract_measurement(title, 'volume'), '1.2 لیتر')
        self.assertEqual(measurement_signature(title, 'volume'), measurement_signature('1200 ml', 'volume'))
        self.assertEqual(extract_measurement('آبلیمو1/5ل', 'volume'), '1.5 لیتر')

    def test_weight_and_volume_are_not_cross_classified(self):
        self.assertEqual(extract_measurement('کنسرو 180 گرم', 'volume'), '')
        self.assertEqual(extract_measurement('آبمیوه 250میل', 'weight'), '')
        self.assertEqual(extract_measurement('محصول 1/2 کیلوگرم', 'weight'), '1.2 کیلوگرم')
        self.assertEqual(extract_measurement('محصول 1⁄2 کیلوگرم', 'weight'), '0.5 کیلوگرم')

    def test_normalized_matching_title_preserves_measurement_meaning(self):
        self.assertEqual(
            normalize_measurement_tokens('آبلیمو دست چین1/2ل (304ف)6ع'),
            'آبلیمو دست چین1.2 لیتر (304ف)6ع',
        )


if __name__ == '__main__':
    unittest.main()
