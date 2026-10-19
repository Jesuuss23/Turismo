<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class QuoteVersion extends Model
{
    use HasUuids;

    protected $table = 'quote_versions';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tenant_id',
        'quote_id',
        'version_no',
        'status',
        'issued_on',
        'author_id',
        'discount_pct',
        'tax_pct',
        'markup_pct',
        'notes',
        'conditions',
        'snapshot_label',
        'catalog_snapshot',
        'policies_snapshot',
        'fx_rate',
        'cost_total',
        'sale_total',
        'discount_total',
        'net_total',
        'tax_total',
        'grand_total',
        'profit_total',
        'margin_pct',
        'internal_approval_id',
        'frozen_at',
        'content_hash',
        'pdf_document_id',
        'lock_version',
    ];

    protected $casts = [
        'issued_on' => 'date:Y-m-d',
        'discount_pct' => 'decimal:2',
        'tax_pct' => 'decimal:2',
        'markup_pct' => 'decimal:2',
        'cost_total' => 'decimal:2',
        'sale_total' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'net_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'profit_total' => 'decimal:2',
        'margin_pct' => 'decimal:2',
        'catalog_snapshot' => 'array',
        'policies_snapshot' => 'array',
        'frozen_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(QuoteItem::class, 'quote_version_id')->orderBy('line_no', 'asc');
    }

    public function quote()
    {
        return $this->belongsTo(Quote::class, 'quote_id');
    }

    public function internalApproval()
    {
        return $this->belongsTo(QuoteInternalApproval::class, 'internal_approval_id');
    }
}