<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'crm_leads';

    protected $fillable = [
        'tenant_id',
        'code',
        'full_name',
        'company',
        'email',
        'email_norm',
        'phone',
        'phone_norm',
        'channel_code',
        'source_code',
        'interest_code',
        'destination_label',
        'travel_date',
        'pax',
        'status',
        'owner_id',
        'score',
        'notes',
        'lock_version',
        'created_by',
    ];

    protected $casts = [
        'travel_date' => 'date',
        'pax' => 'integer',
        'score' => 'integer',
        'lock_version' => 'integer',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
