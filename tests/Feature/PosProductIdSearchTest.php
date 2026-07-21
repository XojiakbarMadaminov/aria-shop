<?php

use Tests\TestCase;
use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
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
