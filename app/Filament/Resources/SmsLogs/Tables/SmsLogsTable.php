<?php

namespace App\Filament\Resources\SmsLogs\Tables;

use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;

class SmsLogsTable
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
                    ->limit(60)
                    ->searchable()
                    ->wrap(),
                TextColumn::make('total_clients')
                    ->label('Jami mijozlar')
                    ->sortable(),
                TextColumn::make('successful_count')
                    ->label('Muvaffaqiyatli')
                    ->badge()
                    ->color('success')
                    ->sortable(),
                TextColumn::make('failed_count')
                    ->label('Xatoliklar')
                    ->badge()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending'    => 'warning',
                        'processing' => 'info',
                        'completed'  => 'success',
                        'failed'     => 'danger',
                        default      => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending'    => 'Kutilmoqda',
                        'processing' => 'Yuborilmoqda...',
                        'completed'  => 'Tugallandi',
                        'failed'     => 'Xatolik',
                        default      => $state,
                    }),
                TextColumn::make('created_at')
                    ->label('Yuborilgan vaqti')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
