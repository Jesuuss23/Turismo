<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class QuoteItem extends Model
{
    use HasUuids;

    protected $table = 'quote_items';

    protected $fillable = [
        'tenant_id',
        'quote_version_id',
        'line_no',
        'product_id',
        'product_version',
        'rate_id',
        'segment',
        'service_date',
        'qty',
        'pax',
        'unit_cost',
        'unit_price',
        'cost_total',
        'sale_total',
        'season_id',
        'season_factor',
        'price_source',
        'rate_snapshot',
    ];

    protected $casts = [
        'service_date' => 'date',
        'qty' => 'integer',
        'pax' => 'integer',
        'unit_cost' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'cost_total' => 'decimal:2',
        'sale_total' => 'decimal:2',
        'season_factor' => 'decimal:3',
        'rate_snapshot' => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
