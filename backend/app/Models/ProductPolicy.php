<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductPolicy extends Model
{
    use HasUuids;

    protected $table = 'catalog_product_policies';
    protected $fillable = ['tenant_id', 'product_id', 'cancellation', 'modification', 'no_show', 'refund', 'penalties'];
}
