<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Rate extends Model
{
    use HasUuids;

    protected $table = 'catalog_rates';

    protected $fillable = [
        'tenant_id',
        'ref',
        'product_id',
        'rate_type',
        'segment',
        'channel',
        'currency',
        'price_basis',
        'cost',
        'price',
        'min_price',
        'max_price',
        'valid_from',
        'valid_to',
        'season_id',
        'conditions',
        'status',
        'lock_version',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'price' => 'decimal:2',
        'min_price' => 'decimal:2',
        'max_price' => 'decimal:2',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'lock_version' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function season()
    {
        return $this->belongsTo(Season::class, 'season_id');
    }
}
