<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ItineraryDay extends Model
{
    use HasUuids;

    protected $table = 'catalog_itinerary_days';
    protected $fillable = ['tenant_id', 'itinerary_id', 'day_number', 'title'];

    public function items()
    {
        return $this->hasMany(ItineraryItem::class, 'day_id')->orderByRaw('start_time ASC NULLS LAST');
    }
}
