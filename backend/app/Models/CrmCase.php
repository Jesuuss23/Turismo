<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmCase extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'crm_cases';

    protected $fillable = [
        'tenant_id',
        'code',
        'client_id',
        'reservation_id',
        'survey_id',
        'origin',
        'type_code',
        'priority',
        'subject',
        'description',
        'status',
        'owner_id',
        'resolution',
        'resolved_at',
        'closed_at',
        'lock_version',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
