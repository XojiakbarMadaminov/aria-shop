<?php

namespace App\Filament\Resources\Products\Pages;

use App\Models\Stock;
use App\Models\Category;
use App\Models\ProductStock;
use Filament\Actions\ViewAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Resources\Pages\EditRecord;
use App\Filament\Resources\Products\ProductResource;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;
    protected $stocks;
    protected $sizesData;
    protected $packageStockData;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $product = $this->record;
        $stocks  = Stock::all();

        if (!$product->isPackageBased()) {
            $data['sizes'] = $product->sizes()
                ->with('productStocks')
                ->get()
                ->map(function ($size) use ($stocks) {
                    $row = ['size' => $size->size];
                    foreach ($stocks as $stock) {
                        $row["stock_{$stock->id}"] = $size->productStocks
                            ->firstWhere('stock_id', $stock->id)?->quantity ?? 0;
                    }

                    return $row;
                })
                ->values()
                ->toArray();
        } else {
            foreach ($stocks as $stock) {
                $qty = ProductStock::whereNull('product_size_id')
                    ->where('product_id', $product->id)
                    ->where('stock_id', $stock->id)
                    ->value('quantity') ?? 0;
                $data["pkg_stock_{$stock->id}"] = $qty;
            }
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['name'] = Category::query()->findOrFail($data['category_id'])->name;

        $this->sizesData        = $data['sizes'] ?? [];
        $this->packageStockData = collect($data)
            ->filter(fn ($v, $k) => str_starts_with($k, 'pkg_stock_'))
            ->all();

        unset($data['sizes']);
        foreach (array_keys($this->packageStockData) as $k) {
            unset($data[$k]);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $product = $this->record;

        if (!$product->isPackageBased()) {
            $currentSizeRows = collect($this->sizesData);

            $currentSizes  = $currentSizeRows->pluck('size')->toArray();
            $existingSizes = $product->sizes()->pluck('size')->toArray();
            $deletedSizes  = array_diff($existingSizes, $currentSizes);

            if (!empty($deletedSizes)) {
                $product->sizes()
                    ->whereIn('size', $deletedSizes)
                    ->each(function ($size) {
                        $size->productStocks()->delete();
                        $size->delete();
                    });
            }

            foreach ($this->sizesData as $sizeRow) {
                $sizeModel = $product->sizes()->updateOrCreate(
                    ['size' => $sizeRow['size']],
                    []
                );

                foreach ($sizeRow as $key => $value) {
                    if (str_starts_with($key, 'stock_')) {
                        $stockId = (int) str_replace('stock_', '', $key);
                        $sizeModel->productStocks()->updateOrCreate(
                            ['stock_id' => $stockId],
                            ['quantity' => (int) $value]
                        );
                    }
                }
            }
        } else {
            foreach ($this->packageStockData as $key => $val) {
                $stockId = (int) str_replace('pkg_stock_', '', $key);

                ProductStock::updateOrCreate(
                    [
                        'product_id'      => $product->id,
                        'product_size_id' => null,
                        'stock_id'        => $stockId,
                    ],
                    [
                        'quantity' => (int) ($val ?? 0),
                    ]
                );
            }
        }
    }
}
