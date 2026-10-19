<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'tenants';

    protected $fillable = [
        'name',
        'slug',
        'ruc',
        'plan',
        'status',
        'timezone',
        'currency',
        'fx_rate',
        'tax_pct',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
        'fx_rate' => 'decimal:6',
        'tax_pct' => 'decimal:2',
    ];
}
