<?php

namespace App\Filament\Pages;

use App\Models\Stock;
use App\Helpers\Helper;
use App\Models\Product;
use App\Models\Supplier;
use Filament\Pages\Page;
use App\Models\ProductSize;
use App\Models\ProductStock;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use App\Enums\NavigationGroup;
use App\Services\PurchaseService;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Validation\ValidationException;
use Filament\Forms\Concerns\InteractsWithForms;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Livewire\Features\SupportFileUploads\WithFileUploads;

class PurchaseEntry extends Page implements HasForms
{
    use HasPageShield, InteractsWithForms, WithFileUploads;

    protected static string|null|\UnitEnum $navigationGroup  = NavigationGroup::BaseActions;
    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationLabel                = 'Ta’minotchidan xarid';
    protected static ?string $title                          = 'Ta’minotchidan xarid qilish';
    protected static ?string $slug                           = 'purchases/create';
    protected static ?int $navigationSort                    = 2;

    protected string $view = 'filament.pages.purchase-entry';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'purchase_date' => now(),
            'payment_type'  => 'cash',
        ]);
    }

    // ✅ Filament 4 uchun to‘g‘ri form() metodi
    public function form(Schema $schema)
    {
        return $schema
            ->components([
                Section::make('Xarid maʼlumotlari')
                    ->columns(2)
                    ->schema([
                        Select::make('supplier_id')
                            ->label('Ta’minotchi')
                            ->options(fn () => Supplier::orderBy('full_name')->pluck('full_name', 'id'))
                            ->searchable()
                            ->required()
                            ->createOptionForm([
                                TextInput::make('full_name')
                                    ->label('Ismi va familiyasi')
                                    ->required(),
                                TextInput::make('phone')
                                    ->label('Telefon raqam')
                                    ->maxLength(9)
                                    ->prefix('+998')
                                    ->placeholder('90 123 45 67 yoki 0')
                                    ->required()
                                    ->reactive()
                                    ->rule('regex:/^[0-9]{0,9}$/')
                                    ->afterStateUpdated(function ($state, callable $set) {
                                        $digits = preg_replace('/\D/', '', (string) $state);
                                        if ($digits === '0') {
                                            return;
                                        }
                                        $phone  = '+998' . $digits;
                                        $exists = Supplier::where('phone', $phone)->exists();
                                        if ($exists) {
                                            $set('phone', null);
                                            Notification::make()
                                                ->title('Ushbu raqam ro?yxatda mavjud!')
                                                ->danger()
                                                ->send();
                                        }
                                    }),
                                TextInput::make('address')
                                    ->label('Manzil')
                                    ->nullable(),
                            ])
                            ->createOptionUsing(function (array $data) {
                                $fullName = trim((string) ($data['full_name'] ?? ''));
                                $rawPhone = (string) ($data['phone'] ?? '');
                                $address  = trim((string) ($data['address'] ?? '')) ?: null;
                                if ($fullName === '') {
                                    throw ValidationException::withMessages([
                                        'full_name' => 'Ism va familiya kiritilishi shart.',
                                    ]);
                                }
                                $digits = preg_replace('/\D/', '', $rawPhone);
                                if ($digits !== '0') {
                                    if (!preg_match('/^[0-9]{9}$/', $digits ?? '')) {
                                        throw ValidationException::withMessages([
                                            'phone' => 'Telefon raqam 9 ta raqamdan iborat bo?lishi kerak yoki 0 bo?lishi mumkin.',
                                        ]);
                                    }
                                    $phone = '+998' . $digits;
                                    if (Supplier::where('phone', $phone)->exists()) {
                                        throw ValidationException::withMessages([
                                            'phone' => 'Ushbu raqam ro?yxatda mavjud!',
                                        ]);
                                    }
                                } else {
                                    $phone = '0';
                                }
                                $supplier = Supplier::create([
                                    'full_name' => $fullName,
                                    'phone'     => $phone,
                                    'address'   => $address,
                                ]);

                                return $supplier->id;
                            }), Select::make('stock_id')
                            ->label('Sklad')
                            ->options(fn () => Stock::query()->where('is_active', true)->pluck('name', 'id'))
                            ->live()
                            ->searchable()
                            ->required(),

                        DatePicker::make('purchase_date')
                            ->label('Xarid sanasi')
                            ->default(today())
                            ->required(),

                        Select::make('payment_type')
                            ->label('To‘lov turi')
                            ->options([
                                'cash'    => 'Naqd',
                                'card'    => 'Karta',
                                'debt'    => 'Qarz',
                                'partial' => 'Qisman',
                            ])
                            ->default('cash')
                            ->live()
                            ->required(),

                        TextInput::make('partial_paid_amount')
                            ->label('Qisman to‘langan summa')
                            ->numeric()
                            ->minValue(0.01)
                            ->visible(fn (Get $get) => $get('payment_type') === 'partial'),

                        Textarea::make('note')
                            ->label('Izoh')
                            ->columnSpanFull(),
                    ]),

                Section::make('Mahsulotlar')
                    ->hidden(fn (Get $get) => blank($get('stock_id')))
                    ->schema([
                        Repeater::make('items')
                            ->label('Mahsulot pozitsiyalari')
                            ->minItems(1)
                            ->defaultItems(1)
                            ->reorderable(false)
                            ->columns(12)
                            ->schema([
                                Select::make('product_id')
                                    ->label('Mahsulot')
                                    ->columnSpan(4)
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->options(fn () => Product::withoutGlobalScope('current_store')->orderBy('name')->get()
                                        ->mapWithKeys(fn ($product) => [$product->id => $product->display_label ?? $product->name]))
                                    ->getSearchResultsUsing(function (string $search) {
                                        if (blank($search)) {
                                            return Product::withoutGlobalScope('current_store')->orderBy('name')
                                                ->limit(50)
                                                ->get()
                                                ->mapWithKeys(fn ($product) => [$product->id => $product->display_label ?? $product->name]);
                                        }

                                        return Product::withoutGlobalScope('current_store')
                                            ->where(fn ($q) => $q
                                                ->where('barcode', 'ILIKE', "%{$search}%")
                                                ->orWhere('name', 'ILIKE', "%{$search}%"))
                                            ->orderBy('name')
                                            ->limit(50)
                                            ->get()
                                            ->mapWithKeys(fn ($product) => [$product->id => $product->display_label ?? $product->name]);
                                    })
                                    ->getOptionLabelUsing(fn ($value) => optional(Product::withoutGlobalScope('current_store')->find($value))->display_label ?? '')
                                    ->required()
                                    ->createOptionForm($this->productCreateForm())
                                    ->createOptionUsing(function (array $data) {
                                        $stockId = data_get($this->data, 'stock_id');

                                        if (!$stockId) {
                                            throw ValidationException::withMessages([
                                                'stock_id' => 'Mahsulot yaratishdan oldin skladni tanlang.',
                                            ]);
                                        }

                                        $sizes = collect($data['sizes'] ?? [])
                                            ->pluck('size')
                                            ->filter()
                                            ->unique()
                                            ->values();

                                        unset($data['sizes']);

                                        $product = Product::create([
                                            'name'          => $data['name'],
                                            'barcode'       => $data['barcode'] ?? null,
                                            'type'          => $data['type'],
                                            'initial_price' => $data['initial_price'] ?? 0,
                                            'price'         => $data['price'] ?? 0,
                                            'category_id'   => $data['category_id'] ?? null,
                                        ]);

                                        $createdSizes = collect();

                                        if ($product->isSizeBased() && $sizes->isNotEmpty()) {
                                            foreach ($sizes as $size) {
                                                $createdSizes->push(
                                                    $product->sizes()->create(['size' => $size])
                                                );
                                            }
                                        }

                                        if ($stockId) {
                                            if ($product->isPackageBased()) {
                                                ProductStock::firstOrCreate(
                                                    [
                                                        'product_id'      => $product->id,
                                                        'product_size_id' => null,
                                                        'stock_id'        => $stockId,
                                                    ],
                                                    ['quantity' => 0]
                                                );
                                            } else {
                                                $createdSizes->each(function (ProductSize $size) use ($stockId) {
                                                    ProductStock::firstOrCreate(
                                                        [
                                                            'product_id'      => null,
                                                            'product_size_id' => $size->id,
                                                            'stock_id'        => $stockId,
                                                        ],
                                                        ['quantity' => 0]
                                                    );
                                                });
                                            }
                                        }

                                        return $product->getKey();
                                    })
                                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                        $product = $state ? Product::withoutGlobalScope('current_store')->with('sizes')->find($state) : null;
                                        $type    = $product?->type ?? Product::TYPE_PACKAGE;
                                        $set('product_type', $type);

                                        if ($type === Product::TYPE_PACKAGE) {
                                            $set('size_quantities', []);
                                            $set('quantity', $get('quantity') ?: 1);
                                        } else {
                                            $set('size_quantities', $product?->sizes?->map(fn ($size) => [
                                                'product_size_id' => $size->id,
                                                'size_label'      => $size->size,
                                                'quantity'        => 0,
                                            ])->toArray() ?? []);
                                        }
                                    }),

                                Hidden::make('product_type')->default(Product::TYPE_PACKAGE),

                                TextInput::make('unit_cost')
                                    ->label('Xarid narxi')
                                    ->numeric()
                                    ->minValue(0.01)
                                    ->columnSpan(2)
                                    ->required(),

                                TextInput::make('quantity')
                                    ->label('Miqdor')
                                    ->visible(fn (Get $get) => ($get('product_type') ?? Product::TYPE_PACKAGE) === Product::TYPE_PACKAGE)
                                    ->numeric()
                                    ->minValue(1)
                                    ->default(1)
                                    ->columnSpan(2),

                                Repeater::make('size_quantities')
                                    ->label(fn (Get $get) => ($get('product_type') ?? Product::TYPE_PACKAGE) === Product::TYPE_COLOR ? 'Ranglar' : 'Razmerlar')
                                    ->createItemButtonLabel(fn (Get $get) => ($get('product_type') ?? Product::TYPE_PACKAGE) === Product::TYPE_COLOR ? 'Rang qo‘shish' : 'Razmer qo‘shish')
                                    ->reorderable(false)
                                    ->visible(fn (Get $get) => in_array(($get('product_type') ?? Product::TYPE_PACKAGE), [Product::TYPE_SIZE, Product::TYPE_COLOR], true))
                                    ->columnSpan(12)
                                    ->schema([
                                        Hidden::make('product_size_id'),
                                        TextInput::make('size_label')
                                            ->label(fn (Get $get) => ($get('product_type') ?? Product::TYPE_PACKAGE) === Product::TYPE_COLOR ? 'Rang' : 'Razmer')
                                            ->required(fn (Get $get) => blank($get('product_size_id')))
                                            ->disabled(fn (Get $get) => filled($get('product_size_id')))
                                            ->placeholder(fn (Get $get) => ($get('product_type') ?? Product::TYPE_PACKAGE) === Product::TYPE_COLOR ? 'Rang nomi' : 'Razmer nomi'),
                                        TextInput::make('quantity')
                                            ->label('Miqdor')
                                            ->numeric()
                                            ->minValue(0)
                                            ->default(0),
                                    ])
                                    ->columns(3),
                            ])
                            ->addActionLabel('Mahsulot qo‘shish'),
                    ]),
            ])
            ->statePath('data');
    }

    protected function productCreateForm(): array
    {
        return [
            TextInput::make('name')
                ->label('Mahsulot nomi')
                ->required()
                ->columnSpanFull(),
            Section::make()
                ->columns()
                ->schema([
                    TextInput::make('barcode')
                        ->label('Bar kod')
                        ->unique('products', 'barcode', ignoreRecord: true)
                        ->tel()
                        ->maxLength(32)
                        ->rule('regex:/^[0-9]+$/')
                        ->required()
                        ->autofocus()
                        ->suffixAction(
                            Action::make('generateBarcode')
                                ->icon('heroicon-m-sparkles')
                                ->tooltip('EAN-13 Bar kod yaratish')
                                ->action(function (Set $set) {
                                    $set('barcode', Helper::generateEAN13Barcode());
                                })
                        ),

                    Select::make('type')
                        ->label('Turi')
                        ->options([
                            Product::TYPE_PACKAGE => 'Paketli',
                            Product::TYPE_SIZE    => 'Razmerli',
                            Product::TYPE_COLOR   => 'Rangli',
                        ])
                        ->default(Product::TYPE_PACKAGE)
                        ->live()
                        ->required()
                        ->afterStateUpdated(function ($state, Set $set, Get $get) {
                            if ($state === Product::TYPE_SIZE && empty($get('sizes'))) {
                                $set('sizes', collect(range(36, 41))
                                    ->map(fn ($size) => ['size' => $size])
                                    ->toArray());
                            }
                        }),
                ]),
            Section::make()
                ->columns()
                ->schema([
                    TextInput::make('initial_price')
                        ->label('Kelgan narxi')
                        ->numeric()
                        ->minValue(0)
                        ->default(0),

                    TextInput::make('price')
                        ->label('Sotish narxi')
                        ->numeric()
                        ->minValue(0)
                        ->default(0),
                ]),

            Repeater::make('sizes')
                ->label(fn (Get $get) => ($get('type') ?? Product::TYPE_PACKAGE) === Product::TYPE_COLOR ? 'Ranglar' : 'Razmerlar')
                ->visible(fn (Get $get) => in_array(($get('type') ?? Product::TYPE_PACKAGE), [Product::TYPE_SIZE, Product::TYPE_COLOR], true))
                ->schema([
                    TextInput::make('size')
                        ->label(fn (Get $get) => ($get('type') ?? Product::TYPE_PACKAGE) === Product::TYPE_COLOR ? 'Rang' : 'Razmer')
                        ->required(),
                ])
                ->minItems(1)
                ->default([]),
        ];
    }

    public function submit(): void
    {
        $data = $this->form->getState();

        $stockId = $data['stock_id'] ?? null;

        $data['items'] = collect($data['items'] ?? [])
            ->map(function (array $item) use ($stockId) {
                $productId = $item['product_id'] ?? null;

                if (!$productId) {
                    return $item;
                }

                $product = Product::withoutGlobalScope('current_store')
                    ->with('sizes')
                    ->find($productId);

                if (!$product || !in_array(($item['product_type'] ?? Product::TYPE_PACKAGE), [Product::TYPE_SIZE, Product::TYPE_COLOR], true)) {
                    return $item;
                }

                $item['size_quantities'] = collect($item['size_quantities'] ?? [])
                    ->map(function (array $row) use ($product, $stockId) {
                        $sizeId    = $row['product_size_id'] ?? null;
                        $sizeLabel = trim((string) ($row['size_label'] ?? ''));

                        if (!$sizeId && $sizeLabel !== '') {
                            $size                   = $product->sizes()->firstOrCreate(['size' => $sizeLabel]);
                            $row['product_size_id'] = $size->id;

                            if ($stockId) {
                                ProductStock::firstOrCreate(
                                    [
                                        'product_id'      => null,
                                        'product_size_id' => $size->id,
                                        'stock_id'        => $stockId,
                                    ],
                                    ['quantity' => 0]
                                );
                            }
                        }

                        return $row;
                    })
                    ->toArray();

                return $item;
            })
            ->toArray();

        try {
            $purchase = app(PurchaseService::class)->create($data);

            Notification::make()
                ->title('Xarid muvaffaqiyatli saqlandi')
                ->success()
                ->send();

            $this->redirect(PurchaseEntry::getUrl());
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $throwable) {
            report($throwable);

            Notification::make()
                ->title('Xaridni saqlashda xatolik yuz berdi')
                ->body($throwable->getMessage())
                ->danger()
                ->send();
        }
    }
}
