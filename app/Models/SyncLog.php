<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncLog extends Model
{
    protected $fillable = [
        'shop',
        'type',
        'status',
        'message',
        'details',
    ];
}
