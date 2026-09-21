<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\Store;
use RuntimeException;
use App\Helpers\Helper;
use App\Models\Product;
use App\Models\Category;
use Illuminate\Support\Arr;
use App\Models\ProductStock;
use Illuminate\Support\Facades\DB;
use App\Support\XlsxWorksheetReader;

class ProductPackageImportService
{
    public function __construct(private readonly XlsxWorksheetReader $reader) {}

    /**
     * @return array{created: int, updated: int, skipped: int, errors: array<int, string>}
     */
    public function import(string $path, Store $store, Stock $stock): array
    {
        $this->ensureStockBelongsToStore($store, $stock);

        $rows = $this->reader->rows($path);

        if ($rows === []) {
            throw new RuntimeException('Excel fayl bo‘sh.');
        }

        $header = array_map(fn (string $value): string => $this->normalizeHeader($value), array_shift($rows));

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors  = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $data      = $this->combineRow($header, $row);

            if ($this->isBlankRow($data)) {
                continue;
            }

            $result = $this->importRow($data, $store, $stock, $rowNumber);

            if ($result === 'created') {
                $created++;
            } elseif ($result === 'updated') {
                $updated++;
            } else {
                $skipped++;
                $errors[] = $result;
            }
        }

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors'  => $errors,
        ];
    }

    private function ensureStockBelongsToStore(Store $store, Stock $stock): void
    {
        $belongsToStore = $store->stocks()
            ->whereKey($stock->getKey())
            ->exists();

        if (!$belongsToStore) {
            throw new RuntimeException('Tanlangan stock joriy storega tegishli emas.');
        }
    }

    /**
     * @param  array<int, string>  $header
     * @param  array<int, string>  $row
     * @return array<string, string>
     */
    private function combineRow(array $header, array $row): array
    {
        $data = [];

        foreach ($header as $index => $key) {
            if ($key === '') {
                continue;
            }

            $data[$key] = trim($row[$index] ?? '');
        }

        return $data;
    }

    /**
     * @param  array<string, string>  $data
     */
    private function importRow(array $data, Store $store, Stock $stock, int $rowNumber): string
    {
        $categoryName = trim((string) Arr::get($data, 'category', ''));
        $name         = trim((string) Arr::get($data, 'name', ''));

        if ($name === '' && $categoryName !== '') {
            $name = $categoryName;
        }

        if ($name === '') {
            return "{$rowNumber}-qator: name yoki category kiritilmagan.";
        }

        $quantity = $this->toIntNullable(Arr::get($data, 'quantity'));

        if ($quantity === null || $quantity < 0) {
            return "{$rowNumber}-qator: quantity 0 yoki undan katta butun son bo‘lishi kerak.";
        }

        $barcode      = trim((string) Arr::get($data, 'barcode', ''));
        $initialPrice = $this->toIntNullable(Arr::get($data, 'purchase_price', Arr::get($data, 'initial_price')));
        $price        = $this->toIntNullable(Arr::get($data, 'sale_price', Arr::get($data, 'price')));
        $categoryId   = $this->resolveCategoryId($categoryName);

        if ($initialPrice === null || $initialPrice < 0) {
            return "{$rowNumber}-qator: purchase_price 0 yoki undan katta butun son bo‘lishi kerak.";
        }

        if ($price === null || $price < 0) {
            return "{$rowNumber}-qator: sale_price 0 yoki undan katta butun son bo‘lishi kerak.";
        }

        if ($barcode === '') {
            $barcode = $this->generateUniqueBarcode($store);
        }

        $result = 'created';

        DB::transaction(function () use ($barcode, $categoryId, $initialPrice, $name, $price, $quantity, $stock, $store, &$result): void {
            $product = Product::query()
                ->withoutGlobalScopes()
                ->where('store_id', $store->id)
                ->where('barcode', $barcode)
                ->first();

            $result = $product instanceof Product ? 'updated' : 'created';

            if (!$product instanceof Product) {
                $product           = new Product;
                $product->store_id = $store->id;
                $product->barcode  = $barcode;
            }

            $product->name          = $name;
            $product->initial_price = $initialPrice;
            $product->price         = $price;
            $product->category_id   = $categoryId;
            $product->type          = Product::TYPE_PACKAGE;
            $product->save();

            ProductStock::query()->updateOrCreate(
                [
                    'product_id'      => $product->id,
                    'product_size_id' => null,
                    'stock_id'        => $stock->id,
                ],
                [
                    'quantity' => $quantity,
                ]
            );
        });

        return $result;
    }

    private function normalizeHeader(string $value): string
    {
        return str($value)
            ->lower()
            ->trim()
            ->replace([' ', '-'], '_')
            ->toString();
    }

    /**
     * @param  array<string, string>  $data
     */
    private function isBlankRow(array $data): bool
    {
        return count(array_filter($data, fn (string $value): bool => trim($value) !== '')) === 0;
    }

    private function toIntNullable(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $normalized = preg_replace('/[^0-9\-]/', '', $value);

        if ($normalized === '' || $normalized === '-') {
            return null;
        }

        return (int) $normalized;
    }

    private function resolveCategoryId(string $categoryName): ?int
    {
        if ($categoryName === '') {
            return null;
        }

        return (int) Category::query()
            ->firstOrCreate(['name' => $categoryName])
            ->id;
    }

    private function generateUniqueBarcode(Store $store): string
    {
        do {
            $barcode = Helper::generateEAN13Barcode();
        } while (
            Product::query()
                ->withoutGlobalScopes()
                ->where('store_id', $store->id)
                ->where('barcode', $barcode)
                ->exists()
        );

        return $barcode;
    }
}
