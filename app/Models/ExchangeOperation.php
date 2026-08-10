<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\HasCurrentStoreScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeOperation extends Model
{
    use HasCurrentStoreScope;

    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function inProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'in_product_id');
    }

    public function inProductSize(): BelongsTo
    {
        return $this->belongsTo(ProductSize::class, 'in_product_size_id');
    }

    public function outProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'out_product_id');
    }

    public function outProductSize(): BelongsTo
    {
        return $this->belongsTo(ProductSize::class, 'out_product_size_id');
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
