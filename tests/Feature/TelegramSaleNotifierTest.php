<?php

use App\Models\Sale;
use App\Models\Stock;
use App\Models\Product;
use App\Models\SaleItem;
use App\Services\TelegramSaleNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('includes item and total discount breakdown in sale telegram messages', function () {
    $stock = Stock::create([
        'name'      => 'Main stock',
        'is_main'   => true,
        'is_active' => true,
    ]);

    $selectedProduct = Product::factory()->create([
        'name'  => 'Tanlangan product',
        'price' => 200000,
    ]);

    $regularProduct = Product::factory()->create([
        'name'  => 'Oddiy product',
        'price' => 400000,
    ]);

    $sale = Sale::withoutEvents(fn (): Sale => Sale::create([
        'cart_id'                  => 1,
        'subtotal_amount'          => 600000,
        'total_amount'             => 405000,
        'product_discount_total'   => 100000,
        'order_discount_total'     => 50000,
        'discount_total'           => 150000,
        'customer_discount_type'   => 'percent',
        'customer_discount_value'  => 10,
        'customer_discount_amount' => 45000,
        'paid_amount'              => 405000,
        'remaining_amount'         => 0,
        'payment_type'             => 'cash',
        'status'                   => Sale::STATUS_COMPLETED,
    ]));

    SaleItem::create([
        'sale_id'                => $sale->id,
        'product_id'             => $selectedProduct->id,
        'stock_id'               => $stock->id,
        'quantity'               => 1,
        'price'                  => 200000,
        'subtotal_amount'        => 200000,
        'product_discount_total' => 100000,
        'total'                  => 100000,
    ]);

    SaleItem::create([
        'sale_id'                => $sale->id,
        'product_id'             => $regularProduct->id,
        'stock_id'               => $stock->id,
        'quantity'               => 1,
        'price'                  => 400000,
        'subtotal_amount'        => 400000,
        'product_discount_total' => 0,
        'total'                  => 400000,
    ]);

    $method  = new ReflectionMethod(TelegramSaleNotifier::class, 'buildMessage');
    $message = $method->invoke(app(TelegramSaleNotifier::class), $sale, 'created');

    expect($message)
        ->toContain('Tanlangan product')
        ->toContain("1 x 200 000 = 100 000 so'm")
        ->toContain("Chegirma: -100 000 so'm")
        ->toContain('Oddiy product')
        ->toContain("1 x 400 000 = 400 000 so'm")
        ->toContain('Jami mahsulotlar: 2 dona')
        ->toContain("Subtotal: 600 000 so'm")
        ->toContain("Avtomatik chegirma: -150 000 so'm")
        ->toContain("Mijoz chegirmasi (10%): -45 000 so'm")
        ->toContain("JAMI SUMMA: 405 000 so'm");
});

it('includes cash and card amounts only for mixed payment telegram messages', function (float $cash, float $card) {
    $sale = Sale::withoutEvents(fn (): Sale => Sale::create([
        'cart_id'           => 1,
        'total_amount'      => 150000,
        'paid_amount'       => 150000,
        'payment_type'      => 'mixed',
        'mixed_cash_amount' => $cash,
        'mixed_card_amount' => $card,
    ]));

    $message = (new ReflectionMethod(TelegramSaleNotifier::class, 'buildMessage'))
        ->invoke(app(TelegramSaleNotifier::class), $sale, 'created');

    expect($message)
        ->toContain("💳 To'lov turi: Karta + Naqd")
        ->toContain('💵 Naqd: ' . number_format($cash, 0, '.', ' ') . " so'm")
        ->toContain('💳 Karta: ' . number_format($card, 0, '.', ' ') . " so'm")
        ->toContain("JAMI SUMMA: 150 000 so'm");
})->with([
    'split payment' => [100000.0, 50000.0],
    'all cash'      => [150000.0, 0.0],
    'all card'      => [0.0, 150000.0],
]);

it('keeps the existing telegram message for other payment types', function (string $paymentType) {
    $sale = Sale::withoutEvents(fn (): Sale => Sale::create([
        'cart_id'           => 1,
        'total_amount'      => 150000,
        'paid_amount'       => 150000,
        'payment_type'      => $paymentType,
        'mixed_cash_amount' => 100000,
        'mixed_card_amount' => 50000,
    ]));

    $message = (new ReflectionMethod(TelegramSaleNotifier::class, 'buildMessage'))
        ->invoke(app(TelegramSaleNotifier::class), $sale, 'created');

    expect($message)
        ->toContain("JAMI SUMMA: 150 000 so'm")
        ->not->toContain('💵 Naqd:')
        ->not->toContain('💳 Karta:');
})->with(['cash', 'card', 'debt', 'partial', 'transfer', 'preorder']);
