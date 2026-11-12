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
use Illuminate\Support\Facades\Process;
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

it('adds a product by its stored legacy or UPC barcode', function (string $storedBarcode, string $barcode, string $method) {
    [$product, $user] = createVisiblePosProduct($this, ['barcode' => $storedBarcode]);

    $component = Livewire::actingAs($user)->test(Pos::class);

    if ($method === 'scanEnter') {
        $component->set('search', $barcode)->call($method);
    } else {
        $component->call($method, $barcode);
    }

    $component->assertSet('search', '');

    expect(session('pos_carts.1.' . $product->id . '.qty'))->toBe(1);
})->with([
    'original scanner barcode' => ['012002800069', '012002800069', 'addByBarcode'],
    'legacy scanner label'     => ['012002800069', '0120028000698', 'addByBarcode'],
    'original enter barcode'   => ['012002800069', '012002800069', 'scanEnter'],
    'legacy enter label'       => ['012002800069', '0120028000698', 'scanEnter'],
    'UPC-A scanner barcode'    => ['0522368943916', '522368943916', 'addByBarcode'],
    'UPC-A enter barcode'      => ['0522368943916', '522368943916', 'scanEnter'],
    'legacy UPC-A scanner'     => ['012002800069', '120028000698', 'addByBarcode'],
    'legacy UPC-A enter'       => ['012002800069', '120028000698', 'scanEnter'],
]);

it('adds the product found by a UPC-A search to the cart', function () {
    [$product, $user] = createVisiblePosProduct($this, ['barcode' => '0522368943916']);

    Livewire::actingAs($user)
        ->test(Pos::class)
        ->set('search', '522368943916')
        ->assertSee($product->name)
        ->call('addByBarcode', '522368943916')
        ->assertSet('search', '');

    expect(session('pos_carts.1.' . $product->id . '.qty'))->toBe(1);
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

it('opens a mixed payment modal for a sale without a client and balances either input', function (string $field, string $otherField) {
    [$product, $user] = createVisiblePosProduct($this, ['price' => 150000]);

    Livewire::actingAs($user)
        ->test(Pos::class)
        ->call('addByProductId', (string) $product->id)
        ->assertSee('Aralash')
        ->set('saleWithoutClientPaymentType', 'mixed')
        ->assertSet('paymentType', 'mixed')
        ->assertSet('showMixedPaymentModal', true)
        ->assertSee('To‘lanishi kerak')
        ->assertSee('150 000')
        ->assertSeeHtml('x-model="card"')
        ->assertSeeHtml('x-model="cash"')
        ->assertDontSeeHtml('wire:model.blur="mixedPayment.card"')
        ->assertDontSeeHtml('wire:model.blur="mixedPayment.cash"')
        ->assertDontSeeHtml('wire:model.live.debounce.300ms="mixedPayment.card"')
        ->assertDontSeeHtml('wire:model.live.debounce.300ms="mixedPayment.cash"')
        ->set("mixedPayment.{$field}", 50000)
        ->assertSet("mixedPayment.{$otherField}", 100000.0)
        ->call('closeMixedPaymentModal')
        ->assertSet('showMixedPaymentModal', false)
        ->call('openMixedPaymentModal')
        ->assertSet('showMixedPaymentModal', true)
        ->assertSet("mixedPayment.{$field}", 50000.0)
        ->assertSet("mixedPayment.{$otherField}", 100000.0);
})->with([
    'card first' => ['card', 'cash'],
    'cash first' => ['cash', 'card'],
]);

it('completes a mixed payment sale without a client and stores both payment amounts', function () {
    [$product, $user] = createVisiblePosProduct($this, ['price' => 150000]);

    Livewire::actingAs($user)
        ->test(Pos::class)
        ->call('addByProductId', (string) $product->id)
        ->set('saleWithoutClientPaymentType', 'mixed')
        ->set('mixedPayment.card', 50000)
        ->call('closeMixedPaymentModal')
        ->call('checkout')
        ->assertSet('showMixedPaymentModal', false)
        ->assertSet('paymentType', 'cash');

    $sale = Sale::query()->sole();

    expect($sale->client_id)->toBeNull()
        ->and($sale->payment_type)->toBe('mixed')
        ->and($sale->total_amount)->toBe(150000.0)
        ->and($sale->paid_amount)->toBe(150000.0)
        ->and($sale->remaining_amount)->toBe(0.0)
        ->and($sale->mixed_card_amount)->toBe(50000.0)
        ->and($sale->mixed_cash_amount)->toBe(100000.0)
        ->and(ProductStock::query()->where('product_id', $product->id)->sole()->quantity)->toBe(9);
});

it('restores mixed payment for the current cart after a page reload', function () {
    [$product, $user] = createVisiblePosProduct($this, ['price' => 150000]);

    Livewire::actingAs($user)
        ->test(Pos::class)
        ->call('addByProductId', (string) $product->id)
        ->set('saleWithoutClientPaymentType', 'mixed')
        ->set('mixedPayment.cash', 50000);

    Livewire::actingAs($user)
        ->test(Pos::class)
        ->assertSet('paymentType', 'mixed')
        ->assertSet('saleWithoutClientPaymentType', 'mixed')
        ->assertSet('mixedPayment.cash', 50000.0)
        ->assertSet('mixedPayment.card', 100000.0)
        ->assertSet('showMixedPaymentModal', false);
});

it('keeps mixed payment amounts separate when switching carts', function () {
    [$product, $user] = createVisiblePosProduct($this, ['price' => 150000]);

    Livewire::actingAs($user)
        ->test(Pos::class)
        ->call('addByProductId', (string) $product->id)
        ->set('saleWithoutClientPaymentType', 'mixed')
        ->set('mixedPayment.card', 50000)
        ->call('createNewCart')
        ->assertSet('showMixedPaymentModal', false)
        ->assertSet('paymentType', 'cash')
        ->assertSet('mixedPayment.card', null)
        ->call('switchCart', 1)
        ->assertSet('paymentType', 'mixed')
        ->assertSet('mixedPayment.card', 50000.0)
        ->assertSet('mixedPayment.cash', 100000.0);
});

it('clears mixed payment when changing to a single payment method', function (string $type) {
    [$product, $user] = createVisiblePosProduct($this, ['price' => 150000]);

    Livewire::actingAs($user)
        ->test(Pos::class)
        ->call('addByProductId', (string) $product->id)
        ->set('saleWithoutClientPaymentType', 'mixed')
        ->set('mixedPayment.card', 50000)
        ->set('saleWithoutClientPaymentType', $type)
        ->assertSet('paymentType', $type)
        ->assertSet('showMixedPaymentModal', false)
        ->assertSet('mixedPayment.card', null)
        ->assertSet('mixedPayment.cash', null);
})->with(['cash', 'card']);

it('bounds mixed payment input to the total amount', function (int $amount, ?float $card, ?float $cash) {
    [$product, $user] = createVisiblePosProduct($this, ['price' => 150000]);

    Livewire::actingAs($user)
        ->test(Pos::class)
        ->call('addByProductId', (string) $product->id)
        ->set('saleWithoutClientPaymentType', 'mixed')
        ->set('mixedPayment.card', $amount)
        ->assertSet('mixedPayment.card', $card)
        ->assertSet('mixedPayment.cash', $cash);
})->with([
    'negative'    => [-50000, null, 150000.0],
    'above total' => [200000, 150000.0, null],
]);

it('rejects a mixed payment whose amounts do not match the total', function () {
    [$product, $user] = createVisiblePosProduct($this, ['price' => 150000]);

    Livewire::actingAs($user)
        ->test(Pos::class)
        ->call('addByProductId', (string) $product->id)
        ->set('saleWithoutClientPaymentType', 'mixed')
        ->set('mixedPayment', ['card' => 50000, 'cash' => 50000])
        ->call('checkout')
        ->assertNotified('To‘lov summasi mos emas');

    expect(Sale::query()->count())->toBe(0)
        ->and(ProductStock::query()->where('product_id', $product->id)->sole()->quantity)->toBe(10);
});

it('preserves typed mixed payment digits while balancing locally without live server updates', function () {
    $script = <<<'JS'
        const assert = require('node:assert/strict');
        const fs = require('node:fs');
        const vm = require('node:vm');
        const scope = {
            document: { addEventListener() {} },
            window: { addEventListener() {} },
        };

        vm.createContext(scope);
        vm.runInContext(fs.readFileSync('public/js/pos.js', 'utf8'), scope);

        const bindings = [];
        const wire = {
            totals: { amount: 150000 },
            entangle(name, live = false) {
                assert.equal(live, false);
                bindings.push(name);
                return null;
            },
        };
        const state = scope.posMixedPaymentInputs(wire);

        assert.deepEqual(bindings, ['mixedPayment.card', 'mixedPayment.cash']);

        for (const field of ['card', 'cash']) {
            const other = field === 'card' ? 'cash' : 'card';

            for (const typed of ['5', '50', '500', '5000', '50000', '050000', '50000.00']) {
                state[field] = typed;
                state.balance(field, typed);
                assert.equal(state[field], typed);
                assert.equal(state[other], 150000 - Number(typed));
            }

            for (const [typed, expected] of [['', 150000], ['0', 150000], ['-1', 150000], ['200000', 0]]) {
                state[field] = typed;
                state.balance(field, typed);
                assert.equal(state[field], typed);
                assert.equal(state[other], expected);
            }
        }

        wire.totals.amount = 150000.25;
        state.card = '50000.15';
        state.balance('card', state.card);
        assert.equal(state.card, '50000.15');
        assert.equal(state.cash, 100000.10);
        JS;

    $result = Process::path(base_path())->run(['node', '-e', $script]);

    expect($result->exitCode())->toBe(0, $result->errorOutput());
});

it('persists deferred mixed payment amounts when the modal is confirmed', function () {
    [$product, $user] = createVisiblePosProduct($this, ['price' => 150000]);

    Livewire::actingAs($user)
        ->test(Pos::class)
        ->call('addByProductId', (string) $product->id)
        ->set('saleWithoutClientPaymentType', 'mixed')
        ->set('mixedPayment', ['card' => '50000', 'cash' => '100000'])
        ->call('closeMixedPaymentModal')
        ->assertSet('showMixedPaymentModal', false);

    Livewire::actingAs($user)
        ->test(Pos::class)
        ->assertSet('mixedPayment.card', 50000.0)
        ->assertSet('mixedPayment.cash', 100000.0)
        ->call('checkout');

    $sale = Sale::query()->sole();

    expect($sale->mixed_card_amount)->toBe(50000.0)
        ->and($sale->mixed_cash_amount)->toBe(100000.0);
});
