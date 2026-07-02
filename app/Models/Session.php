<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Session extends Model
{
    /**
     * The connection name for the model.
     *
     * @var string
     */
    protected $connection = 'mysql_shopify';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'Session';

    /**
     * The primary key associated with the table.
     *
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * Indicates if the model's ID is auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the model should be term-stamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'shop',
        'state',
        'isOnline',
        'scope',
        'expires',
        'accessToken',
        'userId',
        'firstName',
        'lastName',
        'email',
        'accountOwner',
        'locale',
        'collaborator',
        'emailVerified',
        'refreshToken',
        'refreshTokenExpires',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'isOnline' => 'boolean',
        'expires' => 'datetime',
        'userId' => 'integer',
        'accountOwner' => 'boolean',
        'collaborator' => 'boolean',
        'emailVerified' => 'boolean',
        'refreshTokenExpires' => 'datetime',
    ];
}
