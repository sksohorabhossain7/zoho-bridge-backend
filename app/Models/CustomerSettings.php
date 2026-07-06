<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerSettings extends Model
{
    protected $fillable = [
        'shop',
        'sync_direction',
        'sync_option',
        'enable_customer_sync_shopify_to_zoho',
        'enable_customer_sync_zoho_to_shopify',
        'sync_shopify_customer_tags',
        'sync_zoho_customer_tags',
    ];

    protected $casts = [
        'enable_customer_sync_shopify_to_zoho' => 'boolean',
        'enable_customer_sync_zoho_to_shopify' => 'boolean',
        'sync_shopify_customer_tags' => 'boolean',
        'sync_zoho_customer_tags' => 'boolean',
    ];
}
