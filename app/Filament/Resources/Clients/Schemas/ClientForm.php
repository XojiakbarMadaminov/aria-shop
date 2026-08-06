<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Models\Client;
use Filament\Schemas\Schema;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columnSpanFull()
                    ->components([
                        TextInput::make('full_name'),
                        TextInput::make('phone')
                            ->label('Telefon raqam')
                            ->maxLength(9)
                            ->prefix('+998')
                            ->placeholder('90 123 45 67')
                            ->required()
                            ->reactive()
                            ->rule('regex:/^[0-9]{0,9}$/')
                            ->afterStateUpdated(function ($state, callable $set) {
                                $phone = '+998' . preg_replace('/\D/', '', $state);

                                $exists = Client::where('phone', $phone)
                                    ->withTrashed()
                                    ->exists();

                                if ($exists) {
                                    $set('phone', null);
                                    Notification::make()
                                        ->title('Ushbu raqam klientlar ro‘yxatida mavjud!')
                                        ->danger()
                                        ->send();
                                }
                            })
                            ->dehydrateStateUsing(fn ($state) => '+998' . preg_replace('/\D/', '', $state))
                            ->formatStateUsing(fn ($state) => $state
                                ? ltrim(preg_replace('/^\+998/', '', $state), '0')
                                : $state),
                        Toggle::make('send_sms')->label('SMS yuborishga ruhsat')->default(true),

                    ]),

            ]);
    }
}
