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
    ];

    protected $casts = [
        'enable_customer_sync_shopify_to_zoho' => 'boolean',
        'enable_customer_sync_zoho_to_shopify' => 'boolean',
    ];
}
