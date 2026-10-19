<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class QuoteInternalApproval extends Model
{
    use HasUuids;

    protected $table = 'quote_internal_approvals';

    protected $fillable = [
        'tenant_id',
        'quote_version_id',
        'reasons',
        'over_cap',
        'requested_by',
        'requested_at',
        'request_note',
        'status',
        'decided_by',
        'decided_at',
        'decision_note',
        'is_override',
    ];

    protected $casts = [
        'reasons' => 'array',
        'over_cap' => 'boolean',
        'is_override' => 'boolean',
        'requested_at' => 'datetime',
        'decided_at' => 'datetime',
    ];
}
