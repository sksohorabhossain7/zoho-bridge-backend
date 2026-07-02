<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZohoToken extends Model
{
    protected $fillable = [
        'shop',
        'access_token',
        'refresh_token',
        'expires_at',
        'api_domain',
        'accounts_server',
        'organization_id',
        'organization_name',
        'last_product_sync_at',
        'client_id',
        'client_secret',
        'region',
    ];

    /**
     * Map camelCase properties to snake_case database columns.
     */
    protected function getSnakeKey($key)
    {
        return match ($key) {
            'organizationID' => 'organization_id',
            'organizationName' => 'organization_name',
            'accessToken' => 'access_token',
            'refreshToken' => 'refresh_token',
            'expiresAt' => 'expires_at',
            'apiDomain' => 'api_domain',
            'accountsServer' => 'accounts_server',
            'lastProductSyncAt' => 'last_product_sync_at',
            'clientId' => 'client_id',
            'clientSecret' => 'client_secret',
            default => \Illuminate\Support\Str::snake($key),
        };
    }

    public function getAttribute($key)
    {
        $snakeKey = $this->getSnakeKey($key);
        return parent::getAttribute($snakeKey) ?? parent::getAttribute($key);
    }

    public function setAttribute($key, $value)
    {
        $snakeKey = $this->getSnakeKey($key);
        return parent::setAttribute($snakeKey, $value);
    }

    public function toArray()
    {
        $array = parent::toArray();
        $camelKeys = [
            'organization_id' => 'organizationID',
            'organization_name' => 'organizationName',
            'access_token' => 'accessToken',
            'refresh_token' => 'refreshToken',
            'expires_at' => 'expiresAt',
            'api_domain' => 'apiDomain',
            'accounts_server' => 'accountsServer',
            'last_product_sync_at' => 'lastProductSyncAt',
            'client_id' => 'clientId',
            'client_secret' => 'clientSecret',
        ];

        foreach ($camelKeys as $snake => $camel) {
            if (array_key_exists($snake, $array)) {
                $array[$camel] = $array[$snake];
            }
        }

        return $array;
    }
}
