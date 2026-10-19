<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'crm_clients';

    protected $fillable = [
        'tenant_id',
        'code',
        'kind',
        'name',
        'doc_type',
        'doc_number',
        'email',
        'email_norm',
        'phone',
        'phone_norm',
        'country',
        'city',
        'birth_date',
        'segment_code',
        'owner_id',
        'tags',
        'nps_last',
        'source_lead_id',
        'b2b_agency_id',
    ];

    protected $casts = [
        'tags' => 'array',
        'birth_date' => 'date',
        'nps_last' => 'integer',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function contacts()
    {
        return $this->hasMany(ClientContact::class, 'client_id');
    }
}