<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\Store;
use Telegram\Bot\Api;
use App\Models\SaleItem;
use Illuminate\Support\Carbon;
use App\Models\TelegramSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class TelegramWeeklySummaryService
{
    /**
     * Sends the weekly summary report for the completed week containing the given date.
     * By default, it reports on the previous full week (Monday - Sunday) compared to the week before it.
     */
    public function sendForDate(?Carbon $referenceDate = null): bool
    {
        [$botToken, $chatId] = $this->resolveCredentials();

        if (blank($botToken) || blank($chatId)) {
            Log::warning('Weekly Telegram summary skipped due to missing credentials.');

            return false;
        }

        $ref = ($referenceDate ?? now('Asia/Tashkent'))->copy();

        // Target report week (e.g. Previous week: Monday to Sunday)
        $currentWeekStart = $ref->copy()->subWeek()->startOfWeek();
        $currentWeekEnd   = $ref->copy()->subWeek()->endOfWeek();

        // Comparison week (The week before target report week: Monday to Sunday)
        $previousWeekStart = $ref->copy()->subWeeks(2)->startOfWeek();
        $previousWeekEnd   = $ref->copy()->subWeeks(2)->endOfWeek();

        $stores = Store::query()->orderBy('name')->get();

        if ($stores->isEmpty()) {
            Log::warning('Weekly Telegram summary skipped because no stores were found.');

            return false;
        }

        $summaries = $stores->map(function (Store $store) use (
            $currentWeekStart,
            $currentWeekEnd,
            $previousWeekStart,
            $previousWeekEnd
        ) {
            $currentMetrics  = $this->collectStoreMetrics($currentWeekStart, $currentWeekEnd, $store);
            $previousMetrics = $this->collectStoreMetrics($previousWeekStart, $previousWeekEnd, $store);

            return [
                'store'      => $store,
                'current'    => $currentMetrics,
                'previous'   => $previousMetrics,
                'comparison' => $this->calculateComparison($currentMetrics, $previousMetrics),
                'motivation' => $this->getRandomMotivation($currentMetrics['profit'], $previousMetrics['profit']),
            ];
        })->values();

        $message = $this->formatMessage($summaries, $currentWeekStart, $currentWeekEnd);

        if (blank($message)) {
            return false;
        }

        try {
            $client = new Api($botToken);
            $client->sendMessage([
                'chat_id'    => $chatId,
                'text'       => $message,
                'parse_mode' => 'HTML',
            ]);
        } catch (\Throwable $throwable) {
            Log::error('Failed to send weekly Telegram summary.', [
                'error' => $throwable->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveCredentials(): array
    {
        $settings = TelegramSetting::query()->first();

        $botToken = $settings?->bot_token ?: config('services.telegram.bot_token');
        $chatId   = $settings?->sales_chat_id ?: config('services.telegram.sales_chat_id');

        return [$botToken, $chatId];
    }

    /**
     * Collects sales and profit metrics for a given store within a date range.
     *
     * @return array{sales: float, profit: float, count: int}
     */
    public function collectStoreMetrics(Carbon $start, Carbon $end, Store $store): array
    {
        $saleQuery = Sale::withoutGlobalScopes()
            ->whereBetween('created_at', [$start, $end])
            ->where('status', Sale::STATUS_COMPLETED)
            ->where('store_id', $store->id);

        $totalSales = (float) (clone $saleQuery)->sum('total_amount');
        $salesCount = (int) (clone $saleQuery)->count();

        $totalCost = SaleItem::withoutGlobalScopes()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->whereBetween('sales.created_at', [$start, $end])
            ->where('sales.status', Sale::STATUS_COMPLETED)
            ->where('sales.store_id', $store->id)
            ->selectRaw('COALESCE(SUM(COALESCE(products.initial_price, 0) * sale_items.quantity), 0) AS cost')
            ->value('cost');

        $totalProfit = $totalSales - (float) ($totalCost ?? 0);

        return [
            'sales'  => $totalSales,
            'profit' => $totalProfit,
            'count'  => $salesCount,
        ];
    }

    /**
     * Calculates differences and percentages between current week and previous week.
     *
     * @param  array{sales: float, profit: float, count: int}  $current
     * @param  array{sales: float, profit: float, count: int}  $previous
     * @return array{sales_diff: float, sales_percent: float, profit_diff: float, profit_percent: float}
     */
    public function calculateComparison(array $current, array $previous): array
    {
        $salesDiff    = $current['sales'] - $previous['sales'];
        $salesPercent = $this->calculatePercentageChange($current['sales'], $previous['sales']);

        $profitDiff    = $current['profit'] - $previous['profit'];
        $profitPercent = $this->calculatePercentageChange($current['profit'], $previous['profit']);

        return [
            'sales_diff'     => $salesDiff,
            'sales_percent'  => $salesPercent,
            'profit_diff'    => $profitDiff,
            'profit_percent' => $profitPercent,
        ];
    }

    private function calculatePercentageChange(float $current, float $previous): float
    {
        if ($previous == 0.0) {
            return $current > 0.0 ? 100.0 : ($current < 0.0 ? -100.0 : 0.0);
        }

        return (($current - $previous) / abs($previous)) * 100;
    }

    /**
     * Returns a dynamic motivational text based on profit growth/decline.
     */
    public function getRandomMotivation(float $currentProfit, float $previousProfit): string
    {
        if ($currentProfit > $previousProfit) {
            $quotes = [
                "Ajoyib natija! Mehnat va jamoaviy birdamlik o'z samarasini berdi. Ushbu sur'atni yangi haftada ham saqlab qolamiz va yangi cho'qqilarni zabt etamiz! 🚀",
                "Barakalla, jamoa! Har bir harakat o'z mevasini berdi va foyda o'sishiga erishildi. Yangi haftada bundan-da yuqori marralar sari olg'a! 🌟",
                "Muvaffaqiyat — bu to'g'ri strategiya va intizom natijasi. O'tgan haftadagi o'sish barchamizning umumiy g'alabamiz. Yangi haftada yangi rekordlar kutmoqda! 🏆",
                "Katta natijalar kichik qadamlarning izchilligidan boshlanadi. Bu haftadagi ijobiy o'sish jamoamizning kuchini ko'rsatdi. To'xtash yo'q! 📈",
                "Natijalar ko'zni quvontiradi! Jamoaning har bir a'zosiga fidokorona mehnati uchun rahmat. Ushbu haftani ham g'alaba bilan boshlaymiz! 💼✨",
                "G'alaba tasodif emas, balki qilingan sa'y-harakatlar mahsulidir. Zo'r ko'rsatkich! Yangi haftada ham shu shiddatni davom ettiramiz! 🔥",
                "O'sish sur'ati a'lo darajada! Rejalashtirilgan marralar birin-ketin zabt etilmoqda. Yangi haftaga yangi kuch va ishonch bilan qadam qo'yamiz! 🥇",
                'Muvaffaqiyatli hafta ortda qoldi. Bu natija har biringizning mehnatingiz samarasi. Yangi haftada yanada yaxshiroq natijalarga erishamiz! 🎯',
                "Zo'r natija! Har bir mijozga e'tibor va sifatli xizmat o'z samarasini ko'rsatdi. O'sishda davom etamiz! 👏",
                "Yuqori natijalar uchun butun jamoaga tashakkur! Mehnat qilishdan va o'sishdan to'xtamaymiz, yangi hafta — yangi imkoniyatlar haftasi bo'lsin! 🚀",
            ];
        } elseif ($currentProfit < $previousProfit) {
            $quotes = [
                'Har bir pasayish — bu kelgusi katta sakrash uchun imkoniyatdir. Xatolarni tahlil qilamiz va yangi haftada yanada kuchliroq qaytamiz! 💪',
                "Qiyinchiliklar bizni yanada kuchli qiladi. O'tgan hafta natijalari bizga qayerda yaxshilanishimiz kerakligini ko'rsatdi. Yangi haftada yangi g'ayrat bilan olg'a! ⚡",
                "Muvaffaqiyat sari yo'l har doim tekis bo'lmaydi. Muhimi — to'xtamaslik va xulosalar chiqarish. Yangi haftani kuchliroq start bilan boshlaymiz! 🎯",
                "Bu hafta o'zimiz kutgan natijaga yetmagan bo'lsak-da, bu bizga yangi kuch va tajriba berdi. Yangi haftada barcha imkoniyatlarni ishga solamiz! 🚀",
                "Chekinish yo'q, faqat olg'a! Yangi hafta — yangi sahifa va yangi imkoniyatlar demakdir. Barchamiz birgalikda o'sishga erishamiz! 🔥",
                "Bozorda har doim to'lqinlar bo'ladi, lekin kuchli jamoa har doim o'z o'rnini topadi. Keling, yangi haftada sotuvlarni maksimal darajaga ko'taramiz! 💼",
                "O'tgan hafta natijalarini to'g'ri tahlil qilib, harakat rejamizni kuchaytiramiz. Bu haftada barcha yo'qotishlarning o'rnini to'ldiramiz va yangi cho'qqilarga chiqamiz! 📈",
                "Hech qachon taslim bo'lmaymiz! Birgalikdagi harakat va to'g'ri yondashuv orqali yangi haftada rekord ko'rsatkichlarga erishishimizga ishonamiz! 🤝",
                'Har bir qiyin hafta — bu mahoratimizni charxlash uchun saboqdir. Yangi haftaga yangi energiya va katta maqsadlar bilan kirishamiz! 🌟',
                "Yiqilish emas, qayta turib olg'a intilish muhim. Jamoamiz bunga qodir! Yangi haftani kuchli g'alabalar bilan bezaymiz! 💥",
            ];
        } else {
            $quotes = [
                "Barqarorlik — bu mustahkam poydevor. Endigi vazifamiz — ushbu poydevor ustida yangi o'sish bosqichiga ko'tarilishdir! 🎯",
                "Natijalar barqaror saqlanmoqda. Yangi haftada bir oz ko'proq tashabbus va harakat bilan o'sish tomon yo'l olamiz! 🚀",
                "Barqaror natija yaxshi, lekin bizning maqsadimiz doimiy o'sish! Yangi haftada yanada yaxshiroq natijalar sari birgalikda olg'a! 💼",
            ];
        }

        return $quotes[array_rand($quotes)];
    }

    /**
     * @param  Collection<int, array{store: Store, current: array{sales: float, profit: float, count: int}, previous: array{sales: float, profit: float, count: int}, comparison: array{sales_diff: float, sales_percent: float, profit_diff: float, profit_percent: float}, motivation: string}>  $summaries
     */
    public function formatMessage(Collection $summaries, Carbon $startDate, Carbon $endDate): string
    {
        $lines = [
            '📊 <b>HAFTALIK SAVDO VA FOYDA HISOBOTI</b>',
            '🗓 <b>Davr:</b> ' . $startDate->format('d.m.Y') . ' - ' . $endDate->format('d.m.Y'),
            '<i>(O\'tgan to\'liq hafta ko\'rsatkichlari)</i>',
            '',
        ];

        foreach ($summaries as $index => $summary) {
            $store      = $summary['store'];
            $current    = $summary['current'];
            $previous   = $summary['previous'];
            $comparison = $summary['comparison'];
            $motivation = $summary['motivation'];
            $currency   = $store->currency ?? "so'm";

            $salesBadge  = $this->formatChangeBadge($comparison['sales_diff'], $comparison['sales_percent'], $currency);
            $profitBadge = $this->formatChangeBadge($comparison['profit_diff'], $comparison['profit_percent'], $currency);

            $lines[] = '━━━━━━━━━━━━━━━━━━━━━';
            $lines[] = '🏪 <b>' . htmlspecialchars($store->name) . '</b>';
            $lines[] = '';
            $lines[] = '🛒 <b>Sotuvlar:</b>';
            $lines[] = '  • Ushbu hafta: ' . $this->formatCurrency($current['sales'], $currency) . ' (' . $current['count'] . ' ta sotuv)';
            $lines[] = '  • Oldingi hafta: ' . $this->formatCurrency($previous['sales'], $currency) . ' (' . $previous['count'] . ' ta sotuv)';
            $lines[] = '  • O\'zgarish: ' . $salesBadge;
            $lines[] = '';
            $lines[] = '💹 <b>Foyda:</b>';
            $lines[] = '  • Ushbu hafta: ' . $this->formatCurrency($current['profit'], $currency);
            $lines[] = '  • Oldingi hafta: ' . $this->formatCurrency($previous['profit'], $currency);
            $lines[] = '  • O\'zgarish: ' . $profitBadge;
            $lines[] = '';
            $lines[] = '💡 <b>Hafta xulosasi:</b>';
            $lines[] = '<i>"' . htmlspecialchars($motivation) . '"</i>';

            if ($index === $summaries->count() - 1) {
                $lines[] = '━━━━━━━━━━━━━━━━━━━━━';
            }
        }

        return implode(PHP_EOL, $lines);
    }

    private function formatChangeBadge(float $diff, float $percent, string $currency): string
    {
        if ($diff > 0) {
            return '🟢 <b>+' . number_format($percent, 1, '.', ' ') . '%</b> (+' . $this->formatCurrency($diff, $currency) . ')';
        }

        if ($diff < 0) {
            return '🔴 <b>' . number_format($percent, 1, '.', ' ') . '%</b> (' . $this->formatCurrency($diff, $currency) . ')';
        }

        return '⚪ <b>0.0%</b> (0 ' . $currency . ')';
    }

    private function formatCurrency(float $value, string $currency): string
    {
        return number_format($value, 0, '.', ' ') . ' ' . $currency;
    }
}
