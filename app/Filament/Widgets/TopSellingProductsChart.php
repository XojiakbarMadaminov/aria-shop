<?php

namespace App\Filament\Widgets;

use App\Models\Product;
use Livewire\Attributes\On;
use Illuminate\Support\Carbon;
use Filament\Widgets\ChartWidget;
use App\Services\NetSalesQuantityService;
use Filament\Forms\Concerns\InteractsWithForms;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;

class TopSellingProductsChart extends ChartWidget
{
    use HasWidgetShield, InteractsWithForms;

    protected ?string $pollingInterval = '30s';

    public ?string $start_date = null;
    public ?string $end_date   = null;

    protected ?string $heading = 'Top 10 sotilgan tovarlar';

    public function getColumnSpan(): int|string|array
    {
        return 'full';
    }

    #[On('refreshStats')]
    public function updateFilters($start_date, $end_date)
    {
        $this->start_date = $start_date;
        $this->end_date   = $end_date;
    }

    protected function getData(): array
    {
        $start = Carbon::parse($this->start_date ?? now())->startOfDay();
        $end   = Carbon::parse($this->end_date ?? now())->endOfDay();

        $topQuantities = app(NetSalesQuantityService::class)
            ->forPeriod($start, $end)
            ->sortDesc()
            ->take(10);

        $productNames = Product::query()
            ->withTrashed()
            ->whereIn('id', $topQuantities->keys())
            ->pluck('name', 'id');

        return [
            'datasets' => [
                [
                    'label' => 'Sotilgan soni',
                    'data'  => $topQuantities->values(),
                ],
            ],
            'labels' => $topQuantities
                ->keys()
                ->map(fn (int $productId): string => $productNames->get($productId, 'Noma’lum'))
                ->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
