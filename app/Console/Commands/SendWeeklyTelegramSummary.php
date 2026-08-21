<?php

namespace App\Console\Commands;

use Illuminate\Support\Carbon;
use Illuminate\Console\Command;
use App\Services\TelegramWeeklySummaryService;

class SendWeeklyTelegramSummary extends Command
{
    protected $signature   = 'telegram:send-weekly-summary {--date= : Hisobot olinadigan hafta sanasi (Y-m-d)}';
    protected $description = 'Haftalik sotuv va foyda hisobotini (taqqoslash va motivatsion xulosalar bilan) Telegram guruhiga yuboradi';

    public function __construct(private TelegramWeeklySummaryService $summaryService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dateOption = $this->option('date');

        try {
            $date = $dateOption
                ? Carbon::parse($dateOption, 'Asia/Tashkent')
                : now('Asia/Tashkent');
        } catch (\Throwable $e) {
            $this->error("Noto'g'ri sana formati berildi. Format: YYYY-MM-DD");

            return self::INVALID;
        }

        $success = $this->summaryService->sendForDate($date);

        if ($success) {
            $this->info("Haftalik hisobot Telegram'ga yuborildi (Sana: {$date->format('d.m.Y')}).");

            return self::SUCCESS;
        }

        $this->warn("Haftalik Telegram hisobotini yuborib bo'lmadi. Log faylini yoki sozlamalarni tekshiring.");

        return self::FAILURE;
    }
}
