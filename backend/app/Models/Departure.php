<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Departure extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'catalog_product_departures';

    protected $fillable = [
        'tenant_id',
        'ref',
        'product_id',
        'departure_date',
        'departure_time',
        'capacity',
        'sold',
        'status',
        'provider_id',
        'meeting_point',
        'return_point',
        'notes',
        'lock_version',
    ];

    protected $casts = [
        'departure_date' => 'date',
        'capacity' => 'integer',
        'sold' => 'integer',
        'lock_version' => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class, 'provider_id');
    }

    public function holds()
    {
        return $this->hasMany(AvailabilityHold::class, 'departure_id');
    }
}
