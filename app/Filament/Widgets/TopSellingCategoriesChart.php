<?php

namespace App\Filament\Widgets;

use App\Models\Product;
use App\Models\Category;
use Livewire\Attributes\On;
use Illuminate\Support\Carbon;
use Filament\Widgets\ChartWidget;
use App\Services\NetSalesQuantityService;
use Filament\Forms\Concerns\InteractsWithForms;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;

class TopSellingCategoriesChart extends ChartWidget
{
    use HasWidgetShield, InteractsWithForms;

    protected ?string $pollingInterval = '30s';

    public ?string $start_date = null;
    public ?string $end_date   = null;

    protected ?string $heading = 'Top 10 sotilgan kategoriyalar';

    public function getColumnSpan(): int|string|array
    {
        return 'full';
    }

    #[On('refreshStats')]
    public function updateFilters($start_date, $end_date): void
    {
        $this->start_date = $start_date;
        $this->end_date   = $end_date;
    }

    protected function getData(): array
    {
        $start = Carbon::parse($this->start_date ?? now())->startOfDay();
        $end   = Carbon::parse($this->end_date ?? now())->endOfDay();

        $productQuantities = app(NetSalesQuantityService::class)->forPeriod($start, $end);

        $categoryIdsByProduct = Product::query()
            ->withTrashed()
            ->whereIn('id', $productQuantities->keys())
            ->pluck('category_id', 'id');

        $categoryQuantities = collect();

        foreach ($productQuantities as $productId => $quantity) {
            $categoryId = (int) ($categoryIdsByProduct->get($productId) ?? 0);
            $categoryQuantities->put(
                $categoryId,
                (int) $categoryQuantities->get($categoryId, 0) + $quantity,
            );
        }

        $topQuantities = $categoryQuantities
            ->sortDesc()
            ->take(10);

        $categoryNames = Category::query()
            ->whereIn('id', $topQuantities->keys()->filter())
            ->pluck('name', 'id');

        return [
            'datasets' => [
                [
                    'label'           => 'Sotilgan soni',
                    'data'            => $topQuantities->values(),
                    'backgroundColor' => '#f59e0b',
                ],
            ],
            'labels' => $topQuantities
                ->keys()
                ->map(fn (int $categoryId): string => $categoryId === 0
                    ? 'Kategoriyasiz'
                    : $categoryNames->get($categoryId, 'Noma’lum'))
                ->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
