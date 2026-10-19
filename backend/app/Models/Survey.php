<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Survey extends Model
{
    use HasUuids;

    protected $table = 'crm_surveys';

    protected $fillable = [
        'tenant_id',
        'code',
        'client_id',
        'reservation_id',
        'score',
        'category',
        'comment',
        'channel_code',
        'responded_at',
        'recorded_by',
    ];

    protected $casts = [
        'score' => 'integer',
        'responded_at' => 'datetime',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
