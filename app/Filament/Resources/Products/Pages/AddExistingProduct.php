<?php

namespace App\Filament\Resources\Products\Pages;

use App\Models\Stock;
use App\Models\Product;
use App\Models\ProductStock;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\DB;
use Filament\Forms\Components\Select;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Forms\Concerns\InteractsWithForms;
use App\Filament\Resources\Products\ProductResource;

class AddExistingProduct extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = ProductResource::class;

    protected static ?string $title = 'Mavjud tovarga qo‘shish';

    protected string $view = 'filament.resources.products.pages.add-existing-product';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public ?int $productId = null;

    public ?string $searchedBarcode = null;

    public function mount(): void
    {
        abort_unless(ProductResource::canCreate(), 403);

        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Tovarni qidirish')
                    ->description('Mavjud tovarning bar kodini kiriting va qidiruv tugmasini bosing.')
                    ->schema([
                        TextInput::make('barcode')
                            ->label('Bar kod')
                            ->placeholder('Bar kodni kiriting')
                            ->tel()
                            ->maxLength(32)
                            ->rule('regex:/^[0-9]+$/')
                            ->required()
                            ->autofocus()
                            ->suffixAction(
                                Action::make('searchProduct')
                                    ->label('Qidirish')
                                    ->icon('heroicon-o-magnifying-glass')
                                    ->button()
                                    ->action(function (): void {
                                        $this->searchProduct();
                                    })
                            ),
                    ]),
                Section::make('Tovar ma’lumotlari')
                    ->visible(fn (): bool => filled($this->productId))
                    ->schema([
                        TextInput::make('name')
                            ->label('Nomi')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('category_name')
                            ->label('Kategoriya')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('initial_price')
                            ->label('Kelgan narxi')
                            ->suffix('so‘m')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('price')
                            ->label('Sotish narxi')
                            ->suffix('so‘m')
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->columns([
                        'default' => 1,
                        'md'      => 2,
                    ]),
                Section::make('Miqdori')
                    ->visible(fn (): bool => filled($this->productId))
                    ->schema([
                        Select::make('stock_id')
                            ->label('Ombor')
                            ->options(fn (): array => $this->stockOptions())
                            ->live()
                            ->afterStateUpdated(function (mixed $state): void {
                                $this->refreshCurrentQuantity($state);
                            })
                            ->required(),
                        TextInput::make('current_quantity')
                            ->label('Hozir mavjud')
                            ->suffix('dona')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('quantity')
                            ->label('Qo‘shiladigan miqdor')
                            ->placeholder('0')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->suffix('dona')
                            ->required(),
                    ])
                    ->columns([
                        'default' => 1,
                        'md'      => 3,
                    ]),
            ])
            ->statePath('data');
    }

    public function searchProduct(): void
    {
        $barcode = trim((string) ($this->form->getRawState()['barcode'] ?? ''));

        $this->resetErrorBag('data.barcode');

        if ($barcode === '' || !ctype_digit($barcode)) {
            $this->addError('data.barcode', 'Faqat raqamlardan iborat bar kod kiriting.');

            return;
        }

        $storeId = auth()->user()?->current_store_id;

        $product = Product::query()
            ->withoutGlobalScopes()
            ->with('category')
            ->where('store_id', $storeId)
            ->where('barcode', $barcode)
            ->whereNull('deleted_at')
            ->first();

        if (!$product) {
            $this->clearFoundProduct($barcode);

            Notification::make()
                ->warning()
                ->title('Tovar topilmadi')
                ->body('Ushbu bar kod bo‘yicha joriy filialda tovar topilmadi.')
                ->send();

            return;
        }

        if (!$product->isPackageBased()) {
            $this->clearFoundProduct($barcode);

            Notification::make()
                ->warning()
                ->title('Tovar paket turida emas')
                ->body('Razmerli yoki rangli tovar qoldig‘ini tahrirlash sahifasidan o‘zgartiring.')
                ->send();

            return;
        }

        $stockId = array_key_first($this->stockOptions());

        if (!$stockId) {
            Notification::make()
                ->danger()
                ->title('Ombor topilmadi')
                ->body('Joriy filialga biriktirilgan faol ombor mavjud emas.')
                ->send();

            return;
        }

        $this->productId       = (int) $product->getKey();
        $this->searchedBarcode = $barcode;

        $this->form->fill([
            'barcode'          => $barcode,
            'name'             => $product->name,
            'category_name'    => $product->category?->name,
            'initial_price'    => $product->initial_price,
            'price'            => $product->price,
            'stock_id'         => $stockId,
            'current_quantity' => $this->currentQuantity((int) $stockId),
            'quantity'         => null,
        ]);
    }

    public function save(): void
    {
        $this->addQuantity(shouldPrint: false);
    }

    public function saveAndPrint(): void
    {
        $this->addQuantity(shouldPrint: true);
    }

    /** @return array<int, string> */
    protected function stockOptions(): array
    {
        $storeId = auth()->user()?->current_store_id;

        return Stock::query()
            ->withoutGlobalScopes()
            ->scopes('active')
            ->whereHas('stores', fn ($query) => $query->where('stores.id', $storeId))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    protected function refreshCurrentQuantity(mixed $stockId): void
    {
        $this->data['current_quantity'] = $this->currentQuantity((int) $stockId);
    }

    protected function currentQuantity(int $stockId): int
    {
        if (!$this->productId || !$stockId) {
            return 0;
        }

        return (int) ProductStock::query()
            ->where('product_id', $this->productId)
            ->whereNull('product_size_id')
            ->where('stock_id', $stockId)
            ->value('quantity');
    }

    protected function addQuantity(bool $shouldPrint): void
    {
        if (!$this->productId || !$this->searchedBarcode) {
            Notification::make()
                ->warning()
                ->title('Avval tovarni qidiring')
                ->send();

            return;
        }

        $data     = $this->form->getState();
        $barcode  = trim((string) ($data['barcode'] ?? ''));
        $stockId  = (int) $data['stock_id'];
        $quantity = (int) $data['quantity'];
        $storeId  = (int) auth()->user()?->current_store_id;

        if ($barcode !== $this->searchedBarcode) {
            $this->addError('data.barcode', 'Bar kod o‘zgargan. Tovarni qayta qidiring.');

            return;
        }

        $newQuantity = DB::transaction(function () use ($storeId, $stockId, $quantity): int {
            $product = Product::query()
                ->withoutGlobalScopes()
                ->whereKey($this->productId)
                ->where('store_id', $storeId)
                ->where('barcode', $this->searchedBarcode)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->firstOrFail();

            Stock::query()
                ->withoutGlobalScopes()
                ->whereKey($stockId)
                ->whereHas('stores', fn ($query) => $query->where('stores.id', $storeId))
                ->lockForUpdate()
                ->firstOrFail();

            $productStock = ProductStock::query()
                ->where('product_id', $product->id)
                ->whereNull('product_size_id')
                ->where('stock_id', $stockId)
                ->lockForUpdate()
                ->first();

            if (!$productStock) {
                $productStock = ProductStock::query()->create([
                    'product_id'      => $product->id,
                    'product_size_id' => null,
                    'stock_id'        => $stockId,
                    'quantity'        => 0,
                ]);
            }

            $productStock->increment('quantity', $quantity);

            return (int) $productStock->refresh()->quantity;
        }, attempts: 3);

        $this->data['current_quantity'] = $newQuantity;
        $this->data['quantity']         = null;

        Notification::make()
            ->success()
            ->title('Miqdor qo‘shildi')
            ->body("Yangi qoldiq: {$newQuantity} dona.")
            ->send();

        if ($shouldPrint) {
            $this->redirect(route('product.barcode.pdf', [
                'product' => $this->productId,
                'size'    => '57x30',
                'copies'  => $quantity,
            ]));
        }
    }

    protected function clearFoundProduct(string $barcode): void
    {
        $this->productId       = null;
        $this->searchedBarcode = null;
        $this->form->fill(['barcode' => $barcode]);
    }
}
