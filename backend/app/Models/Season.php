<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Season extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'catalog_seasons';

    protected $fillable = [
        'tenant_id',
        'ref',
        'name',
        'type',
        'date_from',
        'date_to',
        'priority',
        'factor',
        'status',
    ];

    protected $casts = [
        'date_from' => 'date',
        'date_to' => 'date',
        'priority' => 'integer',
        'factor' => 'decimal:2',
    ];
}
