<?php

namespace App\Filament\Resources\SmsLogs;

use BackedEnum;
use App\Models\SmsLog;
use Filament\Tables\Table;
use App\Enums\NavigationGroup;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use App\Filament\Resources\SmsLogs\Pages\ListSmsLogs;
use App\Filament\Resources\SmsLogs\Tables\SmsLogsTable;

class SmsLogResource extends Resource
{
    protected static ?string $model = SmsLog::class;

    protected static string|null|\UnitEnum $navigationGroup = NavigationGroup::Sms;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;
    protected static ?int $navigationSort                   = 6;
    protected static ?string $label                         = 'SMS Jurnali';
    protected static ?string $pluralLabel                   = 'SMS Jurnallari';
    protected static ?string $navigationLabel               = 'SMS Jurnallari';

    public static function table(Table $table): Table
    {
        return SmsLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSmsLogs::route('/'),
        ];
    }
}
