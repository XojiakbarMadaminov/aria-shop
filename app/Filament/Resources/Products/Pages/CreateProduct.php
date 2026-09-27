<?php

namespace App\Filament\Resources\Products\Pages;

use App\Models\Product;
use App\Models\Category;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Component;
use App\Filament\Resources\Products\ProductResource;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;
    protected $sizesData;
    protected $packageStockData;

    protected ?int $createdProductIdForPrint = null;

    public function getMaxContentWidth(): Width
    {
        return Width::SevenExtraLarge;
    }

    public function getFormActionsContentComponent(): Component
    {
        return parent::getFormActionsContentComponent()
            ->key('productCreateFormActions');
    }

    protected function getCreateAnotherFormAction(): Action
    {
        return Action::make('createAndPrint')
            ->label('Yaratish va chop etish')
            ->icon('heroicon-o-printer')
            ->color('gray')
            ->modalHeading('Shtrix-kodni chop etish')
            ->modalSubmitActionLabel('Yaratish va chop etish')
            ->schema([
                TextInput::make('copies')
                    ->label('Chop etish soni')
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->required(),
            ])
            ->mountUsing(function (Schema $schema): void {
                $schema->fill([
                    'copies' => max(1, $this->getEnteredQuantity()),
                ]);
            })
            ->action(function (array $data): void {
                $this->createdProductIdForPrint = null;
                $this->create(another: true);

                if (!$this->createdProductIdForPrint) {
                    return;
                }

                $this->redirect(route('product.barcode.pdf', [
                    'product' => $this->createdProductIdForPrint,
                    'size'    => '57x30',
                    'copies'  => $data['copies'],
                ]));
            })
            ->keyBindings(['mod+shift+s']);
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['name']     = Category::query()->findOrFail($data['category_id'])->name;
        $data['store_id'] = auth()->user()?->current_store_id;
        $data['type']     = Product::TYPE_PACKAGE;

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

    protected function afterCreate(): void
    {
        $product = $this->record;

        if (!$product->isPackageBased()) {
            foreach ($this->sizesData as $sizeRow) {
                $size = $product->sizes()->create(['size' => $sizeRow['size']]);

                foreach ($sizeRow as $key => $val) {
                    if (str_starts_with($key, 'stock_') && $val !== null) {
                        $stockId = (int) str_replace('stock_', '', $key);
                        // Save into unified product_stocks table
                        $size->productStocks()->create([
                            'stock_id' => $stockId,
                            'quantity' => (int) $val,
                        ]);
                    }
                }
            }
        } else {
            // package based: create product-level stock rows
            foreach ($this->packageStockData as $key => $val) {
                if ($val === null) {
                    continue;
                }
                $stockId = (int) str_replace('pkg_stock_', '', $key);
                $product->productStocks()->create([
                    'stock_id' => $stockId,
                    'quantity' => (int) $val,
                ]);
            }
        }

        $this->createdProductIdForPrint = $product->getKey();
    }

    protected function getEnteredQuantity(): int
    {
        $state = $this->form->getRawState();

        return (int) collect($state)
            ->filter(fn (mixed $value, string $key): bool => str_starts_with($key, 'pkg_stock_'))
            ->sum(fn (mixed $value): int => (int) $value);
    }
}
