<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasUuids, Notifiable, SoftDeletes;

    protected $table = 'iam_users';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'email',
        'password',
        'phone',
        'sede_id',
        'status',
        'mfa_enabled',
        'mfa_required',
        'last_login_at',
        'last_login_ip',
        'avatar_color',
        'client_id',
        'agency_id',
        'provider_id',
        'guide_id',
        'driver_id',
        'reservation_id',
        'locale',
    ];

    protected $hidden = [
        'password',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}