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
use Illuminate\Support\Facades\Log;
use App\Support\XlsxWorksheetReader;

class ProductPackageImportService
{
    public function __construct(private readonly XlsxWorksheetReader $reader) {}

    /**
     * @return array{created: int, updated: int, merged: int, skipped: int, errors: array<int, string>}
     */
    public function import(string $path, Store $store, Stock $stock): array
    {
        $this->ensureStockBelongsToStore($store, $stock);

        $rows = $this->reader->rows($path);

        if ($rows === []) {
            throw new RuntimeException('Excel fayl bo‘sh.');
        }

        $header    = array_map(fn (string $value): string => $this->normalizeHeader($value), array_shift($rows));
        $totalRows = count($rows);

        $created        = 0;
        $updated        = 0;
        $merged         = 0;
        $skipped        = 0;
        $errors         = [];
        $preparedRows   = [];
        $barcodeIndexes = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $data      = $this->combineRow($header, $row);

            if ($this->isBlankRow($data)) {
                continue;
            }

            $preparedRow = $this->prepareRow($data, $rowNumber);

            if (is_string($preparedRow)) {
                $skipped++;
                $errors[] = $preparedRow;

                continue;
            }

            $barcode = $preparedRow['barcode'];

            if ($barcode !== '' && array_key_exists($barcode, $barcodeIndexes)) {
                $preparedRowIndex = $barcodeIndexes[$barcode];

                if (!$this->hasMatchingProductDetails($preparedRows[$preparedRowIndex], $preparedRow)) {
                    $skipped++;
                    $errors[] = "{$rowNumber}-qator: {$barcode} barcode uchun nom, kategoriya yoki narxlar boshqa qator bilan mos emas.";

                    continue;
                }

                $preparedRow['quantity'] += $preparedRows[$preparedRowIndex]['quantity'];
                $preparedRows[$preparedRowIndex] = $preparedRow;
                $merged++;

                continue;
            }

            if ($barcode !== '') {
                $barcodeIndexes[$barcode] = count($preparedRows);
            }

            $preparedRows[] = $preparedRow;
        }

        foreach ($preparedRows as $preparedRow) {
            $result = $this->importRow($preparedRow, $store, $stock);

            if ($result === 'created') {
                $created++;
            } elseif ($result === 'updated') {
                $updated++;
            }
        }

        $summary = [
            'created' => $created,
            'updated' => $updated,
            'merged'  => $merged,
            'skipped' => $skipped,
            'errors'  => $errors,
        ];

        Log::info('Product Excel import completed.', [
            'file'              => basename($path),
            'store_id'          => $store->id,
            'stock_id'          => $stock->id,
            'total_rows'        => $totalRows,
            'imported_products' => $created + $updated,
            ...$summary,
        ]);

        return $summary;
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
     * @return array{name: string, category_name: string, barcode: string, initial_price: int, price: int, quantity: int}|string
     */
    private function prepareRow(array $data, int $rowNumber): array|string
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

        if ($initialPrice === null || $initialPrice < 0) {
            return "{$rowNumber}-qator: purchase_price 0 yoki undan katta butun son bo‘lishi kerak.";
        }

        if ($price === null || $price < 0) {
            return "{$rowNumber}-qator: sale_price 0 yoki undan katta butun son bo‘lishi kerak.";
        }

        return [
            'name'          => $name,
            'category_name' => $categoryName,
            'barcode'       => $barcode,
            'initial_price' => $initialPrice,
            'price'         => $price,
            'quantity'      => $quantity,
        ];
    }

    /**
     * @param  array{name: string, category_name: string, barcode: string, initial_price: int, price: int, quantity: int}  $data
     */
    private function importRow(array $data, Store $store, Stock $stock): string
    {
        $barcode = $data['barcode'];

        if ($barcode === '') {
            $barcode = $this->generateUniqueBarcode($store);
        }

        $categoryId = $this->resolveCategoryId($data['category_name']);
        $result     = 'created';

        DB::transaction(function () use ($barcode, $categoryId, $data, $stock, $store, &$result): void {
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

            $product->name          = $data['name'];
            $product->initial_price = $data['initial_price'];
            $product->price         = $data['price'];
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
                    'quantity' => $data['quantity'],
                ]
            );
        });

        return $result;
    }

    /**
     * @param  array{name: string, category_name: string, barcode: string, initial_price: int, price: int, quantity: int}  $firstRow
     * @param  array{name: string, category_name: string, barcode: string, initial_price: int, price: int, quantity: int}  $secondRow
     */
    private function hasMatchingProductDetails(array $firstRow, array $secondRow): bool
    {
        return $firstRow['name'] === $secondRow['name']
            && $firstRow['category_name'] === $secondRow['category_name']
            && $firstRow['initial_price'] === $secondRow['initial_price']
            && $firstRow['price'] === $secondRow['price'];
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
