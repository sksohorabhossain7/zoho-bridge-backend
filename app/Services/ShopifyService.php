<?php

namespace App\Services;

use App\Models\Session;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class ShopifyService
{
    /**
     * Get the Shopify access token for a given shop.
     *
     * @param string $shopDomain
     * @return string
     * @throws Exception
     */
    public function getAccessToken(string $shopDomain): string
    {
        $session = Session::where('shop', $shopDomain)->first();
        if (!$session) {
            throw new Exception("Shopify session not found for shop: {$shopDomain}");
        }
        return $session->accessToken;
    }

    /**
     * Query the Shopify GraphQL API.
     *
     * @param string $shopDomain
     * @param string $accessToken
     * @param string $query
     * @param array $variables
     * @return array
     * @throws Exception
     */
    public function queryGraphQL(string $shopDomain, string $accessToken, string $query, array $variables = []): array
    {
        $url = "https://{$shopDomain}/admin/api/2026-07/graphql.json";
        
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Shopify-Access-Token' => $accessToken,
        ])->timeout(10)->post($url, [
            'query' => $query,
            'variables' => $variables,
        ]);

        if ($response->failed()) {
            throw new Exception("Shopify API HTTP request failed: {$response->status()} - {$response->body()}");
        }

        $result = $response->json();
        
        if (isset($result['errors']) && count($result['errors']) > 0) {
            throw new Exception("Shopify GraphQL errors: " . $result['errors'][0]['message']);
        }

        return $result;
    }

    /**
     * Create or update a Shopify product using productSet mutation.
     *
     * @param string $shopDomain
     * @param string $accessToken
     * @param array $input
     * @param string $productID
     * @return array Array with [productID, variantID, inventoryItemID]
     * @throws Exception
     */
    public function syncProduct(string $shopDomain, string $accessToken, array $input, string $productID = ''): array
    {
        $mutation = '
        mutation productSet($input: ProductSetInput!, $identifier: ProductSetIdentifiers) {
            productSet(input: $input, identifier: $identifier) {
                product {
                    id
                    variants(first: 250) {
                        nodes {
                            id
                            sku
                            inventoryItem {
                                id
                            }
                        }
                    }
                }
                userErrors {
                    field
                    message
                }
            }
        }
        ';

        $variables = ['input' => $input];

        if ($productID !== '') {
            $variables['identifier'] = ['id' => $productID];
        }

        $result = $this->queryGraphQL($shopDomain, $accessToken, $mutation, $variables);
        
        Log::debug("Shopify SyncProduct Response: " . json_encode($result));

        $productSet = $result['data']['productSet'] ?? null;
        if (!$productSet) {
            throw new Exception("Invalid response structure from Shopify productSet mutation.");
        }

        if (isset($productSet['userErrors']) && count($productSet['userErrors']) > 0) {
            throw new Exception("shopify productSet error: " . $productSet['userErrors'][0]['message']);
        }

        $product = $productSet['product'];
        $pID = $product['id'];
        $vID = '';
        $iID = '';

        if (isset($product['variants']['nodes']) && count($product['variants']['nodes']) > 0) {
            $vID = $product['variants']['nodes'][0]['id'];
            $iID = $product['variants']['nodes'][0]['inventoryItem']['id'] ?? '';
        }

        return [$pID, $vID, $iID];
    }

    /**
     * Fetch a product variant.
     *
     * @param string $shopDomain
     * @param string $accessToken
     * @param string $variantID
     * @return array|null
     * @throws Exception
     */
    public function fetchVariant(string $shopDomain, string $accessToken, string $variantID): ?array
    {
        $query = '
        query variant($id: ID!) {
            productVariant(id: $id) {
                id
                sku
                barcode
                price
                inventoryItem {
                    id
                    unitCost {
                        amount
                    }
                    tracked
                }
            }
        }
        ';

        $variables = ['id' => $variantID];
        $result = $this->queryGraphQL($shopDomain, $accessToken, $query, $variables);
        
        return $result['data']['productVariant'] ?? null;
    }

    /**
     * Fetch product collection IDs.
     *
     * @param string $shopDomain
     * @param string $accessToken
     * @param string $productID
     * @return array
     * @throws Exception
     */
    public function fetchProductCollections(string $shopDomain, string $accessToken, string $productID): array
    {
        $query = '
        query product($id: ID!) {
            product(id: $id) {
                collections(first: 250) {
                    nodes {
                        id
                    }
                }
            }
        }
        ';

        $variables = ['id' => $productID];
        $result = $this->queryGraphQL($shopDomain, $accessToken, $query, $variables);
        
        $nodes = $result['data']['product']['collections']['nodes'] ?? [];
        
        return collect($nodes)->pluck('id')->toArray();
    }

    /**
     * Fetch multiple products from Shopify.
     *
     * @param string $shopDomain
     * @param string $accessToken
     * @param string $queryStr
     * @return array
     * @throws Exception
     */
    public function fetchProducts(string $shopDomain, string $accessToken, string $queryStr = ''): array
    {
        $query = '
        query products($first: Int!, $query: String) {
            products(first: $first, query: $query) {
                nodes {
                    id
                    title
                    vendor
                    descriptionHtml
                    status
                    tags
                    collections(first: 10) {
                        nodes {
                            id
                        }
                    }
                    variants(first: 10) {
                        nodes {
                            id
                            title
                            sku
                            barcode
                            price
                            inventoryItem {
                                id
                                unitCost {
                                    amount
                                }
                                tracked
                            }
                        }
                    }
                }
                pageInfo {
                    hasNextPage
                }
            }
        }
        ';

        $variables = ['first' => 50];
        if ($queryStr !== '') {
            $variables['query'] = $queryStr;
        }

        $result = $this->queryGraphQL($shopDomain, $accessToken, $query, $variables);
        
        return $result['data']['products']['nodes'] ?? [];
    }

    /**
     * Update product inventory cost.
     *
     * @param string $shopDomain
     * @param string $accessToken
     * @param string $inventoryItemID
     * @param float $cost
     * @return void
     * @throws Exception
     */
    public function updateInventoryCost(string $shopDomain, string $accessToken, string $inventoryItemID, float $cost): void
    {
        $mutation = '
        mutation inventoryItemUpdate($id: ID!, $input: InventoryItemInput!) {
            inventoryItemUpdate(id: $id, input: $input) {
                inventoryItem {
                    id
                    unitCost {
                        amount
                    }
                }
                userErrors {
                    field
                    message
                }
            }
        }
        ';

        $variables = [
            'id' => $inventoryItemID,
            'input' => ['cost' => $cost],
        ];

        $result = $this->queryGraphQL($shopDomain, $accessToken, $mutation, $variables);
        
        $userErrors = $result['data']['inventoryItemUpdate']['userErrors'] ?? [];
        if (count($userErrors) > 0) {
            throw new Exception("shopify cost update error: " . $userErrors[0]['message']);
        }
    }

    /**
     * Get the count of products in Shopify.
     *
     * @param string $shopDomain
     * @param string $accessToken
     * @return int
     * @throws Exception
     */
    public function getProductsCount(string $shopDomain, string $accessToken): int
    {
        $query = '
        query {
            productsCount {
                count
            }
        }
        ';
        
        $result = $this->queryGraphQL($shopDomain, $accessToken, $query);
        return $result['data']['productsCount']['count'] ?? 0;
    }

    /**
     * Fetch all product IDs from Shopify.
     *
     * @param string $shopDomain
     * @param string $accessToken
     * @return array
     * @throws Exception
     */
    public function fetchAllProductIds(string $shopDomain, string $accessToken): array
    {
        $productIds = [];
        $cursor = null;

        while (true) {
            $query = '
            query products($first: Int!, $after: String) {
                products(first: $first, after: $after) {
                    nodes {
                        id
                    }
                    pageInfo {
                        hasNextPage
                        endCursor
                    }
                }
            }
            ';

            $variables = ['first' => 250];
            if ($cursor) {
                $variables['after'] = $cursor;
            }

            $result = $this->queryGraphQL($shopDomain, $accessToken, $query, $variables);
            $nodes = $result['data']['products']['nodes'] ?? [];
            foreach ($nodes as $node) {
                $productIds[] = $node['id'];
            }

            $hasNextPage = $result['data']['products']['pageInfo']['hasNextPage'] ?? false;
            if (!$hasNextPage) {
                break;
            }
            $cursor = $result['data']['products']['pageInfo']['endCursor'] ?? null;
        }

        return $productIds;
    }

    /**
     * Search for a customer ID by email in Shopify.
     *
     * @param string $shopDomain
     * @param string $accessToken
     * @param string $email
     * @return string|null
     * @throws Exception
     */
    public function findCustomerByEmail(string $shopDomain, string $accessToken, string $email): ?string
    {
        $query = <<<'GRAPHQL'
query ($query: String!) {
  customers(first: 1, query: $query) {
    edges {
      node {
        id
      }
    }
  }
}
GRAPHQL;

        $variables = ['query' => "email:{$email}"];
        $result = $this->queryGraphQL($shopDomain, $accessToken, $query, $variables);
        $edges = $result['data']['customers']['edges'] ?? [];
        if (count($edges) > 0) {
            return $edges[0]['node']['id'];
        }

        return null;
    }

    /**
     * Create or Update a customer in Shopify.
     *
     * @param string $shopDomain
     * @param string $accessToken
     * @param array $input
     * @param string|null $shopifyCustomerId
     * @return string Shopify Customer ID
     * @throws Exception
     */
    public function syncCustomer(string $shopDomain, string $accessToken, array $input, ?string $shopifyCustomerId = null): string
    {
        if (!empty($shopifyCustomerId)) {
            // Update
            $query = <<<'GRAPHQL'
mutation customerUpdate($input: CustomerInput!) {
  customerUpdate(input: $input) {
    customer {
      id
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;
            $input['id'] = $shopifyCustomerId;
            $variables = ['input' => $input];
            $result = $this->queryGraphQL($shopDomain, $accessToken, $query, $variables);
            
            $userErrors = $result['data']['customerUpdate']['userErrors'] ?? [];
            if (count($userErrors) > 0) {
                // If there's an error about phone number, retry without phone
                $hasPhoneError = false;
                foreach ($userErrors as $err) {
                    if (str_contains(strtolower($err['field'][0] ?? ''), 'phone')) {
                        $hasPhoneError = true;
                        break;
                    }
                }
                if ($hasPhoneError && isset($input['phone'])) {
                    unset($input['phone']);
                    return $this->syncCustomer($shopDomain, $accessToken, $input, $shopifyCustomerId);
                }
                throw new Exception("Shopify Customer Update Error: " . $userErrors[0]['message']);
            }
            
            return $result['data']['customerUpdate']['customer']['id'];
        } else {
            // Create
            $query = <<<'GRAPHQL'
mutation customerCreate($input: CustomerInput!) {
  customerCreate(input: $input) {
    customer {
      id
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;
            $variables = ['input' => $input];
            $result = $this->queryGraphQL($shopDomain, $accessToken, $query, $variables);
            
            $userErrors = $result['data']['customerCreate']['userErrors'] ?? [];
            if (count($userErrors) > 0) {
                // If there's an error about phone number, retry without phone
                $hasPhoneError = false;
                foreach ($userErrors as $err) {
                    if (str_contains(strtolower($err['field'][0] ?? ''), 'phone')) {
                        $hasPhoneError = true;
                        break;
                    }
                }
                if ($hasPhoneError && isset($input['phone'])) {
                    unset($input['phone']);
                    return $this->syncCustomer($shopDomain, $accessToken, $input);
                }
                throw new Exception("Shopify Customer Create Error: " . $userErrors[0]['message']);
            }
            
            return $result['data']['customerCreate']['customer']['id'];
        }
    }
}
