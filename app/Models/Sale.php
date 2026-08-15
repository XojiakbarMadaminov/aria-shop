<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\HasCurrentStoreScope;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sale extends Model
{
    use HasCurrentStoreScope;

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PENDING   = 'pending';
    public const STATUS_REJECTED  = 'rejected';

    protected $table   = 'sales';
    protected $guarded = [];

    protected $casts = [
        'total_amount'             => 'float',
        'subtotal_amount'          => 'float',
        'product_discount_total'   => 'float',
        'order_discount_total'     => 'float',
        'discount_total'           => 'float',
        'applied_discounts'        => 'array',
        'customer_discount_value'  => 'float',
        'customer_discount_amount' => 'float',
        'paid_amount'              => 'float',
        'remaining_amount'         => 'float',
        'mixed_cash_amount'        => 'float',
        'mixed_card_amount'        => 'float',
        'status'                   => 'string',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function itemsDistinctName()
    {
        return $this->items()->get()->unique('product_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(DebtorTransaction::class);
    }

    public function isPending(): bool
    {
        return ($this->status ?? self::STATUS_COMPLETED) === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return ($this->status ?? self::STATUS_COMPLETED) === self::STATUS_COMPLETED;
    }

    public function isRejected(): bool
    {
        return ($this->status ?? self::STATUS_COMPLETED) === self::STATUS_REJECTED;
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeRejected($query)
    {
        return $query->where('status', self::STATUS_REJECTED);
    }
}
