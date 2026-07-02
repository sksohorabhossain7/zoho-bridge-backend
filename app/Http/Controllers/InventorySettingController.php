<?php

namespace App\Http\Controllers;

use App\Models\InventorySettings;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Exception;

class InventorySettingController extends Controller
{
    /**
     * Update inventory synchronization settings.
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
                'auto_sync_enabled' => 'nullable|boolean',
                'quantity_type' => 'nullable|string',
                'sync_frequency' => 'nullable|string',
                'skip_zero_stock' => 'nullable|boolean',
                'location_mapping' => 'nullable|array',
                'sync_by_collection' => 'nullable|boolean',
                'selected_collections' => 'nullable|array',
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => 'Invalid request data: ' . $e->getMessage()], 400);
        }

        $shop = $validated['shop'];

        try {
            InventorySettings::updateOrCreate(
                ['shop' => $shop],
                $validated
            );

            return response()->json([
                'message' => 'Inventory settings updated successfully',
            ]);
        } catch (Exception $e) {
            Log::error("Failed to update inventory settings for shop {$shop}: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get inventory synchronization settings.
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
            $settings = InventorySettings::where('shop', $shopDomain)->first();

            if (!$settings) {
                return response()->json([
                    'shop' => $shopDomain,
                    'sync_direction' => 'shopify-to-zoho',
                    'auto_sync_enabled' => false,
                    'quantity_type' => 'available',
                    'sync_frequency' => '30',
                    'skip_zero_stock' => false,
                    'location_mapping' => null,
                    'sync_by_collection' => false,
                    'selected_collections' => null,
                ]);
            }

            return response()->json($settings);
        } catch (Exception $e) {
            Log::error("Failed to fetch inventory settings for shop {$shopDomain}: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
