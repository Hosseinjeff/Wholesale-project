<?php
/** Plugin Name: Molavi Native Domain Schemas */
declare(strict_types=1);
defined('ABSPATH') || exit;
function molavi_domain_schema_sql(string $store):array {
    switch($store){
    case 'pipeline': return [
        // catalog/durable.py
        <<<'MOLAVI_SCHEMA_PIPELINE_0'
CREATE TABLE IF NOT EXISTS jobs (
                id TEXT PRIMARY KEY, kind TEXT NOT NULL, payload TEXT NOT NULL,
                state TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0,
                available REAL NOT NULL, lease TEXT, expires REAL,
                result TEXT, error TEXT, created_at REAL, updated_at REAL);
                CREATE TABLE IF NOT EXISTS inbox (
                id TEXT PRIMARY KEY, payload TEXT NOT NULL, batch_id TEXT, received_at REAL);
                CREATE TABLE IF NOT EXISTS publication_holds (
                hold_id TEXT PRIMARY KEY, reason TEXT NOT NULL, created_at REAL NOT NULL);
                CREATE TABLE IF NOT EXISTS publication_hold_resolutions (
                id INTEGER PRIMARY KEY, hold_id TEXT NOT NULL, reason TEXT NOT NULL,
                actor_id TEXT NOT NULL, note TEXT NOT NULL, resolved_at REAL NOT NULL);
                CREATE TABLE IF NOT EXISTS sheet_sync_conflicts (
                conflict_id TEXT PRIMARY KEY, product_id TEXT NOT NULL, product_name TEXT NOT NULL,
                channel TEXT NOT NULL, field TEXT NOT NULL, source_revision TEXT NOT NULL,
                base_value TEXT NOT NULL, server_value TEXT NOT NULL, sheet_value TEXT NOT NULL,
                state TEXT NOT NULL DEFAULT 'open', created_at REAL NOT NULL,
                resolved_at REAL, choice TEXT, actor_id TEXT, note TEXT);
                CREATE TABLE IF NOT EXISTS sheet_projection_state (
                singleton INTEGER PRIMARY KEY CHECK(singleton=1), observed_digest TEXT NOT NULL DEFAULT '',
                published_digest TEXT NOT NULL DEFAULT '', job_id TEXT);
                CREATE TABLE IF NOT EXISTS pipeline_workers (
                worker_id TEXT PRIMARY KEY, started_at REAL NOT NULL, heartbeat_at REAL NOT NULL,
                progress_at REAL NOT NULL,
                state TEXT NOT NULL, current_job_id TEXT, last_claimed_job_id TEXT,
                last_claimed_at REAL, last_completed_job_id TEXT, last_completed_at REAL,
                last_error_code TEXT);
                CREATE TABLE IF NOT EXISTS pending_telegram_chats (
                chat_id TEXT PRIMARY KEY, first_seen_at REAL NOT NULL, last_seen_at REAL NOT NULL,
                seen_count INTEGER NOT NULL DEFAULT 1, revision INTEGER NOT NULL DEFAULT 1,
                state TEXT NOT NULL DEFAULT 'pending', decided_at REAL, decided_by TEXT);
MOLAVI_SCHEMA_PIPELINE_0,
        // catalog/durable.py
        <<<'MOLAVI_SCHEMA_PIPELINE_1'
CREATE TRIGGER IF NOT EXISTS jobs_set_timestamps_after_insert
                AFTER INSERT ON jobs
                WHEN NEW.created_at IS NULL OR NEW.updated_at IS NULL
                BEGIN
                    UPDATE jobs SET
                        created_at=COALESCE(NEW.created_at,(julianday('now')-2440587.5)*86400.0),
                        updated_at=COALESCE(NEW.updated_at,(julianday('now')-2440587.5)*86400.0)
                    WHERE id=NEW.id;
                END;
                CREATE TRIGGER IF NOT EXISTS jobs_update_timestamp
                AFTER UPDATE ON jobs
                WHEN NEW.updated_at IS OLD.updated_at
                BEGIN
                    UPDATE jobs SET updated_at=(julianday('now')-2440587.5)*86400.0
                    WHERE id=NEW.id;
                END;
MOLAVI_SCHEMA_PIPELINE_1,
        // catalog/ai_confirmations.py
        <<<'MOLAVI_SCHEMA_PIPELINE_2'
CREATE TABLE IF NOT EXISTS ai_confirmation_decisions (
                decision_id TEXT PRIMARY KEY, case_id TEXT NOT NULL, offer_id TEXT NOT NULL,
                signature TEXT NOT NULL, choice TEXT NOT NULL, fields_json TEXT NOT NULL,
                actor_id TEXT NOT NULL, note TEXT NOT NULL, created_at REAL NOT NULL)
MOLAVI_SCHEMA_PIPELINE_2,
        // catalog/ai_confirmations.py
        <<<'MOLAVI_SCHEMA_PIPELINE_3'
CREATE INDEX IF NOT EXISTS ai_confirmation_case_history
                ON ai_confirmation_decisions(case_id,created_at)
MOLAVI_SCHEMA_PIPELINE_3,
        // catalog/ai_confirmations.py
        <<<'MOLAVI_SCHEMA_PIPELINE_4'
CREATE TABLE IF NOT EXISTS ai_message_dismissals (
                message_key TEXT PRIMARY KEY, message_id TEXT NOT NULL, batch_id TEXT NOT NULL,
                channel_username TEXT NOT NULL, source_timestamp TEXT NOT NULL,
                content_sha256 TEXT NOT NULL, media_unique_id TEXT NOT NULL DEFAULT '',
                actor_id TEXT NOT NULL, note TEXT NOT NULL,
                created_at REAL NOT NULL)
MOLAVI_SCHEMA_PIPELINE_4,
        // catalog/ai_confirmations.py
        <<<'MOLAVI_SCHEMA_PIPELINE_5'
CREATE TABLE IF NOT EXISTS ai_source_reextractions (
                source_key TEXT PRIMARY KEY, batch_id TEXT NOT NULL, outcome TEXT NOT NULL,
                run_id TEXT NOT NULL, actor_id TEXT NOT NULL, completed_at REAL NOT NULL,
                policy_version TEXT NOT NULL DEFAULT '')
MOLAVI_SCHEMA_PIPELINE_5,
        // catalog/inventory_lots.py
        <<<'MOLAVI_SCHEMA_PIPELINE_6'
CREATE TABLE IF NOT EXISTS stock_lots (
                lot_id TEXT PRIMARY KEY, sku TEXT NOT NULL, supplier TEXT NOT NULL,
                purchase_date TEXT,
                received_at TEXT NOT NULL, production_date TEXT, expiry_date TEXT,
                units INTEGER NOT NULL, unit_cost INTEGER NOT NULL, receipt_hash TEXT NOT NULL,
                state TEXT NOT NULL CHECK(state IN ('ready','review','held')));
                CREATE TABLE IF NOT EXISTS stock_allocations (
                order_id TEXT NOT NULL, sku TEXT NOT NULL, lot_id TEXT NOT NULL,
                units INTEGER NOT NULL, state TEXT NOT NULL CHECK(state IN ('reserved','released','fulfilled')),
                PRIMARY KEY(order_id,sku,lot_id));
                CREATE TABLE IF NOT EXISTS stock_reservation_requests (
                order_id TEXT NOT NULL, sku TEXT NOT NULL, requested INTEGER NOT NULL,
                PRIMARY KEY(order_id,sku));
MOLAVI_SCHEMA_PIPELINE_6,
        // catalog/inventory_lots.py
        <<<'MOLAVI_SCHEMA_PIPELINE_7'
CREATE TABLE IF NOT EXISTS stock_lot_events (
                sequence INTEGER PRIMARY KEY AUTOINCREMENT, event_id TEXT NOT NULL UNIQUE,
                lot_id TEXT NOT NULL, actor_id TEXT NOT NULL, action TEXT NOT NULL,
                delta_units INTEGER NOT NULL, reason TEXT NOT NULL, note TEXT NOT NULL,
                created_at TEXT NOT NULL, new_state TEXT,
                expected_revision TEXT NOT NULL DEFAULT '')
MOLAVI_SCHEMA_PIPELINE_7,
        // catalog/inventory_lots.py
        <<<'MOLAVI_SCHEMA_PIPELINE_8'
CREATE TABLE IF NOT EXISTS stock_lot_receipt_audit (
                lot_id TEXT NOT NULL, receipt_hash TEXT NOT NULL, actor_id TEXT NOT NULL,
                note TEXT NOT NULL, created_at TEXT NOT NULL,
                PRIMARY KEY(lot_id,receipt_hash,actor_id));
MOLAVI_SCHEMA_PIPELINE_8,
        // catalog/order_fulfillment.py
        <<<'MOLAVI_SCHEMA_PIPELINE_9'
CREATE TABLE IF NOT EXISTS paid_order_plans (
                order_id TEXT PRIMARY KEY, request_hash TEXT NOT NULL,
                terms_hash TEXT NOT NULL, source_order TEXT NOT NULL,
                result TEXT, state TEXT NOT NULL, updated_at TEXT NOT NULL,
                supplier_terms TEXT NOT NULL DEFAULT '{}');
                CREATE TABLE IF NOT EXISTS paid_order_decisions (
                decision_id INTEGER PRIMARY KEY AUTOINCREMENT, order_id TEXT NOT NULL,
                actor_id TEXT NOT NULL, note TEXT NOT NULL, terms TEXT NOT NULL,
                created_at TEXT NOT NULL);
MOLAVI_SCHEMA_PIPELINE_9,
        // catalog/order_operations.py
        <<<'MOLAVI_SCHEMA_PIPELINE_10'
CREATE TABLE IF NOT EXISTS order_workflows (
                order_id TEXT PRIMARY KEY, revision INTEGER NOT NULL, workflow TEXT NOT NULL,
                updated_at TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS order_workflow_events (
                event_id TEXT PRIMARY KEY, order_id TEXT NOT NULL, actor_id TEXT NOT NULL,
                action TEXT NOT NULL, details TEXT NOT NULL, note TEXT NOT NULL,
                created_at TEXT NOT NULL);
MOLAVI_SCHEMA_PIPELINE_10,
        // catalog/clearance_pricing.py
        <<<'MOLAVI_SCHEMA_PIPELINE_11'
CREATE TABLE IF NOT EXISTS clearance_proposals (
                proposal_id TEXT PRIMARY KEY, lot_id TEXT NOT NULL, sku TEXT NOT NULL,
                receipt_hash TEXT NOT NULL, actor_id TEXT NOT NULL, note TEXT NOT NULL,
                inputs TEXT NOT NULL, result TEXT NOT NULL, created_at TEXT NOT NULL)
MOLAVI_SCHEMA_PIPELINE_11,
        // catalog/supplier_freshness.py
        <<<'MOLAVI_SCHEMA_PIPELINE_12'
CREATE TABLE IF NOT EXISTS supplier_price_evidence (
                offer_id TEXT PRIMARY KEY, source_revision TEXT NOT NULL,
                verified_at TEXT NOT NULL, method TEXT NOT NULL, verified_by TEXT)
MOLAVI_SCHEMA_PIPELINE_12,
        // catalog/supplier_freshness.py
        <<<'MOLAVI_SCHEMA_PIPELINE_13'
CREATE TABLE IF NOT EXISTS supplier_price_evidence_history (
                id INTEGER PRIMARY KEY, offer_id TEXT NOT NULL, source_revision TEXT NOT NULL,
                verified_at TEXT NOT NULL, method TEXT NOT NULL, verified_by TEXT,
                note TEXT NOT NULL DEFAULT '')
MOLAVI_SCHEMA_PIPELINE_13,
        // catalog/supplier_freshness.py
        <<<'MOLAVI_SCHEMA_PIPELINE_14'
CREATE TABLE IF NOT EXISTS supplier_price_verification_commands (
                command_id TEXT PRIMARY KEY, offer_id TEXT NOT NULL, source_revision TEXT NOT NULL,
                verified_at TEXT NOT NULL, verified_by TEXT NOT NULL, note TEXT NOT NULL)
MOLAVI_SCHEMA_PIPELINE_14,
        // catalog/product_status.py
        <<<'MOLAVI_SCHEMA_PIPELINE_15'
CREATE TABLE IF NOT EXISTS product_status_snapshots (
                snapshot_id TEXT PRIMARY KEY, captured_at TEXT NOT NULL, reason TEXT NOT NULL,
                product_count INTEGER NOT NULL, changed_count INTEGER NOT NULL,
                record_digest TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS product_status_records (
                snapshot_id TEXT NOT NULL, product_key TEXT NOT NULL, product_name TEXT NOT NULL,
                state_json TEXT NOT NULL, PRIMARY KEY(snapshot_id, product_key));
                CREATE INDEX IF NOT EXISTS product_status_captured_idx
                ON product_status_snapshots(captured_at DESC);
MOLAVI_SCHEMA_PIPELINE_15,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS published_product_reviews (
    case_id TEXT PRIMARY KEY,
    product_id TEXT NOT NULL,
    offer_id TEXT NOT NULL,
    signature TEXT NOT NULL,
    opened_by TEXT NOT NULL,
    opened_at REAL NOT NULL,
    resolved_decision_id TEXT
);
CREATE UNIQUE INDEX IF NOT EXISTS active_published_product_review
    ON published_product_reviews(product_id,offer_id,signature)
    WHERE resolved_decision_id IS NULL
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS product_message_choices (
    woo_product_id INTEGER NOT NULL, offer_id TEXT NOT NULL, state TEXT NOT NULL,
    case_id TEXT, actor TEXT NOT NULL, decided_at REAL NOT NULL, PRIMARY KEY(woo_product_id,offer_id)
)
SQL,
        // Supplier link checks (round eight): one row per product, latest check.
        // outcome: 'unchanged' (a) | 'changed' (b) | 'uncheckable' (c).
        // reason_code carries the C-/C-S category for outcome (c), or the
        // unchanged detail ('identical' | 'price_unchanged') for outcome (a).
        // Nothing here ever re-verifies a price: only the manual-verify call
        // writes supplier_price_evidence. A changed live price is recorded
        // as surfaced evidence only and is never written to any price field.
        <<<'SQL'
CREATE TABLE IF NOT EXISTS supplier_link_checks (
    product_id TEXT PRIMARY KEY, offer_id TEXT NOT NULL,
    title TEXT NOT NULL, url TEXT NOT NULL, source_revision TEXT NOT NULL,
    stored_sale_price TEXT NOT NULL, stored_message TEXT NOT NULL,
    outcome TEXT, reason_code TEXT, live_price TEXT, live_text TEXT,
    route TEXT, checked_at INTEGER, next_at INTEGER NOT NULL,
    lease TEXT, confirmed_at INTEGER, confirmed_verified_at TEXT,
    created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS supplier_link_checks_due ON supplier_link_checks(next_at) WHERE outcome IS NOT NULL OR url IS NOT NULL;
CREATE TABLE IF NOT EXISTS supplier_link_check_events (
    id TEXT PRIMARY KEY, product_id TEXT NOT NULL, offer_id TEXT NOT NULL,
    title TEXT NOT NULL, kind TEXT NOT NULL,
    old_price TEXT, live_price TEXT, code TEXT, url TEXT NOT NULL,
    occurred_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS supplier_link_check_events_recent ON supplier_link_check_events(occurred_at);
CREATE INDEX IF NOT EXISTS supplier_link_check_events_product ON supplier_link_check_events(product_id, occurred_at);
SQL,
        // Market agreement checks (round nine): one row per product, latest check.
        // outcome: 'agree' | 'disagree' | 'no_quotes' | 'chain_limit'.
        // reason_code carries the agreement detail ('agreement' |
        // 'rezvan_crosscheck' | 'quotes_disagree' | 'single_quote_no_reference' |
        // 'rezvan_mismatch' | 'spread_too_thin' | 'spread_too_wide' | 'no_match' |
        // 'lookup_not_ready' | 'lookup_pending' | 'quotes_stale' | 'no_identity' |
        // 'chain_limit'). An 'agree' row means supplier_price_evidence was
        // written with method 'market'; every other outcome writes no evidence.
        <<<'SQL'
CREATE TABLE IF NOT EXISTS market_agreement_checks (
    product_id TEXT PRIMARY KEY, offer_id TEXT NOT NULL,
    title TEXT NOT NULL, source_revision TEXT NOT NULL,
    stored_sale_price TEXT NOT NULL,
    outcome TEXT, reason_code TEXT, basis TEXT,
    quote_url TEXT, quote_price INTEGER, implied_spread REAL,
    checked_at INTEGER, next_at INTEGER NOT NULL, lease TEXT,
    verified_at TEXT, created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS market_agreement_checks_due ON market_agreement_checks(next_at);
CREATE TABLE IF NOT EXISTS market_agreement_events (
    id TEXT PRIMARY KEY, product_id TEXT NOT NULL, offer_id TEXT NOT NULL,
    title TEXT NOT NULL, kind TEXT NOT NULL,
    old_price TEXT, quote_price INTEGER, implied_spread REAL, code TEXT, url TEXT,
    occurred_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS market_agreement_events_recent ON market_agreement_events(occurred_at);
CREATE INDEX IF NOT EXISTS market_agreement_events_product ON market_agreement_events(product_id, occurred_at);
SQL,
    ];
    case 'reference': return [
        // reference_import/decisions.py
        <<<'MOLAVI_SCHEMA_REFERENCE_0'
CREATE TABLE IF NOT EXISTS reference_events (
                event_id TEXT PRIMARY KEY, reason TEXT NOT NULL,
                snapshot_id TEXT, created_at TEXT NOT NULL,
                choice TEXT, actor_id TEXT, decided_at TEXT)
MOLAVI_SCHEMA_REFERENCE_0,
        // reference_import/decisions.py
        <<<'MOLAVI_SCHEMA_REFERENCE_1'
CREATE TABLE IF NOT EXISTS reference_retirements (
                event_id TEXT PRIMARY KEY, replacement_snapshot_id TEXT NOT NULL,
                retired_at TEXT NOT NULL)
MOLAVI_SCHEMA_REFERENCE_1,
        // reference_import/snapshots.py
        <<<'MOLAVI_SCHEMA_REFERENCE_2'
CREATE TABLE IF NOT EXISTS reference_snapshots (
                snapshot_id TEXT PRIMARY KEY, payload TEXT NOT NULL,
                reviewed_by TEXT, review_note TEXT, reviewed_at TEXT);
                CREATE TABLE IF NOT EXISTS reference_active (
                singleton INTEGER PRIMARY KEY CHECK(singleton=1), snapshot_id TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS reference_activations (
                activation_id INTEGER PRIMARY KEY, snapshot_id TEXT NOT NULL,
                previous_snapshot_id TEXT, actor_id TEXT NOT NULL, activated_at TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS reference_source_products (
                source_product_id TEXT PRIMARY KEY, product_code TEXT NOT NULL, payload TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS reference_snapshot_source_products (
                snapshot_id TEXT NOT NULL, source_product_id TEXT NOT NULL,
                product_code TEXT NOT NULL, payload TEXT NOT NULL,
                PRIMARY KEY(snapshot_id,source_product_id));
                CREATE TABLE IF NOT EXISTS reference_projection_state (
                singleton INTEGER PRIMARY KEY CHECK(singleton=1), snapshot_id TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS reference_reconciliation_state (
                singleton INTEGER PRIMARY KEY CHECK(singleton=1), snapshot_id TEXT NOT NULL);
                CREATE TABLE IF NOT EXISTS reference_source_check (
                singleton INTEGER PRIMARY KEY CHECK(singleton=1), snapshot_id TEXT NOT NULL,
                checked_at TEXT NOT NULL);
MOLAVI_SCHEMA_REFERENCE_2,
        // reference_import/item_reviews.py
        <<<'MOLAVI_SCHEMA_REFERENCE_3'
CREATE TABLE IF NOT EXISTS reference_item_decisions (
                sequence INTEGER PRIMARY KEY AUTOINCREMENT,
                decision_id TEXT NOT NULL UNIQUE,
                case_id TEXT NOT NULL,
                offer_id TEXT NOT NULL,
                snapshot_id TEXT NOT NULL,
                signature TEXT NOT NULL,
                choice TEXT NOT NULL,
                reference_id TEXT NOT NULL,
                price_role TEXT NOT NULL,
                actor_id TEXT NOT NULL,
                note TEXT NOT NULL,
                created_at TEXT NOT NULL,
                supersedes_decision_id TEXT)
MOLAVI_SCHEMA_REFERENCE_3,
        // reference_import/item_reviews.py
        <<<'MOLAVI_SCHEMA_REFERENCE_4'
CREATE INDEX IF NOT EXISTS reference_item_case_idx ON reference_item_decisions(case_id, sequence)
MOLAVI_SCHEMA_REFERENCE_4,
        // reference_import/item_reviews.py
        <<<'MOLAVI_SCHEMA_REFERENCE_5'
CREATE INDEX IF NOT EXISTS reference_item_signature_idx ON reference_item_decisions(snapshot_id, signature, sequence)
MOLAVI_SCHEMA_REFERENCE_5,
        // reference_import/item_reviews.py
        <<<'MOLAVI_SCHEMA_REFERENCE_6'
CREATE TABLE IF NOT EXISTS reference_item_inheritances (
                inheritance_id TEXT PRIMARY KEY,
                snapshot_id TEXT NOT NULL,
                source_snapshot_id TEXT NOT NULL,
                source_decision_id TEXT NOT NULL,
                signature TEXT NOT NULL,
                reference_id TEXT NOT NULL,
                price_role TEXT NOT NULL,
                prior_reference_price REAL,
                current_reference_price REAL,
                change_ratio REAL,
                state TEXT NOT NULL,
                created_at TEXT NOT NULL,
                UNIQUE(snapshot_id, signature))
MOLAVI_SCHEMA_REFERENCE_6,
        // reference_import/refresh_scheduler.py
        <<<'MOLAVI_SCHEMA_REFERENCE_7'
CREATE TABLE IF NOT EXISTS reference_refresh_state (
                singleton INTEGER PRIMARY KEY CHECK(singleton=1), last_attempt_at TEXT,
                state TEXT, occurrence_id TEXT, snapshot_id TEXT, error_code TEXT,
                change_summary TEXT)
MOLAVI_SCHEMA_REFERENCE_7,
        'CREATE TABLE IF NOT EXISTS reference_refresh_progress (singleton INTEGER PRIMARY KEY CHECK(singleton=1), occurrence_id TEXT NOT NULL, detail_json TEXT NOT NULL)',
        <<<'SQL'
CREATE TABLE IF NOT EXISTS market_price_lookups (
    lookup_key TEXT PRIMARY KEY, identity_json TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1, priority INTEGER NOT NULL DEFAULT 0,
    state TEXT NOT NULL DEFAULT 'queued', next_at INTEGER NOT NULL,
    attempted_at INTEGER, checked_at INTEGER, candidates_json TEXT NOT NULL DEFAULT '[]',
    selected_url TEXT, selected_by TEXT, selected_at INTEGER)
SQL,
        'CREATE INDEX IF NOT EXISTS market_price_due ON market_price_lookups(active,next_at)',
        'CREATE TABLE IF NOT EXISTS market_price_budget (day TEXT PRIMARY KEY, used INTEGER NOT NULL)',
        <<<'SQL'
CREATE TABLE IF NOT EXISTS market_product_sources (
    target TEXT PRIMARY KEY, product_id TEXT NOT NULL, offer_id TEXT NOT NULL,
    title TEXT NOT NULL, identity_json TEXT NOT NULL,
    url TEXT, quote_json TEXT, regular_price INTEGER,
    next_at INTEGER, checked_at INTEGER, verified_at INTEGER, lease TEXT, failure TEXT,
    attachment_id INTEGER, image_identity_json TEXT, image_source_url TEXT,
    changed_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS market_product_sources_due ON market_product_sources(next_at) WHERE url IS NOT NULL;
CREATE TABLE IF NOT EXISTS market_source_events (
    id TEXT PRIMARY KEY, product_id TEXT NOT NULL, offer_id TEXT NOT NULL,
    title TEXT NOT NULL, kind TEXT NOT NULL,
    old_price INTEGER, regular_price INTEGER, code TEXT,
    occurred_at INTEGER NOT NULL, publish_pending INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS market_source_events_recent ON market_source_events(occurred_at);
CREATE INDEX IF NOT EXISTS market_source_events_pending ON market_source_events(publish_pending,occurred_at);
SQL,
    ];
    default: throw new InvalidArgumentException('Unknown domain store');
    }
}
