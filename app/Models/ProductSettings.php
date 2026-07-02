<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductSettings extends Model
{

    protected $fillable = [
        'shop',
        'sync_direction',
        'sync_draft_products',
        'auto_sync_enabled',
        'update_fields_enabled',
        'export_fields',
        'sync_by_collection',
        'selected_collections',
        'sync_by_tags',
        'selected_tags',
    ];

    protected $casts = [
        'export_fields' => 'array',
        'selected_collections' => 'array',
        'selected_tags' => 'array',
        'sync_draft_products' => 'boolean',
        'auto_sync_enabled' => 'boolean',
        'update_fields_enabled' => 'boolean',
        'sync_by_collection' => 'boolean',
        'sync_by_tags' => 'boolean',
    ];
}
