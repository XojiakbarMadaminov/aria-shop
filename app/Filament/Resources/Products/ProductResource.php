<?php

namespace App\Filament\Resources\Products;

use BackedEnum;
use App\Models\Product;
use Filament\Tables\Table;
use Filament\Schemas\Schema;
use App\Enums\NavigationGroup;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ViewProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Filament\Resources\Products\Tables\ProductsTable;
use App\Filament\Resources\Products\Pages\GalleryProducts;
use App\Filament\Resources\Products\Schemas\ProductInfolist;
use App\Filament\Resources\Products\Pages\AddExistingProduct;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|null|\UnitEnum $navigationGroup = NavigationGroup::ProductsAndCategories;
    protected static ?string $navigationLabel               = 'Tovarlar';
    protected static ?string $label                         = 'Tovarlar';
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;
    protected static ?int $navigationSort                   = 2;

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ProductInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'        => ListProducts::route('/'),
            'gallery'      => GalleryProducts::route('/gallery'),
            'create'       => CreateProduct::route('/create'),
            'add-existing' => AddExistingProduct::route('/add-existing'),
            'view'         => ViewProduct::route('/{record}'),
            'edit'         => EditProduct::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
