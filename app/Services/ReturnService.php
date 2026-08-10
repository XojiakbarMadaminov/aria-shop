<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\ProductStock;
use App\Models\CashTransaction;
use Illuminate\Support\Facades\DB;
use App\Models\InventoryAdjustment;
use Illuminate\Validation\ValidationException;

class ReturnService
{
    /**
     * @throws \Throwable
     */
    public function handle(array $payload): InventoryAdjustment
    {
        return DB::transaction(function () use ($payload) {
            $storeId = auth()->user()?->current_store_id;

            if (!$storeId) {
                throw ValidationException::withMessages([
                    'store_id' => 'Foydalanuvchi uchun joriy do‘kon tanlanmagan.',
                ]);
            }

            $productId = (int) ($payload['product_id'] ?? 0);
            $stockId   = (int) ($payload['stock_id'] ?? 0);
            $quantity  = (int) ($payload['quantity'] ?? 0);

            if ($quantity <= 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'Qaytarilayotgan miqdor 0 dan katta bo‘lishi kerak.',
                ]);
            }

            $stock = Stock::find($stockId);

            if (!$stock) {
                throw ValidationException::withMessages([
                    'stock_id' => 'Sklad topilmadi.',
                ]);
            }

            $product = Product::with('sizes')->find($productId);

            if (!$product) {
                throw ValidationException::withMessages([
                    'product_id' => 'Mahsulot topilmadi.',
                ]);
            }

            $productSizeId = null;

            if ($product->isPackageBased()) {
                $productSizeId = null;
            } else {
                $productSizeId = (int) ($payload['product_size_id'] ?? 0) ?: null;

                if (!$productSizeId) {
                    throw ValidationException::withMessages([
                        'product_size_id' => 'Razmer tanlang.',
                    ]);
                }

                /** @var ProductSize|null $size */
                $size = $product->sizes->firstWhere('id', $productSizeId);
                if (!$size) {
                    throw ValidationException::withMessages([
                        'product_size_id' => 'Mahsulot razmeri noto‘g‘ri.',
                    ]);
                }
            }

            $unitPrice = $this->resolveUnitPrice($payload['price'] ?? null, $product->price);

            $stockRecord = ProductStock::firstOrNew([
                'product_id'      => $product->isPackageBased() ? $product->id : null,
                'product_size_id' => $product->isPackageBased() ? null : $productSizeId,
                'stock_id'        => $stock->id,
            ]);

            $stockRecord->quantity = (int) $stockRecord->quantity + $quantity;
            $stockRecord->save();

            $adjustment = InventoryAdjustment::create([
                'product_id'      => $product->id,
                'product_size_id' => $productSizeId,
                'quantity'        => $quantity,
                'unit_price'      => $unitPrice,
                'adjustment_type' => InventoryAdjustment::TYPE_RETURN,
                'reason'          => $payload['reason'] ?? null,
                'handled_by'      => auth()->id(),
                'store_id'        => $storeId,
            ]);

            CashTransaction::create([
                'amount'    => $quantity * $unitPrice,
                'direction' => CashTransaction::DIRECTION_OUT,
                'reason'    => CashTransaction::REASON_RETURN,
                'store_id'  => $storeId,
            ]);

            return $adjustment;
        });
    }

    protected function resolveUnitPrice(?float $inputPrice, ?int $currentPrice): int
    {
        $price = (int) round($inputPrice ?? $currentPrice ?? 0);

        if ($price <= 0) {
            throw ValidationException::withMessages([
                'price' => 'Narx 0 dan katta bo‘lishi kerak.',
            ]);
        }

        return $price;
    }
}
