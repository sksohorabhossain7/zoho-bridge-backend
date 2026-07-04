<?php

namespace App\Http\Controllers;

use App\Models\ZohoToken;
use App\Services\ZohoAuthService;
use App\Services\ZohoService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Exception;

class ZohoAuthController extends Controller
{
    protected ZohoAuthService $authService;
    protected ZohoService $zohoService;

    public function __construct(ZohoAuthService $authService, ZohoService $zohoService)
    {
        $this->authService = $authService;
        $this->zohoService = $zohoService;
    }

    // Connect and callback endpoints removed.

    /**
     * Check Zoho connection status.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function status(Request $request): JsonResponse
    {
        $shopDomain = $request->query('shop');
        if (empty($shopDomain)) {
            return response()->json(['error' => 'Missing shop domain parameter'], 400);
        }

        try {
            $token = ZohoToken::where('shop', $shopDomain)->first();
            $connected = $token && !empty($token->accessToken);

            return response()->json([
                'connected' => $connected,
            ]);
        } catch (Exception $e) {
            Log::error("Failed to fetch connection status: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // Disconnect endpoint removed.

    /**
     * Get Zoho organizations.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getOrganizations(Request $request): JsonResponse
    {
        $shopDomain = $request->query('shop');
        if (empty($shopDomain)) {
            return response()->json(['error' => 'Missing shop domain parameter'], 400);
        }

        try {
            $orgs = $this->zohoService->fetchOrganizations($shopDomain);
            return response()->json([
                'organizations' => $orgs,
            ]);
        } catch (Exception $e) {
            Log::error("Failed to fetch Zoho organizations for shop {$shopDomain}: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get Zoho accounts.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getAccounts(Request $request): JsonResponse
    {
        $shopDomain = $request->query('shop');
        if (empty($shopDomain)) {
            return response()->json(['error' => 'Missing shop domain parameter'], 400);
        }

        try {
            $token = ZohoToken::where('shop', $shopDomain)->first();
            if (!$token || empty($token->organization_id)) {
                return response()->json(['accounts' => []]);
            }

            $accounts = $this->zohoService->fetchAccounts($shopDomain, $token->organization_id);
            return response()->json([
                'accounts' => $accounts,
            ]);
        } catch (Exception $e) {
            Log::error("Failed to fetch Zoho accounts for shop {$shopDomain}: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get active Zoho configuration token record.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getConfiguration(Request $request): JsonResponse
    {
        $shopDomain = $request->query('shop');
        if (empty($shopDomain)) {
            return response()->json(['error' => 'Missing shop parameter'], 400);
        }

        try {
            $token = ZohoToken::where('shop', $shopDomain)->first();
            if (!$token) {
                return response()->json(['error' => 'Token not found'], 404);
            }

            return response()->json($token);
        } catch (Exception $e) {
            Log::error("Failed to get config for shop {$shopDomain}: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update Zoho connection credentials and organization.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateConfiguration(Request $request): JsonResponse
    {
        $shopDomain = $request->json('shop');
        $clientId = $request->json('clientId');
        $clientSecret = $request->json('clientSecret');
        $region = $request->json('region');
        $organizationID = $request->json('organizationID');
        $organizationName = $request->json('organizationName');
        $skuMapping = $request->json('skuMapping');
        $zohoCustomField = $request->json('zohoCustomField');
        $saleInvoiceJournal = $request->json('saleInvoiceJournal');
        $enableFixedTax = $request->json('enableFixedTax');
        $taxType = $request->json('taxType');

        if (empty($shopDomain)) {
            return response()->json(['error' => 'Missing shop parameter'], 400);
        }

        try {
            // Find or create ZohoToken record
            $token = ZohoToken::where('shop', $shopDomain)->first();
            if (!$token) {
                $token = new ZohoToken();
                $token->shop = $shopDomain;
            }

            // Update credentials
            $token->clientId = $clientId;
            $token->clientSecret = $clientSecret;
            $token->region = $region;
            $token->sku_mapping = $skuMapping ?? 'sku';
            $token->zoho_custom_field = $zohoCustomField;
            $token->sale_invoice_journal = $saleInvoiceJournal;
            $token->enable_fixed_tax = filter_var($enableFixedTax, FILTER_VALIDATE_BOOLEAN);
            $token->tax_type = $taxType;

            // Map region to server and domain
            if (!empty($region)) {
                $token->accountsServer = $this->authService->getAccountsUrlForRegion($region);
                $token->apiDomain = $this->authService->getApiUrlForRegion($region);
            }

            // Only update organization if organizationID is provided
            if ($organizationID !== null) {
                $token->organizationID = $organizationID;
                $token->organizationName = $organizationName;
            }

            $token->save();

            // Try to authenticate with the new credentials if we have all of them
            if (!empty($clientId) && !empty($clientSecret) && !empty($region)) {
                try {
                    $this->authService->fetchTokenUsingClientCredentials($token);
                } catch (Exception $authEx) {
                    Log::error("Authentication failed during credentials save for shop {$shopDomain}: " . $authEx->getMessage());
                    return response()->json([
                        'success' => false,
                        'error' => 'Saved configuration, but Zoho authentication failed: ' . $authEx->getMessage()
                    ], 400);
                }
            }

            return response()->json(['success' => true]);
        } catch (Exception $e) {
            Log::error("Failed to update Zoho configuration for shop {$shopDomain}: " . $e->getMessage());
            return response()->json(['error' => 'Failed to save configuration: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Get Zoho taxes.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getTaxes(Request $request): JsonResponse
    {
        $shopDomain = $request->query('shop');
        if (empty($shopDomain)) {
            return response()->json(['error' => 'Missing shop parameter'], 400);
        }

        try {
            $token = ZohoToken::where('shop', $shopDomain)->first();
            if (!$token) {
                return response()->json(['error' => 'Shop not connected'], 404);
            }

            if (empty($token->organizationID)) {
                return response()->json(['taxes' => []]);
            }

            $taxes = $this->zohoService->fetchTaxes($shopDomain, $token->organizationID);
            return response()->json([
                'taxes' => $taxes,
            ]);
        } catch (Exception $e) {
            Log::error("Failed to fetch taxes for shop {$shopDomain}: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get warehouses/locations mapping.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getWarehouses(Request $request): JsonResponse
    {
        $shopDomain = $request->query('shop');
        if (empty($shopDomain)) {
            return response()->json(['error' => 'Missing shop parameter'], 400);
        }

        try {
            $token = ZohoToken::where('shop', $shopDomain)->first();
            if (!$token) {
                return response()->json(['error' => 'Shop not connected'], 404);
            }

            if (empty($token->organizationID)) {
                return response()->json(['error' => 'No Zoho organization selected'], 400);
            }

            $locations = $this->zohoService->fetchLocations($shopDomain, $token->organizationID);
            return response()->json([
                'locations' => $locations,
            ]);
        } catch (Exception $e) {
            Log::error("Failed to fetch warehouses for shop {$shopDomain}: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
