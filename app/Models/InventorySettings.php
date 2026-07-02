<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventorySettings extends Model
{
    protected $fillable = [
        'shop',
        'sync_direction',
        'auto_sync_enabled',
        'quantity_type',
        'sync_frequency',
        'skip_zero_stock',
        'location_mapping',
        'sync_by_collection',
        'selected_collections',
    ];

    protected $casts = [
        'location_mapping' => 'array',
        'selected_collections' => 'array',
        'auto_sync_enabled' => 'boolean',
        'skip_zero_stock' => 'boolean',
        'sync_by_collection' => 'boolean',
    ];
}
