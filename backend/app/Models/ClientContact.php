<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ClientContact extends Model
{
    use HasUuids;

    protected $table = 'crm_client_contacts';

    protected $fillable = [
        'tenant_id',
        'client_id',
        'name',
        'role',
        'email',
        'phone',
        'is_primary',
        'source_lead_id',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];
}
