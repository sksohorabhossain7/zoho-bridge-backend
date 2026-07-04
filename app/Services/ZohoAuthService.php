<?php

namespace App\Services;

use App\Models\ZohoToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Exception;

class ZohoAuthService
{
    protected string $clientId;
    protected string $clientSecret;
    protected string $redirectUri;
    protected string $accountsUrl;

    public function __construct()
    {
        $this->clientId = config('services.zoho.client_id', env('ZOHO_CLIENT_ID', ''));
        $this->clientSecret = config('services.zoho.client_secret', env('ZOHO_CLIENT_SECRET', ''));
        $this->redirectUri = config('services.zoho.redirect_uri', env('ZOHO_REDIRECT_URI', ''));
        $this->accountsUrl = config('services.zoho.accounts_url', env('ZOHO_ACCOUNTS_URL', 'https://accounts.zoho.com'));
    }

    /**
     * Get target regional accounts URL.
     */
    public function getAccountsUrlForRegion(?string $region): string
    {
        if (empty($region)) {
            return 'https://accounts.zoho.com';
        }
        $region = strtolower($region);
        return match ($region) {
            'eu' => 'https://accounts.zoho.eu',
            'in' => 'https://accounts.zoho.in',
            'au' => 'https://accounts.zoho.com.au',
            'jp' => 'https://accounts.zoho.jp',
            'sa' => 'https://accounts.zoho.sa',
            'ca' => 'https://accounts.zoho.ca',
            'uk' => 'https://accounts.zoho.co.uk',
            default => 'https://accounts.zoho.com',
        };
    }

    /**
     * Get target regional API domain.
     */
    public function getApiUrlForRegion(?string $region): string
    {
        if (empty($region)) {
            return 'https://www.zohoapis.com';
        }
        $region = strtolower($region);
        return match ($region) {
            'eu' => 'https://www.zohoapis.eu',
            'in' => 'https://www.zohoapis.in',
            'au' => 'https://www.zohoapis.com.au',
            'jp' => 'https://www.zohoapis.jp',
            'sa' => 'https://www.zohoapis.sa',
            'ca' => 'https://www.zohoapis.ca',
            'uk' => 'https://www.zohoapis.co.uk',
            default => 'https://www.zohoapis.com',
        };
    }

    /**
     * Request an access token using Client Credentials grant.
     *
     * @param ZohoToken $token
     * @return ZohoToken
     * @throws Exception
     */
    public function fetchTokenUsingClientCredentials(ZohoToken $token): ZohoToken
    {
        if (empty($token->clientId) || empty($token->clientSecret) || empty($token->region)) {
            throw new Exception("Zoho Client ID, Client Secret, and Region are all required to authenticate.");
        }

        $accountsUrl = $this->getAccountsUrlForRegion($token->region);
        $apiUrl = $this->getApiUrlForRegion($token->region);
        $url = rtrim($accountsUrl, '/') . '/oauth/v2/token';

        $response = Http::asForm()->post($url, [
            'grant_type' => 'client_credentials',
            'client_id' => $token->clientId,
            'client_secret' => $token->clientSecret,
            'scope' => 'ZohoBooks.fullaccess.all,ZohoInventory.fullaccess.all,ZohoBooks.settings.READ',
        ]);

        if ($response->failed()) {
            Log::error("Zoho Client Credentials token request failed: {$response->status()} - {$response->body()}");
            throw new Exception("Zoho OAuth request failed: {$response->status()} - {$response->body()}");
        }

        $result = $response->json();

        if (isset($result['error']) && $result['error'] !== '') {
            Log::error("Zoho Client Credentials OAuth error response: " . json_encode($result));
            throw new Exception("Zoho OAuth error: {$result['error']}");
        }

        if (empty($result['access_token'])) {
            Log::error("Zoho Client Credentials response missing access_token: " . json_encode($result));
            throw new Exception("Zoho OAuth error: Response did not contain access_token.");
        }

        $expiresIn = $result['expires_in'] ?? 3600;
        $expiresAt = Carbon::now('UTC')->addSeconds($expiresIn);

        $token->accessToken = $result['access_token'];
        $token->expiresAt = $expiresAt;
        $token->apiDomain = $apiUrl;
        $token->accountsServer = $accountsUrl;
        $token->save();

        return $token;
    }

    /**
     * Ensure we have a valid Zoho access token. Automatically requests new token if expired/missing.
     *
     * @param string $shopDomain
     * @return ZohoToken
     * @throws Exception
     */
    public function ensureValidToken(string $shopDomain): ZohoToken
    {
        $token = ZohoToken::where('shop', $shopDomain)->first();
        if (!$token) {
            throw new Exception("Zoho configuration not set up for shop: {$shopDomain}");
        }

        if (empty($token->clientId) || empty($token->clientSecret) || empty($token->region)) {
            throw new Exception("Zoho connection is not configured yet. Please save your Client ID, Client Secret, and Region.");
        }

        $now = Carbon::now('UTC');

        // Check if access token is empty, expiresAt is empty, or token is within 5 minutes of expiring
        $shouldRefresh = empty($token->accessToken) ||
                         empty($token->expiresAt) ||
                         Carbon::parse($token->expiresAt, 'UTC')->subMinutes(5)->lessThan($now);

        if ($shouldRefresh) {
            Log::info("Zoho access token is expired or missing. Fetching new one for shop: {$shopDomain}...");
            $token = $this->fetchTokenUsingClientCredentials($token);
        }

        return $token;
    }
}
