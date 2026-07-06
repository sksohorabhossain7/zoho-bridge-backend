<?php

namespace App\Services;

use App\Models\ZohoToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class ZohoService
{
    protected ZohoAuthService $authService;
    protected string $defaultApiDomain;

    public function __construct(ZohoAuthService $authService)
    {
        $this->authService = $authService;
        $this->defaultApiDomain = config('services.zoho.api_domain', env('ZOHO_API_DOMAIN', 'https://www.zohoapis.com'));
    }

    /**
     * Get target regional API domain.
     *
     * @param ZohoToken $token
     * @return string
     */
    protected function getApiDomain(ZohoToken $token): string
    {
        if (!empty($token->apiDomain)) {
            return $token->apiDomain;
        }
        return $this->defaultApiDomain;
    }

    /**
     * Get headers for Zoho API request.
     *
     * @param ZohoToken $token
     * @return array
     */
    protected function getHeaders(ZohoToken $token): array
    {
        return [
            'Authorization' => 'Zoho-oauthtoken ' . $token->accessToken,
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Get the count of items in Zoho.
     *
     * @param string $shopDomain
     * @param string $orgID
     * @return int
     * @throws Exception
     */
    public function getItemsCount(string $shopDomain, string $orgID): int
    {
        $token = $this->authService->ensureValidToken($shopDomain);
        $apiDomain = $this->getApiDomain($token);

        $url = rtrim($apiDomain, '/') . '/books/v3/items';

        $response = Http::withHeaders($this->getHeaders($token))->get($url, [
            'organization_id' => $orgID,
            'per_page' => 1,
            'response_option' => 2, // 2 = Includes only the number of items
        ]);

        if ($response->failed()) {
            Log::error("Zoho getItemsCount API error: {$response->status()} - {$response->body()}");
            throw new Exception("Zoho API error: {$response->status()} - {$response->body()}");
        }

        $result = $response->json();

        if (isset($result['count'])) {
            return (int) $result['count'];
        }

        if (isset($result['page_context'])) {
            $pc = $result['page_context'];
            if (isset($pc['total'])) {
                return (int) $pc['total'];
            }
            if (isset($pc['total_count'])) {
                return (int) $pc['total_count'];
            }
        }

        Log::warning("Could not find total in Zoho response: " . json_encode($result));
        return 0;
    }

    /**
     * Fetch items sorted by modified time.
     *
     * @param string $shopDomain
     * @param string $orgID
     * @return array
     * @throws Exception
     */
    public function fetchItemsSorted(string $shopDomain, string $orgID, string $status = ''): array
    {
        $token = $this->authService->ensureValidToken($shopDomain);
        $apiDomain = $this->getApiDomain($token);

        $allItems = [];
        $page = 1;

        while (true) {
            $url = rtrim($apiDomain, '/') . '/books/v3/items';

            $params = [
                'organization_id' => $orgID,
                'sort_column' => 'last_modified_time',
                'sort_order' => 'D', // Descending
                'page' => $page,
            ];

            if ($status !== '') {
                $params['status'] = $status;
            }

            $response = Http::withHeaders($this->getHeaders($token))->get($url, $params);

            if ($response->failed()) {
                throw new Exception("Zoho fetchItemsSorted error: {$response->status()} - {$response->body()}");
            }

            $result = $response->json();
            $items = $result['items'] ?? [];
            $allItems = array_merge($allItems, $items);

            $hasMore = $result['page_context']['has_more_page'] ?? false;

            // Limit to 5 pages for safety in initial sync (same as Go app)
            if (!$hasMore || $page >= 5) {
                break;
            }
            $page++;
        }

        return $allItems;
    }

    /**
     * Fetch a single Zoho item.
     *
     * @param string $shopDomain
     * @param string $orgID
     * @param string $itemID
     * @return array|null
     * @throws Exception
     */
    public function fetchItem(string $shopDomain, string $orgID, string $itemID): ?array
    {
        $token = $this->authService->ensureValidToken($shopDomain);
        $apiDomain = $this->getApiDomain($token);

        $url = rtrim($apiDomain, '/') . "/books/v3/items/{$itemID}";

        $response = Http::withHeaders($this->getHeaders($token))->get($url, [
            'organization_id' => $orgID,
        ]);

        if ($response->failed()) {
            throw new Exception("Zoho fetchItem error: {$response->status()} - {$response->body()}");
        }

        $result = $response->json();
        return $result['item'] ?? null;
    }

    /**
     * Fetch connected Zoho Organizations.
     *
     * @param string $shopDomain
     * @return array
     * @throws Exception
     */
    public function fetchOrganizations(string $shopDomain): array
    {
        $token = $this->authService->ensureValidToken($shopDomain);
        $apiDomain = $this->getApiDomain($token);

        $url = rtrim($apiDomain, '/') . '/books/v3/organizations';

        $response = Http::withHeaders($this->getHeaders($token))->get($url);

        if ($response->failed()) {
            Log::error("Zoho fetchOrganizations error: {$response->status()} - {$response->body()}");
            throw new Exception("Zoho API error: {$response->status()} - {$response->body()}");
        }

        $result = $response->json();
        return $result['organizations'] ?? [];
    }

    /**
     * Fetch paginated Zoho items with options.
     *
     * @param string $shopDomain
     * @param string $orgID
     * @param int $page
     * @param int $perPage
     * @param string $search
     * @param string $status
     * @return array Array with [items, page_context]
     * @throws Exception
     */
    public function fetchItemsPaginated(string $shopDomain, string $orgID, int $page, int $perPage, string $search = '', string $status = ''): array
    {
        $token = $this->authService->ensureValidToken($shopDomain);
        $apiDomain = $this->getApiDomain($token);

        $url = rtrim($apiDomain, '/') . '/books/v3/items';

        $params = [
            'organization_id' => $orgID,
            'page' => $page,
            'per_page' => $perPage,
            'sort_column' => 'name',
            'sort_order' => 'A',
        ];

        if ($search !== '') {
            $params['search_text'] = $search;
        }

        if ($status !== '') {
            $params['status'] = $status;
        }

        $response = Http::withHeaders($this->getHeaders($token))->get($url, $params);

        if ($response->failed()) {
            Log::error("Zoho fetchItemsPaginated API error: {$response->status()} - {$response->body()}");
            throw new Exception("Zoho API error: {$response->status()} - {$response->body()}");
        }

        $result = $response->json();

        return [
            $result['items'] ?? [],
            $result['page_context'] ?? null,
        ];
    }

    /**
     * Fetch warehouse locations for mapping.
     *
     * @param string $shopDomain
     * @param string $orgID
     * @return array
     * @throws Exception
     */
    public function fetchLocations(string $shopDomain, string $orgID): array
    {
        $token = $this->authService->ensureValidToken($shopDomain);
        $apiDomain = $this->getApiDomain($token);

        $url = rtrim($apiDomain, '/') . '/books/v3/locations';

        $response = Http::withHeaders($this->getHeaders($token))->get($url, [
            'organization_id' => $orgID,
        ]);

        if ($response->failed()) {
            throw new Exception("Zoho fetchLocations error: {$response->status()} - {$response->body()}");
        }

        $result = $response->json();
        return $result['locations'] ?? [];
    }

    /**
     * Create a new item in Zoho Books/Inventory.
     *
     * @param string $shopDomain
     * @param string $orgID
     * @param array $itemData
     * @return array
     * @throws Exception
     */
    public function createItem(string $shopDomain, string $orgID, array $itemData): array
    {
        $token = $this->authService->ensureValidToken($shopDomain);
        $apiDomain = $this->getApiDomain($token);

        $url = rtrim($apiDomain, '/') . '/books/v3/items';

        $response = Http::withHeaders($this->getHeaders($token))
            ->withQueryParameters(['organization_id' => $orgID])
            ->post($url, $itemData);

        if ($response->failed()) {
            throw new Exception("Zoho createItem error: {$response->status()} - {$response->body()}");
        }

        $result = $response->json();

        if (!isset($result['item'])) {
            throw new Exception("Zoho createItem missing item node in response: {$response->body()}");
        }

        return $result['item'];
    }

    /**
     * Update an item in Zoho Books/Inventory.
     *
     * @param string $shopDomain
     * @param string $orgID
     * @param string $itemID
     * @param array $itemData
     * @return array
     * @throws Exception
     */
    public function updateItem(string $shopDomain, string $orgID, string $itemID, array $itemData): array
    {
        $token = $this->authService->ensureValidToken($shopDomain);
        $apiDomain = $this->getApiDomain($token);

        $url = rtrim($apiDomain, '/') . "/books/v3/items/{$itemID}";

        $response = Http::withHeaders($this->getHeaders($token))
            ->withQueryParameters(['organization_id' => $orgID])
            ->put($url, $itemData);

        if ($response->failed()) {
            throw new Exception("Zoho updateItem error: {$response->status()} - {$response->body()}");
        }

        $result = $response->json();

        if (!isset($result['item'])) {
            throw new Exception("Zoho updateItem missing item node in response: {$response->body()}");
        }

        return $result['item'];
    }

    /**
     * Fetch a Zoho item by its SKU.
     *
     * @param string $shopDomain
     * @param string $orgID
     * @param string $sku
     * @return array|null
     * @throws Exception
     */
    public function fetchItemBySku(string $shopDomain, string $orgID, string $sku): ?array
    {
        $token = $this->authService->ensureValidToken($shopDomain);
        $apiDomain = $this->getApiDomain($token);

        $url = rtrim($apiDomain, '/') . '/books/v3/items';

        $response = Http::withHeaders($this->getHeaders($token))->get($url, [
            'organization_id' => $orgID,
            'sku' => $sku,
        ]);

        if ($response->failed()) {
            Log::error("Zoho fetchItemBySku API error: {$response->status()} - {$response->body()}");
            return null;
        }

        $result = $response->json();
        $items = $result['items'] ?? [];

        foreach ($items as $item) {
            if (isset($item['sku']) && strtolower($item['sku']) === strtolower($sku)) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Fetch accounts from Zoho Books Chart of Accounts.
     *
     * @param string $shopDomain
     * @param string $orgID
     * @return array
     * @throws Exception
     */
    public function fetchAccounts(string $shopDomain, string $orgID): array
    {
        $token = $this->authService->ensureValidToken($shopDomain);
        $apiDomain = $this->getApiDomain($token);

        $url = rtrim($apiDomain, '/') . '/books/v3/chartofaccounts';

        $response = Http::withHeaders($this->getHeaders($token))->get($url, [
            'organization_id' => $orgID,
        ]);

        if ($response->failed()) {
            Log::error("Zoho fetchAccounts API error: {$response->status()} - {$response->body()}");
            return [];
        }

        $result = $response->json();
        return $result['chartofaccounts'] ?? [];
    }

    /**
     * Fetch all Zoho tax profiles.
     *
     * @param string $shopDomain
     * @param string $orgID
     * @return array
     * @throws Exception
     */
    public function fetchTaxes(string $shopDomain, string $orgID): array
    {
        $token = $this->authService->ensureValidToken($shopDomain);
        $apiDomain = $this->getApiDomain($token);

        $url = rtrim($apiDomain, '/') . '/books/v3/settings/taxes';

        $response = Http::withHeaders($this->getHeaders($token))->get($url, [
            'organization_id' => $orgID,
        ]);

        if ($response->failed()) {
            Log::error("Zoho fetchTaxes API error: {$response->status()} - {$response->body()}");
            return [];
        }

        $result = $response->json();
        return $result['taxes'] ?? [];
    }

    /**
     * Fetch a contact by name and type from Zoho Books.
     *
     * @param string $shopDomain
     * @param string $orgID
     * @param string $contactName
     * @param string $contactType
     * @return array|null
     */
    public function fetchContactByName(string $shopDomain, string $orgID, string $contactName, string $contactType = 'vendor'): ?array
    {
        $token = $this->authService->ensureValidToken($shopDomain);
        $apiDomain = $this->getApiDomain($token);

        $url = rtrim($apiDomain, '/') . '/books/v3/contacts';

        $response = Http::withHeaders($this->getHeaders($token))
            ->withQueryParameters([
                'organization_id' => $orgID,
                'contact_name' => $contactName,
                'contact_type' => $contactType,
            ])
            ->get($url);

        if ($response->failed()) {
            Log::error("Zoho fetchContactByName API error: {$response->status()} - {$response->body()}");
            return null;
        }

        $result = $response->json();
        $contacts = $result['contacts'] ?? [];

        foreach ($contacts as $contact) {
            if (strtolower($contact['contact_name'] ?? '') === strtolower($contactName)) {
                return $contact;
            }
        }

        return null;
    }

    /**
     * Create a new contact in Zoho Books.
     *
     * @param string $shopDomain
     * @param string $orgID
     * @param array $contactData
     * @return array|null
     */
    public function createContact(string $shopDomain, string $orgID, array $contactData): ?array
    {
        $token = $this->authService->ensureValidToken($shopDomain);
        $apiDomain = $this->getApiDomain($token);

        $url = rtrim($apiDomain, '/') . '/books/v3/contacts';

        $response = Http::withHeaders($this->getHeaders($token))
            ->withQueryParameters(['organization_id' => $orgID])
            ->post($url, $contactData);

        if ($response->failed()) {
            Log::error("Zoho createContact API error: {$response->status()} - {$response->body()}");
            return null;
        }

        $result = $response->json();
        return $result['contact'] ?? null;
    }

    /**
     * Fetch a contact by email from Zoho Books.
     *
     * @param string $shopDomain
     * @param string $orgID
     * @param string $email
     * @return array|null
     */
    public function fetchContactByEmail(string $shopDomain, string $orgID, string $email): ?array
    {
        $token = $this->authService->ensureValidToken($shopDomain);
        $apiDomain = $this->getApiDomain($token);

        $url = rtrim($apiDomain, '/') . '/books/v3/contacts';

        $response = Http::withHeaders($this->getHeaders($token))
            ->withQueryParameters([
                'organization_id' => $orgID,
                'email' => $email,
            ])
            ->get($url);

        if ($response->failed()) {
            Log::error("Zoho fetchContactByEmail API error: {$response->status()} - {$response->body()}");
            return null;
        }

        $result = $response->json();
        $contacts = $result['contacts'] ?? [];

        foreach ($contacts as $contact) {
            if (strtolower($contact['email'] ?? '') === strtolower($email)) {
                return $contact;
            }
        }

        return null;
    }

    /**
     * Update a contact in Zoho Books.
     *
     * @param string $shopDomain
     * @param string $orgID
     * @param string $contactId
     * @param array $contactData
     * @return array|null
     */
    public function updateContact(string $shopDomain, string $orgID, string $contactId, array $contactData): ?array
    {
        $token = $this->authService->ensureValidToken($shopDomain);
        $apiDomain = $this->getApiDomain($token);

        $url = rtrim($apiDomain, '/') . '/books/v3/contacts/' . $contactId;

        $response = Http::withHeaders($this->getHeaders($token))
            ->withQueryParameters(['organization_id' => $orgID])
            ->put($url, $contactData);

        if ($response->failed()) {
            Log::error("Zoho updateContact API error: {$response->status()} - {$response->body()}");
            return null;
        }

        $result = $response->json();
        return $result['contact'] ?? null;
    }
}
