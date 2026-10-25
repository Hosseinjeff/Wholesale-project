# Recovered GAS structural inventory

Revision 4.0. Received/inspected 2026-10-25, Asia/Tehran.

Eight original files, 176 unique named top-level functions. This is a
complete declaration inventory with feature-level dispositions, **not** proof that
every function or externally bound job has semantic parity with Python. Source line
numbers are local to each original file in `legacy-gas/recovered-2026-10-25/`.

The root GAS has 67 named functions: 62 names overlap,
114 are additional in this export, and 5 old
names are absent. A name appearing in both versions says nothing about live use or
equivalence. Those additional names are not automatically that many missing business
features. No exact uploaded file blob exists in the fetched Git objects.

Removed/replaced old declarations: `createProductRow`, `extractVolumeAndPackaging`, `findExistingProduct`, `generateProductId`, `updateProduct`.
Do not infer lost behavior solely from a renamed or replaced function.

See `recovered-gas-audit.md` for the reference schema, behavior, migration map,
18 offline observation groups and remaining closure. Refer to the separate historical
inventory for the 67 root GAS functions. Neither inventory proves installed triggers,
Script Properties values, deployment access or the external refresh server.


## 00_core_config_helpers.js

Configuration, schema and access helpers.

Different configuration/auth contract; map explicitly. Preserve schema meaning and do not copy property values.

| Function | Source line | Name present in root GAS |
|---|---|---|
| `getAvailableEndpoints` | 59 | No |
| `getRequestToken` | 67 | No |
| `validateEndpointAccess` | 71 | No |
| `validatePostIngestionAccess` | 93 | No |
| `logTrace` | 103 | Yes; semantics may differ |
| `clearTrace` | 113 | Yes; semantics may differ |
| `persianToEnglishNumbers` | 118 | Yes; semantics may differ |
| `normalizeProductName` | 133 | No |
| `getColumnIndexByHeader` | 360 | Yes; semantics may differ |
| `debugProperties` | 428 | No |
| `jsonResponse` | 443 | No |
| `debugReportMessagedataProcessingStuck` | 450 | No |

## 10_google_apps_script.js

HTTP dispatch and operator/admin actions.

Different endpoints/acknowledgements. Reference and price-role actions lack equivalent Python handlers; B-04/B-07/B-08.

| Function | Source line | Name present in root GAS |
|---|---|---|
| `doGet` | 1 | Yes; semantics may differ |
| `doPost` | 1134 | Yes; semantics may differ |
| `handleReferenceMatchConfirmation` | 1401 | No |
| `handlePriceRoleConfirmation` | 1461 | No |
| `handleSheetMutationRequest` | 1518 | No |
| `applySheetMutationOperation` | 1565 | No |
| `requirePositiveNumber` | 1637 | No |

## 20_inventory_reference_matching.js

Inventory mapping, identity and price confirmation.

Inventory-log contract differs from Offers/Products/Items. Preserve provenance and correct B-04/B-06 before selecting ownership.

| Function | Source line | Name present in root GAS |
|---|---|---|
| `appendSupplierOfferToInventoryLog` | 1 | No |
| `resolveExtractionStatusPhrase` | 32 | No |
| `createChannelObjectFromProduct` | 38 | No |
| `mergeChannelsToInventoryLog` | 76 | No |
| `buildInventoryMergeKeySet` | 113 | No |
| `createInventoryLogRowFromChannelRow` | 135 | No |
| `getChannelMergeKey` | 207 | No |
| `headerIndexMap` | 217 | No |
| `findHeaderIndex` | 223 | No |
| `findHeaderIndexes` | 231 | No |
| `normalizeHeaderKey` | 240 | No |
| `normalizeIsoTimestamp` | 244 | No |
| `normalizeSheetTimestamp` | 251 | No |
| `normalizeOriginalPostDate` | 257 | No |
| `formatInventoryDateColumn` | 267 | No |
| `generateBotProductId` | 274 | No |
| `getEnglishProductId` | 278 | No |
| `findExistingInventoryProductId` | 284 | No |
| `normalizeProductMatchText` | 307 | No |
| `applyPriceAnchorToInventoryRows` | 311 | No |
| `applyPriceConfirmationToWaitingInventory` | 338 | No |
| `getPriceConfirmationHint` | 400 | No |
| `parseExplicitPriceValue` | 410 | No |
| `extractExplicitSalePricesFromMessage` | 416 | No |
| `extractExplicitRegularPricesFromMessage` | 442 | No |
| `applyExplicitMessagePrices` | 463 | No |
| `detectExplicitSalePriceRole` | 502 | No |
| `applyPriceConfirmationHint` | 512 | No |
| `assessPriceCertainty` | 533 | No |
| `buildPriceConfirmationRequest` | 550 | No |
| `inferUnitPackagePair` | 586 | No |
| `calculateInventoryCost` | 599 | No |
| `inferInventoryUnit` | 605 | No |
| `extractPackageCount` | 619 | No |
| `getProductExtractionWarning` | 625 | No |
| `extractPackageCountFromProduct` | 637 | No |

## 30_reference_prices.js

Reference acquisition, matching and price-role inference.

No equivalent reference integration found in current Python. Preserve or deliberately retain with upstream-server audit; B-01/B-02/B-03.

| Function | Source line | Name present in root GAS |
|---|---|---|
| `writeReferencePriceRows` | 1 | No |
| `handleReplaceReferencePrices` | 42 | No |
| `handleAppendReferencePrices` | 53 | No |
| `refreshReferencePrices` | 64 | No |
| `refreshReferencePricesFromPdfLegacy` | 116 | No |
| `notifyReferenceRefreshFailure` | 178 | No |
| `refreshReferencePricesFromPdf` | 215 | No |
| `parseAiJsonResponse` | 309 | No |
| `extractBalancedJson` | 328 | No |
| `parseReferenceNumber` | 356 | No |
| `extractRetailHintPriceFromName` | 363 | No |
| `getReferenceItemsFromParsedAi` | 372 | No |
| `callGeminiWithInlinePdf` | 386 | No |
| `referencePriceDailyTrigger` | 425 | No |
| `setupReferencePriceTrigger` | 429 | No |
| `readReferencePriceRows` | 448 | No |
| `extractProductAttributes` | 493 | No |
| `normalizeReferenceProductName` | 513 | No |
| `referenceNameSimilarity` | 520 | No |
| `attributeMatchScore` | 536 | No |
| `computeCompositeMatchScore` | 545 | No |
| `getReferenceMatchOverrideKey` | 585 | No |
| `readReferenceMatchOverrides` | 595 | No |
| `saveReferenceMatchOverride` | 603 | No |
| `findReferencePriceMatch` | 611 | No |
| `findCategoryReferenceProducts` | 647 | No |
| `assessHistoricalPriceScale` | 663 | No |
| `validateProductPriceScale` | 882 | No |
| `getRecentUnitPriceHistory` | 922 | No |
| `priceDistanceRatio` | 992 | No |

## 40_storefront.js

Owned stock, offer selection and storefront pricing.

Different authority and fixed-markup pricing. Reconcile with basket discounts; fix selected-offer inconsistency B-05.

| Function | Source line | Name present in root GAS |
|---|---|---|
| `rebuildStorefront` | 1 | No |
| `getRequiredSheetByAnyName` | 133 | No |
| `readSheetObjects` | 141 | No |
| `buildInventoryStockMap` | 158 | No |
| `selectLatestSupplierOffers` | 186 | No |
| `selectLatestProductOffer` | 209 | No |
| `selectBestCurrentOffer` | 216 | No |
| `isConfirmedInventoryRow` | 230 | No |
| `isCurrentSupplierOffer` | 236 | No |
| `getOfferTimestamp` | 244 | No |
| `getComparableUnitCost` | 250 | No |
| `getCanonicalProductKey` | 258 | No |
| `normalizeStorefrontKey` | 272 | No |
| `firstValue` | 279 | No |
| `firstNumber` | 294 | No |
| `calculateStorefrontPrice` | 302 | No |

## 50_ingestion_batches.js

Message ingestion, batches, retries and status.

Related Python ingestion exists, but storage, AI queues and replies differ. Compare contracts; historical parser tests do not prove this export equivalent.

| Function | Source line | Name present in root GAS |
|---|---|---|
| `validateData` | 1 | Yes; semantics may differ |
| `checkForDuplicates` | 27 | Yes; semantics may differ |
| `isDuplicate` | 65 | Yes; semantics may differ |
| `importMessageData` | 70 | Yes; semantics may differ |
| `importProductData` | 163 | Yes; semantics may differ |
| `processPendingMessages` | 272 | Yes; semantics may differ |
| `batchIngestChunk` | 363 | Yes; semantics may differ |
| `_getBatchMessageStatus` | 369 | No |
| `finalizeBatch` | 379 | Yes; semantics may differ |
| `countBatchMessages` | 497 | Yes; semantics may differ |
| `listBatchMessageIds` | 511 | Yes; semantics may differ |
| `deleteProductsByBatchId` | 526 | Yes; semantics may differ |
| `processBatchProducts` | 544 | Yes; semantics may differ |
| `processBatchProductsAI` | 598 | No |
| `recordBatchStatus` | 673 | Yes; semantics may differ |
| `storeLastBatchStatus` | 689 | Yes; semantics may differ |
| `checkSystemicErrors` | 706 | Yes; semantics may differ |

## 60_extraction_ai.js

AI extraction, regex parsers and quality checks.

Python has regex/quality helpers but no equivalent AI/reference queue. Existing historical fixtures did not validate this newer export's parsers.

| Function | Source line | Name present in root GAS |
|---|---|---|
| `extractChannelBonakdarjavan` | 63 | Yes; semantics may differ |
| `extractChannelTopShopRahimi` | 135 | Yes; semantics may differ |
| `extractChannelNobelshop118` | 237 | Yes; semantics may differ |
| `extractJsonArray` | 345 | No |
| `extractJsonObject` | 366 | No |
| `callAIProvider` | 391 | No |
| `callGemini` | 407 | No |
| `callOpenRouter` | 456 | No |
| `parseAIProducts` | 502 | No |
| `extractWithAI` | 579 | No |
| `processAIBatch` | 628 | No |
| `_runBatchAI` | 841 | No |
| `buildInventoryProductFromExtraction` | 1112 | No |
| `_resetToPending` | 1180 | No |
| `countPendingAIMessages` | 1194 | No |
| `processPendingTrigger` | 1218 | No |
| `_normalizePrices` | 1258 | No |
| `extractProducts` | 1291 | Yes; semantics may differ |
| `extractUniversalProducts` | 1318 | Yes; semantics may differ |
| `extractPricesFromLine` | 1458 | Yes; semantics may differ |
| `cleanProductName` | 1501 | Yes; semantics may differ |
| `finalizeProduct` | 1549 | Yes; semantics may differ |
| `extractPackaging` | 1583 | Yes; semantics may differ |
| `extractVolume` | 1588 | Yes; semantics may differ |
| `inferPriceTypeFromPackaging` | 1594 | Yes; semantics may differ |
| `detectPresentationMethod` | 1600 | Yes; semantics may differ |
| `extractVariationType` | 1611 | Yes; semantics may differ |
| `analyzePricingForSegment` | 1623 | Yes; semantics may differ |
| `performQualityChecks` | 1672 | Yes; semantics may differ |
| `logExtractionIssue` | 1689 | Yes; semantics may differ |
| `extractConsumerPrice` | 1709 | Yes; semantics may differ |
| `extractStockStatus` | 1724 | Yes; semantics may differ |
| `extractCategory` | 1743 | Yes; semantics may differ |
| `addChannelPatterns` | 1803 | Yes; semantics may differ |
| `getChannelPatterns` | 1809 | Yes; semantics may differ |
| `extractLocation` | 1813 | Yes; semantics may differ |
| `extractContactInfo` | 1856 | Yes; semantics may differ |

## 70_sheets_admin_debug.js

Sheet setup, maintenance and diagnostics.

Classify needed operations separately from tests/debug/destructive maintenance. Do not auto-port or run setup/replay functions.

| Function | Source line | Name present in root GAS |
|---|---|---|
| `getOrCreateSheet` | 1 | Yes; semantics may differ |
| `ensureSheetHeaders` | 27 | No |
| `ensureMessageStatusValidation` | 54 | No |
| `setupSheet` | 64 | Yes; semantics may differ |
| `cleanupProductsSheet` | 106 | Yes; semantics may differ |
| `compactProductsHeaders` | 146 | No |
| `clearProductsSheet` | 168 | Yes; semantics may differ |
| `getIngestionEnabled` | 189 | Yes; semantics may differ |
| `setIngestionEnabled` | 195 | Yes; semantics may differ |
| `testImport` | 208 | Yes; semantics may differ |
| `testProductImport` | 242 | Yes; semantics may differ |
| `testProductUpdate` | 286 | Yes; semantics may differ |
| `testFashionChannel` | 330 | Yes; semantics may differ |
| `testImportWithMedia` | 391 | Yes; semantics may differ |
| `testDuplicateImport` | 417 | Yes; semantics may differ |
| `testSetup` | 425 | Yes; semantics may differ |
| `testErrorHandling` | 431 | Yes; semantics may differ |
| `debugLastMessage` | 450 | Yes; semantics may differ |
| `debugAllMessages` | 510 | Yes; semantics may differ |
| `debugPersianPatterns` | 563 | Yes; semantics may differ |
| `getMessagePatterns` | 758 | Yes; semantics may differ |
