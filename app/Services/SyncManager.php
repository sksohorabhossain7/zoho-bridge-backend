<?php

namespace App\Services;

use App\Models\ProductSettings;
use App\Models\SyncedProduct;
use App\Models\ZohoToken;
use App\Models\SyncLog;
use App\Jobs\ProcessShopifyWebhookJob;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

class SyncManager
{
    protected ZohoService $zohoService;
    protected ShopifyService $shopifyService;

    public function __construct(ZohoService $zohoService, ShopifyService $shopifyService)
    {
        $this->zohoService = $zohoService;
        $this->shopifyService = $shopifyService;
    }

    /**
     * Process all registered shops for synchronization.
     *
     * @return void
     */
    public function processAllShops(): void
    {
        $tokens = ZohoToken::all();

        foreach ($tokens as $token) {
            if (empty($token->organizationID)) {
                continue;
            }

            Log::info("Starting scheduled sync for shop: {$token->shop}");

            try {
                $this->syncShopProducts($token);
                Log::info("Successfully finished sync for shop: {$token->shop}");
            } catch (Exception $e) {
                Log::error("Error syncing shop {$token->shop}: " . $e->getMessage());
                $this->createLog($token->shop, 'product', 'error', 'Scheduled product sync failed', $e->getMessage());
            }
        }
    }

    /**
     * Sync products for a single shop based on settings.
     *
     * @param ZohoToken $token
     * @return void
     * @throws Exception
     */
    public function syncShopProducts(ZohoToken $token): void
    {
        $settings = ProductSettings::where('shop', $token->shop)->first();
        if (!$settings) {
            throw new Exception("Product settings not found for shop: {$token->shop}");
        }

        if (!$settings->auto_sync_enabled) {
            Log::info("Skipping sync for shop {$token->shop}: Auto sync is disabled");
            return;
        }

        if ($settings->sync_direction === 'zoho-to-shopify') {
            $this->syncZohoProductsToShopify($token, $settings);
        } else {
            Log::info("Skipping cron sync for shop {$token->shop}: Direction is shopify-to-zoho (handled by webhooks)");
        }
    }

    /**
     * Sync Zoho products into Shopify.
     *
     * @param ZohoToken $token
     * @param ProductSettings $settings
     * @return void
     * @throws Exception
     */
    public function syncZohoProductsToShopify(ZohoToken $token, ProductSettings $settings): void
    {
        $items = $this->zohoService->fetchItemsSorted($token->shop, $token->organizationID);
        $shopifyToken = $this->shopifyService->getAccessToken($token->shop);

        $exportFields = $settings->export_fields ?? ['Price', 'Title', 'Description', 'Cost'];
        $exportFieldsMap = array_fill_keys($exportFields, true);

        foreach ($items as $item) {
            $status = $item['status'] ?? 'active';
            if ($status === 'inactive' && !$settings->sync_draft_products) {
                continue;
            }

            $itemId = $item['item_id'];
            $sku = $item['sku'] ?? '';
            $name = $item['name'] ?? '';

            // Check if already synced
            $synced = SyncedProduct::where('shop', $token->shop)
                ->where('zoho_item_id', $itemId)
                ->first();

            if (!$synced) {
                Log::info("Creating new product in Shopify: {$name} (SKU: {$sku})");

                $variantInput = [
                    'sku' => $sku,
                    'optionValues' => [
                        [
                            'optionName' => 'Title',
                            'name' => 'Default Title',
                        ]
                    ]
                ];

                if (isset($exportFieldsMap['Price'])) {
                    $variantInput['price'] = $item['rate'] ?? 0.0;
                }

                if (isset($exportFieldsMap['Cost'])) {
                    $itemType = $item['item_type'] ?? '';
                    $tracked = $itemType === 'inventory';
                    $variantInput['inventoryItem'] = [
                        'cost' => $item['purchase_rate'] ?? 0.0,
                        'tracked' => $tracked,
                    ];
                }

                $productInput = [
                    'title' => $name,
                    'status' => 'ACTIVE',
                    'productOptions' => [
                        [
                            'name' => 'Title',
                            'values' => [
                                ['name' => 'Default Title']
                            ]
                        ]
                    ],
                    'variants' => [
                        $variantInput
                    ]
                ];

                if (isset($exportFieldsMap['Description'])) {
                    $productInput['descriptionHtml'] = $item['description'] ?? '';
                }

                try {
                    [$productID, $variantID, $inventoryItemID] = $this->shopifyService->syncProduct(
                        $token->shop,
                        $shopifyToken,
                        $productInput,
                        ''
                    );

                    SyncedProduct::create([
                        'shop' => $token->shop,
                        'zoho_item_id' => $itemId,
                        'shopify_product_id' => $productID,
                        'shopify_variant_id' => $variantID,
                        'title' => $name,
                        'sku' => $sku,
                        'last_sync_source' => 'zoho',
                        'last_sync_at' => Carbon::now('UTC'),
                    ]);
                } catch (Exception $e) {
                    Log::error("Failed to create product in Shopify: {$name} - " . $e->getMessage());
                    continue;
                }
            }
        }

        $token->last_product_sync_at = Carbon::now('UTC');
        $token->save();

        $this->createLog($token->shop, 'product', 'success', 'Completed scheduled product sync', '');
    }

    /**
     * Sync Shopify products into Zoho.
     *
     * @param ZohoToken $token
     * @return void
     * @throws Exception
     */
    public function syncShopifyProductsToZoho(ZohoToken $token): void
    {
        $settings = ProductSettings::where('shop', $token->shop)->first();
        if (!$settings) {
            throw new Exception("Product settings not found for shop: {$token->shop}");
        }

        if ($settings->sync_direction !== 'shopify-to-zoho') {
            Log::info("Sync skipped for {$token->shop}: Direction is {$settings->sync_direction}");
            return;
        }

        if (!$settings->auto_sync_enabled) {
            Log::info("Sync skipped for {$token->shop}: AutoSync is disabled");
            return;
        }

        $shopifyToken = $this->shopifyService->getAccessToken($token->shop);

        Log::info("Starting Shopify to Zoho product sync for {$token->shop}...");

        $products = $this->shopifyService->fetchProducts($token->shop, $shopifyToken);
        Log::info("Fetched " . count($products) . " products from Shopify for {$token->shop}");

        $allowedCollections = $settings->selected_collections ?? [];
        $allowedTags = $settings->selected_tags ?? [];

        foreach ($products as $product) {
            $title = $product['title'] ?? '';
            $status = $product['status'] ?? 'ACTIVE';

            if (strtoupper($status) === 'DRAFT' && !$settings->sync_draft_products) {
                Log::info("Skipping product {$title}: status is DRAFT and sync_draft is disabled");
                continue;
            }

            // Collection Filter
            if ($settings->sync_by_collection && count($allowedCollections) > 0) {
                $nodes = $product['collections']['nodes'] ?? [];
                $productCollectionIds = collect($nodes)->pluck('id')->toArray();
                
                $hasCommonCollection = count(array_intersect($productCollectionIds, $allowedCollections)) > 0;
                if (!$hasCommonCollection) {
                    Log::info("Skipping product {$title}: collection filter mismatch");
                    continue;
                }
            }

            // Tag Filter
            if ($settings->sync_by_tags && count($allowedTags) > 0) {
                $productTags = $product['tags'] ?? [];
                $hasCommonTag = count(array_intersect($productTags, $allowedTags)) > 0;
                if (!$hasCommonTag) {
                    Log::info("Skipping product {$title}: tag filter mismatch");
                    continue;
                }
            }

            $variants = $product['variants']['nodes'] ?? [];
            Log::info("Processing " . count($variants) . " variants for product {$title}");

            foreach ($variants as $variant) {
                $sku = $variant['sku'] ?? '';
                if ($sku === '') {
                    Log::info("Skipping variant " . ($variant['id'] ?? '') . ": missing SKU");
                    continue;
                }

                $variantId = $variant['id'];

                $synced = SyncedProduct::where('shop', $token->shop)
                    ->where('shopify_variant_id', $variantId)
                    ->first();

                $variantTitle = $variant['title'] ?? 'Default Title';
                $itemName = $variantTitle !== 'Default Title' ? "{$title} - {$variantTitle}" : $title;

                if (strlen($itemName) > 100) {
                    $itemName = substr($itemName, 0, 100);
                }

                $itemInput = [
                    'name' => $itemName,
                    'sku' => $sku,
                    'rate' => 0.0,
                ];

                if (isset($variant['price'])) {
                    $itemInput['rate'] = (float) $variant['price'];
                }

                if (isset($product['descriptionHtml'])) {
                    $desc = strip_tags($product['descriptionHtml']);
                    $itemInput['description'] = strlen($desc) > 2000 ? substr($desc, 0, 2000) : $desc;
                }

                $inventoryItem = $variant['inventoryItem'] ?? [];
                $tracked = $inventoryItem['tracked'] ?? false;
                $itemInput['item_type'] = $tracked ? 'inventory' : 'sales';

                if (isset($inventoryItem['unitCost']['amount'])) {
                    $itemInput['purchase_rate'] = (float) $inventoryItem['unitCost']['amount'];
                }

                try {
                    if (!$synced) {
                        Log::info("Creating new item in Zoho: {$itemName} (SKU: {$sku})");
                        $zohoItem = $this->zohoService->createItem($token->shop, $token->organizationID, $itemInput);
                        Log::info("Successfully created item in Zoho: {$sku} (ID: {$zohoItem['item_id']})");

                        SyncedProduct::create([
                            'shop' => $token->shop,
                            'zoho_item_id' => $zohoItem['item_id'],
                            'shopify_product_id' => $product['id'],
                            'shopify_variant_id' => $variantId,
                            'title' => $title,
                            'sku' => $sku,
                            'last_sync_source' => 'shopify',
                            'last_sync_at' => Carbon::now('UTC'),
                        ]);
                    } else {
                        Log::info("Updating existing item in Zoho: {$itemName} (SKU: {$sku})");
                        $this->zohoService->updateItem($token->shop, $token->organizationID, $synced->zoho_item_id, $itemInput);

                        $synced->last_sync_source = 'shopify';
                        $synced->last_sync_at = Carbon::now('UTC');
                        $synced->save();
                    }
                } catch (Exception $e) {
                    Log::error("Failed to sync Shopify product variant {$sku} to Zoho: " . $e->getMessage());
                    continue;
                }
            }
        }

        $this->createLog($token->shop, 'product', 'success', 'Completed Shopify to Zoho product sync', '');
    }

    /**
     * Sync a single Zoho product into Shopify.
     *
     * @param ZohoToken $token
     * @param string $zohoItemID
     * @return void
     * @throws Exception
     */
    public function syncSingleZohoItem(ZohoToken $token, string $zohoItemID): void
    {
        $item = $this->zohoService->fetchItem($token->shop, $token->organizationID, $zohoItemID);
        if (!$item) {
            throw new Exception("Zoho item not found: {$zohoItemID}");
        }

        $shopifyToken = $this->shopifyService->getAccessToken($token->shop);
        $settings = ProductSettings::where('shop', $token->shop)->first();

        $exportFields = $settings->export_fields ?? ['Price', 'Title', 'Description', 'Cost'];
        $exportFieldsMap = array_fill_keys($exportFields, true);

        $status = $item['status'] ?? 'active';
        if ($status === 'inactive' && (!$settings || !$settings->sync_draft_products)) {
            throw new Exception("Skipping sync for inactive product {$item['name']} (SKU: {$item['sku']})");
        }

        $synced = SyncedProduct::where('shop', $token->shop)
            ->where('zoho_item_id', $zohoItemID)
            ->first();

        if (!$synced) {
            Log::info("Creating single product in Shopify: {$item['name']} (SKU: {$item['sku']})");

            $variantInput = [
                'sku' => $item['sku'] ?? '',
                'optionValues' => [
                    [
                        'optionName' => 'Title',
                        'name' => 'Default Title',
                    ]
                ]
            ];

            if (isset($exportFieldsMap['Price'])) {
                $variantInput['price'] = $item['rate'] ?? 0.0;
            }

            if (isset($exportFieldsMap['Cost'])) {
                $itemType = $item['item_type'] ?? '';
                $tracked = $itemType === 'inventory';
                $variantInput['inventoryItem'] = [
                    'cost' => $item['purchase_rate'] ?? 0.0,
                    'tracked' => $tracked,
                ];
            }

            $productInput = [
                'title' => $item['name'] ?? '',
                'status' => 'ACTIVE',
                'productOptions' => [
                    [
                        'name' => 'Title',
                        'values' => [
                            ['name' => 'Default Title']
                        ]
                    ]
                ],
                'variants' => [
                    $variantInput
                ]
            ];

            if (isset($exportFieldsMap['Description'])) {
                $productInput['descriptionHtml'] = $item['description'] ?? '';
            }

            [$productID, $variantID, $inventoryItemID] = $this->shopifyService->syncProduct(
                $token->shop,
                $shopifyToken,
                $productInput,
                ''
            );

            SyncedProduct::create([
                'shop' => $token->shop,
                'zoho_item_id' => $zohoItemID,
                'shopify_product_id' => $productID,
                'shopify_variant_id' => $variantID,
                'title' => $item['name'] ?? '',
                'sku' => $item['sku'] ?? '',
                'last_sync_source' => 'zoho',
                'last_sync_at' => Carbon::now('UTC'),
            ]);
        }

        $this->createLog(
            $token->shop,
            'product',
            'success',
            "Successfully synced item: {$item['name']} (SKU: " . ($item['sku'] ?? '') . ")",
            ''
        );
    }

    /**
     * Dispatch Shopify webhook product payloads to background job queues.
     *
     * @param string $shopDomain
     * @param string $topic
     * @param array $payload
     * @return void
     */
    public function enqueueJob(string $shopDomain, string $topic, array $payload): void
    {
        ProcessShopifyWebhookJob::dispatch($shopDomain, $topic, $payload);
    }

    /**
     * Process a single Shopify webhook payload.
     *
     * @param ZohoToken $token
     * @param array $payload
     * @return void
     * @throws Exception
     */
    public function syncSingleShopifyProduct(ZohoToken $token, array $payload): void
    {
        $settings = ProductSettings::where('shop', $token->shop)->first();
        if (!$settings) {
            throw new Exception("Product settings not found for shop: {$token->shop}");
        }

        if ($settings->sync_direction !== 'shopify-to-zoho') {
            return;
        }

        $title = $payload['title'] ?? '';
        $status = $payload['status'] ?? 'ACTIVE';

        if (strtoupper($status) === 'DRAFT' && !$settings->sync_draft_products) {
            return;
        }

        // Collection Filter
        $allowedCollections = $settings->selected_collections ?? [];
        if ($settings->sync_by_collection && count($allowedCollections) > 0) {
            $shopifyToken = $this->shopifyService->getAccessToken($token->shop);
            $pID = "gid://shopify/Product/" . $payload['id'];
            
            $productCollections = $this->shopifyService->fetchProductCollections($token->shop, $shopifyToken, $pID);
            
            $hasCommonCollection = count(array_intersect($productCollections, $allowedCollections)) > 0;
            if (!$hasCommonCollection) {
                Log::info("Skipping webhook sync for {$title}: collection filter mismatch");
                return;
            }
        }

        // Tag Filter
        $allowedTags = $settings->selected_tags ?? [];
        if ($settings->sync_by_tags && count($allowedTags) > 0) {
            $tagsStr = $payload['tags'] ?? '';
            $productTags = array_map('trim', explode(',', $tagsStr));
            
            $hasCommonTag = count(array_intersect($productTags, $allowedTags)) > 0;
            if (!$hasCommonTag) {
                Log::info("Skipping webhook sync for {$title}: tag filter mismatch");
                return;
            }
        }

        $variants = $payload['variants'] ?? [];
        foreach ($variants as $variant) {
            $sku = $variant['sku'] ?? '';
            if ($sku === '') {
                Log::info("Skipping webhook variant processing: missing SKU");
                continue;
            }

            $vID = "gid://shopify/ProductVariant/" . $variant['id'];

            $synced = SyncedProduct::where('shop', $token->shop)
                ->where('shopify_variant_id', $vID)
                ->first();

            // Fetch full variant to get unitCost (webhooks do not contain unitCost)
            $shopifyToken = $this->shopifyService->getAccessToken($token->shop);
            $fullVariant = $this->shopifyService->fetchVariant($token->shop, $shopifyToken, $vID);

            $variantTitle = $variant['title'] ?? 'Default Title';
            $itemName = $variantTitle !== 'Default Title' ? "{$title} - {$variantTitle}" : $title;

            if (strlen($itemName) > 100) {
                $itemName = substr($itemName, 0, 100);
            }

            $itemInput = [
                'name' => $itemName,
                'sku' => $sku,
                'rate' => (float) ($variant['price'] ?? 0.0),
            ];

            if (isset($payload['body_html'])) {
                $desc = strip_tags($payload['body_html']);
                $itemInput['description'] = strlen($desc) > 2000 ? substr($desc, 0, 2000) : $desc;
            }

            $inventoryManagement = $variant['inventory_management'] ?? '';
            $tracked = $inventoryManagement === 'shopify';
            $itemInput['item_type'] = $tracked ? 'inventory' : 'sales';

            if ($fullVariant && isset($fullVariant['inventoryItem']['unitCost']['amount'])) {
                $itemInput['purchase_rate'] = (float) $fullVariant['inventoryItem']['unitCost']['amount'];
            }

            try {
                if (!$synced) {
                    $zohoItem = $this->zohoService->createItem($token->shop, $token->organizationID, $itemInput);
                    $this->createLog($token->shop, 'product_sync_webhook', 'success', "Created item in Zoho from webhook: {$itemName}", '');

                    SyncedProduct::create([
                        'shop' => $token->shop,
                        'zoho_item_id' => $zohoItem['item_id'],
                        'shopify_product_id' => "gid://shopify/Product/" . $payload['id'],
                        'shopify_variant_id' => $vID,
                        'title' => $title,
                        'sku' => $sku,
                        'last_sync_source' => 'shopify',
                        'last_sync_at' => Carbon::now('UTC'),
                    ]);
                } else {
                    $this->zohoService->updateItem($token->shop, $token->organizationID, $synced->zoho_item_id, $itemInput);
                    $this->createLog($token->shop, 'product_sync_webhook', 'success', "Created item in Zoho from webhook: {$itemName}", '');

                    $synced->last_sync_source = 'shopify';
                    $synced->last_sync_at = Carbon::now('UTC');
                    $synced->save();
                }
            } catch (Exception $e) {
                Log::error("Failed in webhook sync for variant {$sku}: " . $e->getMessage());
                $this->createLog($token->shop, 'product_sync_webhook', 'error', "Failed in webhook sync for {$itemName}", $e->getMessage());
                continue;
            }
        }
    }

    /**
     * Create database log entry.
     *
     * @param string $shop
     * @param string $logType
     * @param string $status
     * @param string $message
     * @param string $details
     * @return void
     */
    public function createLog(string $shop, string $logType, string $status, string $message, string $details): void
    {
        try {
            SyncLog::create([
                'shop' => $shop,
                'type' => $logType,
                'status' => $status,
                'message' => $message,
                'details' => $details,
            ]);
        } catch (Exception $e) {
            Log::error("Failed to write to DB SyncLog: " . $e->getMessage());
        }
    }
}
