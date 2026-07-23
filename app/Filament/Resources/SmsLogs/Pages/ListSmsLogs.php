<?php

namespace App\Filament\Resources\SmsLogs\Pages;

use Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\SmsLogs\SmsLogResource;

class ListSmsLogs extends ListRecords
{
    protected static string $resource = SmsLogResource::class;
}
