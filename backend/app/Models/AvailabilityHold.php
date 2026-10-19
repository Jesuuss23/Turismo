<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AvailabilityHold extends Model
{
    use HasUuids;

    protected $table = 'catalog_availability_holds';

    protected $fillable = [
        'tenant_id',
        'ref',
        'departure_id',
        'quantity',
        'reference',
        'reference_type',
        'reference_id',
        'status',
        'expires_at',
        'released_reason',
        'notes',
        'lock_version',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'expires_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    public function departure()
    {
        return $this->belongsTo(Departure::class, 'departure_id')->with('product:id,name,ref');
    }
}
