<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class QuoteSend extends Model
{
    use HasUuids;

    protected $table = 'quote_sends';

    protected $fillable = [
        'tenant_id',
        'quote_id',
        'version_no',
        'channel',
        'recipient',
        'message',
        'sent_by',
        'sent_at',
        'notification_id',
    ];

    protected $casts = [
        'version_no' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
