<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncedProduct extends Model
{
    protected $fillable = [
        'shop',
        'zoho_item_id',
        'shopify_product_id',
        'shopify_variant_id',
        'title',
        'sku',
        'last_sync_source',
        'last_sync_at',
    ];

    protected $casts = [
        'last_sync_at' => 'datetime',
    ];
}
