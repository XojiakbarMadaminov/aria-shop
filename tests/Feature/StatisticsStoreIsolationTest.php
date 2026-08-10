<?php

use App\Models\Sale;
use App\Models\User;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Category;
use App\Models\Discount;
use App\Models\Purchase;
use App\Models\SaleItem;
use App\Models\Supplier;
use App\Enums\DiscountType;
use App\Models\ProductStock;
use App\Models\PurchaseItem;
use App\Services\ReturnService;
use App\Filament\Pages\Dashboard;
use App\Services\DiscountService;
use App\Services\ExchangeService;
use App\Filament\Widgets\SalesStatsOverview;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Filament\Widgets\TopSellingProductsChart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Filament\Widgets\TopPurchasedProductsChart;
use App\Filament\Widgets\TopSellingCategoriesChart;

uses(RefreshDatabase::class);

it('registers the top selling categories chart on the statistics dashboard', function () {
    expect((new Dashboard)->getFooterWidgets())
        ->toContain(TopSellingCategoriesChart::class);
});

it('removes a discounted return only from its own store statistics', function () {
    [$firstStore, $firstStock, $firstUser]    = createStatisticsStoreContext('Birinchi filial');
    [$secondStore, $secondStock, $secondUser] = createStatisticsStoreContext('Ikkinchi filial');
    $firstCategory                            = createStatisticsCategory('Birinchi kategoriya');
    $secondCategory                           = createStatisticsCategory('Ikkinchi kategoriya');
    $firstProduct                             = createStatisticsProduct($firstStore, $firstStock, 'Qaytariladigan mahsulot', 60_000, 100_000, 5, $firstCategory);
    $secondProduct                            = createStatisticsProduct($secondStore, $secondStock, 'Boshqa filial mahsuloti', 100_000, 200_000, 5, $secondCategory);

    createStatisticsDiscount($firstStore, 10);
    createStatisticsDiscount($secondStore, 20);

    $firstSale = createDiscountedStatisticsSale($firstUser, $firstStore, $firstStock, $firstProduct, 'cash');
    createDiscountedStatisticsSale($secondUser, $secondStore, $secondStock, $secondProduct, 'cash');

    expect(statisticsCardValues($firstUser))->toMatchArray([
        'Umumiy sotuvlar' => "90,000 so'm",
        'Foyda'           => "30,000 so'm",
    ]);

    auth()->login($firstUser);
    app(ReturnService::class)->handle([
        'product_id' => $firstProduct->id,
        'stock_id'   => $firstStock->id,
        'quantity'   => 1,
        'price'      => $firstSale->total_amount,
    ]);

    expect(statisticsCardValues($firstUser))->toMatchArray([
        'Umumiy sotuvlar' => "0 so'm",
        'Foyda'           => "0 so'm",
    ])
        ->and(statisticsChartData($firstUser, new TopSellingProductsChart))
        ->toMatchArray([
            'labels' => [],
            'data'   => [],
        ])
        ->and(statisticsChartData($firstUser, new TopSellingCategoriesChart))
        ->toMatchArray([
            'labels' => [],
            'data'   => [],
        ])
        ->and(statisticsCardValues($secondUser))->toMatchArray([
            'Umumiy sotuvlar' => "160,000 so'm",
            'Foyda'           => "60,000 so'm",
        ]);
});

it('keeps discounted sales and all statistics isolated between stores', function () {
    [$firstStore, $firstStock, $firstUser]    = createStatisticsStoreContext('Birinchi filial');
    [$secondStore, $secondStock, $secondUser] = createStatisticsStoreContext('Ikkinchi filial');
    $firstCategory                            = createStatisticsCategory('Birinchi kategoriya');
    $secondCategory                           = createStatisticsCategory('Ikkinchi kategoriya');

    $firstSoldProduct     = createStatisticsProduct($firstStore, $firstStock, 'Birinchi sotilgan mahsulot', 60_000, 100_000, 5, $firstCategory);
    $firstExchangeProduct = createStatisticsProduct($firstStore, $firstStock, 'Birinchi almashtirilgan mahsulot', 70_000, 120_000, 5, $firstCategory);
    $secondSoldProduct    = createStatisticsProduct($secondStore, $secondStock, 'Ikkinchi sotilgan mahsulot', 100_000, 200_000, 5, $secondCategory);

    createStatisticsDiscount($firstStore, 10);
    createStatisticsDiscount($secondStore, 20);

    $firstSale  = createDiscountedStatisticsSale($firstUser, $firstStore, $firstStock, $firstSoldProduct, 'cash');
    $secondSale = createDiscountedStatisticsSale($secondUser, $secondStore, $secondStock, $secondSoldProduct, 'debt');

    createIgnoredStatisticsSale($firstStore, $firstStock, $firstSoldProduct, Sale::STATUS_PENDING, 900_000, 90);
    createIgnoredStatisticsSale($firstStore, $firstStock, $firstSoldProduct, Sale::STATUS_REJECTED, 800_000, 80);
    createIgnoredStatisticsSale($firstStore, $firstStock, $firstSoldProduct, Sale::STATUS_COMPLETED, 700_000, 70, now()->subDay());

    createStatisticsExpense($firstStore, $firstUser, 11_000);
    createStatisticsExpense($secondStore, $secondUser, 22_000);

    createStatisticsPurchase($firstStore, $firstStock, $firstUser, $firstSoldProduct, 300_000, 'debt', 3);
    createStatisticsPurchase($secondStore, $secondStock, $secondUser, $secondSoldProduct, 400_000, 'cash', 4);

    expect($firstSale->total_amount)->toBe(90_000.0)
        ->and($firstSale->discount_total)->toBe(10_000.0)
        ->and($secondSale->total_amount)->toBe(160_000.0)
        ->and($secondSale->discount_total)->toBe(40_000.0)
        ->and(statisticsCardValues($firstUser))->toMatchArray([
            'Umumiy sotuvlar'                => "90,000 so'm",
            'Foyda'                          => "30,000 so'm",
            'Qarzga sotuvlar'                => "0 so'm",
            'Xarajatlar'                     => "11,000 so'm",
            "Ta'minotchidan jami xaridlar"   => "300,000 so'm",
            "Ta'minotchidan qarzga xaridlar" => "300,000 so'm",
        ])
        ->and(statisticsCardValues($secondUser))->toMatchArray([
            'Umumiy sotuvlar'                => "160,000 so'm",
            'Foyda'                          => "60,000 so'm",
            'Qarzga sotuvlar'                => "160,000 so'm",
            'Xarajatlar'                     => "22,000 so'm",
            "Ta'minotchidan jami xaridlar"   => "400,000 so'm",
            "Ta'minotchidan qarzga xaridlar" => "0 so'm",
        ]);

    expect(statisticsChartData($firstUser, new TopSellingProductsChart))
        ->toMatchArray([
            'labels' => ['Birinchi sotilgan mahsulot'],
            'data'   => [1],
        ])
        ->and(statisticsChartData($secondUser, new TopSellingProductsChart))
        ->toMatchArray([
            'labels' => ['Ikkinchi sotilgan mahsulot'],
            'data'   => [1],
        ])
        ->and(statisticsChartData($firstUser, new TopSellingCategoriesChart))
        ->toMatchArray([
            'labels' => ['Birinchi kategoriya'],
            'data'   => [1],
        ])
        ->and(statisticsChartData($secondUser, new TopSellingCategoriesChart))
        ->toMatchArray([
            'labels' => ['Ikkinchi kategoriya'],
            'data'   => [1],
        ])
        ->and(statisticsChartData($firstUser, new TopPurchasedProductsChart))
        ->toMatchArray([
            'labels' => ['Birinchi sotilgan mahsulot'],
            'data'   => [3],
        ])
        ->and(statisticsChartData($secondUser, new TopPurchasedProductsChart))
        ->toMatchArray([
            'labels' => ['Ikkinchi sotilgan mahsulot'],
            'data'   => [4],
        ]);

    auth()->login($firstUser);
    app(ExchangeService::class)->handle([
        'stock_id'       => $firstStock->id,
        'quantity'       => 1,
        'in_product_id'  => $firstSoldProduct->id,
        'out_product_id' => $firstExchangeProduct->id,
        'in_price'       => $firstSale->total_amount,
        'out_price'      => 120_000,
    ]);

    expect(statisticsCardValues($firstUser))->toMatchArray([
        'Umumiy sotuvlar' => "120,000 so'm",
        'Foyda'           => "50,000 so'm",
    ])
        ->and(statisticsChartData($firstUser, new TopSellingProductsChart))
        ->toMatchArray([
            'labels' => ['Birinchi almashtirilgan mahsulot'],
            'data'   => [1],
        ])
        ->and(statisticsChartData($firstUser, new TopSellingCategoriesChart))
        ->toMatchArray([
            'labels' => ['Birinchi kategoriya'],
            'data'   => [1],
        ])
        ->and(statisticsCardValues($secondUser))->toMatchArray([
            'Umumiy sotuvlar' => "160,000 so'm",
            'Foyda'           => "60,000 so'm",
        ]);

    auth()->login($firstUser);
    app(ReturnService::class)->handle([
        'product_id' => $firstExchangeProduct->id,
        'stock_id'   => $firstStock->id,
        'quantity'   => 1,
        'price'      => 120_000,
    ]);

    expect(statisticsCardValues($firstUser))->toMatchArray([
        'Umumiy sotuvlar' => "0 so'm",
        'Foyda'           => "0 so'm",
    ])
        ->and(statisticsChartData($firstUser, new TopSellingProductsChart))
        ->toMatchArray([
            'labels' => [],
            'data'   => [],
        ])
        ->and(statisticsChartData($firstUser, new TopSellingCategoriesChart))
        ->toMatchArray([
            'labels' => [],
            'data'   => [],
        ])
        ->and(statisticsCardValues($secondUser))->toMatchArray([
            'Umumiy sotuvlar' => "160,000 so'm",
            'Foyda'           => "60,000 so'm",
        ]);
});

/**
 * @return array{0: Store, 1: Stock, 2: User}
 */
function createStatisticsStoreContext(string $name): array
{
    $store = Store::query()->create([
        'name'    => $name,
        'address' => fake()->address(),
        'phone'   => fake()->unique()->phoneNumber(),
    ]);
    $stock = Stock::withoutGlobalScopes()->create([
        'name' => "{$name} ombori",
    ]);
    $store->stocks()->attach($stock);
    $user = User::factory()->create([
        'current_store_id' => $store->id,
    ]);

    return [$store, $stock, $user];
}

function createStatisticsProduct(
    Store $store,
    Stock $stock,
    string $name,
    int $initialPrice,
    int $price,
    int $quantity,
    ?Category $category = null,
): Product {
    $product = Product::withoutGlobalScopes()->create([
        'store_id'      => $store->id,
        'name'          => $name,
        'type'          => Product::TYPE_PACKAGE,
        'category_id'   => $category?->id,
        'initial_price' => $initialPrice,
        'price'         => $price,
    ]);
    ProductStock::query()->create([
        'product_id' => $product->id,
        'stock_id'   => $stock->id,
        'quantity'   => $quantity,
    ]);

    return $product;
}

function createStatisticsCategory(string $name): Category
{
    return Category::query()->create([
        'name'      => $name,
        'is_active' => true,
    ]);
}

function createStatisticsDiscount(Store $store, int $percent): Discount
{
    return Discount::withoutGlobalScopes()->create([
        'store_id'  => $store->id,
        'name'      => 'Barcha tovarlarga chegirma',
        'type'      => DiscountType::GlobalPercent,
        'percent'   => $percent,
        'is_active' => true,
    ]);
}

function createDiscountedStatisticsSale(
    User $user,
    Store $store,
    Stock $stock,
    Product $product,
    string $paymentType,
): Sale {
    auth()->login($user);
    $summary = app(DiscountService::class)->calculate([
        ['product_id' => $product->id, 'quantity' => 1, 'price' => $product->price],
    ]);

    $sale = Sale::query()->create([
        'store_id'               => $store->id,
        'cart_id'                => fake()->unique()->numberBetween(1, 1_000_000),
        'subtotal_amount'        => $summary['subtotal'],
        'total_amount'           => $summary['total'],
        'product_discount_total' => $summary['product_discount_total'],
        'order_discount_total'   => $summary['order_discount_total'],
        'discount_total'         => $summary['discount_total'],
        'applied_discounts'      => $summary['applied_discounts'],
        'paid_amount'            => $paymentType === 'debt' ? 0 : $summary['total'],
        'remaining_amount'       => $paymentType === 'debt' ? $summary['total'] : 0,
        'payment_type'           => $paymentType,
        'status'                 => Sale::STATUS_COMPLETED,
        'created_by'             => $user->id,
    ]);
    $item = $summary['items'][0];
    SaleItem::query()->create([
        'sale_id'                => $sale->id,
        'product_id'             => $product->id,
        'stock_id'               => $stock->id,
        'quantity'               => 1,
        'price'                  => $product->price,
        'subtotal_amount'        => $item['subtotal'],
        'product_discount_total' => $item['product_discount_total'],
        'total'                  => $item['total'],
        'applied_discounts'      => $item['applied_discounts'],
    ]);

    return $sale->refresh();
}

function createIgnoredStatisticsSale(
    Store $store,
    Stock $stock,
    Product $product,
    string $status,
    int $total,
    int $quantity,
    ?DateTimeInterface $createdAt = null,
): void {
    $sale = Sale::withoutGlobalScopes()->create([
        'store_id'     => $store->id,
        'cart_id'      => fake()->unique()->numberBetween(1, 1_000_000),
        'total_amount' => $total,
        'payment_type' => 'cash',
        'status'       => $status,
        'created_at'   => $createdAt ?? now(),
        'updated_at'   => $createdAt ?? now(),
    ]);
    SaleItem::withoutGlobalScopes()->create([
        'sale_id'    => $sale->id,
        'product_id' => $product->id,
        'stock_id'   => $stock->id,
        'quantity'   => $quantity,
        'price'      => $product->price,
        'total'      => $total,
        'created_at' => $createdAt ?? now(),
        'updated_at' => $createdAt ?? now(),
    ]);
}

function createStatisticsExpense(Store $store, User $user, int $amount): void
{
    Expense::withoutGlobalScopes()->create([
        'store_id'   => $store->id,
        'created_by' => $user->id,
        'amount'     => $amount,
        'date'       => now(),
    ]);
}

function createStatisticsPurchase(
    Store $store,
    Stock $stock,
    User $user,
    Product $product,
    int $total,
    string $paymentType,
    int $quantity,
): void {
    $supplier = Supplier::query()->create([
        'full_name' => fake()->name(),
    ]);
    $purchase = Purchase::withoutGlobalScopes()->create([
        'supplier_id'      => $supplier->id,
        'store_id'         => $store->id,
        'stock_id'         => $stock->id,
        'created_by'       => $user->id,
        'purchase_date'    => today(),
        'payment_type'     => $paymentType,
        'total_amount'     => $total,
        'paid_amount'      => $paymentType === 'debt' ? 0 : $total,
        'remaining_amount' => $paymentType === 'debt' ? $total : 0,
    ]);
    PurchaseItem::withoutGlobalScopes()->create([
        'purchase_id' => $purchase->id,
        'product_id'  => $product->id,
        'stock_id'    => $stock->id,
        'quantity'    => $quantity,
        'unit_cost'   => $total / $quantity,
        'total_cost'  => $total,
    ]);
}

/**
 * @return array<string, string>
 */
function statisticsCardValues(User $user): array
{
    auth()->login($user);
    $widget = new SalesStatsOverview;

    /** @var array<int, Stat> $cards */
    $cards = (function (): array {
        return $this->getCards();
    })->call($widget);

    return collect($cards)
        ->mapWithKeys(fn (Stat $card): array => [(string) $card->getLabel() => (string) $card->getValue()])
        ->all();
}

/**
 * @return array{labels: array<int, string>, data: array<int, int>}
 */
function statisticsChartData(
    User $user,
    TopSellingProductsChart|TopSellingCategoriesChart|TopPurchasedProductsChart $widget,
): array {
    auth()->login($user);
    $data = (function (): array {
        return $this->getData();
    })->call($widget);

    return [
        'labels' => array_values($data['labels']),
        'data'   => collect($data['datasets'][0]['data'])->map(fn (mixed $value): int => (int) $value)->values()->all(),
    ];
}
