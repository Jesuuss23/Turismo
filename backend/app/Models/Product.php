<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'catalog_products';

    protected $fillable = [
        'tenant_id',
        'ref',
        'code',
        'sku',
        'name',
        'internal_name',
        'product_type_id',
        'category_id',
        'subcategory_id',
        'primary_destination_id',
        'status',
        'short_description',
        'long_description',
        'duration_value',
        'duration_unit',
        'modality',
        'main_provider_id',
        'minimum_passengers',
        'maximum_passengers',
        'minimum_age',
        'maximum_age',
        'booking_advance_min_days',
        'booking_advance_max_days',
        'is_bookable',
        'is_quoteable',
        'is_b2b',
        'is_ecommerce',
        'is_featured',
        'markup_pct',
        'availability_model',
        'availability_capacity',
        'availability_source',
        'features',
        'slug',
        'lock_version',
    ];

    protected $casts = [
        'features' => 'array',
        'duration_value' => 'decimal:1',
        'markup_pct' => 'decimal:2',
        'is_bookable' => 'boolean',
        'is_quoteable' => 'boolean',
        'is_b2b' => 'boolean',
        'is_ecommerce' => 'boolean',
        'is_featured' => 'boolean',
        'lock_version' => 'integer',
    ];

    public function type()
    {
        return $this->belongsTo(ProductType::class, 'product_type_id');
    }

    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function destination()
    {
        return $this->belongsTo(Destination::class, 'primary_destination_id');
    }

    public function provider()
    {
        return $this->belongsTo(Provider::class, 'main_provider_id');
    }

    public function inclusions()
    {
        return $this->hasMany(ProductInclusion::class, 'product_id')->orderBy('sort_order');
    }

    public function policy()
    {
        return $this->hasOne(ProductPolicy::class, 'product_id');
    }

    public function itinerary()
    {
        return $this->hasOne(Itinerary::class, 'product_id')->with('days.items');
    }
}
