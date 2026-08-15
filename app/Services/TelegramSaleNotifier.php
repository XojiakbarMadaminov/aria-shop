<?php

namespace App\Services;

use Throwable;
use App\Models\Sale;
use Telegram\Bot\Api;
use App\Models\SaleItem;
use App\Models\TelegramSetting;
use Illuminate\Support\Facades\Log;

class TelegramSaleNotifier
{
    public function notify(Sale $sale, string $event = 'created'): void
    {
        $settings = TelegramSetting::query()->first();

        $botToken = $settings?->bot_token ?: config('services.telegram.bot_token');
        $chatId   = $settings?->sales_chat_id ?: config('services.telegram.sales_chat_id');

        if (blank($botToken) || blank($chatId)) {
            Log::warning('TelegramSaleNotifier missing configuration', [
                'sale_id'   => $sale->id,
                'bot_token' => blank($botToken) ? 'empty' : 'set',
                'chat_id'   => blank($chatId) ? 'empty' : 'set',
            ]);

            return;
        }

        try {
            $telegram = new Api($botToken);
            $message  = $this->buildMessage($sale, $event);

            if (blank($message)) {
                return;
            }

            $telegram->sendMessage([
                'chat_id' => $chatId,
                'text'    => $message,
            ]);
        } catch (Throwable $exception) {
            Log::error('Failed to send sale notification to Telegram', [
                'sale_id' => $sale->id,
                'error'   => $exception->getMessage(),
            ]);
        }
    }

    private function buildMessage(Sale $sale, string $event): string
    {
        $sale->loadMissing(['client', 'items.product', 'items.productSize', 'store', 'createdBy']);

        $title       = $this->messageTitle($event);
        $storeName   = $sale->store?->name ?? "Noma'lum do'kon";
        $clientName  = $sale->client?->full_name ?? '-';
        $cashierName = $sale->createdBy?->name ?? "Noma'lum kassir";
        $total       = number_format((float) $sale->total_amount, 0, '.', ' ');
        $currency    = $this->resolveCurrency($sale);
        $payment     = $this->paymentTypeLabel($sale->payment_type);
        $date        = optional($sale->created_at)->format('d.m.Y H:i') ?? now()->format('d.m.Y H:i');
        $hr          = str_repeat('-', 50);

        $itemLines = $sale->items
            ->map(fn (SaleItem $item): string => $this->formatItemLine($item, $currency))
            ->filter()
            ->implode(PHP_EOL . PHP_EOL);

        if (blank($itemLines)) {
            $itemLines = '- Mahsulotlar mavjud emas';
        }

        $lines = [
            $title,
            "#️⃣ Sotuv ID: {$sale->id}",
            "🏪 Do'kon: {$storeName}",
            "👤 Mijoz: {$clientName}",
            "🧑‍💼 Kassir: {$cashierName}",
            "💰 Summasi: {$total} {$currency}",
            "💳 To'lov turi: {$payment}",
            '📦 Mahsulotlar:',
            $hr,
            $itemLines,
            $hr,
        ];

        $lines = array_merge($lines, $this->formatTotalsLines($sale, $currency));

        if ($sale->payment_type === 'partial' || (float) $sale->paid_amount > 0) {
            $paid    = number_format((float) $sale->paid_amount, 0, '.', ' ');
            $lines[] = "💵 To'langan: {$paid} {$currency}";
        }

        if ((float) $sale->remaining_amount > 0) {
            $remaining = number_format((float) $sale->remaining_amount, 0, '.', ' ');
            $lines[]   = "📉 Qolgan qarz: {$remaining} {$currency}";
        }

        $lines[] = "⏰ Sana: {$date}";

        return implode(PHP_EOL, array_filter($lines));
    }

    private function formatItemLine(SaleItem $item, string $currency): string
    {
        $name     = $item->product?->name ?? 'Mahsulot';
        $size     = $item->productSize?->size;
        $quantity = (float) ($item->quantity ?? 0);
        $price    = (float) ($item->price ?? 0);

        if (filled($size)) {
            $name .= sprintf(' (%s)', $size);
        }

        $lineSubtotal = (float) ($item->subtotal_amount ?: ($quantity * $price));
        $lineDiscount = (float) ($item->product_discount_total ?? 0);
        $lineTotal    = (float) ($item->total ?: ($lineSubtotal - $lineDiscount));

        $lines = [
            $name,
            sprintf(
                '%s x %s = %s %s',
                $this->formatQuantity($quantity),
                $this->formatMoney($price),
                $this->formatMoney($lineTotal),
                $currency
            ),
        ];

        if ($lineDiscount > 0) {
            $lines[] = 'Chegirma: -' . $this->formatMoney($lineDiscount) . " {$currency}";
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @return array<int, string>
     */
    private function formatTotalsLines(Sale $sale, string $currency): array
    {
        $quantityTotal          = (float) $sale->items->sum('quantity');
        $subtotal               = (float) ($sale->subtotal_amount ?: $sale->items->sum('total'));
        $discountTotal          = (float) ($sale->discount_total ?? 0);
        $customerDiscountAmount = (float) ($sale->customer_discount_amount ?? 0);
        $total                  = (float) $sale->total_amount;

        $lines = [
            'Jami mahsulotlar: ' . $this->formatQuantity($quantityTotal) . ' dona',
        ];

        if ($discountTotal > 0 || $customerDiscountAmount > 0) {
            $lines[] = 'Subtotal: ' . $this->formatMoney($subtotal) . " {$currency}";
        }

        if ($discountTotal > 0) {
            $lines[] = 'Avtomatik chegirma: -' . $this->formatMoney($discountTotal) . " {$currency}";
        }

        if ($customerDiscountAmount > 0) {
            $customerDiscountValue = (float) ($sale->customer_discount_value ?? 0);
            $customerDiscountLabel = $sale->customer_discount_type === CustomerDiscountService::TYPE_PERCENT
                ? $this->formatQuantity($customerDiscountValue) . '%'
                : $this->formatMoney($customerDiscountValue) . " {$currency}";

            $lines[] = 'Mijoz chegirmasi (' . $customerDiscountLabel . '): -'
                . $this->formatMoney($customerDiscountAmount) . " {$currency}";
        }

        $lines[] = 'JAMI SUMMA: ' . $this->formatMoney($total) . " {$currency}";

        return $lines;
    }

    private function formatQuantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
    }

    private function formatMoney(float $value): string
    {
        return number_format($value, 0, '.', ' ');
    }

    private function paymentTypeLabel(?string $type): string
    {
        return match ($type) {
            'cash'     => 'Naqd',
            'card'     => 'Karta',
            'debt'     => 'Qarz',
            'partial'  => 'Qisman',
            'mixed'    => 'Karta + Naqd',
            'transfer' => "O'tkazma",
            'preorder' => 'Oldindan buyurtma',
            default    => "Noma'lum",
        };
    }

    private function resolveCurrency(Sale $sale): string
    {
        return $sale->store?->currency ?? "so'm";
    }

    private function messageTitle(string $event): string
    {
        return match ($event) {
            'completed' => '✅ Oldindan buyurtma yakunlandi!',
            'canceled'  => '⚠️ Oldindan buyurtma qilindi!',
            default     => '🧾 Yangi sotuv!',
        };
    }
}
