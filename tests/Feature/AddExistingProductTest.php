<?php

use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use Livewire\Livewire;
use App\Models\Product;
use App\Models\Category;
use App\Models\ProductStock;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Filament\Resources\Products\Pages\AddExistingProduct;

uses(RefreshDatabase::class);

it('finds an existing product by barcode and adds to its current quantity', function () {
    [$product, $stock, $user] = createExistingProductContext('017001900284', 5);

    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(AddExistingProduct::class)
        ->fillForm(['barcode' => $product->barcode])
        ->call('searchProduct')
        ->assertSet('productId', $product->id)
        ->assertSchemaStateSet([
            'name'             => $product->name,
            'category_name'    => $product->category->name,
            'initial_price'    => $product->initial_price,
            'price'            => $product->price,
            'stock_id'         => $stock->id,
            'current_quantity' => 5,
        ])
        ->fillForm(['quantity' => 4])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSchemaStateSet([
            'current_quantity' => 9,
            'quantity'         => null,
        ]);

    expect($product->productStocks()->where('stock_id', $stock->id)->value('quantity'))->toBe(9);
});

it('adds quantity and redirects to print only the added amount', function () {
    [$product, $stock, $user] = createExistingProductContext('017001900291', 10);

    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(AddExistingProduct::class)
        ->fillForm(['barcode' => $product->barcode])
        ->call('searchProduct')
        ->fillForm(['quantity' => 3])
        ->call('saveAndPrint')
        ->assertHasNoFormErrors()
        ->assertRedirect(route('product.barcode.pdf', [
            'product' => $product->id,
            'size'    => '57x30',
            'copies'  => 3,
        ]));

    expect($product->productStocks()->where('stock_id', $stock->id)->value('quantity'))->toBe(13);
});

it('does not find a product from another store', function () {
    [$product, , $firstUser] = createExistingProductContext('017001900307', 6);

    $secondStore = Store::query()->create([
        'name'    => 'Ikkinchi filial',
        'address' => 'Test address',
        'phone'   => '+998901234599',
    ]);

    $secondStock = Stock::query()->withoutGlobalScopes()->create([
        'name'      => 'Ikkinchi ombor',
        'is_active' => true,
    ]);

    $secondStore->stocks()->attach($secondStock);

    $firstUser->update(['current_store_id' => $secondStore->id]);
    $firstUser->stores()->attach($secondStore);

    $this->actingAs($firstUser);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(AddExistingProduct::class)
        ->fillForm(['barcode' => $product->barcode])
        ->call('searchProduct')
        ->assertSet('productId', null)
        ->assertNotified('Tovar topilmadi');

    expect($product->productStocks()->sum('quantity'))->toBe(6);
});

/**
 * @return array{Product, Stock, User}
 */
function createExistingProductContext(string $barcode, int $quantity): array
{
    $store = Store::query()->create([
        'name'    => "Filial {$barcode}",
        'address' => 'Test address',
        'phone'   => '+99890' . substr($barcode, -7),
    ]);

    $stock = Stock::query()->withoutGlobalScopes()->create([
        'name'      => "Ombor {$barcode}",
        'is_active' => true,
    ]);

    $store->stocks()->attach($stock);

    $category = Category::query()->create([
        'name'      => "Kategoriya {$barcode}",
        'is_active' => true,
    ]);

    $product = Product::query()->withoutGlobalScopes()->create([
        'store_id'      => $store->id,
        'category_id'   => $category->id,
        'name'          => $category->name,
        'barcode'       => $barcode,
        'type'          => Product::TYPE_PACKAGE,
        'initial_price' => 120000,
        'price'         => 180000,
    ]);

    ProductStock::query()->create([
        'product_id'      => $product->id,
        'product_size_id' => null,
        'stock_id'        => $stock->id,
        'quantity'        => $quantity,
    ]);

    $user = User::factory()->create([
        'current_store_id' => $store->id,
    ]);

    $user->stores()->attach($store);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    foreach (['ViewAny:Product', 'Create:Product'] as $permissionName) {
        Permission::firstOrCreate([
            'name'       => $permissionName,
            'guard_name' => 'web',
        ]);
    }
    $user->givePermissionTo(['ViewAny:Product', 'Create:Product']);

    return [$product, $stock, $user];
}
