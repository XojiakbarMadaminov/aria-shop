<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\HasCurrentStoreScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashTransaction extends Model
{
    use HasCurrentStoreScope;

    public const DIRECTION_IN  = 'in';
    public const DIRECTION_OUT = 'out';

    public const REASON_RETURN        = 'return';
    public const REASON_EXCHANGE_DIFF = 'exchange_diff';

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
