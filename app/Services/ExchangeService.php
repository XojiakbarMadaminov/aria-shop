<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\Product;
use App\Models\ProductSize;
use App\Models\ProductStock;
use App\Models\CashTransaction;
use App\Models\ExchangeOperation;
use Illuminate\Support\Facades\DB;
use App\Models\InventoryAdjustment;
use Illuminate\Validation\ValidationException;

class ExchangeService
{
    /**
     * @throws \Throwable
     */
    public function handle(array $payload): ExchangeOperation
    {
        return DB::transaction(function () use ($payload) {
            $storeId = auth()->user()?->current_store_id;

            if (!$storeId) {
                throw ValidationException::withMessages([
                    'store_id' => 'Foydalanuvchi uchun joriy do‘kon tanlanmagan.',
                ]);
            }

            $stockId  = (int) ($payload['stock_id'] ?? 0);
            $quantity = (int) ($payload['quantity'] ?? 0);

            if ($quantity <= 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'Almashinuv miqdori 0 dan katta bo‘lishi kerak.',
                ]);
            }

            $stock = Stock::find($stockId);
            if (!$stock) {
                throw ValidationException::withMessages([
                    'stock_id' => 'Sklad topilmadi.',
                ]);
            }

            $inProduct  = Product::with('sizes')->find((int) ($payload['in_product_id'] ?? 0));
            $outProduct = Product::with('sizes')->find((int) ($payload['out_product_id'] ?? 0));

            $errors = [];

            if (!$inProduct) {
                $errors['in_product_id'] = 'Qaytariladigan mahsulot topilmadi.';
            }

            if (!$outProduct) {
                $errors['out_product_id'] = 'Beriladigan mahsulot topilmadi.';
            }

            if (!empty($errors)) {
                throw ValidationException::withMessages($errors);
            }

            [$inProductId, $inProductSizeId] = $this->resolveInventoryTarget(
                $inProduct,
                $payload['in_product_size_id'] ?? null,
                'in_product_size_id'
            );

            [$outProductId, $outProductSizeId] = $this->resolveInventoryTarget(
                $outProduct,
                $payload['out_product_size_id'] ?? null,
                'out_product_size_id'
            );

            $incomingRecord = ProductStock::firstOrNew([
                'product_id'      => $inProductId,
                'product_size_id' => $inProductSizeId,
                'stock_id'        => $stock->id,
            ]);

            $outgoingQuantity = $this->getAvailableQuantity(
                $outProductId,
                $outProductSizeId,
                $stock->id
            );

            if ($outgoingQuantity < $quantity) {
                throw ValidationException::withMessages([
                    'quantity' => "{$outProduct->name} uchun maksimal {$outgoingQuantity} dona bor.",
                ]);
            }

            $outgoingRecord = ProductStock::firstOrNew([
                'product_id'      => $outProductId,
                'product_size_id' => $outProductSizeId,
                'stock_id'        => $stock->id,
            ]);

            $incomingRecord->quantity = (int) $incomingRecord->quantity + $quantity;
            $incomingRecord->save();

            $outgoingRecord->quantity = (int) $outgoingQuantity - $quantity;
            $outgoingRecord->save();

            $incomingPrice = $this->resolveUnitPrice(
                $payload['in_price'] ?? null,
                $inProduct->price,
                'in_price'
            );

            $outgoingPrice = $this->resolveUnitPrice(
                $payload['out_price'] ?? null,
                $outProduct->price,
                'out_price'
            );

            $priceDifference = ($outgoingPrice - $incomingPrice) * $quantity;
            $reason          = $payload['reason'] ?? null;

            InventoryAdjustment::create([
                'product_id'      => $inProduct->id,
                'product_size_id' => $inProductSizeId,
                'quantity'        => $quantity,
                'unit_price'      => $incomingPrice,
                'adjustment_type' => InventoryAdjustment::TYPE_EXCHANGE_IN,
                'reason'          => $reason,
                'handled_by'      => auth()->id(),
                'store_id'        => $storeId,
            ]);

            InventoryAdjustment::create([
                'product_id'      => $outProduct->id,
                'product_size_id' => $outProductSizeId,
                'quantity'        => -$quantity,
                'unit_price'      => $outgoingPrice,
                'adjustment_type' => InventoryAdjustment::TYPE_EXCHANGE_OUT,
                'reason'          => $reason,
                'handled_by'      => auth()->id(),
                'store_id'        => $storeId,
            ]);

            $operation = ExchangeOperation::create([
                'in_product_id'       => $inProduct->id,
                'in_product_size_id'  => $inProductSizeId,
                'out_product_id'      => $outProduct->id,
                'out_product_size_id' => $outProductSizeId,
                'price_difference'    => $priceDifference,
                'handled_by'          => auth()->id(),
                'store_id'            => $storeId,
            ]);

            if ($priceDifference !== 0) {
                CashTransaction::create([
                    'amount'    => abs($priceDifference),
                    'direction' => $priceDifference > 0
                        ? CashTransaction::DIRECTION_IN
                        : CashTransaction::DIRECTION_OUT,
                    'reason'   => CashTransaction::REASON_EXCHANGE_DIFF,
                    'store_id' => $storeId,
                ]);
            }

            return $operation;
        });
    }

    protected function resolveInventoryTarget(Product $product, ?int $sizeId, string $field): array
    {
        if ($product->isPackageBased()) {
            return [$product->id, null];
        }

        $sizeId = (int) ($sizeId ?? 0) ?: null;

        if (!$sizeId) {
            throw ValidationException::withMessages([
                $field => 'Razmer tanlang.',
            ]);
        }

        /** @var ProductSize|null $size */
        $size = $product->sizes->firstWhere('id', $sizeId);

        if (!$size) {
            throw ValidationException::withMessages([
                $field => 'Mahsulot razmeri noto‘g‘ri.',
            ]);
        }

        return [null, $size->id];
    }

    protected function getAvailableQuantity(?int $productId, ?int $productSizeId, int $stockId): int
    {
        $query = ProductStock::query()
            ->where('stock_id', $stockId);

        if ($productId) {
            $query->where('product_id', $productId)
                ->whereNull('product_size_id');
        } else {
            $query->where('product_size_id', $productSizeId);
        }

        return (int) $query->value('quantity');
    }

    protected function resolveUnitPrice(?float $inputPrice, ?int $currentPrice, string $field): int
    {
        $price = (int) round($inputPrice ?? $currentPrice ?? 0);

        if ($price <= 0) {
            throw ValidationException::withMessages([
                $field => 'Narx 0 dan katta bo‘lishi kerak.',
            ]);
        }

        return $price;
    }
}
