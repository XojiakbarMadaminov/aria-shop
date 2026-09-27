<?php

namespace App\Filament\Widgets;

use App\Models\Sale;
use App\Models\Expense;
use App\Models\Purchase;
use App\Models\SaleItem;
use Livewire\Attributes\On;
use Illuminate\Support\Carbon;
use App\Models\CashTransaction;
use App\Models\InventoryAdjustment;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Filament\Forms\Concerns\InteractsWithForms;
use BezhanSalleh\FilamentShield\Traits\HasWidgetShield;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;

class SalesStatsOverview extends BaseWidget
{
    use HasWidgetShield, InteractsWithForms;

    protected ?string $pollingInterval = '30s';
    protected int|array|null $columns  = 3;

    public ?string $start_date = null;
    public ?string $end_date   = null;

    #[On('refreshStats')]
    public function updateFilters($start_date, $end_date)
    {
        $this->start_date = $start_date;
        $this->end_date   = $end_date;
    }

    protected function getCards(): array
    {
        $start = Carbon::parse($this->start_date ?? now())->startOfDay();
        $end   = Carbon::parse($this->end_date ?? now())->endOfDay();

        $sales = Sale::whereBetween('created_at', [$start, $end])
            ->where('status', Sale::STATUS_COMPLETED)
            ->get();
        $totalSales = $sales->sum('total_amount');

        $cashSales = $sales->sum(fn (Sale $sale): float => match ($sale->payment_type) {
            'cash'  => (float) $sale->total_amount,
            'mixed' => (float) $sale->mixed_cash_amount,
            default => 0.0,
        });

        $cardSales = $sales->sum(fn (Sale $sale): float => match ($sale->payment_type) {
            'card'  => (float) $sale->total_amount,
            'mixed' => (float) $sale->mixed_card_amount,
            default => 0.0,
        });

        $totalExpenses = Expense::query()
            ->whereBetween('date', [$start, $end])
            ->sum('amount');

        $debtSales = $sales
            ->where('payment_type', 'debt')
            ->sum('total_amount');

        $totalCost = SaleItem::whereIn('sale_items.sale_id', $sales->pluck('id'))
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->selectRaw('COALESCE(SUM(COALESCE(products.initial_price, 0) * sale_items.quantity), 0) AS cost')
            ->value('cost');

        $totalProfit = $totalSales - (float) ($totalCost ?? 0);

        $purchases = Purchase::query()
            ->whereBetween('purchase_date', [$start, $end])
            ->get();

        $returnRevenue = CashTransaction::query()
            ->where('reason', CashTransaction::REASON_RETURN)
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');

        $returnCost = InventoryAdjustment::query()
            ->where('adjustment_type', InventoryAdjustment::TYPE_RETURN)
            ->whereBetween('inventory_adjustments.created_at', [$start, $end])
            ->join('products', 'products.id', '=', 'inventory_adjustments.product_id')
            ->selectRaw('COALESCE(SUM(COALESCE(products.initial_price, 0) * inventory_adjustments.quantity), 0) as total')
            ->value('total') ?? 0;

        $exchangeInRevenue = InventoryAdjustment::query()
            ->where('adjustment_type', InventoryAdjustment::TYPE_EXCHANGE_IN)
            ->whereBetween('inventory_adjustments.created_at', [$start, $end])
            ->selectRaw('COALESCE(SUM(unit_price * inventory_adjustments.quantity), 0) as total')
            ->value('total') ?? 0;

        $exchangeInCost = InventoryAdjustment::query()
            ->where('adjustment_type', InventoryAdjustment::TYPE_EXCHANGE_IN)
            ->whereBetween('inventory_adjustments.created_at', [$start, $end])
            ->join('products', 'products.id', '=', 'inventory_adjustments.product_id')
            ->selectRaw('COALESCE(SUM(COALESCE(products.initial_price, 0) * inventory_adjustments.quantity), 0) as total')
            ->value('total') ?? 0;

        $exchangeOutRevenue = InventoryAdjustment::query()
            ->where('adjustment_type', InventoryAdjustment::TYPE_EXCHANGE_OUT)
            ->whereBetween('inventory_adjustments.created_at', [$start, $end])
            ->selectRaw('COALESCE(SUM(unit_price * ABS(inventory_adjustments.quantity)), 0) as total')
            ->value('total') ?? 0;

        $exchangeOutCost = InventoryAdjustment::query()
            ->where('adjustment_type', InventoryAdjustment::TYPE_EXCHANGE_OUT)
            ->whereBetween('inventory_adjustments.created_at', [$start, $end])
            ->join('products', 'products.id', '=', 'inventory_adjustments.product_id')
            ->selectRaw('COALESCE(SUM(COALESCE(products.initial_price, 0) * ABS(inventory_adjustments.quantity)), 0) as total')
            ->value('total') ?? 0;

        $returnProfitImpact     = $returnRevenue - $returnCost;
        $exchangeInProfitImpact = $exchangeInRevenue - $exchangeInCost;
        $exchangeOutProfit      = $exchangeOutRevenue - $exchangeOutCost;

        $netSales = $totalSales - $returnRevenue - $exchangeInRevenue + $exchangeOutRevenue;

        $netProfit = $totalProfit
            - $returnProfitImpact
            - $exchangeInProfitImpact
            + $exchangeOutProfit;

        $totalPurchases = $purchases->sum('total_amount');

        $debtPurchases = $purchases
            ->where('payment_type', 'debt')
            ->sum('total_amount');

        return [
            Stat::make('Umumiy sotuvlar', number_format($netSales) . " so'm")
                ->description(
                    'Naqd: ' . number_format($cashSales) . " so'm · "
                    . 'Karta: ' . number_format($cardSales) . " so'm"
                )
                ->icon('heroicon-o-wallet')
                ->color('success'),
            Stat::make('Foyda', number_format($netProfit) . " so'm")
                ->description('Tanlangan davr uchun foyda')
                ->icon('heroicon-o-wallet')
                ->color('primary'),
            Stat::make('Qarzga sotuvlar', number_format($debtSales) . " so'm")
                ->icon('heroicon-o-wallet')
                ->description('Tanlangan davr uchun qarzga sotuvlar')
                ->color('warning'),
            Stat::make('Xarajatlar', number_format($totalExpenses) . " so'm")
                ->description('Tanlangan davr uchun xarajatlar')
                ->icon('heroicon-o-wallet')
                ->color('danger'),
            Stat::make('Ta\'minotchidan jami xaridlar', number_format($totalPurchases) . " so'm")
                ->description('Tanlangan davr uchun xaridlar')
                ->icon('heroicon-o-wallet')
                ->color('success'),
            Stat::make('Ta\'minotchidan qarzga xaridlar', number_format($debtPurchases) . " so'm")
                ->description('Tanlangan davr uchun qarzga xaridlar')
                ->icon('heroicon-o-wallet')
                ->color('warning'),
        ];
    }
}
