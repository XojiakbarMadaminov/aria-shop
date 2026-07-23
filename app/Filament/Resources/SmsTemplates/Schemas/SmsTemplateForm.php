<?php

namespace App\Filament\Resources\SmsTemplates\Schemas;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;

class SmsTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columnSpanFull()
                    ->components([
                        Textarea::make('content')
                            ->label('SMS matni')
                            ->placeholder('SMS xabari matnini kiriting...')
                            ->required()
                            ->rows(5)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
