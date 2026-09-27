<?php

use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use Livewire\Livewire;
use App\Models\Product;
use App\Models\Category;
use Filament\Facades\Filament;
use Filament\Actions\Testing\TestAction;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Filament\Resources\Products\Pages\CreateProduct;

uses(RefreshDatabase::class);

it('creates a product name from the selected category', function () {
    $store = Store::query()->create([
        'name'    => 'Main store',
        'address' => 'Test address',
        'phone'   => '+998901234571',
    ]);

    $stock = Stock::query()->create([
        'name'      => 'Magazin',
        'is_active' => true,
    ]);

    $store->stocks()->attach($stock);

    $category = Category::query()->create([
        'name'      => 'Bolalar kiyimi',
        'is_active' => true,
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

    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $component = Livewire::test(CreateProduct::class);

    $component->assertOk();

    $component->fillForm([
        'category_id'            => $category->id,
        'barcode'                => '017001900260',
        'type'                   => Product::TYPE_PACKAGE,
        'initial_price'          => 120000,
        'price'                  => 180000,
        "pkg_stock_{$stock->id}" => 7,
    ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $product = Product::query()
        ->withoutGlobalScopes()
        ->where('barcode', '017001900260')
        ->sole();

    expect($product->name)->toBe('Bolalar kiyimi')
        ->and($product->category_id)->toBe($category->id)
        ->and($product->store_id)->toBe($store->id)
        ->and($product->type)->toBe(Product::TYPE_PACKAGE)
        ->and($product->productStocks()->where('stock_id', $stock->id)->value('quantity'))->toBe(7);
});

it('creates a product and redirects to print the entered stock quantity', function () {
    $store = Store::query()->create([
        'name'    => 'Print store',
        'address' => 'Test address',
        'phone'   => '+998901234572',
    ]);

    $stock = Stock::query()->create([
        'name'      => 'Magazin',
        'is_active' => true,
    ]);

    $store->stocks()->attach($stock);

    $category = Category::query()->create([
        'name'      => 'Chop etiladigan tovar',
        'is_active' => true,
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

    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $component = Livewire::test(CreateProduct::class)
        ->fillForm([
            'category_id'            => $category->id,
            'barcode'                => '017001900277',
            'type'                   => Product::TYPE_PACKAGE,
            'initial_price'          => 120000,
            'price'                  => 180000,
            "pkg_stock_{$stock->id}" => 7,
        ]);

    $printAction = TestAction::make('createAndPrint')
        ->schemaComponent('productCreateFormActions', 'content');

    $component
        ->mountAction($printAction)
        ->assertSchemaStateSet([
            'copies' => 7,
        ])
        ->callMountedAction();

    $product = Product::query()
        ->withoutGlobalScopes()
        ->where('barcode', '017001900277')
        ->sole();

    $component
        ->assertHasNoFormErrors()
        ->assertRedirect(route('product.barcode.pdf', [
            'product' => $product->id,
            'size'    => '57x30',
            'copies'  => 7,
        ]));

    expect($product->productStocks()->where('stock_id', $stock->id)->value('quantity'))->toBe(7);
});
