<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('debtors:send-sms')->dailyAt('9:30')->timezone('Asia/Tashkent');
Schedule::command('telegram:send-daily-summary')->dailyAt('21:35')->timezone('Asia/Tashkent');
Schedule::command('telegram:send-weekly-summary')->weeklyOn(1, '05:00')->timezone('Asia/Tashkent');
