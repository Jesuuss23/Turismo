<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Activity extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'crm_activities';

    protected $fillable = [
        'tenant_id',
        'code',
        'type',
        'subject',
        'description',
        'related_type',
        'related_id',
        'owner_id',
        'due_at',
        'status',
        'result',
        'completed_at',
        'completed_by',
        'auto_closed',
        'previous_activity_id',
        'merged_from_lead_id',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
        'auto_closed' => 'boolean',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
