<?php

namespace App\Filament\Pages;

use Throwable;
use Carbon\Carbon;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\Debtor;
use Filament\Pages\Page;
use Filament\Tables\Table;
use App\Models\ProductStock;
use Filament\Actions\Action;
use App\Enums\NavigationGroup;
use App\Models\DebtorTransaction;
use Illuminate\Support\Facades\DB;
use Filament\Support\Icons\Heroicon;
use Filament\Forms\Components\Select;
use App\Services\TelegramSaleNotifier;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\ValidationException;
use Filament\Tables\Concerns\InteractsWithTable;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;

class SalesHistoryPage extends Page implements HasTable
{
    use HasPageShield, InteractsWithTable;

    protected static string|null|\UnitEnum $navigationGroup  = NavigationGroup::Finance;
    protected static ?string $navigationLabel                = 'Sotuv tarixi';
    protected static ?string $title                          = 'Sotuv tarixi';
    protected static ?string $slug                           = 'sales-history';
    protected static string|null|\BackedEnum $navigationIcon = Heroicon::ListBullet;
    protected static ?int $navigationSort                    = 2;

    protected string $view = 'filament.pages.sales-history-page';

    public string $datePreset   = 'today'; // today, week, month, custom
    public ?string $customStart = null;
    public ?string $customEnd   = null;

    public function mount(): void
    {
        // Ensure default is always 'today'
        $this->datePreset = 'today';
    }

    public function table(Table $table): Table
    {
        return $table
            ->searchable(false)
            ->query(
                fn (): Builder => Sale::query()
                    ->with(['client', 'items.product', 'items.productSize', 'createdBy'])
            )
            ->modifyQueryUsing(function (Builder $query) {
                $preset = $this->datePreset;
                if ($preset === 'today') {
                    $query->whereDate('created_at', today());
                } elseif ($preset === 'week') {
                    $query->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]);
                } elseif ($preset === 'month') {
                    $query->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]);
                } elseif ($preset === 'custom') {
                    $start = $this->customStart ? Carbon::parse($this->customStart)->startOfDay() : null;
                    $end   = $this->customEnd ? Carbon::parse($this->customEnd)->endOfDay() : null;
                    if ($start && $end) {
                        $query->whereBetween('created_at', [$start, $end]);
                    } elseif ($start) {
                        $query->where('created_at', '>=', $start);
                    } elseif ($end) {
                        $query->where('created_at', '<=', $end);
                    }
                }
            })
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->width('1%')
                    ->toggleable()
                    ->sortable()
                    ->searchable(isIndividual: true),

                TextColumn::make('client.full_name')
                    ->label('Klient')
                    ->placeholder('-')
                    ->toggleable()
                    ->sortable()
                    ->searchable(isIndividual: true),

                TextColumn::make('client.phone')
                    ->label('Telefon')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable()
                    ->searchable(isIndividual: true),

                TextColumn::make('createdBy.name')
                    ->label('Kassir')
                    ->badge()
                    ->color('primary')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('payment_type')
                    ->label('To‘lov turi')
                    ->color(fn ($state) => match ($state) {
                        'debt'     => 'danger',
                        'partial'  => 'warning',
                        'preorder' => 'gray',
                        default    => 'success',
                    })
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'cash'     => 'Naqd',
                        'card'     => 'Karta',
                        'debt'     => 'Qarz',
                        'transfer' => 'O‘tkazma',
                        'partial'  => 'Qisman',
                        'mixed'    => 'Karta + Naqd',
                        'preorder' => 'Oldindan buyurtma',
                        default    => $state ?? '-',
                    })
                    ->toggleable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        Sale::STATUS_PENDING  => 'warning',
                        Sale::STATUS_REJECTED => 'danger',
                        default               => 'success',
                    })
                    ->formatStateUsing(fn ($state) => match ($state) {
                        Sale::STATUS_PENDING   => 'Kutilmoqda',
                        Sale::STATUS_REJECTED  => 'Bekor qilingan',
                        Sale::STATUS_COMPLETED => 'Yakunlangan',
                        default                => ucfirst($state ?? '-'),
                    })
                    ->toggleable(),

                TextColumn::make('total_amount')
                    ->label('Jami')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('customer_discount_amount')
                    ->label('Mijoz chegirmasi')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('paid_amount')
                    ->label('To‘langan')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('remaining_amount')
                    ->label('Qolgan')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Sana')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                // Stock filter by sale items
                SelectFilter::make('stock_id')
                    ->label('Sklad')
                    ->options(fn () => Stock::scopes('active')->pluck('name', 'id')->toArray())
                    ->query(function (Builder $query, array $data): Builder {
                        $stockId = $data['value'] ?? null;
                        if ($stockId) {
                            $query->whereHas('items', fn ($q) => $q->where('stock_id', $stockId));
                        }

                        return $query;
                    }),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        Sale::STATUS_PENDING   => 'Kutilmoqda',
                        Sale::STATUS_COMPLETED => 'Yakunlangan',
                        Sale::STATUS_REJECTED  => 'Bekor qilingan',
                    ]),
            ])
            ->headerActions([
                Action::make('today')
                    ->label('Bugun')
                    ->color(fn (): string => $this->datePreset === 'today' ? 'primary' : 'gray')
                    ->action(function () {
                        $this->datePreset  = 'today';
                        $this->customStart = $this->customEnd = null;
                    }),
                Action::make('week')
                    ->label('Hafta')
                    ->color(fn (): string => $this->datePreset === 'week' ? 'primary' : 'gray')
                    ->action(function () {
                        $this->datePreset  = 'week';
                        $this->customStart = $this->customEnd = null;
                    }),
                Action::make('month')
                    ->label('Oy')
                    ->color(fn (): string => $this->datePreset === 'month' ? 'primary' : 'gray')
                    ->action(function () {
                        $this->datePreset  = 'month';
                        $this->customStart = $this->customEnd = null;
                    }),
                Action::make('custom')
                    ->label('Oraliq')
                    ->color(fn (): string => $this->datePreset === 'custom' ? 'primary' : 'gray')
                    ->schema([
                        DatePicker::make('start')
                            ->label('Boshlanish sana')
                            ->native(false)
                            ->required(),
                        DatePicker::make('end')
                            ->label('Tugash sana')
                            ->native(false)
                            ->required(),
                    ])
                    ->action(function (array $data) {
                        $this->datePreset  = 'custom';
                        $this->customStart = $data['start'] ?? null;
                        $this->customEnd   = $data['end'] ?? null;
                    }),
            ])
            ->recordActions([
                Action::make('view_details')
                    ->label('Ko‘rish')
                    ->icon('heroicon-o-eye')
                    ->modalHeading('Sotuv tafsilotlari')
                    ->modalSubmitAction(false)
                    ->modalWidth('4xl')
                    ->modalContent(function (Sale $record) {
                        $record->loadMissing(['client', 'items.product', 'items.productSize', 'createdBy']);

                        return view('filament.sales.partials.sale-details', [
                            'sale' => $record,
                        ]);
                    }),
                Action::make('finalize_pending')
                    ->label('Yakunlash')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Sale $record) => $record->isPending())
                    ->modalHeading('Oldindan buyurtmani yakunlash')
                    ->modalSubmitActionLabel('Yakunlash')
                    ->modalWidth('lg')
                    ->form([
                        Select::make('payment_type')
                            ->label('To‘lov turi')
                            ->options([
                                'cash'    => 'Naqd',
                                'card'    => 'Karta',
                                'debt'    => 'Qarz',
                                'partial' => 'Qisman',
                                'mixed'   => 'Karta + Naqd',
                            ])
                            ->required()
                            ->reactive(),
                        TextInput::make('partial_amount')
                            ->label('Qisman to‘lov (so‘m)')
                            ->numeric()
                            ->minValue(0.01)
                            ->step(0.01)
                            ->visible(fn (Get $get) => $get('payment_type') === 'partial')
                            ->required(fn (Get $get) => $get('payment_type') === 'partial'),
                        TextInput::make('mixed_card_amount')
                            ->label('Karta (so‘m)')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->visible(fn (Get $get) => $get('payment_type') === 'mixed'),
                        TextInput::make('mixed_cash_amount')
                            ->label('Naqd (so‘m)')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->visible(fn (Get $get) => $get('payment_type') === 'mixed'),
                        Textarea::make('note')
                            ->label('Izoh (majburiy emas)')
                            ->rows(2)
                            ->visible(fn (Get $get) => in_array($get('payment_type'), ['debt', 'partial'], true))
                            ->maxLength(500),
                    ])
                    ->action(function (Sale $record, array $data): void {
                        $this->finalizePendingSale($record, $data);
                    }),
                Action::make('cancel_pending')
                    ->label('Bekor qilish')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Sale $record) => $record->isPending())
                    ->requiresConfirmation()
                    ->modalHeading('Pending buyurtmani bekor qilasizmi?')
                    ->modalSubmitActionLabel('Ha, bekor qilish')
                    ->action(function (Sale $record): void {
                        $this->cancelPendingSale($record);
                    }),

                Action::make('print_receipt')
                    ->label('Chek')
                    ->icon('heroicon-o-printer')
                    ->visible(fn (Sale $record) => $record->isCompleted())
                    ->url(fn (Sale $record) => route('sale.receipt.print', $record))
                    ->openUrlInNewTab(),
            ])
            ->emptyStateHeading('Sotuvlar topilmadi')
            ->emptyStateDescription('Filtrlarni o‘zgartirib ko‘ring.');
    }

    protected function finalizePendingSale(Sale $sale, array $data): void
    {
        if (!$sale->isPending()) {
            throw ValidationException::withMessages([
                'sale' => 'Faqat pending statusdagi buyurtmalarni yangilash mumkin.',
            ]);
        }

        $paymentType = $data['payment_type'] ?? null;
        $validTypes  = ['cash', 'card', 'transfer', 'debt', 'partial', 'mixed'];

        if (!in_array($paymentType, $validTypes, true)) {
            throw ValidationException::withMessages([
                'payment_type' => 'Noto‘g‘ri to‘lov turi tanlandi.',
            ]);
        }

        $totalAmount = round((float) $sale->total_amount, 2);

        if ($totalAmount <= 0) {
            throw ValidationException::withMessages([
                'sale' => 'Sotuv summasi noto‘g‘ri.',
            ]);
        }

        $partialAmount = null;
        $mixedCard     = 0.0;
        $mixedCash     = 0.0;
        $note          = filled($data['note'] ?? null) ? trim($data['note']) : null;

        if ($paymentType === 'partial') {
            $partialAmount = round((float) ($data['partial_amount'] ?? 0), 2);

            if ($partialAmount <= 0) {
                throw ValidationException::withMessages([
                    'partial_amount' => 'Qisman to‘lov summasi kiritilishi kerak.',
                ]);
            }

            if ($partialAmount >= $totalAmount) {
                throw ValidationException::withMessages([
                    'partial_amount' => 'Qisman to‘lov jami summadan kichik bo‘lishi kerak.',
                ]);
            }
        }

        if ($paymentType === 'mixed') {
            $mixedCard = round((float) ($data['mixed_card_amount'] ?? 0), 2);
            $mixedCash = round((float) ($data['mixed_cash_amount'] ?? 0), 2);

            if ($mixedCard <= 0 && $mixedCash <= 0) {
                throw ValidationException::withMessages([
                    'mixed_card_amount' => 'Kamida bitta summa kiritilishi kerak.',
                ]);
            }

            if ($mixedCard <= 0) {
                $mixedCard = round(max($totalAmount - $mixedCash, 0), 2);
            }

            if ($mixedCash <= 0) {
                $mixedCash = round(max($totalAmount - $mixedCard, 0), 2);
            }

            $sum = round($mixedCard + $mixedCash, 2);

            if (abs($sum - $totalAmount) > 0.01) {
                throw ValidationException::withMessages([
                    'mixed_card_amount' => 'Naqd va karta summasi jami summaga teng bo‘lishi kerak.',
                ]);
            }
        }

        $paidAmount = match ($paymentType) {
            'debt'    => 0.0,
            'partial' => $partialAmount ?? 0.0,
            default   => $totalAmount,
        };

        $remainingAmount = round(max($totalAmount - $paidAmount, 0), 2);

        try {
            DB::transaction(function () use ($sale, $paymentType, $paidAmount, $remainingAmount, $mixedCash, $mixedCard, $note): void {
                $this->clearSaleDebt($sale);

                $sale->update([
                    'payment_type'      => $paymentType,
                    'paid_amount'       => $paidAmount,
                    'remaining_amount'  => $remainingAmount,
                    'mixed_cash_amount' => $paymentType === 'mixed' ? $mixedCash : 0.0,
                    'mixed_card_amount' => $paymentType === 'mixed' ? $mixedCard : 0.0,
                    'status'            => Sale::STATUS_COMPLETED,
                ]);

                if ($remainingAmount > 0) {
                    $this->recordSaleDebt($sale, $remainingAmount, $note);
                }
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            report($throwable);

            Notification::make()
                ->title('Buyurtmani yakunlashda xatolik yuz berdi')
                ->body($throwable->getMessage())
                ->danger()
                ->send();

            return;
        }

        $sale->refresh();

        app(TelegramSaleNotifier::class)->notify($sale, 'completed');

        Notification::make()
            ->title("Sotuv #{$sale->id} yakunlandi")
            ->success()
            ->send();

        $this->resetTable();
    }

    protected function cancelPendingSale(Sale $sale): void
    {
        if (!$sale->isPending()) {
            Notification::make()
                ->title('Faqat pending statusdagi buyurtmalarni bekor qilish mumkin')
                ->warning()
                ->send();

            return;
        }

        try {
            DB::transaction(function () use ($sale): void {
                $this->clearSaleDebt($sale);
                $this->restockSaleItems($sale);

                $sale->update([
                    'status'            => Sale::STATUS_REJECTED,
                    'paid_amount'       => 0.0,
                    'remaining_amount'  => 0.0,
                    'mixed_cash_amount' => 0.0,
                    'mixed_card_amount' => 0.0,
                ]);
            });
        } catch (Throwable $throwable) {
            report($throwable);

            Notification::make()
                ->title('Buyurtmani bekor qilishda xatolik yuz berdi')
                ->body($throwable->getMessage())
                ->danger()
                ->send();

            return;
        }

        $sale->refresh();

        app(TelegramSaleNotifier::class)->notify($sale, 'canceled');

        Notification::make()
            ->title("Sotuv #{$sale->id} bekor qilindi")
            ->warning()
            ->send();

        $this->resetTable();
    }

    protected function clearSaleDebt(Sale $sale): void
    {
        $transactions = DebtorTransaction::where('sale_id', $sale->id)
            ->lockForUpdate()
            ->get();

        foreach ($transactions as $transaction) {
            $debtor = $transaction->debtor()->lockForUpdate()->first();

            if ($debtor) {
                if ($transaction->type === 'debt') {
                    $debtor->amount = max(0, $debtor->amount - $transaction->amount);
                } elseif ($transaction->type === 'payment') {
                    $debtor->amount += $transaction->amount;
                }

                $debtor->save();
            }

            $transaction->delete();
        }
    }

    protected function recordSaleDebt(Sale $sale, float $remainingAmount, ?string $note = null): void
    {
        $storeId  = $sale->store_id;
        $clientId = $sale->client_id;

        if (!$storeId || !$clientId) {
            throw ValidationException::withMessages([
                'sale' => 'Klient tanlanmaganligi sababli qarz qayd qilib bo‘lmaydi.',
            ]);
        }

        $debtor = Debtor::firstOrCreate(
            [
                'store_id'  => $storeId,
                'client_id' => $clientId,
            ],
            [
                'amount'   => 0,
                'currency' => 'uzs',
                'date'     => now(),
            ]
        );

        $addedAmount = (int) round($remainingAmount);
        $debtor->increment('amount', $addedAmount);

        DebtorTransaction::create([
            'debtor_id' => $debtor->id,
            'amount'    => $addedAmount,
            'type'      => 'debt',
            'date'      => now(),
            'sale_id'   => $sale->id,
            'note'      => $note ?: "Sotuv #{$sale->id}",
        ]);
    }

    protected function restockSaleItems(Sale $sale): void
    {
        $sale->loadMissing('items');

        foreach ($sale->items as $item) {
            if ($item->product_size_id) {
                ProductStock::where('product_size_id', $item->product_size_id)
                    ->where('stock_id', $item->stock_id)
                    ->lockForUpdate()
                    ->increment('quantity', $item->quantity);
            } else {
                ProductStock::whereNull('product_size_id')
                    ->where('product_id', $item->product_id)
                    ->where('stock_id', $item->stock_id)
                    ->lockForUpdate()
                    ->increment('quantity', $item->quantity);
            }
        }
    }
}
