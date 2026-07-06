<?php

namespace App\Http\Controllers;

use App\Models\CustomerSettings;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Exception;

class CustomerSettingController extends Controller
{
    /**
     * Update customer synchronization settings.
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
                'sync_option' => 'nullable|string',
                'enable_customer_sync_shopify_to_zoho' => 'nullable|boolean',
                'enable_customer_sync_zoho_to_shopify' => 'nullable|boolean',
            ]);
        } catch (Exception $e) {
            return response()->json(['error' => 'Invalid request data: ' . $e->getMessage()], 400);
        }

        $shop = $validated['shop'];

        try {
            $settings = CustomerSettings::updateOrCreate(
                ['shop' => $shop],
                $validated
            );

            return response()->json([
                'success' => true,
                'message' => 'Customer settings updated successfully',
                'data' => $settings
            ]);
        } catch (Exception $e) {
            Log::error("Failed to update customer settings for shop {$shop}: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get customer synchronization settings.
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
            $settings = CustomerSettings::where('shop', $shopDomain)->first();

            if (!$settings) {
                return response()->json([
                    'shop' => $shopDomain,
                    'sync_direction' => 'shopify-to-zoho',
                    'sync_option' => 'create_new_and_update_existing',
                    'enable_customer_sync_shopify_to_zoho' => false,
                    'enable_customer_sync_zoho_to_shopify' => false,
                ]);
            }

            return response()->json($settings);
        } catch (Exception $e) {
            Log::error("Failed to fetch customer settings for shop {$shopDomain}: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
