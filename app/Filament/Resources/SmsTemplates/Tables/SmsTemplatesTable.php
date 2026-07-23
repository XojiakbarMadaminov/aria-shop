<?php

namespace App\Filament\Resources\SmsTemplates\Tables;

use App\Models\Client;
use App\Models\SmsLog;
use Filament\Tables\Table;
use App\Models\SmsTemplate;
use App\Jobs\SendMassSmsJob;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Notifications\Notification;

class SmsTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('content')
                    ->label('SMS matni')
                    ->limit(80)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('created_at')
                    ->label('Yaratilgan vaqti')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('send_to_all')
                    ->label('Barcha mijozlarga yuborish')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Barcha mijozlarga SMS yuborish')
                    ->modalDescription(function (): string {
                        $count = Client::query()
                            ->whereNotNull('phone')
                            ->where('phone', '!=', '')
                            ->count();

                        return "Tizimda telefon raqami mavjud bo'lgan {$count} ta mijozga ushbu SMS xabari yuboriladi. Tasdiqlaysizmi?";
                    })
                    ->modalSubmitActionLabel('Ha, yuborilsin')
                    ->action(function (SmsTemplate $record): void {
                        $clientCount = Client::query()
                            ->whereNotNull('phone')
                            ->where('phone', '!=', '')
                            ->count();

                        if ($clientCount === 0) {
                            Notification::make()
                                ->title('Telefon raqami mavjud mijozlar topilmadi!')
                                ->warning()
                                ->send();

                            return;
                        }

                        $smsLog = SmsLog::create([
                            'sms_template_id'  => $record->id,
                            'content'          => $record->content,
                            'total_clients'    => $clientCount,
                            'successful_count' => 0,
                            'failed_count'     => 0,
                            'status'           => 'pending',
                        ]);

                        SendMassSmsJob::dispatch($smsLog);

                        Notification::make()
                            ->title('SMS yuborish jarayoni fon rejimida boshlandi!')
                            ->body("Jami {$clientCount} ta mijozga SMS yuboriladi.")
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
