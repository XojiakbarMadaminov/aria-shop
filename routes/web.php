<?php

use App\Models\Sale;
use App\Models\Store;
use App\Models\Debtor;
use App\Models\Product;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Services\DiscountService;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->to('/admin/login');
});

// debtor uchun
Route::get('/debtor/{debtor}/check-pdf', function (Debtor $debtor) {
    $debtor->load('transactions');

    $base   = 300;
    $extra  = 20 * $debtor->transactions->count();
    $height = min(396, $base + $extra); // max 140mm

    return Pdf::loadView('debtor-check', compact('debtor'))
        ->setPaper([0, 0, 176, $height], 'portrait')  // 62mm × height
        ->stream('check.pdf');
})->name('debtor.check.pdf');

Route::get('/switch-store/{store}', function (Store $store) {
    $user = auth()->user();

    abort_unless($user->stores->contains($store->id), 403);

    $user->update(['current_store_id' => $store->id]);

    return back();
})->name('switch-store');

// Sales receipt PDF (same layout intent as POS receipt)
Route::get('/sales/{sale}/receipt', function (Sale $sale) {
    $sale->load(['client', 'items.product', 'createdBy']);

    return view('sales-receipt-print', [
        'sale' => $sale,
    ]);
})->name('sale.receipt.print');

Route::get('/sales/{sale}/receipt-pdf', function (Sale $sale) {
    $sale->load(['client', 'items.product', 'createdBy']);

    $itemsCount = $sale->items->count();
    $base       = 320; // base height
    $extra      = 22 * $itemsCount;
    $height     = min(600, $base + $extra);

    return Pdf::loadView('sales-receipt', [
        'sale' => $sale,
    ])->setPaper([0, 0, 200, $height], 'portrait')->stream('sale-receipt.pdf');
})->name('sale.receipt.pdf');

// 1. Bitta product uchun
Route::get('/products/{product}/barcode-pdf', function (Product $product, Request $request) {
    $size             = $request->get('size', '57x30');
    $useDiscountPrice = $request->boolean('use_discount_price');
    $copies           = max(1, $request->integer('copies', 1));

    $sizes = [
        '30x20' => [0, 0, 85.04, 56.69],   // 30mm x 20mm
        '57x30' => [0, 0, 161.62, 85.04],  // 57mm x 30mm
        '85x65' => [0, 0, 240.94, 184.25], // 85mm x 65mm
    ];

    $paper = $sizes[$size] ?? $sizes['57x30'];

    $discountPrices = $useDiscountPrice
        ? collect([$product])->mapWithKeys(fn (Product $product): array => [
            $product->id => app(DiscountService::class)->calculateProductLabelPrice($product),
        ])
        : collect();

    return Pdf::loadView('product-barcode', [
        'products'       => collect(range(1, $copies))->map(fn (): Product => $product),
        'size'           => $size,
        'discountPrices' => $discountPrices,
    ])
        ->setPaper($paper)
        ->stream("barcode-{$product->id}.pdf");
})->name('product.barcode.pdf');

// 2. Ko‘p product uchun (masalan, tanlanganlar)
Route::get('/products/barcodes/bulk', function (Request $request) {
    $ids  = explode(',', $request->input('ids', ''));
    $size = $request->get('size', '57x30');

    $products = Product::whereIn('id', $ids)->get();

    $sizes = [
        '30x20' => [0, 0, 85.04, 56.69],
        '57x30' => [0, 0, 161.62, 85.04],
        '85x65' => [0, 0, 240.94, 184.25],
    ];

    $paper = $sizes[$size] ?? $sizes['57x30'];

    return Pdf::loadView('product-barcode', [
        'products'       => $products,
        'size'           => $size,
        'discountPrices' => collect(),
    ])
        ->setPaper($paper)
        ->stream('barcodes.pdf');
})->name('product.barcodes.bulk');
