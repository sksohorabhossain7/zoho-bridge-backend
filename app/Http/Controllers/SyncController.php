<?php

namespace App\Http\Controllers;

use App\Models\ProductSettings;
use App\Models\SyncedProduct;
use App\Models\ZohoToken;
use App\Models\SyncLog;
use App\Services\SyncManager;
use App\Services\ZohoService;
use App\Services\ShopifyService;
use App\Jobs\SyncProductsJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Exception;

class SyncController extends Controller
{
    protected SyncManager $syncManager;
    protected ZohoService $zohoService;
    protected ShopifyService $shopifyService;

    public function __construct(
        SyncManager $syncManager,
        ZohoService $zohoService,
        ShopifyService $shopifyService
    ) {
        $this->syncManager = $syncManager;
        $this->zohoService = $zohoService;
        $this->shopifyService = $shopifyService;
    }

    /**
     * Trigger immediate synchronization.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function syncNow(Request $request): JsonResponse
    {
        $shopDomain = $request->query('shop');
        $zohoItemID = $request->query('zoho_item_id');

        if (empty($shopDomain)) {
            return response()->json(['error' => 'Missing shop domain parameter'], 400);
        }

        $token = ZohoToken::where('shop', $shopDomain)->first();
        if (!$token) {
            return response()->json(['error' => 'Shop not found or not connected to Zoho'], 404);
        }

        try {
            SyncProductsJob::dispatch($shopDomain, $zohoItemID);

            return response()->json([
                'message' => 'Sync job has been dispatched and is processing in the background.',
            ]);
        } catch (Exception $e) {
            Log::error("Failed to dispatch SyncProductsJob: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get paginated synchronization logs.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getLogs(Request $request): JsonResponse
    {
        $shopDomain = $request->query('shop');
        if (empty($shopDomain)) {
            return response()->json(['error' => 'Missing shop domain parameter'], 400);
        }

        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 50);

        try {
            $query = SyncLog::where('shop', $shopDomain);
            $total = $query->count();
            
            $logs = $query->orderBy('created_at', 'desc')
                ->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();

            return response()->json([
                'logs' => $logs,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
            ]);
        } catch (Exception $e) {
            Log::error("Failed to fetch logs: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get paginated Zoho items list.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getZohoItems(Request $request): JsonResponse
    {
        $shopDomain = $request->query('shop');
        if (empty($shopDomain)) {
            return response()->json(['error' => 'Missing shop parameter'], 400);
        }

        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);
        $search = $request->query('query', '');
        $status = $request->query('status', '');

        $token = ZohoToken::where('shop', $shopDomain)->first();
        if (!$token) {
            return response()->json(['error' => 'Shop not connected'], 404);
        }

        if (empty($token->organizationID)) {
            return response()->json(['error' => 'No Zoho organization selected'], 400);
        }

        try {
            [$items, $pageContext] = $this->zohoService->fetchItemsPaginated(
                $shopDomain,
                $token->organizationID,
                $page,
                $perPage,
                $search,
                $status
            );

            return response()->json([
                'items' => $items,
                'page_context' => $pageContext,
            ]);
        } catch (Exception $e) {
            Log::error("Error fetching Zoho items for {$shopDomain}: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle incoming Shopify product webhook events.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function handleShopifyWebhook(Request $request): JsonResponse
    {
        $topic = $request->header('X-Shopify-Topic');
        $shopDomain = $request->header('X-Shopify-Shop-Domain');

        if (empty($topic) || empty($shopDomain)) {
            return response()->json(['error' => 'Missing webhook headers'], 400);
        }

        $payload = $request->all();
        if (empty($payload)) {
            return response()->json(['error' => 'Invalid webhook payload'], 400);
        }

        // Dispatch background job via SyncManager (immediate queue offloading)
        $this->syncManager->enqueueJob($shopDomain, $topic, $payload);

        return response()->json(['message' => 'Webhook received successfully']);
    }

    /**
     * Get synchronization metrics count.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getMetrics(Request $request): JsonResponse
    {
        $shopDomain = $request->query('shop');
        if (empty($shopDomain)) {
            return response()->json(['error' => 'Missing shop domain parameter'], 400);
        }

        $token = null;
        try {
            $settings = ProductSettings::where('shop', $shopDomain)->first();
            $syncDirection = $settings ? $settings->sync_direction : 'shopify-to-zoho';

            $token = ZohoToken::where('shop', $shopDomain)->first();
            $hasZohoToken = $token && !empty($token->accessToken);

            $totalProducts = 0;
            $syncedProducts = 0;

            if ($syncDirection === 'zoho-to-shopify') {
                if ($hasZohoToken && !empty($token->organizationID)) {
                    $statusFilter = ($settings && !$settings->sync_draft_products) ? 'active' : '';
                    $zohoItems = $this->zohoService->fetchItemsSorted($shopDomain, $token->organizationID, $statusFilter);
                    $totalProducts = count($zohoItems);

                    $zohoItemIds = array_column($zohoItems, 'item_id');
                    $syncedProducts = SyncedProduct::where('shop', $shopDomain)
                        ->whereIn('zoho_item_id', $zohoItemIds)
                        ->count();
                }
            } else {
                $shopifyToken = $this->shopifyService->getAccessToken($shopDomain);
                $shopifyProductIds = $this->shopifyService->fetchAllProductIds($shopDomain, $shopifyToken);
                $totalProducts = count($shopifyProductIds);

                $syncedProducts = SyncedProduct::where('shop', $shopDomain)
                    ->whereIn('shopify_product_id', $shopifyProductIds)
                    ->count();
            }
        } catch (Exception $e) {
            Log::error("Error fetching metrics for shop {$shopDomain}: " . $e->getMessage());
            $totalProducts = 0;
            $syncedProducts = 0;
        }

        return response()->json([
            'products' => [
                'synced' => $syncedProducts,
                'total' => $totalProducts,
            ],
            'api_usage' => [
                'limit' => $token ? (int) $token->api_calls_limit : 0,
                'remaining' => $token ? (int) $token->api_calls_remaining : 0,
            ]
        ]);
    }

    /**
     * Get all synced products.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getSyncedProducts(Request $request): JsonResponse
    {
        $shopDomain = $request->query('shop');
        if (empty($shopDomain)) {
            return response()->json(['error' => 'Missing shop parameter'], 400);
        }

        try {
            $synced = SyncedProduct::where('shop', $shopDomain)->get();
            return response()->json($synced);
        } catch (Exception $e) {
            Log::error("Failed to fetch synced products for shop {$shopDomain}: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
