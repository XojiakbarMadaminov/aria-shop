<?php

use App\Models\Store;
use App\Models\Discount;
use App\Enums\DiscountType;
use Database\Seeders\DiscountSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates every default discount for every active store', function () {
    $firstStore   = createDiscountSeederStore('Birinchi filial');
    $secondStore  = createDiscountSeederStore('Ikkinchi filial');
    $deletedStore = createDiscountSeederStore('O‘chirilgan filial');
    $deletedStore->delete();

    $this->seed(DiscountSeeder::class);

    $expectedTypes = collect(DiscountType::cases())
        ->map(fn (DiscountType $type): string => $type->value)
        ->sort()
        ->values()
        ->all();

    expect(Discount::withoutGlobalScopes()->count())->toBe(8)
        ->and($firstStore->discounts()->pluck('type')->map(fn (DiscountType $type): string => $type->value)->sort()->values()->all())->toBe($expectedTypes)
        ->and($secondStore->discounts()->pluck('type')->map(fn (DiscountType $type): string => $type->value)->sort()->values()->all())->toBe($expectedTypes)
        ->and(Discount::withoutGlobalScopes()->where('store_id', $deletedStore->id)->doesntExist())->toBeTrue()
        ->and(Discount::withoutGlobalScopes()->where('is_active', true)->doesntExist())->toBeTrue();
});

it('is idempotent and preserves configured store discounts', function () {
    $store = createDiscountSeederStore('Sozlangan filial');

    $this->seed(DiscountSeeder::class);

    $configuredDiscount = Discount::withoutGlobalScopes()
        ->where('store_id', $store->id)
        ->where('type', DiscountType::GlobalPercent)
        ->sole();
    $configuredDiscount->update([
        'percent'   => 17,
        'is_active' => true,
    ]);

    $this->seed(DiscountSeeder::class);

    expect(Discount::withoutGlobalScopes()->where('store_id', $store->id)->count())->toBe(4)
        ->and($configuredDiscount->refresh()->percent)->toBe('17.00')
        ->and($configuredDiscount->is_active)->toBeTrue();
});

function createDiscountSeederStore(string $name): Store
{
    return Store::query()->create([
        'name'    => $name,
        'address' => fake()->address(),
        'phone'   => fake()->unique()->phoneNumber(),
    ]);
}
