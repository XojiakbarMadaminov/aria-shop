<?php

use App\Models\Sale;
use App\Models\Stock;
use App\Models\Store;
use App\Models\Product;
use App\Models\SaleItem;
use Illuminate\Support\Carbon;
use App\Services\TelegramWeeklySummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('calculates weekly store metrics and comparison accurately', function () {
    $store = Store::create([
        'name'    => 'Chilonzor Filiali',
        'address' => 'Chilonzor 9-kvartal',
        'phone'   => '+998901234567',
    ]);

    $stock = Stock::create([
        'name'      => 'Chilonzor ombori',
        'is_main'   => true,
        'is_active' => true,
    ]);

    $product = Product::factory()->create([
        'name'          => 'Nike Air Max',
        'initial_price' => 150000,
        'price'         => 250000,
    ]);

    $referenceDate = Carbon::parse('2026-08-17 10:00:00', 'Asia/Tashkent'); // Monday
    // Current report week: 2026-08-10 to 2026-08-16
    $currDate = Carbon::parse('2026-08-12 14:00:00', 'Asia/Tashkent');
    // Previous comparison week: 2026-08-03 to 2026-08-09
    $prevDate = Carbon::parse('2026-08-05 14:00:00', 'Asia/Tashkent');

    // Create a sale in current week
    $saleCurr = Sale::withoutEvents(fn (): Sale => Sale::create([
        'cart_id'          => 1,
        'store_id'         => $store->id,
        'total_amount'     => 500000,
        'subtotal_amount'  => 500000,
        'paid_amount'      => 500000,
        'remaining_amount' => 0,
        'payment_type'     => 'cash',
        'status'           => Sale::STATUS_COMPLETED,
        'created_at'       => $currDate,
        'updated_at'       => $currDate,
    ]));

    SaleItem::create([
        'sale_id'         => $saleCurr->id,
        'product_id'      => $product->id,
        'stock_id'        => $stock->id,
        'quantity'        => 2,
        'price'           => 250000,
        'subtotal_amount' => 500000,
        'total'           => 500000,
        'created_at'      => $currDate,
        'updated_at'      => $currDate,
    ]);

    // Create a sale in previous week
    $salePrev = Sale::withoutEvents(fn (): Sale => Sale::create([
        'cart_id'          => 2,
        'store_id'         => $store->id,
        'total_amount'     => 250000,
        'subtotal_amount'  => 250000,
        'paid_amount'      => 250000,
        'remaining_amount' => 0,
        'payment_type'     => 'cash',
        'status'           => Sale::STATUS_COMPLETED,
        'created_at'       => $prevDate,
        'updated_at'       => $prevDate,
    ]));

    SaleItem::create([
        'sale_id'         => $salePrev->id,
        'product_id'      => $product->id,
        'stock_id'        => $stock->id,
        'quantity'        => 1,
        'price'           => 250000,
        'subtotal_amount' => 250000,
        'total'           => 250000,
        'created_at'      => $prevDate,
        'updated_at'      => $prevDate,
    ]);

    $service = app(TelegramWeeklySummaryService::class);

    $currStart = $referenceDate->copy()->subWeek()->startOfWeek();
    $currEnd   = $referenceDate->copy()->subWeek()->endOfWeek();
    $prevStart = $referenceDate->copy()->subWeeks(2)->startOfWeek();
    $prevEnd   = $referenceDate->copy()->subWeeks(2)->endOfWeek();

    $currentMetrics  = $service->collectStoreMetrics($currStart, $currEnd, $store);
    $previousMetrics = $service->collectStoreMetrics($prevStart, $prevEnd, $store);

    expect($currentMetrics['sales'])->toEqual(500000.0)
        ->and($currentMetrics['count'])->toBe(1)
        ->and($currentMetrics['profit'])->toEqual(200000.0) // 500k - (2 * 150k) = 200k
        ->and($previousMetrics['sales'])->toEqual(250000.0)
        ->and($previousMetrics['count'])->toBe(1)
        ->and($previousMetrics['profit'])->toEqual(100000.0); // 250k - (1 * 150k) = 100k

    $comparison = $service->calculateComparison($currentMetrics, $previousMetrics);

    expect($comparison['sales_diff'])->toEqual(250000.0)
        ->and($comparison['sales_percent'])->toEqual(100.0)
        ->and($comparison['profit_diff'])->toEqual(100000.0)
        ->and($comparison['profit_percent'])->toEqual(100.0);
});

it('generates motivational quotes dynamically depending on profit change', function () {
    $service = app(TelegramWeeklySummaryService::class);

    $growthQuote = $service->getRandomMotivation(200000.0, 100000.0);
    expect($growthQuote)->toBeString()->not->toBeEmpty();

    $dropQuote = $service->getRandomMotivation(50000.0, 100000.0);
    expect($dropQuote)->toBeString()->not->toBeEmpty();

    $flatQuote = $service->getRandomMotivation(100000.0, 100000.0);
    expect($flatQuote)->toBeString()->not->toBeEmpty();
});

it('formats telegram html message with store metrics and badges', function () {
    $store = Store::create([
        'name'    => 'Samarqand Filiali',
        'address' => 'Samarqand shahar',
        'phone'   => '+998901234568',
    ]);

    $service = app(TelegramWeeklySummaryService::class);

    $summaries = collect([
        [
            'store'      => $store,
            'current'    => ['sales' => 10000000.0, 'profit' => 3000000.0, 'count' => 20],
            'previous'   => ['sales' => 8000000.0, 'profit' => 2000000.0, 'count' => 15],
            'comparison' => [
                'sales_diff'     => 2000000.0,
                'sales_percent'  => 25.0,
                'profit_diff'    => 1000000.0,
                'profit_percent' => 50.0,
            ],
            'motivation' => 'Ajoyib natija!',
        ],
    ]);

    $message = $service->formatMessage(
        $summaries,
        Carbon::parse('2026-08-10'),
        Carbon::parse('2026-08-16')
    );

    expect($message)
        ->toContain('HAFTALIK SAVDO VA FOYDA HISOBOTI')
        ->toContain('Samarqand Filiali')
        ->toContain('10 000 000')
        ->toContain('+25.0%')
        ->toContain('3 000 000')
        ->toContain('+50.0%')
        ->toContain('Ajoyib natija!');
});

it('fails gracefully when telegram settings are missing and handles invalid date option', function () {
    $this->artisan('telegram:send-weekly-summary')
        ->assertFailed();

    $this->artisan('telegram:send-weekly-summary --date=not-a-valid-date')
        ->expectsOutput("Noto'g'ri sana formati berildi. Format: YYYY-MM-DD")
        ->assertExitCode(2);
});

it('succeeds when summaryService sends report successfully', function () {
    $mock = Mockery::mock(TelegramWeeklySummaryService::class);
    $mock->shouldReceive('sendForDate')
        ->once()
        ->andReturn(true);

    $this->app->instance(TelegramWeeklySummaryService::class, $mock);

    $this->artisan('telegram:send-weekly-summary --date=2026-08-17')
        ->expectsOutput("Haftalik hisobot Telegram'ga yuborildi (Sana: 17.08.2026).")
        ->assertSuccessful();
});
