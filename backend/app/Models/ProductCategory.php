<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductCategory extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'catalog_product_categories';

    protected $fillable = ['tenant_id', 'ref', 'name'];

    public function subcategories()
    {
        return $this->hasMany(ProductSubcategory::class, 'category_id')->orderBy('name');
    }
}
