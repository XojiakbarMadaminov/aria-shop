<?php

namespace App\Filament\Resources\Products\Pages;

use Throwable;
use App\Models\Stock;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Illuminate\Support\Facades\Log;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Storage;
use Filament\Notifications\Notification;
use Filament\Forms\Components\FileUpload;
use Filament\Resources\Pages\ListRecords;
use App\Services\ProductPackageImportService;
use App\Filament\Resources\Products\ProductResource;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('gallery')
                ->label('Gallery')
                ->icon('heroicon-o-photo')
                ->color('gray')
                ->url(ProductResource::getUrl('gallery')),
            Action::make('importPackages')
                ->label('Excel import')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn (): bool => auth()->user()?->hasRole(config('filament-shield.super_admin.name', 'super_admin')) ?? false)
                ->schema([
                    Select::make('stock_id')
                        ->label('Stock')
                        ->options(fn (): array => Stock::query()
                            ->scopes('active')
                            ->whereHas('stores', fn ($query) => $query->where('stores.id', auth()->user()?->current_store_id))
                            ->pluck('name', 'id')
                            ->toArray())
                        ->searchable()
                        ->preload()
                        ->required(),
                    FileUpload::make('file')
                        ->label('Excel fayl')
                        ->disk('local')
                        ->directory('imports/products')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        ])
                        ->required(),
                ])
                ->action(function (array $data): void {
                    if (!(auth()->user()?->hasRole(config('filament-shield.super_admin.name', 'super_admin')) ?? false)) {
                        Notification::make()
                            ->danger()
                            ->title('Ruxsat yo‘q')
                            ->body('Excel import faqat super admin uchun.')
                            ->send();

                        return;
                    }

                    $store = auth()->user()?->currentStore;

                    if (!$store) {
                        Notification::make()
                            ->danger()
                            ->title('Store tanlanmagan')
                            ->body('Avval joriy storeni tanlang.')
                            ->send();

                        return;
                    }

                    $stock = Stock::query()->findOrFail((int) $data['stock_id']);
                    $file  = $this->uploadedFilePath($data['file']);

                    $importService = app(ProductPackageImportService::class);

                    try {
                        $summary = $importService->import(Storage::disk('local')->path($file), $store, $stock);
                    } catch (Throwable $exception) {
                        Log::error('Product Excel import failed.', [
                            'file'            => basename($file),
                            'store_id'        => $store->id,
                            'stock_id'        => $stock->id,
                            'exception_class' => $exception::class,
                            'error'           => $exception->getMessage(),
                        ]);

                        Notification::make()
                            ->danger()
                            ->title('Import bajarilmadi')
                            ->body($exception->getMessage())
                            ->send();

                        return;
                    }

                    $body = "Yaratildi: {$summary['created']}. Yangilandi: {$summary['updated']}. Birlashtirildi: {$summary['merged']}. O‘tkazib yuborildi: {$summary['skipped']}.";

                    if ($summary['errors'] !== []) {
                        $body .= ' ' . implode(' ', array_slice($summary['errors'], 0, 3));
                    }

                    Notification::make()
                        ->success()
                        ->title('Import yakunlandi')
                        ->body($body)
                        ->send();
                }),
            CreateAction::make(),

        ];
    }

    /**
     * @param  string|array<int, string>  $file
     */
    private function uploadedFilePath(string|array $file): string
    {
        if (is_array($file)) {
            return (string) reset($file);
        }

        return $file;
    }
}
