<?php

use App\Models\Product;

it('renders the product id next to the barcode label name', function () {
    $product          = new Product;
    $product->id      = 263;
    $product->name    = 'BD/BC 203GC Xingx Morozilnik';
    $product->barcode = '6959854200118';
    $product->price   = 150000;

    $html = view('product-barcode', [
        'products'       => collect([$product]),
        'size'           => '57x30',
        'discountPrices' => collect(),
    ])->render();

    expect($html)
        ->toContain('150 000')
        ->toContain('(ID#263)')
        ->not->toContain('ID 263')
        ->not->toContain('Kod 263')
        ->not->toContain('Код 263');
});

it('encodes the same barcode that is printed beneath the label', function (string $barcode) {
    $product          = new Product;
    $product->id      = 263;
    $product->name    = 'Scanner regression product';
    $product->barcode = $barcode;
    $product->price   = 150000;

    $html = view('product-barcode', [
        'products'       => collect([$product]),
        'size'           => '57x30',
        'discountPrices' => collect(),
    ])->render();

    $generator      = app('DNS1D');
    $encodedBarcode = (new ReflectionProperty($generator, 'barcode_array'))->getValue($generator);

    expect($encodedBarcode['code'])->toBe($barcode)
        ->and($html)->toContain($barcode);
})->with([
    'twelve digits with a leading zero' => '012002800069',
    'valid EAN13'                       => '0120028000698',
    'invalid EAN13'                     => '0120028000693',
    'alphanumeric barcode'              => 'BR1234567890',
]);
