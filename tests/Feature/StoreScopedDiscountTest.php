<?php

use App\Models\User;
use App\Models\Store;
use App\Models\Product;
use App\Models\Category;
use App\Models\Discount;
use App\Enums\DiscountType;
use App\Services\DiscountService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('applies only the current store global discount', function () {
    [$firstStore, $firstUser]   = createDiscountStoreUser('Birinchi filial');
    [$secondStore, $secondUser] = createDiscountStoreUser('Ikkinchi filial');
    $firstProduct               = createDiscountStoreProduct($firstStore, 'Birinchi filial mahsuloti');
    $secondProduct              = createDiscountStoreProduct($secondStore, 'Ikkinchi filial mahsuloti');

    createStoreDiscount($firstStore, DiscountType::GlobalPercent, 10);
    createStoreDiscount($secondStore, DiscountType::GlobalPercent, 25);

    $this->actingAs($firstUser);
    $firstResult = app(DiscountService::class)->calculate([
        ['product_id' => $firstProduct->id, 'quantity' => 1, 'price' => 100_000],
    ]);

    $this->actingAs($secondUser);
    $secondResult = app(DiscountService::class)->calculate([
        ['product_id' => $secondProduct->id, 'quantity' => 1, 'price' => 100_000],
    ]);

    expect($firstResult['discount_total'])->toBe(10_000.0)
        ->and($firstResult['total'])->toBe(90_000.0)
        ->and($secondResult['discount_total'])->toBe(25_000.0)
        ->and($secondResult['total'])->toBe(75_000.0);
});

it('isolates selected product and category discounts between stores', function () {
    [$firstStore, $firstUser]   = createDiscountStoreUser('Birinchi filial');
    [$secondStore, $secondUser] = createDiscountStoreUser('Ikkinchi filial');
    $category                   = Category::query()->create([
        'name'      => 'Krossovka',
        'is_active' => true,
    ]);
    $firstProduct  = createDiscountStoreProduct($firstStore, 'Birinchi mahsulot', $category);
    $secondProduct = createDiscountStoreProduct($secondStore, 'Ikkinchi mahsulot', $category);

    $selectedDiscount = createStoreDiscount($firstStore, DiscountType::SelectedProductsPercent, 40);
    $selectedDiscount->products()->attach($firstProduct);
    $categoryDiscount = createStoreDiscount($secondStore, DiscountType::CategoryPercent, 15);
    $categoryDiscount->categories()->attach($category);

    $this->actingAs($firstUser);
    $firstResult = app(DiscountService::class)->calculate([
        ['product_id' => $firstProduct->id, 'quantity' => 1, 'price' => 100_000],
    ]);

    $this->actingAs($secondUser);
    $secondResult = app(DiscountService::class)->calculate([
        ['product_id' => $secondProduct->id, 'quantity' => 1, 'price' => 100_000],
    ]);

    expect($firstResult['discount_total'])->toBe(40_000.0)
        ->and($secondResult['discount_total'])->toBe(15_000.0);
});

it('isolates order amount rules and discount administration queries between stores', function () {
    [$firstStore, $firstUser]   = createDiscountStoreUser('Birinchi filial');
    [$secondStore, $secondUser] = createDiscountStoreUser('Ikkinchi filial');

    createStoreDiscount($firstStore, DiscountType::OrderAmountPercent, 10, 50_000);
    createStoreDiscount($secondStore, DiscountType::OrderAmountPercent, 30, 200_000);

    $this->actingAs($firstUser);
    $firstResult = app(DiscountService::class)->calculate([
        ['quantity' => 1, 'price' => 100_000],
    ]);
    $firstVisibleDiscounts = Discount::query()->pluck('store_id')->unique()->all();

    $this->actingAs($secondUser);
    $secondResult = app(DiscountService::class)->calculate([
        ['quantity' => 1, 'price' => 100_000],
    ]);
    $secondVisibleDiscounts = Discount::query()->pluck('store_id')->unique()->all();

    expect($firstResult['order_discount_total'])->toBe(10_000.0)
        ->and($secondResult['order_discount_total'])->toBe(0.0)
        ->and($firstVisibleDiscounts)->toBe([$firstStore->id])
        ->and($secondVisibleDiscounts)->toBe([$secondStore->id]);
});

/**
 * @return array{0: Store, 1: User}
 */
function createDiscountStoreUser(string $name): array
{
    $store = Store::query()->create([
        'name'    => $name,
        'address' => fake()->address(),
        'phone'   => fake()->unique()->phoneNumber(),
    ]);
    $user = User::factory()->create([
        'current_store_id' => $store->id,
    ]);

    return [$store, $user];
}

function createDiscountStoreProduct(Store $store, string $name, ?Category $category = null): Product
{
    return Product::withoutGlobalScopes()->create([
        'name'        => $name,
        'store_id'    => $store->id,
        'category_id' => $category?->id,
        'type'        => Product::TYPE_PACKAGE,
        'price'       => 100_000,
    ]);
}

function createStoreDiscount(
    Store $store,
    DiscountType $type,
    int $percent,
    ?int $minimumOrderAmount = null,
): Discount {
    return Discount::withoutGlobalScopes()->create([
        'store_id'         => $store->id,
        'name'             => $type->getLabel(),
        'type'             => $type,
        'percent'          => $percent,
        'min_order_amount' => $minimumOrderAmount,
        'is_active'        => true,
    ]);
}
