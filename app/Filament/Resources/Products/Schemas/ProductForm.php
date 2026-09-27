<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Models\Stock;
use App\Helpers\Helper;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        // Optimize: fetch active stocks once and reuse in all closures
        $stocks = self::getActiveStocks();

        return $schema
            ->components([
                Section::make('Tovar maʼlumotlari')
                    ->columnSpanFull()
                    ->description('Tovar nomi tanlangan kategoriya nomidan avtomatik olinadi.')
                    ->schema([
                        Select::make('category_id')
                            ->label('Kategoriya')
                            ->placeholder('Kategoriyani tanlang')
                            ->relationship('category', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->autofocus(),

                        TextInput::make('barcode')
                            ->label('Bar kod')
                            ->placeholder('Bar kodni kiriting yoki avtomatik yarating')
                            ->unique('products', 'barcode', ignoreRecord: true)
                            ->tel()
                            ->maxLength(32)
                            ->rule('regex:/^[0-9]+$/')
                            ->required()
                            ->helperText('Faqat raqam kiriting.')
                            ->suffixAction(
                                Action::make('generateBarcode')
                                    ->icon('heroicon-m-sparkles')
                                    ->tooltip('EAN-13 Bar kod yaratish')
                                    ->action(function (Set $set) {
                                        $set('barcode', Helper::generateEAN13Barcode());
                                    })
                            ),

                        Select::make('type')
                            ->hidden()
                            ->label('Turi')
                            ->options([
                                Product::TYPE_SIZE    => 'Razmerli',
                                Product::TYPE_COLOR   => 'Rangli',
                                Product::TYPE_PACKAGE => 'Paketli',
                            ])
                            ->default(Product::TYPE_PACKAGE)
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                if ($state === Product::TYPE_SIZE && empty($get('sizes'))) {
                                    $set('sizes', collect(range(36, 41))
                                        ->map(fn ($size) => ['size' => $size])
                                        ->toArray());
                                }
                            }),
                    ])
                    ->columns([
                        'default' => 1,
                        'md'      => 2,
                    ]),

                Section::make('Narxlar')
                    ->columnSpanFull()
                    ->description('Kelgan va sotish narxlarini so‘mda kiriting.')
                    ->schema([
                        TextInput::make('initial_price')
                            ->label('Kelgan narxi')
                            ->placeholder('0')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('so‘m')
                            ->required(),

                        TextInput::make('price')
                            ->label('Sotish narxi')
                            ->placeholder('0')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('so‘m')
                            ->required(),
                    ])
                    ->columns([
                        'default' => 1,
                        'md'      => 2,
                    ]),

                Section::make('Variantlar va Stocklar')
                    ->columnSpanFull()
                    ->description('Har bir variant uchun har bir ombordagi miqdorni kiriting')
                    ->visible(fn (Get $get) => in_array(($get('type') ?? Product::TYPE_SIZE), [Product::TYPE_SIZE, Product::TYPE_COLOR], true))
                    ->schema([
                        Repeater::make('sizes')
                            ->label(fn (Get $get) => ($get('type') ?? Product::TYPE_SIZE) === Product::TYPE_COLOR ? 'Ranglar' : 'Razmerlar')
                            ->schema(function () use ($stocks) {
                                return [
                                    Grid::make()
                                        ->columns(count($stocks) + 1)
                                        ->schema(function () use ($stocks) {
                                            $fields = [];

                                            $fields[] = TextInput::make('size')
                                                ->label(fn (Get $get) => ($get('../../type') ?? Product::TYPE_SIZE) === Product::TYPE_COLOR ? 'Rang' : 'Razmer');

                                            foreach ($stocks as $id => $name) {
                                                $fields[] = TextInput::make("stock_{$id}")
                                                    ->label($name)
                                                    ->numeric()
                                                    ->default(0);
                                            }

                                            return $fields;
                                        }),
                                ];
                            })
                            ->columns(1)
                            ->reorderable(false),

                    ]),

                Section::make('Miqdori')
                    ->columnSpanFull()
                    ->description('Har bir ombordagi boshlang‘ich qoldiqni kiriting.')
                    ->visible(fn (Get $get) => ($get('type') ?? 'size') === 'package')
                    ->schema(function () use ($stocks) {
                        return [
                            Grid::make()
                                ->columns(count($stocks))
                                ->schema(function () use ($stocks) {
                                    $fields = [];
                                    foreach ($stocks as $id => $name) {
                                        $fields[] = TextInput::make("pkg_stock_{$id}")
                                            ->label($name)
                                            ->placeholder('0')
                                            ->numeric()
                                            ->minValue(0)
                                            ->suffix('dona')
                                            ->required();
                                    }

                                    return $fields;
                                }),
                        ];
                    }),
                Section::make('Rasm')
                    ->hidden()
                    ->columnSpanFull()
                    ->schema(function () {
                        $upload = SpatieMediaLibraryFileUpload::make('images')
                            ->disk(config('filesystems.default'))
                            ->collection(Product::IMAGE_COLLECTION)
                            ->label('Mahsulot rasmlari')
                            ->imageEditor()
                            ->maxSize(10240)
                            ->multiple()
                            ->reorderable()
                            ->responsiveImages()
                            ->extraAttributes(['class' => 'cursor-zoom-in', 'capture' => 'environment'])
                            ->visibility('public');

                        if (Product::canOptimizeImages()) {
                            $upload->conversion(Product::OPTIMIZED_CONVERSION); // WebP konversiyani ishlatadi
                        } else {
                            $upload->helperText('Diqqat: GD yoki Imagick PHP kengaytmasi yoqilmagan. Rasm optimizatsiyasi vaqtincha o\'chirildi.');
                        }

                        return [$upload];
                    }),
            ]);
    }

    private static function getActiveStocks(): array
    {
        static $cached = null;
        if ($cached === null) {
            $cached = Stock::scopes('active')->pluck('name', 'id')->toArray();
        }

        return $cached;
    }
}
