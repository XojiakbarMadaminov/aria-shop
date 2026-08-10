<?php

use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\CashTransaction;
use App\Services\ReturnService;
use App\Models\ExchangeOperation;
use App\Services\ExchangeService;
use App\Models\InventoryAdjustment;
use Illuminate\Validation\ValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('assigns returns to the current store', function () {
    [$store, $stock, $user] = createStoreContext();
    $product                = createPackageProduct($store, $stock, 'Qaytariladigan mahsulot', 5);

    $this->actingAs($user);

    app(ReturnService::class)->handle([
        'product_id' => $product->id,
        'stock_id'   => $stock->id,
        'quantity'   => 2,
        'price'      => 150_000,
    ]);

    expect(InventoryAdjustment::query()->sole()->store_id)->toBe($store->id)
        ->and(CashTransaction::query()->sole()->store_id)->toBe($store->id);
});

it('assigns exchanges to the current store', function () {
    [$store, $stock, $user] = createStoreContext();
    $incomingProduct        = createPackageProduct($store, $stock, 'Qaytib kiradigan mahsulot', 0);
    $outgoingProduct        = createPackageProduct($store, $stock, 'Beriladigan mahsulot', 5);

    $this->actingAs($user);

    app(ExchangeService::class)->handle([
        'stock_id'       => $stock->id,
        'quantity'       => 1,
        'in_product_id'  => $incomingProduct->id,
        'out_product_id' => $outgoingProduct->id,
        'in_price'       => 100_000,
        'out_price'      => 150_000,
    ]);

    expect(InventoryAdjustment::query()->count())->toBe(2)
        ->and(InventoryAdjustment::query()->where('store_id', $store->id)->count())->toBe(2)
        ->and(ExchangeOperation::query()->sole()->store_id)->toBe($store->id)
        ->and(CashTransaction::query()->sole()->store_id)->toBe($store->id);
});

it('only exposes return and exchange records for the current store', function () {
    [$currentStore, $stock, $user] = createStoreContext();
    $otherStore                    = Store::query()->create([
        'name'    => 'Boshqa filial',
        'address' => 'Boshqa manzil',
        'phone'   => '+998900000002',
    ]);
    $product = createPackageProduct($currentStore, $stock, 'Scope mahsuloti', 1);

    InventoryAdjustment::query()->insert([
        ['product_id' => $product->id, 'quantity' => 1, 'adjustment_type' => 'return', 'unit_price' => 100, 'store_id' => $currentStore->id],
        ['product_id' => $product->id, 'quantity' => 1, 'adjustment_type' => 'return', 'unit_price' => 100, 'store_id' => $otherStore->id],
    ]);
    CashTransaction::query()->insert([
        ['amount' => 100, 'direction' => 'out', 'reason' => 'return', 'store_id' => $currentStore->id],
        ['amount' => 100, 'direction' => 'out', 'reason' => 'return', 'store_id' => $otherStore->id],
    ]);

    $this->actingAs($user);

    expect(InventoryAdjustment::query()->count())->toBe(1)
        ->and(CashTransaction::query()->count())->toBe(1);
});

it('records every exchange price difference direction correctly', function (
    int $incomingPrice,
    int $outgoingPrice,
    int $expectedDifference,
    ?string $expectedCashDirection,
) {
    [$store, $stock, $user] = createStoreContext();
    $incomingProduct        = createPackageProduct($store, $stock, 'Kiruvchi mahsulot', 0);
    $outgoingProduct        = createPackageProduct($store, $stock, 'Chiquvchi mahsulot', 5);

    $this->actingAs($user);

    app(ExchangeService::class)->handle([
        'stock_id'       => $stock->id,
        'quantity'       => 1,
        'in_product_id'  => $incomingProduct->id,
        'out_product_id' => $outgoingProduct->id,
        'in_price'       => $incomingPrice,
        'out_price'      => $outgoingPrice,
    ]);

    expect(ExchangeOperation::query()->sole()->price_difference)->toBe($expectedDifference)
        ->and(InventoryAdjustment::query()->count())->toBe(2)
        ->and(InventoryAdjustment::query()->where('store_id', $store->id)->count())->toBe(2);

    if ($expectedCashDirection === null) {
        expect(CashTransaction::query()->doesntExist())->toBeTrue();
    } else {
        $cashTransaction = CashTransaction::query()->sole();

        expect($cashTransaction->direction)->toBe($expectedCashDirection)
            ->and($cashTransaction->amount)->toBe(abs($expectedDifference))
            ->and($cashTransaction->store_id)->toBe($store->id);
    }
})->with([
    'mijoz narx farqini to‘laydi'    => [100_000, 150_000, 50_000, CashTransaction::DIRECTION_IN],
    'mijozga narx farqi qaytariladi' => [150_000, 100_000, -50_000, CashTransaction::DIRECTION_OUT],
    'narx farqi yo‘q'                => [100_000, 100_000, 0, null],
]);

it('rejects return and exchange when the current store is not selected', function () {
    $user = User::factory()->create(['current_store_id' => null]);

    $this->actingAs($user);

    expect(fn () => app(ReturnService::class)->handle([]))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(ExchangeService::class)->handle([]))
        ->toThrow(ValidationException::class)
        ->and(InventoryAdjustment::withoutGlobalScopes()->count())->toBe(0)
        ->and(CashTransaction::withoutGlobalScopes()->count())->toBe(0)
        ->and(ExchangeOperation::withoutGlobalScopes()->count())->toBe(0);
});

it('rolls back an exchange when outgoing stock is insufficient', function () {
    [$store, $stock, $user] = createStoreContext();
    $incomingProduct        = createPackageProduct($store, $stock, 'Kiruvchi mahsulot', 0);
    $outgoingProduct        = createPackageProduct($store, $stock, 'Chiquvchi mahsulot', 1);

    $this->actingAs($user);

    expect(fn () => app(ExchangeService::class)->handle([
        'stock_id'       => $stock->id,
        'quantity'       => 2,
        'in_product_id'  => $incomingProduct->id,
        'out_product_id' => $outgoingProduct->id,
        'in_price'       => 100_000,
        'out_price'      => 120_000,
    ]))->toThrow(ValidationException::class)
        ->and(ProductStock::query()->where('product_id', $incomingProduct->id)->value('quantity'))->toBe(0)
        ->and(ProductStock::query()->where('product_id', $outgoingProduct->id)->value('quantity'))->toBe(1)
        ->and(InventoryAdjustment::query()->count())->toBe(0)
        ->and(ExchangeOperation::query()->count())->toBe(0)
        ->and(CashTransaction::query()->count())->toBe(0);
});

/**
 * @return array{0: Store, 1: Stock, 2: User}
 */
function createStoreContext(): array
{
    $store = Store::query()->create([
        'name'    => fake()->unique()->company(),
        'address' => fake()->address(),
        'phone'   => fake()->unique()->phoneNumber(),
    ]);
    $stock = Stock::query()->create([
        'name' => fake()->unique()->word(),
    ]);
    $store->stocks()->attach($stock);
    $user = User::factory()->create([
        'current_store_id' => $store->id,
    ]);

    return [$store, $stock, $user];
}

function createPackageProduct(Store $store, Stock $stock, string $name, int $quantity): Product
{
    $product = Product::query()->create([
        'name'          => $name,
        'store_id'      => $store->id,
        'type'          => 'package',
        'initial_price' => 50_000,
        'price'         => 100_000,
    ]);
    ProductStock::query()->create([
        'product_id' => $product->id,
        'stock_id'   => $stock->id,
        'quantity'   => $quantity,
    ]);

    return $product;
}
