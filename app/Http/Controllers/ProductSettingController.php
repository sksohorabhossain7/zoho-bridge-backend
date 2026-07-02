<?php

namespace App\Http\Controllers;

use App\Models\ProductSettings;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Exception;

class ProductSettingController extends Controller
{
    /**
     * Update product synchronization settings.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'shop' => 'required|string',
                'sync_direction' => 'nullable|string',
                'sync_draft_products' => 'nullable|boolean',
                'auto_sync_enabled' => 'nullable|boolean',
                'update_fields_enabled' => 'nullable|boolean',
                'export_fields' => 'nullable|array',
                'sync_by_collection' => 'nullable|boolean',
                'selected_collections' => 'nullable|array',
                'sync_by_tags' => 'nullable|boolean',
                'selected_tags' => 'nullable|array',
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => 'Invalid request data: ' . $e->getMessage()], 400);
        }

        $shop = $validated['shop'];

        try {
            ProductSettings::updateOrCreate(
                ['shop' => $shop],
                $validated
            );

            return response()->json([
                'message' => 'Product settings updated successfully',
            ]);
        } catch (Exception $e) {
            Log::error("Failed to update product settings for shop {$shop}: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get product synchronization settings.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function get(Request $request): JsonResponse
    {
        $shopDomain = $request->query('shop');
        if (empty($shopDomain)) {
            return response()->json(['error' => 'Missing shop parameter'], 400);
        }

        try {
            $settings = ProductSettings::where('shop', $shopDomain)->first();

            if (!$settings) {
                return response()->json([
                    'shop' => $shopDomain,
                    'sync_direction' => 'shopify-to-zoho',
                    'auto_sync_enabled' => false,
                    'update_fields_enabled' => false,
                    'sync_draft_products' => false,
                    'export_fields' => null,
                    'sync_by_collection' => false,
                    'selected_collections' => null,
                    'sync_by_tags' => false,
                    'selected_tags' => null,
                ]);
            }

            return response()->json($settings);
        } catch (Exception $e) {
            Log::error("Failed to fetch product settings for shop {$shopDomain}: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
