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
        'store_id'     => $store->id,
        'cart_id'      => 1,
        'total_amount' => 100000,
        'payment_type' => 'cash',
    ]);

    $receiptData = ReceiptData::fromSale($sale);

    expect($receiptData['store_phone'])->toBe($store->phone);

    $this->view('receipts.partials.default', [
        'receiptData' => $receiptData,
        'showQr'      => false,
    ])
        ->assertSee('Aloqa uchun:')
        ->assertSee($store->phone);
});
