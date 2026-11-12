<?php

use App\Models\User;
use App\Models\Store;
use App\Models\Product;
use App\Services\ProductBarcodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('finds a twelve digit product from its previously printed EAN13 label', function () {
    $product = Product::factory()->create(['barcode' => '012002800069']);

    expect(app(ProductBarcodeService::class)->findProduct('0120028000698')?->id)->toBe($product->id);
});

it('prefers an exact barcode over a legacy label match', function () {
    Product::factory()->create(['barcode' => '012002800069']);
    $product = Product::factory()->create(['barcode' => '0120028000698']);

    expect(app(ProductBarcodeService::class)->findProduct('0120028000698')?->id)->toBe($product->id);
});

it('does not use a legacy label with an invalid checksum', function () {
    Product::factory()->create(['barcode' => '012002800069']);

    expect(app(ProductBarcodeService::class)->findProduct('0120028000693'))->toBeNull();
});

it('keeps barcode lookup within the current store and excludes deleted products', function () {
    $store      = Store::create(['name' => 'Current store', 'address' => 'Tashkent', 'phone' => '+998901234567']);
    $otherStore = Store::create(['name' => 'Other store', 'address' => 'Tashkent', 'phone' => '+998901234568']);
    $user       = User::factory()->create(['current_store_id' => $store->id]);
    Product::factory()->create(['store_id' => $otherStore->id, 'barcode' => '012002800069']);
    $this->actingAs($user);

    expect(app(ProductBarcodeService::class)->findProduct('0120028000698'))->toBeNull();

    $product = Product::factory()->create(['store_id' => $store->id, 'barcode' => '012002800069']);

    expect(app(ProductBarcodeService::class)->findProduct('0120028000698')?->id)->toBe($product->id);

    $product->delete();

    expect(app(ProductBarcodeService::class)->findProduct('0120028000698'))->toBeNull();
});

it('chooses a label format that preserves the stored barcode', function (string $barcode, string $type) {
    expect(app(ProductBarcodeService::class)->labelType($barcode))->toBe($type);
})->with([
    'twelve digits with a leading zero' => ['012002800069', 'C128'],
    'valid EAN13'                       => ['0120028000698', 'EAN13'],
    'invalid EAN13'                     => ['0120028000693', 'C128'],
    'alphanumeric barcode'              => ['BR1234567890', 'C128'],
]);
