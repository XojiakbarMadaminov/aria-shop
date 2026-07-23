<?php

namespace App\Filament\Resources\SmsTemplates;

use BackedEnum;
use Filament\Tables\Table;
use App\Models\SmsTemplate;
use Filament\Schemas\Schema;
use App\Enums\NavigationGroup;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use App\Filament\Resources\SmsTemplates\Pages\EditSmsTemplate;
use App\Filament\Resources\SmsTemplates\Pages\ListSmsTemplates;
use App\Filament\Resources\SmsTemplates\Pages\CreateSmsTemplate;
use App\Filament\Resources\SmsTemplates\Schemas\SmsTemplateForm;
use App\Filament\Resources\SmsTemplates\Tables\SmsTemplatesTable;

class SmsTemplateResource extends Resource
{
    protected static ?string $model = SmsTemplate::class;

    protected static string|null|\UnitEnum $navigationGroup = NavigationGroup::Sms;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;
    protected static ?int $navigationSort                   = 5;
    protected static ?string $label                         = 'SMS Shablon';
    protected static ?string $pluralLabel                   = 'SMS Shablonlar';
    protected static ?string $navigationLabel               = 'SMS Shablonlar';

    public static function form(Schema $schema): Schema
    {
        return SmsTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SmsTemplatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListSmsTemplates::route('/'),
            'create' => CreateSmsTemplate::route('/create'),
            'edit'   => EditSmsTemplate::route('/{record}/edit'),
        ];
    }
}
