<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quote extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'quote_quotes';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tenant_id',
        'code',
        'client_id',
        'opportunity_id',
        'owner_id',
        'title',
        'status',
        'current_version_no',
        'current_version_id',
        'travel_from',
        'travel_to',
        'valid_until',
        'currency',
        'fx_rate',
        'pax_adt',
        'pax_chd',
        'pax_inf',
        'viewed_at',
        'approved_at',
        'approved_by_name',
        'approval_channel',
        'approval_ref',
        'approval_recorded_by',
        'approval_ip',
        'approval_hash',
        'approval_version_no',
        'rejected_at',
        'rejected_reason',
        'reservation_id',
        'converted_at',
        'duplicated_from_id',
        'lock_version',
    ];

    protected $casts = [
        'travel_from' => 'date:Y-m-d',
        'travel_to' => 'date:Y-m-d',
        'valid_until' => 'date:Y-m-d',
        'fx_rate' => 'decimal:6',
        'pax_adt' => 'integer',
        'pax_chd' => 'integer',
        'pax_inf' => 'integer',
        'viewed_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'converted_at' => 'datetime',
        'lock_version' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function opportunity()
    {
        return $this->belongsTo(Opportunity::class, 'opportunity_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function versions()
    {
        return $this->hasMany(QuoteVersion::class, 'quote_id')->orderBy('version_no', 'desc');
    }

    public function currentVersion()
    {
        return $this->belongsTo(QuoteVersion::class, 'current_version_id', 'id');
    }

    public function sends()
    {
        return $this->hasMany(QuoteSend::class, 'quote_id')->orderBy('sent_at', 'desc');
    }
}