#!/usr/bin/env python3
"""Measure Gemini extraction against a private, owner-labelled JSONL corpus.

The source messages and labels stay in the caller-supplied file. Output contains
aggregate metrics only. Each message makes one paid Gemini request.
"""
from __future__ import annotations

import argparse
import json
import re
import sys
import unicodedata
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from catalog.gemini import ai_extraction_configured, extract_message_products


def _identity(value: object) -> str:
    normalized = unicodedata.normalize('NFKC', str(value or '')).casefold()
    return re.sub(r'\s+', ' ', normalized).strip()


def _number(value: object) -> int | None:
    if value in (None, '') or isinstance(value, bool):
        return None
    try:
        return int(float(str(value).replace(',', '').replace('٬', '')))
    except (TypeError, ValueError, OverflowError):
        return None


def _load_dataset(path: Path, max_cases: int) -> list[dict]:
    rows = []
    with path.open('r', encoding='utf-8') as source:
        for line_number, line in enumerate(source, 1):
            if not line.strip():
                continue
            try:
                row = json.loads(line)
            except json.JSONDecodeError as exc:
                raise ValueError(f'Invalid JSON on line {line_number}') from exc
            if (not isinstance(row, dict) or not isinstance(row.get('text'), str)
                    or not row['text'].strip() or not isinstance(row.get('channel'), str)
                    or not isinstance(row.get('expected'), list) or not row['expected']):
                raise ValueError(f'Invalid labelled case on line {line_number}')
            for expected in row['expected']:
                if (not isinstance(expected, dict) or not _identity(expected.get('name'))
                        or expected.get('price_role') not in {'unit', 'package'}
                        or _number(expected.get('price_amount')) is None):
                    raise ValueError(f'Invalid expected product on line {line_number}')
                count = expected.get('package_count')
                if count not in (None, '') and _number(count) is None:
                    raise ValueError(f'Invalid package count on line {line_number}')
            rows.append(row)
            if max_cases and len(rows) >= max_cases:
                break
    if not rows:
        raise ValueError('The labelled dataset is empty')
    return rows


def _metrics(cases: list[dict]) -> dict:
    metrics = Counter()
    for case in cases:
        metrics['messages'] += 1
        expected = case['expected']
        metrics['expected_products'] += len(expected)
        metrics['identity_checked'] += len(expected)
        metrics['price_checked'] += len(expected)
        metrics['basis_checked'] += len(expected)
        metrics['package_count_checked'] += sum(
            wanted.get('package_count') not in (None, '') for wanted in expected)
        try:
            extracted = extract_message_products(case['text'], case['channel'])
        except Exception:
            metrics['failed_messages'] += 1
            metrics['predicted_products'] += 0
            continue
        metrics['predicted_products'] += len(extracted)
        candidates: dict[str, list[dict]] = {}
        for product in extracted:
            candidates.setdefault(_identity(product.get('name')), []).append(product)
        matched_predictions = 0
        for wanted in expected:
            name = _identity(wanted['name'])
            matches = candidates.get(name) or []
            if not matches:
                continue
            predicted = matches.pop(0)
            matched_predictions += 1
            metrics['identity_correct'] += 1
            metrics['price_correct'] += int(_number(predicted.get('source_price_amount')) == _number(wanted['price_amount']))
            metrics['basis_correct'] += int(predicted.get('confirmed_price_role') == wanted['price_role'])
            if wanted.get('package_count') not in (None, ''):
                metrics['package_count_correct'] += int(
                    _number(predicted.get('package_count')) == _number(wanted['package_count']))
        metrics['extra_products'] += max(0, len(extracted) - matched_predictions)
    return dict(metrics)


def _rate(correct: int, checked: int) -> str:
    return f'{correct}/{checked} ({100 * correct / checked:.1f}%)' if checked else 'not measured'


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('dataset', type=Path, help='Private UTF-8 JSONL file with owner-labelled cases')
    parser.add_argument('--max-cases', type=int, default=0, help='Optional cap; zero processes the full dataset')
    args = parser.parse_args()
    if not ai_extraction_configured():
        parser.error('Set GOOGLE_API_KEY or AI_API_KEY in the environment first')
    try:
        cases = _load_dataset(args.dataset, max(0, args.max_cases))
        result = _metrics(cases)
    except (OSError, ValueError) as exc:
        parser.error(str(exc))
    print(f"Messages: {result.get('messages', 0)}; failed Gemini calls: {result.get('failed_messages', 0)}")
    print(f"Expected products: {result.get('expected_products', 0)}; predicted products: {result.get('predicted_products', 0)}; extra products: {result.get('extra_products', 0)}")
    print(f"Exact product identity: {_rate(result.get('identity_correct', 0), result.get('expected_products', 0))}")
    print(f"Source price amount: {_rate(result.get('price_correct', 0), result.get('price_checked', 0))}")
    print(f"Unit/package basis: {_rate(result.get('basis_correct', 0), result.get('basis_checked', 0))}")
    print(f"Package count: {_rate(result.get('package_count_correct', 0), result.get('package_count_checked', 0))}")
    return 0 if not result.get('failed_messages') else 2


if __name__ == '__main__':
    raise SystemExit(main())
