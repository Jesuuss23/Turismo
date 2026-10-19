<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Itinerary extends Model
{
    use HasUuids;

    protected $table = 'catalog_itineraries';
    protected $fillable = ['tenant_id', 'product_id', 'name', 'is_default'];

    public function days()
    {
        return $this->hasMany(ItineraryDay::class, 'itinerary_id')->orderBy('day_number');
    }
}
