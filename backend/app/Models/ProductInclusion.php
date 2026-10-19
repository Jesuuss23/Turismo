<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductInclusion extends Model
{
    use HasUuids;

    protected $table = 'catalog_product_inclusions';
    protected $fillable = ['tenant_id', 'product_id', 'kind', 'text', 'sort_order'];
}
