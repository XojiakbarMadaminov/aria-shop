<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use App\Models\InventoryAdjustment;

class NetSalesQuantityService
{
    /**
     * @return Collection<int, int>
     */
    public function forPeriod(CarbonInterface $start, CarbonInterface $end): Collection
    {
        $soldQuantities = SaleItem::query()
            ->whereHas('sale', fn ($query) => $query->where('status', Sale::STATUS_COMPLETED))
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('product_id, SUM(quantity) as total_qty')
            ->groupBy('product_id')
            ->get()
            ->mapWithKeys(fn (SaleItem $item): array => [(int) $item->product_id => (int) $item->total_qty]);

        InventoryAdjustment::query()
            ->whereBetween('created_at', [$start, $end])
            ->get(['product_id', 'quantity', 'adjustment_type'])
            ->each(function (InventoryAdjustment $adjustment) use ($soldQuantities): void {
                $quantity = abs((int) $adjustment->quantity);
                $delta    = $adjustment->adjustment_type === InventoryAdjustment::TYPE_EXCHANGE_OUT
                    ? $quantity
                    : -$quantity;

                $soldQuantities->put(
                    (int) $adjustment->product_id,
                    (int) $soldQuantities->get($adjustment->product_id, 0) + $delta,
                );
            });

        return $soldQuantities->filter(fn (int $quantity): bool => $quantity > 0);
    }
}
