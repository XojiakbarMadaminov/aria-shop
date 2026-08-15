<?php

use App\Models\Sale;
use App\Models\Store;
use App\Support\ReceiptData;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows the sale store phone number on the receipt', function () {
    $store = Store::create([
        'name'    => "Test do'koni",
        'address' => 'Test manzili',
        'phone'   => '+998 90 123 45 67',
    ]);

    $sale = Sale::create([
        'store_id'                 => $store->id,
        'cart_id'                  => 1,
        'total_amount'             => 100000,
        'subtotal_amount'          => 120000,
        'discount_total'           => 10000,
        'customer_discount_type'   => 'fixed',
        'customer_discount_value'  => 10000,
        'customer_discount_amount' => 10000,
        'payment_type'             => 'cash',
    ]);

    $receiptData = ReceiptData::fromSale($sale);

    expect($receiptData['store_phone'])->toBe($store->phone);

    $this->view('receipts.partials.default', [
        'receiptData' => $receiptData,
        'showQr'      => false,
    ])
        ->assertSee('Aloqa uchun:')
        ->assertSee($store->phone)
        ->assertSee('Avtomatik chegirma:')
        ->assertSee("Mijoz chegirmasi (10 000 so'm):")
        ->assertSee('100 000');

    $this->view('filament.sales.partials.sale-details', [
        'sale' => $sale,
    ])
        ->assertSee('Avtomatik chegirma')
        ->assertSee('Mijoz chegirmasi')
        ->assertSee("10,000.00 so'm");
});
