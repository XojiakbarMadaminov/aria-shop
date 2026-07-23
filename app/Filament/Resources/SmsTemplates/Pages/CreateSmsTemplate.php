<?php

namespace App\Filament\Resources\SmsTemplates\Pages;

use Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\SmsTemplates\SmsTemplateResource;

class CreateSmsTemplate extends CreateRecord
{
    protected static string $resource = SmsTemplateResource::class;
}
