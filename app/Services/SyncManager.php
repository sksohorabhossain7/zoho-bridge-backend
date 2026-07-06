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

        $products = $this->shopifyService->fetchProducts($token->shop, $shopifyToken);

        $allowedCollections = array_filter(array_map(function ($item) {
            return is_array($item) ? ($item['id'] ?? $item) : (is_object($item) ? ($item->id ?? $item) : $item);
        }, $settings->selected_collections ?? []));
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
            $skuMapping = $token->sku_mapping ?? 'sku';

            foreach ($variants as $variant) {
                $sku = '';
                if ($skuMapping === 'barcode') {
                    $sku = $variant['barcode'] ?? '';
                } elseif ($skuMapping === 'variant_id') {
                    $sku = $variant['sku'] ?? '';
                    if ($sku === '') {
                        $sku = basename($variant['id']);
                    }
                } else {
                    $sku = $variant['sku'] ?? '';
                }

                if ($sku === '') {
                    $msg = "Skipping variant " . ($variant['id'] ?? '') . ": missing SKU/Barcode identifier matching settings (" . $skuMapping . ")";
                    Log::info($msg);
                    $this->createLog($token->shop, 'product', 'error', $msg, "Product: {$title}");
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

                $customFields = [];
                if ($skuMapping === 'variant_id' && !empty($token->zoho_custom_field)) {
                    $numericId = basename($variant['id']);
                    if (is_numeric($token->zoho_custom_field)) {
                        $fieldKey = 'customfield_id';
                    } elseif (str_starts_with($token->zoho_custom_field, 'cf_')) {
                        $fieldKey = 'api_name';
                    } else {
                        $fieldKey = 'label';
                    }
                    $customFields[] = [
                        $fieldKey => $token->zoho_custom_field,
                        'value' => $numericId
                    ];
                }
                if (!empty($customFields)) {
                    $itemInput['custom_fields'] = $customFields;
                }

                if (isset($variant['price'])) {
                    $itemInput['rate'] = (float) $variant['price'];
                }

                $exportFields = $settings->export_fields ?? ['Price', 'Title', 'Description', 'Cost'];
                if (in_array('Vendor', $exportFields) && !empty($product['vendor'])) {
                    $vendorId = $this->getOrCreateZohoVendorId($token, $product['vendor']);
                    if ($vendorId) {
                        $itemInput['vendor_id'] = $vendorId;
                    }
                }

                if (isset($product['descriptionHtml'])) {
                    $desc = strip_tags($product['descriptionHtml']);
                    $itemInput['description'] = strlen($desc) > 2000 ? substr($desc, 0, 2000) : $desc;
                }

                $inventoryItem = $variant['inventoryItem'] ?? [];
                $tracked = $inventoryItem['tracked'] ?? false;
                $itemInput['item_type'] = $tracked ? 'inventory' : 'sales_and_purchases';

                if (isset($inventoryItem['unitCost']['amount'])) {
                    $itemInput['purchase_rate'] = (float) $inventoryItem['unitCost']['amount'];
                    $itemInput['is_purchase'] = true;
                }

                if (!empty($token->enable_fixed_tax) && !empty($token->tax_type)) {
                    $itemInput['tax_id'] = $token->tax_type;
                }

                try {
                    if (!$synced) {
                        // Check if item already exists in Zoho by SKU
                        $existingZohoItem = $this->zohoService->fetchItemBySku($token->shop, $token->organizationID, $sku);
                        if ($existingZohoItem) {
                            Log::info("Found existing item in Zoho by SKU: {$sku} (ID: {$existingZohoItem['item_id']}). Mapping it.");
                            $synced = SyncedProduct::create([
                                'shop' => $token->shop,
                                'zoho_item_id' => $existingZohoItem['item_id'],
                                'shopify_product_id' => $product['id'],
                                'shopify_variant_id' => $variantId,
                                'title' => $title,
                                'sku' => $sku,
                                'last_sync_source' => 'shopify',
                                'last_sync_at' => Carbon::now('UTC'),
                            ]);
                        }
                    }

                    if (!$synced) {
                        $zohoItem = $this->zohoService->createItem($token->shop, $token->organizationID, $itemInput);

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
     * Process a single Shopify customer webhook payload.
     *
     * @param ZohoToken $token
     * @param array $payload
     * @return void
     * @throws Exception
     */
    public function syncSingleShopifyCustomer(ZohoToken $token, array $payload): void
    {
        $settings = \App\Models\CustomerSettings::where('shop', $token->shop)->first();
        if (!$settings) {
            Log::info("Skipping customer sync: Customer settings not found for shop {$token->shop}");
            return;
        }

        if ($settings->sync_direction !== 'shopify-to-zoho') {
            Log::info("Skipping customer sync: Sync direction is not shopify-to-zoho");
            return;
        }

        if (!$settings->enable_customer_sync_shopify_to_zoho) {
            Log::info("Skipping customer sync: Customer sync from Shopify to Zoho is disabled");
            return;
        }

        $shopifyCustomerId = "gid://shopify/Customer/" . ($payload['id'] ?? '');
        $email = $payload['email'] ?? '';
        $phone = $this->formatPhoneForZoho($payload['phone'] ?? '');
        $firstName = $payload['first_name'] ?? '';
        $lastName = $payload['last_name'] ?? '';

        if (empty($email) && empty($phone) && empty($firstName) && empty($lastName)) {
            Log::warning("Skipping customer sync: Customer webhook payload has no identifiers (email, phone, or name). Payload: " . json_encode($payload));
            return;
        }

        $contactName = trim($firstName . ' ' . $lastName);
        if (empty($contactName)) {
            $contactName = !empty($email) ? $email : (!empty($phone) ? $phone : 'Shopify Customer ' . ($payload['id'] ?? ''));
        }

        // Extract default address
        $defaultAddress = $payload['default_address'] ?? null;
        if (!$defaultAddress && !empty($payload['addresses'])) {
            foreach ($payload['addresses'] as $addr) {
                if (!empty($addr['default'])) {
                    $defaultAddress = $addr;
                    break;
                }
            }
            if (!$defaultAddress) {
                $defaultAddress = $payload['addresses'][0];
            }
        }

        $contactData = [
            'contact_name' => $contactName,
            'contact_type' => 'customer',
            'customer_sub_type' => 'individual',
        ];

        // Map Company Name
        $companyName = $payload['company'] ?? ($defaultAddress['company'] ?? '');
        if (!empty($companyName)) {
            $contactData['company_name'] = $companyName;
        }

        if (!empty($email)) {
            $contactData['email'] = $email;
        }
        if (!empty($phone)) {
            $contactData['phone'] = $phone;
        }

        // Map Address Data
        if ($defaultAddress) {
            $attention = trim(($defaultAddress['first_name'] ?? '') . ' ' . ($defaultAddress['last_name'] ?? ''));
            if (empty($attention)) {
                $attention = $contactName;
            }

            $addressPhone = $this->formatPhoneForZoho($defaultAddress['phone'] ?? '');

            $addressData = [
                'attention' => $attention,
                'address' => $defaultAddress['address1'] ?? '',
                'street2' => $defaultAddress['address2'] ?? '',
                'city' => $defaultAddress['city'] ?? '',
                'state' => $defaultAddress['province'] ?? '',
                'zip' => $defaultAddress['zip'] ?? '',
                'country' => $defaultAddress['country'] ?? '',
                'phone' => $addressPhone,
            ];

            $contactData['billing_address'] = $addressData;
            $contactData['shipping_address'] = $addressData;
        }

        try {
            // 1. Check existing mapping in our DB
            $syncedCustomer = \App\Models\SyncedCustomer::where('shop', $token->shop)
                ->where('shopify_customer_id', $shopifyCustomerId)
                ->first();

            $zohoContactId = null;
            $existing = null;

            if ($syncedCustomer) {
                $zohoContactId = $syncedCustomer->zoho_contact_id;
                // Fetch contact details from Zoho to get primary_contact_id
                $existing = $this->zohoService->fetchContactById($token->shop, $token->organizationID, $zohoContactId);
            } else {
                // 2. Fallback to searching Zoho by email
                if (!empty($email)) {
                    $existing = $this->zohoService->fetchContactByEmail($token->shop, $token->organizationID, $email);
                }
                // 3. Fallback to searching Zoho by phone
                if (!$existing && !empty($phone)) {
                    $existing = $this->zohoService->fetchContactByPhone($token->shop, $token->organizationID, $phone);
                }

                if ($existing && !empty($existing['contact_id'])) {
                    $zohoContactId = $existing['contact_id'];
                }
            }

            // Ensure we have full details including primary_contact_id if contact exists
            if ($zohoContactId && (!$existing || empty($existing['primary_contact_id']))) {
                $existing = $this->zohoService->fetchContactById($token->shop, $token->organizationID, $zohoContactId);
            }

            // Build Contact Person Data
            $contactPerson = [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'is_primary_contact' => true,
            ];
            if (!empty($email)) {
                $contactPerson['email'] = $email;
            }
            if (!empty($phone)) {
                $contactPerson['phone'] = $phone;
            }

            // If we have an existing Zoho contact, attach the primary contact person ID to update it
            if ($existing) {
                $primaryContactPersonId = $existing['primary_contact_id'] ?? null;
                if ($primaryContactPersonId) {
                    $contactPerson['contact_person_id'] = $primaryContactPersonId;
                }
            }

            $contactData['contact_persons'] = [$contactPerson];

            if ($zohoContactId) {
                // Update existing Zoho Contact
                $this->zohoService->updateContact($token->shop, $token->organizationID, $zohoContactId, $contactData);

                // Update the primary contact person details directly
                $primaryContactPersonId = $existing['primary_contact_id'] ?? null;
                if ($primaryContactPersonId) {
                    $contactPersonPayload = [
                        'contact_id' => $zohoContactId,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                    ];
                    if (!empty($email)) {
                        $contactPersonPayload['email'] = $email;
                    }
                    if (!empty($phone)) {
                        $contactPersonPayload['phone'] = $phone;
                    }
                    $this->zohoService->updateContactPerson($token->shop, $token->organizationID, $primaryContactPersonId, $contactPersonPayload);
                }

                $this->createLog($token->shop, 'customer_sync_webhook', 'success', "Updated customer in Zoho from webhook: {$contactName} (ID: {$zohoContactId})", '');
            } else {
                // Create new Zoho Contact
                $newContact = $this->zohoService->createContact($token->shop, $token->organizationID, $contactData);
                if ($newContact && !empty($newContact['contact_id'])) {
                    $zohoContactId = $newContact['contact_id'];

                    // Update the newly created primary contact person details
                    $primaryContactPersonId = $newContact['primary_contact_id'] ?? null;
                    if ($primaryContactPersonId) {
                        $contactPersonPayload = [
                            'contact_id' => $zohoContactId,
                            'first_name' => $firstName,
                            'last_name' => $lastName,
                        ];
                        if (!empty($email)) {
                            $contactPersonPayload['email'] = $email;
                        }
                        if (!empty($phone)) {
                            $contactPersonPayload['phone'] = $phone;
                        }
                        $this->zohoService->updateContactPerson($token->shop, $token->organizationID, $primaryContactPersonId, $contactPersonPayload);
                    }

                    $this->createLog($token->shop, 'customer_sync_webhook', 'success', "Created customer in Zoho from webhook: {$contactName} (ID: {$zohoContactId})", '');
                } else {
                    throw new Exception("Failed to create customer in Zoho Books.");
                }
            }

            // 4. Save/update mapping in our DB
            if ($zohoContactId) {
                \App\Models\SyncedCustomer::updateOrCreate(
                    [
                        'shop' => $token->shop,
                        'shopify_customer_id' => $shopifyCustomerId,
                    ],
                    [
                        'zoho_contact_id' => $zohoContactId,
                        'email' => !empty($email) ? $email : null,
                        'phone' => !empty($phone) ? $phone : null,
                    ]
                );
            }
        } catch (Exception $e) {
            Log::error("Failed in customer webhook sync for Shopify Customer ID " . ($payload['id'] ?? '') . ": " . $e->getMessage());
            $this->createLog($token->shop, 'customer_sync_webhook', 'error', "Failed in customer webhook sync for {$contactName}", $e->getMessage());
            throw $e;
        }
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
        $allowedCollections = array_filter(array_map(function ($item) {
            return is_array($item) ? ($item['id'] ?? $item) : (is_object($item) ? ($item->id ?? $item) : $item);
        }, $settings->selected_collections ?? []));
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

        $skuMapping = $token->sku_mapping ?? 'sku';

        $variants = $payload['variants'] ?? [];
        foreach ($variants as $variant) {
            $sku = '';
            if ($skuMapping === 'barcode') {
                $sku = $variant['barcode'] ?? '';
            } elseif ($skuMapping === 'variant_id') {
                $sku = $variant['sku'] ?? '';
                if ($sku === '') {
                    $sku = (string) ($variant['id'] ?? '');
                }
            } else {
                $sku = $variant['sku'] ?? '';
            }

            if ($sku === '') {
                $msg = "Skipping webhook variant processing: missing SKU/Barcode identifier matching settings (" . $skuMapping . ")";
                Log::info($msg);
                $this->createLog($token->shop, 'product_sync_webhook', 'error', $msg, "Product: {$title} (Variant ID: " . ($variant['id'] ?? '') . ")");
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

            $customFields = [];
            if ($skuMapping === 'variant_id' && !empty($token->zoho_custom_field)) {
                $numericId = (string) ($variant['id'] ?? '');
                if (is_numeric($token->zoho_custom_field)) {
                    $fieldKey = 'customfield_id';
                } elseif (str_starts_with($token->zoho_custom_field, 'cf_')) {
                    $fieldKey = 'api_name';
                } else {
                    $fieldKey = 'label';
                }
                $customFields[] = [
                    $fieldKey => $token->zoho_custom_field,
                    'value' => $numericId
                ];
            }
            if (!empty($customFields)) {
                $itemInput['custom_fields'] = $customFields;
            }

            if (isset($payload['body_html'])) {
                $desc = strip_tags($payload['body_html']);
                $itemInput['description'] = strlen($desc) > 2000 ? substr($desc, 0, 2000) : $desc;
            }

            $inventoryManagement = $variant['inventory_management'] ?? '';
            $tracked = $inventoryManagement === 'shopify';
            $itemInput['item_type'] = $tracked ? 'inventory' : 'sales_and_purchases';

            if ($fullVariant && isset($fullVariant['inventoryItem']['unitCost']['amount'])) {
                $itemInput['purchase_rate'] = (float) $fullVariant['inventoryItem']['unitCost']['amount'];
                $itemInput['is_purchase'] = true;
            }

            if (!empty($token->enable_fixed_tax) && !empty($token->tax_type)) {
                $itemInput['tax_id'] = $token->tax_type;
            }

            $exportFields = $settings->export_fields ?? ['Price', 'Title', 'Description', 'Cost'];
            if (in_array('Vendor', $exportFields) && !empty($payload['vendor'])) {
                $vendorId = $this->getOrCreateZohoVendorId($token, $payload['vendor']);
                if ($vendorId) {
                    $itemInput['vendor_id'] = $vendorId;
                }
            }

            try {
                if (!$synced) {
                    $existingZohoItem = $this->zohoService->fetchItemBySku($token->shop, $token->organizationID, $sku);
                    if ($existingZohoItem) {
                        $synced = SyncedProduct::create([
                            'shop' => $token->shop,
                            'zoho_item_id' => $existingZohoItem['item_id'],
                            'shopify_product_id' => "gid://shopify/Product/" . $payload['id'],
                            'shopify_variant_id' => $vID,
                            'title' => $title,
                            'sku' => $sku,
                            'last_sync_source' => 'shopify',
                            'last_sync_at' => Carbon::now('UTC'),
                        ]);
                    }
                }

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
                    $this->createLog($token->shop, 'product_sync_webhook', 'success', "Updated item in Zoho from webhook: {$itemName}", '');

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
     * Sync a single Shopify product into Zoho.
     *
     * @param ZohoToken $token
     * @param string $shopifyProductId
     * @return void
     * @throws Exception
     */
    public function syncSingleShopifyProductById(ZohoToken $token, string $shopifyProductId): void
    {
        $shopifyToken = $this->shopifyService->getAccessToken($token->shop);
        if (!str_starts_with($shopifyProductId, 'gid://')) {
            $shopifyProductId = 'gid://shopify/Product/' . $shopifyProductId;
        }

        $products = $this->shopifyService->fetchProducts($token->shop, $shopifyToken, "id:{$shopifyProductId}");
        if (empty($products)) {
            throw new Exception("Shopify product not found: {$shopifyProductId}");
        }

        $product = $products[0];
        $settings = ProductSettings::where('shop', $token->shop)->first();
        if (!$settings) {
            throw new Exception("Product settings not found for shop: {$token->shop}");
        }

        $skuMapping = $token->sku_mapping ?? 'sku';

        $variants = $product['variants']['nodes'] ?? [];
        foreach ($variants as $variant) {
            $variantId = $variant['id'];
            $sku = $variant[$skuMapping] ?? '';
            if ($skuMapping === 'sku' && empty($sku)) {
                $sku = $variant['barcode'] ?? '';
            }

            if (empty($sku)) {
                $this->createLog($token->shop, 'product', 'warning', "Skipping variant sync: missing SKU/Barcode identifier matching settings ({$skuMapping})", "Variant ID: {$variantId}");
                continue;
            }

            $synced = SyncedProduct::where('shop', $token->shop)
                ->where('shopify_variant_id', $variantId)
                ->first();

            $variantTitle = $variant['title'] ?? 'Default Title';
            $itemName = $variantTitle !== 'Default Title' ? "{$product['title']} - {$variantTitle}" : $product['title'];

            if (strlen($itemName) > 100) {
                $itemName = substr($itemName, 0, 100);
            }

            $itemInput = [
                'name' => $itemName,
                'sku' => $sku,
                'rate' => 0.0,
            ];

            $customFields = [];
            if ($skuMapping === 'variant_id' && !empty($token->zoho_custom_field)) {
                $numericId = (string) basename($variant['id']);
                if (is_numeric($token->zoho_custom_field)) {
                    $fieldKey = 'customfield_id';
                } elseif (str_starts_with($token->zoho_custom_field, 'cf_')) {
                    $fieldKey = 'api_name';
                } else {
                    $fieldKey = 'label';
                }
                $customFields[] = [
                    $fieldKey => $token->zoho_custom_field,
                    'value' => $numericId
                ];
            }
            if (!empty($customFields)) {
                $itemInput['custom_fields'] = $customFields;
            }

            if (isset($variant['price'])) {
                $itemInput['rate'] = (float) $variant['price'];
            }

            $exportFields = $settings->export_fields ?? ['Price', 'Title', 'Description', 'Cost'];
            if (in_array('Vendor', $exportFields) && !empty($product['vendor'])) {
                $vendorId = $this->getOrCreateZohoVendorId($token, $product['vendor']);
                if ($vendorId) {
                    $itemInput['vendor_id'] = $vendorId;
                }
            }

            if (isset($product['descriptionHtml'])) {
                $desc = strip_tags($product['descriptionHtml']);
                $itemInput['description'] = strlen($desc) > 2000 ? substr($desc, 0, 2000) : $desc;
            }

            $inventoryItem = $variant['inventoryItem'] ?? [];
            $tracked = $inventoryItem['tracked'] ?? false;
            $itemInput['item_type'] = $tracked ? 'inventory' : 'sales_and_purchases';

            if ($tracked) {
                $itemInput['inventory_account_id'] = $token->inventory_account ?? '';
            }

            $itemInput['purchase_description'] = $itemInput['description'] ?? '';
            $itemInput['purchase_rate'] = (float) ($inventoryItem['unitCost']['amount'] ?? 0.0);

            if (!empty($token->purchase_account)) {
                $itemInput['purchase_account_id'] = $token->purchase_account;
            }
            if (!empty($token->sales_account)) {
                $itemInput['account_id'] = $token->sales_account;
            }

            if (!empty($token->enable_fixed_tax) && !empty($token->tax_type)) {
                $itemInput['tax_id'] = $token->tax_type;
            }

            try {
                if (!$synced) {
                    $existingZohoItem = $this->zohoService->fetchItemBySku($token->shop, $token->organizationID, $sku);
                    if ($existingZohoItem) {
                        $synced = SyncedProduct::create([
                            'shop' => $token->shop,
                            'zoho_item_id' => $existingZohoItem['item_id'],
                            'shopify_product_id' => $product['id'],
                            'shopify_variant_id' => $variantId,
                            'title' => $product['title'],
                            'sku' => $sku,
                            'last_sync_source' => 'shopify',
                            'last_sync_at' => \Carbon\Carbon::now('UTC'),
                        ]);
                    }
                }

                if (!$synced) {
                    $zohoItem = $this->zohoService->createItem($token->shop, $token->organizationID, $itemInput);

                    SyncedProduct::create([
                        'shop' => $token->shop,
                        'zoho_item_id' => $zohoItem['item_id'],
                        'shopify_product_id' => $product['id'],
                        'shopify_variant_id' => $variantId,
                        'title' => $product['title'],
                        'sku' => $sku,
                        'last_sync_source' => 'shopify',
                        'last_sync_at' => \Carbon\Carbon::now('UTC'),
                    ]);
                } else {
                    $this->zohoService->updateItem($token->shop, $token->organizationID, $synced->zoho_item_id, $itemInput);

                    $synced->last_sync_source = 'shopify';
                    $synced->last_sync_at = \Carbon\Carbon::now('UTC');
                    $synced->save();
                }
            } catch (Exception $e) {
                Log::error("Failed to sync single Shopify product variant {$sku} to Zoho: " . $e->getMessage());
                throw $e;
            }
        }
    }

    /**
     * Map a Shopify product's vendor name to a Zoho Books contact vendor_id.
     * Creates the vendor contact in Zoho Books if it doesn't already exist.
     *
     * @param ZohoToken $token
     * @param string $vendorName
     * @return string|null
     */
    private function getOrCreateZohoVendorId(ZohoToken $token, string $vendorName): ?string
    {
        if (empty(trim($vendorName))) {
            return null;
        }

        try {
            $contact = $this->zohoService->fetchContactByName($token->shop, $token->organizationID, $vendorName, 'vendor');
            if ($contact && !empty($contact['contact_id'])) {
                return $contact['contact_id'];
            }

            $newContact = $this->zohoService->createContact($token->shop, $token->organizationID, [
                'contact_name' => $vendorName,
                'contact_type' => 'vendor',
            ]);

            if ($newContact && !empty($newContact['contact_id'])) {
                Log::info("Created new Zoho vendor contact for mapping: {$vendorName} (ID: {$newContact['contact_id']})");
                return $newContact['contact_id'];
            }
        } catch (Exception $e) {
            Log::error("Failed to map/create Zoho vendor contact for {$vendorName}: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Format phone number to split country code from the rest of the digits using hyphen.
     * This allows Zoho Books UI to correctly parse and populate the country code dropdown
     * and avoid validation errors regarding special characters.
     *
     * @param string|null $phone
     * @return string
     */
    private function formatPhoneForZoho(?string $phone): string
    {
        if (empty($phone)) {
            return '';
        }

        $cleaned = preg_replace('/[^\d+]/', '', $phone);
        if (empty($cleaned)) {
            return '';
        }

        if (str_starts_with($cleaned, '+')) {
            $digits = substr($cleaned, 1);

            // List of calling codes sorted by length desc to match longest prefix first
            $callingCodes = [
                '1242', '1246', '1264', '1268', '1284', '1340', '1345', '1441', '1473', '1649', '1664', '1721', '1758', '1767', '1784', '1809', '1829', '1849', '1868', '1869', '1876',
                '211', '212', '213', '216', '218', '220', '221', '222', '223', '224', '225', '226', '227', '228', '229', '230', '231', '232', '233', '234', '235', '236', '237', '238', '239', '240', '241', '242', '243', '244', '245', '246', '247', '248', '249', '250', '251', '252', '253', '254', '255', '256', '257', '258', '260', '261', '262', '263', '264', '265', '266', '267', '268', '269', '290', '291', '297', '298', '299',
                '350', '351', '352', '353', '354', '355', '356', '357', '358', '359', '370', '371', '372', '373', '374', '375', '376', '377', '378', '380', '381', '382', '383', '385', '386', '387', '389', '420', '421', '423', '500', '501', '502', '503', '504', '505', '506', '507', '508', '509', '590', '591', '592', '593', '594', '595', '596', '597', '598', '599',
                '670', '672', '673', '674', '675', '676', '677', '678', '679', '680', '681', '682', '683', '685', '686', '687', '688', '689', '690', '691', '692',
                '850', '852', '853', '855', '856', '880', '886', '960', '961', '962', '963', '964', '965', '966', '967', '968', '970', '971', '972', '973', '974', '975', '976', '977', '992', '993', '994', '995', '996', '998',
                '20', '27', '30', '31', '32', '33', '34', '36', '39', '40', '41', '43', '44', '45', '46', '47', '48', '49', '51', '52', '53', '54', '55', '56', '57', '58', '60', '61', '62', '63', '64', '65', '66', '81', '82', '84', '86', '90', '92', '93', '94', '95', '98',
                '1', '7'
            ];

            foreach ($callingCodes as $code) {
                if (str_starts_with($digits, $code)) {
                    $localNumber = substr($digits, strlen($code));
                    return '+' . $code . '-' . $localNumber;
                }
            }
        }

        return $cleaned;
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
