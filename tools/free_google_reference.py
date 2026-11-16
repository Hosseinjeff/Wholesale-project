#!/usr/bin/env python3
"""Explicit bounded free-quota tasks. No scheduler, polling or catalog writes."""
import argparse
from datetime import datetime
import json
from pathlib import Path
import sys
from zoneinfo import ZoneInfo

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from catalog.google_quota import configured_router, QuotaDeferred
from catalog.gemini import google_api_key
from reference_import.ai_matching import EmbeddingIndex, ReferenceSuggestions


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', choices=['status', 'index', 'suggest'])
    parser.add_argument('--snapshot', type=Path, help='Private trusted active snapshot JSON')
    parser.add_argument('--product', type=Path, help='Private extracted product JSON')
    parser.add_argument('--embedding-model', choices=['gemini-embedding-001', 'gemini-embedding-2'])
    parser.add_argument('--limit', type=int, default=100, help='Maximum new embeddings in this invocation')
    args = parser.parse_args()
    router = configured_router()
    if router is None and args.command != 'suggest':
        parser.error('GOOGLE_FREE_ROUTING=1 and verified model budgets are required')
    if router is None and args.embedding_model:
        parser.error('Embedding search requires verified free model budgets')
    if args.command == 'status':
        result = router.status()
        for item in result:
            item['reset_tehran'] = datetime.fromtimestamp(item['reset_at'], ZoneInfo('Asia/Tehran')).isoformat()
    else:
        if not args.snapshot:
            parser.error('A trusted snapshot file is required')
        if args.command == 'index' and not google_api_key():
            parser.error('Configured Google credential required for embedding generation')
        snapshot = json.loads(args.snapshot.read_text())
        if (not isinstance(snapshot, dict) or not snapshot.get('snapshot_id')
                or snapshot.get('coverage_verified') is not True):
            parser.error('A coverage-verified trusted snapshot is required')
        import requests
        with requests.Session() as session:
            if args.command == 'index':
                if not args.embedding_model:
                    parser.error('--embedding-model is required for indexing')
                result = EmbeddingIndex(router, args.embedding_model).index(snapshot['rows'],
                    session=session, api_key=google_api_key(), limit=args.limit)
            else:
                if not args.product:
                    parser.error('--product is required for matching')
                product = json.loads(args.product.read_text())
                result = ReferenceSuggestions(router, embedding_model=args.embedding_model).suggest(
                    product, snapshot['rows'], snapshot_id=snapshot['snapshot_id'],
                    session=session, api_key=google_api_key())
    print(json.dumps(result, ensure_ascii=False, indent=2))


if __name__ == '__main__':
    try:
        main()
    except QuotaDeferred as error:
        print(json.dumps({'state': 'quota_wait', 'retry_at': error.retry_at}))
        sys.exit(75)
