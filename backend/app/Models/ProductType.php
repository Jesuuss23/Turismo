<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductType extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'catalog_product_types';

    protected $fillable = [
        'tenant_id',
        'name',
        'requires_itinerary',
        'requires_components',
        'sort_order',
    ];
}
