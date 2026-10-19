<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Destination extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'catalog_destinations';

    protected $fillable = [
        'tenant_id',
        'ref',
        'parent_id',
        'name',
        'type',
        'level',
        'lat',
        'lng',
        'description',
        'path',
    ];

    public function parent()
    {
        return $this->belongsTo(Destination::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Destination::class, 'parent_id')->orderBy('name');
    }
}
