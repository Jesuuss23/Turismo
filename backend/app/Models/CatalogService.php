<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CatalogService extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'catalog_services';

    protected $fillable = [
        'tenant_id',
        'ref',
        'provider_id',
        'name',
        'type',
        'unit',
        'cost',
        'currency',
        'capacity',
        'valid_from',
        'valid_to',
        'status',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'capacity' => 'integer',
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    public function provider()
    {
        return $this->belongsTo(Provider::class, 'provider_id');
    }
}
