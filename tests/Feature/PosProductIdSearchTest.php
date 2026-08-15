<?php

use Tests\TestCase;
use App\Models\Sale;
use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Client;
use Livewire\Livewire;
use App\Models\Product;
use App\Filament\Pages\Pos;
use App\Models\ProductStock;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: Product, 1: User}
 */
function createVisiblePosProduct(TestCase $testCase, array $attributes = []): array
{
    $store = Store::create([
        'name'    => fake()->company(),
        'address' => fake()->address(),
        'phone'   => fake()->phoneNumber(),
    ]);

    $stock = Stock::create([
        'name'      => 'Main stock',
        'is_main'   => true,
        'is_active' => true,
    ]);

    $store->stocks()->attach($stock);

    $user = User::factory()->create([
        'current_store_id' => $store->id,
    ]);

    $user->stores()->attach($store);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::firstOrCreate([
        'name'       => 'View:Pos',
        'guard_name' => 'web',
    ]);
    $user->givePermissionTo('View:Pos');

    $testCase->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $product = Product::factory()->create(array_merge([
        'store_id' => $store->id,
        'type'     => Product::TYPE_PACKAGE,
    ], $attributes));

    ProductStock::create([
        'product_id' => $product->id,
        'stock_id'   => $stock->id,
        'quantity'   => 10,
    ]);

    return [$product, $user];
}

it('searches products by id on the POS page', function () {
    [$product, $user] = createVisiblePosProduct($this, [
        'name' => 'ID orqali topiladigan tovar',
    ]);

    Product::factory()->create([
        'name'     => 'Boshqa tovar',
        'store_id' => $product->store_id,
    ]);

    Livewire::actingAs($user)
        ->test(Pos::class)
        ->set('searchById', (string) $product->id)
        ->assertSet('search', '')
        ->assertSee('ID orqali topiladigan tovar')
        ->assertDontSee('Boshqa tovar');
});

it('adds a product to the POS cart by id', function () {
    [$product, $user] = createVisiblePosProduct($this, [
        'name'  => 'ID orqali savatga tushadigan tovar',
        'price' => 125000,
    ]);

    Livewire::actingAs($user)
        ->test(Pos::class)
        ->call('addByProductId', (string) $product->id)
        ->assertSet('searchById', '')
        ->assertSee('ID orqali savatga tushadigan tovar');

    expect(session('pos_carts.1.' . $product->id))
        ->not->toBeNull()
        ->toMatchArray([
            'id'   => $product->id,
            'name' => 'ID orqali savatga tushadigan tovar',
            'qty'  => 1,
        ]);
});

it('applies and persists a customer discount during checkout', function () {
    [$product, $user] = createVisiblePosProduct($this, [
        'name'  => 'Chegirmali tovar',
        'price' => 200000,
    ]);
    $client = Client::factory()->create();

    Livewire::actingAs($user)
        ->test(Pos::class)
        ->call('addByProductId', (string) $product->id)
        ->call('selectClient', $client->id)
        ->set('customerDiscountType', 'percent')
        ->set('customerDiscountValue', 10)
        ->assertSet('totals.customer_discount_amount', 20000.0)
        ->assertSet('totals.amount', 180000.0)
        ->call('selectPaymentType', 'mixed')
        ->set('mixedPayment.card', 50000)
        ->assertSet('mixedPayment.cash', 130000.0)
        ->call('selectPaymentType', 'cash')
        ->call('checkout')
        ->assertSet('selectedClientId', null)
        ->assertSet('saleWithoutClient', true)
        ->assertSet('saleWithoutClientPaymentType', 'cash');

    $sale = Sale::query()->latest('id')->firstOrFail();

    expect($sale->client_id)->toBe($client->id)
        ->and($sale->customer_discount_type)->toBe('percent')
        ->and($sale->customer_discount_value)->toBe(10.0)
        ->and($sale->customer_discount_amount)->toBe(20_000.0)
        ->and($sale->total_amount)->toBe(180_000.0)
        ->and($sale->paid_amount)->toBe(180_000.0)
        ->and($sale->remaining_amount)->toBe(0.0)
        ->and(session('pos_cart_customer_discounts.1'))->toBeNull();
});

it('allows a fully discounted customer sale with a zero final total', function () {
    [$product, $user] = createVisiblePosProduct($this, [
        'name'  => 'Bepul qilinadigan tovar',
        'price' => 100000,
    ]);
    $client = Client::factory()->create();

    Livewire::actingAs($user)
        ->test(Pos::class)
        ->call('addByProductId', (string) $product->id)
        ->call('selectClient', $client->id)
        ->set('customerDiscountType', 'percent')
        ->set('customerDiscountValue', 100)
        ->assertSet('totals.amount', 0.0)
        ->call('selectPaymentType', 'cash')
        ->call('checkout');

    $sale = Sale::query()->latest('id')->firstOrFail();

    expect($sale->customer_discount_amount)->toBe(100_000.0)
        ->and($sale->total_amount)->toBe(0.0)
        ->and($sale->paid_amount)->toBe(0.0)
        ->and($sale->remaining_amount)->toBe(0.0);
});

it('clears a customer discount when another customer is selected', function () {
    [$product, $user] = createVisiblePosProduct($this, [
        'price' => 100000,
    ]);
    $firstClient  = Client::factory()->create();
    $secondClient = Client::factory()->create();

    Livewire::actingAs($user)
        ->test(Pos::class)
        ->call('addByProductId', (string) $product->id)
        ->call('selectClient', $firstClient->id)
        ->set('customerDiscountType', 'fixed')
        ->set('customerDiscountValue', 25000)
        ->assertSet('totals.amount', 75000.0)
        ->call('selectClient', $secondClient->id)
        ->assertSet('customerDiscountValue', null)
        ->assertSet('totals.customer_discount_amount', 0.0)
        ->assertSet('totals.amount', 100000.0);
});
