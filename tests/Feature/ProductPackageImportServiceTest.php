<?php

use App\Models\Stock;
use App\Models\Store;
use App\Models\Product;
use App\Models\ProductStock;
use Illuminate\Support\Facades\Log;
use App\Services\ProductPackageImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('imports package products from excel into the selected stock', function () {
    $store = Store::query()->create([
        'name'    => 'Main store',
        'address' => 'Test address',
        'phone'   => '+998901234567',
    ]);

    $targetStock = Stock::query()->create(['name' => 'Showroom', 'is_active' => true]);
    $otherStock  = Stock::query()->create(['name' => 'Warehouse', 'is_active' => true]);

    $store->stocks()->attach([$targetStock->id, $otherStock->id]);

    $path = createPackageProductsImportWorkbook([
        ['name', 'barcode', 'category', 'purchase_price', 'sale_price', 'quantity'],
        ['Nike Air Max', '123456789001', 'Krossovka', '350000', '520000', '10'],
        ['', '', 'Tufli', '280000', '430000', '15'],
    ]);

    try {
        $summary = app(ProductPackageImportService::class)->import($path, $store, $targetStock);
    } finally {
        unlink($path);
    }

    expect($summary)->toMatchArray([
        'created' => 2,
        'updated' => 0,
        'merged'  => 0,
        'skipped' => 0,
    ]);

    $nike                = Product::query()->withoutGlobalScopes()->where('barcode', '123456789001')->sole();
    $fallbackNameProduct = Product::query()->withoutGlobalScopes()->where('name', 'Tufli')->sole();

    expect($nike->store_id)->toBe($store->id)
        ->and($nike->type)->toBe(Product::TYPE_PACKAGE)
        ->and($nike->initial_price)->toBe(350000)
        ->and($nike->price)->toBe(520000)
        ->and($nike->category?->name)->toBe('Krossovka')
        ->and($fallbackNameProduct->barcode)->not->toBeEmpty()
        ->and($fallbackNameProduct->category?->name)->toBe('Tufli');

    expect(ProductStock::query()
        ->where('product_id', $nike->id)
        ->where('stock_id', $targetStock->id)
        ->value('quantity'))->toBe(10)
        ->and(ProductStock::query()
            ->where('product_id', $fallbackNameProduct->id)
            ->where('stock_id', $targetStock->id)
            ->value('quantity'))->toBe(15)
        ->and(ProductStock::query()
            ->where('stock_id', $otherStock->id)
            ->exists())->toBeFalse();
});

it('updates an existing product when the store barcode already exists', function () {
    $store = Store::query()->create([
        'name'    => 'Main store',
        'address' => 'Test address',
        'phone'   => '+998901234568',
    ]);

    $stock = Stock::query()->create(['name' => 'Showroom', 'is_active' => true]);
    $store->stocks()->attach($stock->id);

    $product = Product::factory()->create([
        'store_id' => $store->id,
        'barcode'  => '123456789001',
        'name'     => 'Old name',
    ]);

    ProductStock::query()->create([
        'product_id'      => $product->id,
        'product_size_id' => null,
        'stock_id'        => $stock->id,
        'quantity'        => 1,
    ]);

    $path = createPackageProductsImportWorkbook([
        ['name', 'barcode', 'category', 'purchase_price', 'sale_price', 'quantity'],
        ['New name', '123456789001', 'New category', '111000', '222000', '9'],
        ['New name', '123456789001', 'New category', '111000', '222000', '6'],
    ]);

    try {
        $summary = app(ProductPackageImportService::class)->import($path, $store, $stock);
    } finally {
        unlink($path);
    }

    expect($summary)->toMatchArray([
        'created' => 0,
        'updated' => 1,
        'merged'  => 1,
        'skipped' => 0,
    ]);

    expect($product->refresh()->name)->toBe('New name')
        ->and($product->initial_price)->toBe(111000)
        ->and($product->price)->toBe(222000)
        ->and($product->category?->name)->toBe('New category')
        ->and($product->type)->toBe(Product::TYPE_PACKAGE)
        ->and(ProductStock::query()
            ->where('product_id', $product->id)
            ->where('stock_id', $stock->id)
            ->value('quantity'))->toBe(15);
});

it('sums quantities for repeated barcodes before creating a product', function () {
    Log::spy();

    $store = Store::query()->create([
        'name'    => 'Main store',
        'address' => 'Test address',
        'phone'   => '+998901234569',
    ]);

    $stock = Stock::query()->create(['name' => 'Showroom', 'is_active' => true]);
    $store->stocks()->attach($stock->id);

    $path = createPackageProductsImportWorkbook([
        ['name', 'barcode', 'category', 'purchase_price', 'sale_price', 'quantity'],
        ['Paypoq', '016008500992', 'Paypoq', '3000', '6000', '30'],
        ['Paypoq', '016008500992', 'Paypoq', '3000', '6000', '2'],
        ['Paypoq', '016008500992', 'Paypoq', '3000', '6000', '51'],
    ]);

    try {
        $summary = app(ProductPackageImportService::class)->import($path, $store, $stock);
    } finally {
        unlink($path);
    }

    expect($summary)->toMatchArray([
        'created' => 1,
        'updated' => 0,
        'merged'  => 2,
        'skipped' => 0,
    ]);

    $product = Product::query()
        ->withoutGlobalScopes()
        ->where('store_id', $store->id)
        ->where('barcode', '016008500992')
        ->sole();

    expect(Product::query()
        ->withoutGlobalScopes()
        ->where('store_id', $store->id)
        ->where('barcode', '016008500992')
        ->count())->toBe(1)
        ->and(ProductStock::query()
            ->where('product_id', $product->id)
            ->where('stock_id', $stock->id)
            ->value('quantity'))->toBe(83);

    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'Product Excel import completed.'
            && $context['store_id'] === $store->id
            && $context['stock_id'] === $stock->id
            && $context['total_rows'] === 3
            && $context['imported_products'] === 1
            && $context['created'] === 1
            && $context['updated'] === 0
            && $context['merged'] === 2
            && $context['skipped'] === 0
            && $context['errors'] === []);
});

it('skips a repeated barcode when its product details conflict', function () {
    $store = Store::query()->create([
        'name'    => 'Main store',
        'address' => 'Test address',
        'phone'   => '+998901234570',
    ]);

    $stock = Stock::query()->create(['name' => 'Showroom', 'is_active' => true]);
    $store->stocks()->attach($stock->id);

    $path = createPackageProductsImportWorkbook([
        ['name', 'barcode', 'category', 'purchase_price', 'sale_price', 'quantity'],
        ['Paypoq', '016008500992', 'Paypoq', '3000', '6000', '30'],
        ['Paypoq', '016008500992', 'Paypoq', '3000', '7000', '2'],
    ]);

    try {
        $summary = app(ProductPackageImportService::class)->import($path, $store, $stock);
    } finally {
        unlink($path);
    }

    expect($summary)->toMatchArray([
        'created' => 1,
        'updated' => 0,
        'merged'  => 0,
        'skipped' => 1,
    ])->and($summary['errors'])->toHaveCount(1)
        ->and($summary['errors'][0])->toContain('016008500992 barcode');

    $product = Product::query()
        ->withoutGlobalScopes()
        ->where('store_id', $store->id)
        ->where('barcode', '016008500992')
        ->sole();

    expect(ProductStock::query()
        ->where('product_id', $product->id)
        ->where('stock_id', $stock->id)
        ->value('quantity'))->toBe(30);
});

/**
 * @param  array<int, array<int, string>>  $rows
 */
function createPackageProductsImportWorkbook(array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'products-import-') . '.xlsx';
    $zip  = new ZipArchive;

    $zip->open($path, ZipArchive::CREATE|ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
<Default Extension="xml" ContentType="application/xml"/>
<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
<sheets><sheet name="Products" sheetId="1" r:id="rId1"/></sheets>
</workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', worksheetXml($rows));
    $zip->close();

    return $path;
}

/**
 * @param  array<int, array<int, string>>  $rows
 */
function worksheetXml(array $rows): string
{
    $sheetRows = collect($rows)
        ->map(function (array $row, int $rowIndex): string {
            $cells = collect($row)
                ->map(function (string $value, int $columnIndex) use ($rowIndex): string {
                    $reference = columnName($columnIndex) . ($rowIndex + 1);
                    $escaped   = htmlspecialchars($value, ENT_XML1);

                    return "<c r=\"{$reference}\" t=\"inlineStr\"><is><t>{$escaped}</t></is></c>";
                })
                ->implode('');

            $rowNumber = $rowIndex + 1;

            return "<row r=\"{$rowNumber}\">{$cells}</row>";
        })
        ->implode('');

    return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
<worksheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\">
<sheetData>{$sheetRows}</sheetData>
</worksheet>";
}

function columnName(int $index): string
{
    $name = '';
    $index++;

    while ($index > 0) {
        $index--;
        $name  = chr(ord('A') + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }

    return $name;
}
