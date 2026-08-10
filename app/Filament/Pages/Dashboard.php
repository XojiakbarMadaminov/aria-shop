<?php

namespace App\Filament\Pages;

use UnitEnum;
use App\Enums\NavigationGroup;
use Filament\Support\Icons\Heroicon;
use Filament\Schemas\Components\Form;
use Filament\Forms\Components\DatePicker;
use App\Filament\Widgets\SalesStatsOverview;
use Filament\Pages\Dashboard as BaseDashboard;
use App\Filament\Widgets\TopSellingProductsChart;
use App\Filament\Widgets\TopPurchasedProductsChart;
use App\Filament\Widgets\TopSellingCategoriesChart;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;

class Dashboard extends BaseDashboard
{
    use HasPageShield;

    protected static string|UnitEnum|null $navigationGroup   = NavigationGroup::Statistics;
    protected static ?string $navigationLabel                = 'Statistika';
    protected static string|null|\BackedEnum $navigationIcon = Heroicon::ChartBar;
    protected static ?int $navigationSort                    = 1;
    protected static string $routePath                       = '/dashboard';

    protected string $view     = 'filament.pages.dashboard';
    public ?string $start_date = null;
    public ?string $end_date   = null;

    public function getFooterWidgets(): array
    {
        return [
            SalesStatsOverview::class,
            TopSellingProductsChart::class,
            TopSellingCategoriesChart::class,
            TopPurchasedProductsChart::class,
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }

    public function mount()
    {
        $this->start_date = now()->startOfDay()->format('Y-m-d');
        $this->end_date   = now()->endOfDay()->format('Y-m-d');
    }

    public function updateStats()
    {
        $this->dispatch('refreshStats', start_date: $this->start_date, end_date: $this->end_date);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            DatePicker::make('start_date')
                ->label('Boshlanish sanasi')
                ->default(now()->subDay()->format('Y-m-d'))
                ->reactive()
                ->afterStateUpdated(fn ($state) => $this->updateStats()),

            DatePicker::make('end_date')
                ->label('Tugash sanasi')
                ->default(now()->format('Y-m-d'))
                ->reactive()
                ->afterStateUpdated(fn ($state) => $this->updateStats()),
        ]);
    }
}
