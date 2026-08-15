@php
    /** @var \App\Models\Sale|null $sale */
    $paymentLabel = match($sale->payment_type) {
        'cash' => 'Naqd',
        'card' => 'Karta',
        'debt' => 'Qarz',
        'transfer' => 'O‘tkazma',
        'partial' => 'Qisman',
        'mixed' => 'Karta + Naqd',
        'preorder' => 'Oldindan buyurtma',
        default => ($sale->payment_type ?? '-'),
    };
    $statusLabel = match($sale->status) {
        \App\Models\Sale::STATUS_PENDING => 'Kutilmoqda',
        \App\Models\Sale::STATUS_REJECTED => 'Bekor qilingan',
        \App\Models\Sale::STATUS_COMPLETED => 'Yakunlangan',
        default => ucfirst($sale->status ?? '—'),
    };
    $statusBadge = match($sale->status) {
        \App\Models\Sale::STATUS_PENDING => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-500/10 dark:text-yellow-300',
        \App\Models\Sale::STATUS_REJECTED => 'bg-red-100 text-red-800 dark:bg-red-500/10 dark:text-red-300',
        default => 'bg-green-100 text-green-800 dark:bg-green-500/10 dark:text-green-300',
    };
    $customerDiscountLabel = match($sale->customer_discount_type) {
        'percent' => rtrim(rtrim(number_format((float) $sale->customer_discount_value, 2, '.', ''), '0'), '.') . '%',
        'fixed' => number_format((float) $sale->customer_discount_value, 2) . " so'm",
        default => '—',
    };
@endphp

<div class="space-y-4">
    @if(!$sale)
        <div class="text-sm text-gray-500">Sotuv topilmadi.</div>
    @else
        <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
                <div class="text-gray-500">Sale ID</div>
                <div class="font-medium">{{ $sale->id }}</div>
            </div>
            <div>
                <div class="text-gray-500">Sana</div>
                <div class="font-medium">{{ optional($sale->created_at)->format('Y-m-d H:i') }}</div>
            </div>
            <div>
                <div class="text-gray-500">Status</div>
                <div class="font-medium">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold {{ $statusBadge }}">
                        {{ $statusLabel }}
                    </span>
                </div>
            </div>
            <div>
                <div class="text-gray-500">Kassir</div>
                <div class="font-medium">{{ $sale->createdBy?->name ?? '—' }}</div>
            </div>
            <div>
                <div class="text-gray-500">Mijoz</div>
                <div class="font-medium">
                    {{ $sale->client?->full_name ?? '—' }}
                    @if($sale->client?->phone)
                        <span class="text-gray-500"> ({{ $sale->client->phone }})</span>
                    @endif
                </div>
            </div>
            <div>
                <div class="text-gray-500">To'lov turi</div>
                <div class="font-medium">{{ $paymentLabel ?? '—' }}</div>
            </div>
            <div>
                <div class="text-gray-500">Subtotal</div>
                <div class="font-medium">{{ number_format($sale->subtotal_amount ?: $sale->total_amount, 2) }}</div>
            </div>
            <div>
                <div class="text-gray-500">Avtomatik chegirma</div>
                <div class="font-medium">{{ number_format($sale->discount_total ?? 0, 2) }}</div>
            </div>
            <div>
                <div class="text-gray-500">Mijoz chegirmasi</div>
                <div class="font-medium">
                    {{ number_format($sale->customer_discount_amount ?? 0, 2) }}
                    @if(($sale->customer_discount_amount ?? 0) > 0)
                        <span class="text-gray-500">({{ $customerDiscountLabel }})</span>
                    @endif
                </div>
            </div>
            <div>
                <div class="text-gray-500">Jami summa</div>
                <div class="font-medium">{{ number_format($sale->total_amount, 2) }}</div>
            </div>
            <div>
                <div class="text-gray-500">To'langan</div>
                <div class="font-medium">{{ number_format($sale->paid_amount, 2) }}</div>
            </div>
            <div>
                <div class="text-gray-500">Qolgan</div>
                <div class="font-medium">{{ number_format($sale->remaining_amount, 2) }}</div>
            </div>
        </div>

        <div class="pt-4">
            <div class="text-sm text-gray-600 mb-2">Tovarlar</div>
            <div class="border rounded-md overflow-x-auto">
                <table class="min-w-[640px] w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="px-3 py-2 text-left">Mahsulot</th>
                            <th class="px-3 py-2 text-left">Sklad</th>
                            <th class="px-3 py-2 text-right">Miqdor</th>
                            <th class="px-3 py-2 text-right">Narx</th>
                            <th class="px-3 py-2 text-right">Chegirma</th>
                            <th class="px-3 py-2 text-right">Jami</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($sale->items as $item)
                        <tr class="border-t">
                            <td class="px-3 py-2">
                                {{ $item->product->name ?? ('#'.$item->product_id) }}@if($item->productSize?->size)<span class="text-gray-500">({{ $item->productSize->size }})</span>@endif
                            </td>
                            <td class="px-3 py-2">{{ $item->stock->name ?? ('#'.$item->stock_id) }}</td>
                            <td class="px-3 py-2 text-right">{{ $item->quantity }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format($item->price, 2) }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format($item->product_discount_total ?? 0, 2) }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format($item->total, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-3 py-4 text-center text-gray-500">Tovarlar mavjud emas</td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
