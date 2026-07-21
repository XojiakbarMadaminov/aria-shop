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
