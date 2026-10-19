<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductSubcategory extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'catalog_product_subcategories';

    protected $fillable = ['tenant_id', 'category_id', 'name'];
}
