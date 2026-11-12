<?php

use App\Models\User;
use App\Models\Store;
use App\Models\Product;
use App\Services\ProductBarcodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('finds a twelve digit product from its previously printed EAN13 label', function (string $scannedBarcode) {
    $product = Product::factory()->create(['barcode' => '012002800069']);

    expect(app(ProductBarcodeService::class)->findProduct($scannedBarcode)?->id)->toBe($product->id);
})->with([
    'EAN13 scanner' => '0120028000698',
    'UPC-A scanner' => '120028000698',
]);

it('prefers an exact barcode over a legacy label match', function () {
    Product::factory()->create(['barcode' => '012002800069']);
    $product = Product::factory()->create(['barcode' => '0120028000698']);

    expect(app(ProductBarcodeService::class)->findProduct('0120028000698')?->id)->toBe($product->id);
});

it('does not use a legacy label with an invalid checksum', function () {
    Product::factory()->create(['barcode' => '012002800069']);

    expect(app(ProductBarcodeService::class)->findProduct('0120028000693'))->toBeNull();
});

it('finds an EAN13 product when the scanner reports the equivalent UPC-A code', function () {
    $product = Product::factory()->create(['barcode' => '0522368943916']);

    expect(app(ProductBarcodeService::class)->findProduct('522368943916')?->id)->toBe($product->id)
        ->and($product->refresh()->barcode)->toBe('0522368943916');
});

it('prefers an exact barcode over its zero-prefixed EAN13 equivalent', function () {
    Product::factory()->create(['barcode' => '0522368943916']);
    $product = Product::factory()->create(['barcode' => '522368943916']);

    expect(app(ProductBarcodeService::class)->findProduct('522368943916')?->id)->toBe($product->id);
});

it('does not match a zero-prefixed barcode with an invalid checksum', function () {
    Product::factory()->create(['barcode' => '0522368943917']);

    expect(app(ProductBarcodeService::class)->findProduct('522368943917'))->toBeNull();
});

it('keeps barcode lookup within the current store and excludes deleted products', function (string $storedBarcode, string $scannedBarcode) {
    $store      = Store::create(['name' => 'Current store', 'address' => 'Tashkent', 'phone' => '+998901234567']);
    $otherStore = Store::create(['name' => 'Other store', 'address' => 'Tashkent', 'phone' => '+998901234568']);
    $user       = User::factory()->create(['current_store_id' => $store->id]);
    Product::factory()->create(['store_id' => $otherStore->id, 'barcode' => $storedBarcode]);
    $this->actingAs($user);

    expect(app(ProductBarcodeService::class)->findProduct($scannedBarcode))->toBeNull();

    $product = Product::factory()->create(['store_id' => $store->id, 'barcode' => $storedBarcode]);

    expect(app(ProductBarcodeService::class)->findProduct($scannedBarcode)?->id)->toBe($product->id);

    $product->delete();

    expect(app(ProductBarcodeService::class)->findProduct($scannedBarcode))->toBeNull();
})->with([
    'legacy label'  => ['012002800069', '0120028000698'],
    'UPC-A scanner' => ['0522368943916', '522368943916'],
]);

it('chooses a label format that preserves the stored barcode', function (string $barcode, string $type) {
    expect(app(ProductBarcodeService::class)->labelType($barcode))->toBe($type);
})->with([
    'twelve digits with a leading zero' => ['012002800069', 'C128'],
    'valid EAN13'                       => ['0120028000698', 'EAN13'],
    'invalid EAN13'                     => ['0120028000693', 'C128'],
    'alphanumeric barcode'              => ['BR1234567890', 'C128'],
]);
