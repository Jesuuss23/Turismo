<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PriceRule extends Model
{
    use HasUuids;

    protected $table = 'catalog_price_rules';

    protected $fillable = [
        'tenant_id',
        'ref',
        'name',
        'kind',
        'value',
        'scope',
        'channel',
        'min_pax',
        'customer_residency',
        'priority',
        'status',
    ];

    protected $casts = [
        'value' => 'decimal:4',
        'min_pax' => 'integer',
        'priority' => 'integer',
    ];
}
