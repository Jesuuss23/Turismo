<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ItineraryItem extends Model
{
    use HasUuids;

    protected $table = 'catalog_itinerary_items';
    protected $fillable = ['tenant_id', 'day_id', 'start_time', 'activity', 'item_type', 'location', 'service_id', 'note'];
}
