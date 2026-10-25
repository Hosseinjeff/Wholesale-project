# GAS function inventory

Revision 3.0; source `google_apps_script.js` at checkout `1b58f55`.

Scope note, snapshot 4.0: this remains the historical/root inventory. The uploaded
eight-file GAS snapshot has its own 176-function inventory in
`recovered-gas-inventory.md` and supplementary audit in `recovered-gas-audit.md`.

All 67 named top-level declarations are classified below. This is an inventory and
disposition map, not proof of semantic equivalence. Object-method engines such as
`NormalizationEngine` and `MessageClassifier` are additionally exercised/reviewed at
their parser/intake use sites; external GAS trigger bindings remain unverified.

Internal call counts are text occurrences excluding the declaration. A zero count does
not prove a helper is unused: the Apps Script editor, triggers, or another project could call it.

| GAS function / source line | Python destination or disposition | Audit status | Internal occurrences |
|---|---|---|---|
| `logTrace` · 17 | Python logging + ExecutionLogs | Changed logging surface; trace UI not equivalent | 77 |
| `clearTrace` · 27 | Python logging + ExecutionLogs | Changed logging surface; trace UI not equivalent | 1 |
| `persianToEnglishNumbers` · 32 | catalog.extract.persian_to_english_numbers | Covered by selected extraction fixtures | 4 |
| `getColumnIndexByHeader` · 194 | catalog.sync._headers / header-name lookup | Ported architecture; sheet schema preserved/extended | 5 |
| `doGet` · 229 | app health/desk/shop APIs; retained GAS diagnostics | Different API surface; unsafe legacy mutation A-10 | 0 |
| `doPost` · 909 | app.BatchManager + catalog.sync.finalize_batch | Ported + GAS fallback retained; A-02/03/04/05 | 6 |
| `validateData` · 1091 | app.inbound_payload and queue validation | No one-to-one validator; intake contract needs explicit validation coverage | 1 |
| `checkForDuplicates` · 1117 | catalog.sync._unseen_offer_rows | Narrow batch-scoped dedupe; A-02 | 2 |
| `isDuplicate` · 1155 | catalog.sync._unseen_offer_rows | Narrow batch-scoped dedupe; A-02 | 0 |
| `importMessageData` · 1160 | catalog.sync._finalize_batch | Append-only raw intake; duplicate/update semantics differ | 1 |
| `importProductData` · 1219 | catalog.sync.import_product_data | Offers/current-book enhancement; selected tests pass | 4 |
| `processPendingMessages` · 1334 | Retained GAS maintenance; Python batch replay helpers | No equivalent exposed recovery route; decide/retire deliberately | 1 |
| `batchIngestChunk` · 1425 | No-op legacy chunk path; full batch finalization | Retained protocol compatibility, not durable streaming | 1 |
| `finalizeBatch` · 1431 | catalog.sync.finalize_batch | Ported ambiguous acknowledgement; A-03 | 1 |
| `countBatchMessages` · 1512 | catalog.sync._count_batch_messages / _list_batch_message_ids | Ported batch helpers; not independent durability | 1 |
| `listBatchMessageIds` · 1526 | catalog.sync._count_batch_messages / _list_batch_message_ids | Ported batch helpers; not independent durability | 1 |
| `deleteProductsByBatchId` · 1541 | catalog.sync._rollback_catalog | Changed intentionally to restore from Offers; rollback tests pass | 2 |
| `processBatchProducts` · 1558 | catalog.sync._process_batch_products | Retries/rollback preserved; acknowledgement requires fix | 1 |
| `recordBatchStatus` · 1596 | BatchStatus sheet | Preserved; local tests pass | 2 |
| `storeLastBatchStatus` · 1612 | GAS ScriptProperties; Python BatchStatus | Diagnostic contract not identical; A-06 | 2 |
| `checkSystemicErrors` · 1629 | catalog.sync._check_systemic_errors | Ported issue logging, operational delivery unverified | 1 |
| `extractChannelBonakdarjavan` · 1724 | catalog.extract channel/universal dispatch | Eight-fixture comparison; A-12 | 1 |
| `extractChannelTopShopRahimi` · 1794 | catalog.extract channel/universal dispatch | Eight-fixture comparison; A-12 | 1 |
| `extractChannelNobelshop118` · 1896 | catalog.extract channel/universal dispatch | Eight-fixture comparison; A-12 | 1 |
| `extractProducts` · 2004 | catalog.extract channel/universal dispatch | Eight-fixture comparison; A-12 | 7 |
| `extractUniversalProducts` · 2014 | catalog.extract channel/universal dispatch | Eight-fixture comparison; A-12 | 1 |
| `extractPricesFromLine` · 2154 | catalog.extract corresponding parser/quality helpers | Ported/extended; fixture coverage is limited, not full semantics | 1 |
| `cleanProductName` · 2197 | catalog.extract corresponding parser/quality helpers | Ported/extended; fixture coverage is limited, not full semantics | 2 |
| `finalizeProduct` · 2245 | catalog.extract corresponding parser/quality helpers | Ported/extended; fixture coverage is limited, not full semantics | 2 |
| `extractPackaging` · 2279 | catalog.extract corresponding parser/quality helpers | Ported/extended; fixture coverage is limited, not full semantics | 2 |
| `extractVolume` · 2284 | catalog.extract corresponding parser/quality helpers | Ported/extended; fixture coverage is limited, not full semantics | 2 |
| `inferPriceTypeFromPackaging` · 2290 | catalog.extract corresponding parser/quality helpers | Ported/extended; fixture coverage is limited, not full semantics | 1 |
| `detectPresentationMethod` · 2296 | Legacy helper declarations; logic integrated selectively into Python parser fields | No exact helper API port; call-site counts below; bound external callers unverified | 0 |
| `extractVariationType` · 2307 | Legacy helper declarations; logic integrated selectively into Python parser fields | No exact helper API port; call-site counts below; bound external callers unverified | 0 |
| `analyzePricingForSegment` · 2319 | catalog.extract corresponding parser/quality helpers | Ported/extended; fixture coverage is limited, not full semantics | 1 |
| `performQualityChecks` · 2367 | catalog.extract corresponding parser/quality helpers | Ported/extended; fixture coverage is limited, not full semantics | 1 |
| `logExtractionIssue` · 2410 | catalog.sync._log_issue | Ported logging; no assurance of operator alert delivery | 2 |
| `extractVolumeAndPackaging` · 2430 | Legacy helper declarations; logic integrated selectively into Python parser fields | No exact helper API port; call-site counts below; bound external callers unverified | 0 |
| `extractConsumerPrice` · 2447 | Legacy helper declarations; logic integrated selectively into Python parser fields | No exact helper API port; call-site counts below; bound external callers unverified | 0 |
| `extractStockStatus` · 2462 | Legacy helper declarations; logic integrated selectively into Python parser fields | No exact helper API port; call-site counts below; bound external callers unverified | 0 |
| `extractCategory` · 2481 | catalog.extract corresponding parser/quality helpers | Ported/extended; fixture coverage is limited, not full semantics | 1 |
| `addChannelPatterns` · 2541 | Retained GAS pattern-tooling declarations | No equivalent dynamic Python tool; authority/use needs decision | 1 |
| `getChannelPatterns` · 2547 | Retained GAS pattern-tooling declarations | No equivalent dynamic Python tool; authority/use needs decision | 0 |
| `extractLocation` · 2551 | Legacy helper declarations; logic integrated selectively into Python parser fields | No exact helper API port; call-site counts below; bound external callers unverified | 0 |
| `extractContactInfo` · 2594 | Legacy helper declarations; logic integrated selectively into Python parser fields | No exact helper API port; call-site counts below; bound external callers unverified | 0 |
| `findExistingProduct` · 2614 | catalog.sync current-book/header merge and offer-derived IDs | Data model intentionally changed; do not use legacy writer concurrently | 1 |
| `updateProduct` · 2647 | catalog.sync current-book/header merge and offer-derived IDs | Data model intentionally changed; do not use legacy writer concurrently | 1 |
| `createProductRow` · 2703 | catalog.sync current-book/header merge and offer-derived IDs | Data model intentionally changed; do not use legacy writer concurrently | 2 |
| `generateProductId` · 2756 | catalog.sync current-book/header merge and offer-derived IDs | Data model intentionally changed; do not use legacy writer concurrently | 1 |
| `getOrCreateSheet` · 2765 | GoogleSheetStore.ensure + feature-specific initialization | Ported schema creation; legacy setup GET mutation A-10 | 24 |
| `setupSheet` · 2791 | GoogleSheetStore.ensure + feature-specific initialization | Ported schema creation; legacy setup GET mutation A-10 | 2 |
| `cleanupProductsSheet` · 2823 | Retained GAS destructive maintenance | Do not auto-port/run; review permissions and dependent jobs A-10 | 1 |
| `clearProductsSheet` · 2862 | Retained GAS destructive maintenance | Do not auto-port/run; review permissions and dependent jobs A-10 | 1 |
| `getIngestionEnabled` · 2882 | GAS ScriptProperties intake switch | Python counterpart absent in inspected path; A-06 | 2 |
| `setIngestionEnabled` · 2888 | GAS ScriptProperties intake switch | Python counterpart absent in inspected path; A-06 | 2 |
| `testImport` · 2901 | tests/test_catalog.py + tests/test_inbound.py + current suites | Legacy GAS test utility, not required production runtime; sample coverage differs | 2 |
| `testProductImport` · 2935 | tests/test_catalog.py + tests/test_inbound.py + current suites | Legacy GAS test utility, not required production runtime; sample coverage differs | 0 |
| `testProductUpdate` · 2979 | tests/test_catalog.py + tests/test_inbound.py + current suites | Legacy GAS test utility, not required production runtime; sample coverage differs | 0 |
| `testFashionChannel` · 3023 | tests/test_catalog.py + tests/test_inbound.py + current suites | Legacy GAS test utility, not required production runtime; sample coverage differs | 0 |
| `testImportWithMedia` · 3084 | tests/test_catalog.py + tests/test_inbound.py + current suites | Legacy GAS test utility, not required production runtime; sample coverage differs | 0 |
| `testDuplicateImport` · 3110 | tests/test_catalog.py + tests/test_inbound.py + current suites | Legacy GAS test utility, not required production runtime; sample coverage differs | 0 |
| `testSetup` · 3118 | tests/test_catalog.py + tests/test_inbound.py + current suites | Legacy GAS test utility, not required production runtime; sample coverage differs | 0 |
| `testErrorHandling` · 3124 | tests/test_catalog.py + tests/test_inbound.py + current suites | Legacy GAS test utility, not required production runtime; sample coverage differs | 0 |
| `debugLastMessage` · 3143 | Retained GAS debugging/inspection | No equivalent public Python endpoint; old mutating debug paths A-10 | 1 |
| `debugAllMessages` · 3201 | Retained GAS debugging/inspection | No equivalent public Python endpoint; old mutating debug paths A-10 | 1 |
| `debugPersianPatterns` · 3254 | Retained GAS debugging/inspection | No equivalent public Python endpoint; old mutating debug paths A-10 | 1 |
| `getMessagePatterns` · 3449 | Retained GAS debugging/inspection | No equivalent public Python endpoint; old mutating debug paths A-10 | 0 |

The ReferencePrices producer/consumer does not appear among these historical/root
functions or in the searched history. It has now been recovered from the user's
archive; use its separate inventory. Upstream server and deployment/trigger bindings
still block complete migration sign-off.
