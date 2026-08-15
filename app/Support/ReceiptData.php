<?php

namespace App\Support;

use App\Models\Sale;

class ReceiptData
{
    public static function fromSale(Sale $sale, array $metaOverrides = []): array
    {
        $sale->loadMissing(['store']);
        $items     = $sale->items()->with(['product', 'productSize'])->get();
        $storeId   = $sale->store_id;
        $cartId    = $sale->cart_id ?? $sale->id;
        $created   = $sale->created_at ?? now();
        $timestamp = $created instanceof \DateTimeInterface
            ? $created->getTimestamp()
            : now()->timestamp;

        $receiptNumber = 'R' . str_pad((string) $cartId, 4, '0', STR_PAD_LEFT) . $timestamp;

        return [
            'cart_id'     => $cartId,
            'store_id'    => $storeId,
            'store_name'  => $sale->store?->name ?? config('app.store_name'),
            'store_phone' => $sale->store?->phone,
            'items'       => $items->map(function ($item) {
                return [
                    'name'                   => $item->product?->name ?? 'Mahsulot',
                    'size'                   => $item->productSize?->size,
                    'qty'                    => (float) $item->quantity,
                    'price'                  => (float) $item->price,
                    'subtotal'               => (float) ($item->subtotal_amount ?: $item->quantity * $item->price),
                    'product_discount_total' => (float) ($item->product_discount_total ?? 0),
                    'total'                  => (float) $item->total,
                ];
            })->toArray(),
            'totals' => [
                'qty'                      => (float) $items->sum('quantity'),
                'amount'                   => (float) $sale->total_amount,
                'subtotal'                 => (float) ($sale->subtotal_amount ?: $items->sum('total')),
                'product_discount_total'   => (float) ($sale->product_discount_total ?? 0),
                'order_discount_total'     => (float) ($sale->order_discount_total ?? 0),
                'discount_total'           => (float) ($sale->discount_total ?? 0),
                'applied_discounts'        => $sale->applied_discounts ?? [],
                'customer_discount_type'   => $sale->customer_discount_type,
                'customer_discount_value'  => (float) ($sale->customer_discount_value ?? 0),
                'customer_discount_amount' => (float) ($sale->customer_discount_amount ?? 0),
            ],
            'date' => $created instanceof \DateTimeInterface
                ? $created->format('d.m.Y H:i:s')
                : now()->format('d.m.Y H:i:s'),
            'receipt_number' => $receiptNumber,
            'meta'           => array_merge([
                'sale_id'          => $sale->id,
                'client_name'      => $sale->client?->full_name ?? '-',
                'cashier_name'     => $sale->createdBy?->name ?? null,
                'payment_type'     => $sale->payment_type,
                'paid_amount'      => $sale->paid_amount,
                'remaining_amount' => $sale->remaining_amount,
            ], $metaOverrides),
        ];
    }
}
