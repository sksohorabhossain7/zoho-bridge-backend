<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncedCustomer extends Model
{
    protected $fillable = [
        'shop',
        'shopify_customer_id',
        'zoho_contact_id',
        'email',
        'phone',
    ];
}
