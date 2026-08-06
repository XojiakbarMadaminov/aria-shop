<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\SmsLog;
use App\Services\SmsService;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendMassSmsJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public function __construct(
        public SmsLog $smsLog
    ) {}

    public function handle(SmsService $smsService): void
    {
        $this->smsLog->update([
            'status' => 'processing',
        ]);

        $successCount = 0;
        $failedCount  = 0;

        try {
            $clientsQuery = Client::query()
                ->where('send_sms', true)
                ->whereNotNull('phone')
                ->where('phone', '!=', '');

            $totalClients = $clientsQuery->count();

            $this->smsLog->update([
                'total_clients' => $totalClients,
            ]);

            $clientsQuery->chunk(100, function ($clients) use ($smsService, &$successCount, &$failedCount) {
                foreach ($clients as $client) {
                    $result = $smsService->sendSms($client->phone, $this->smsLog->content);

                    if ($result['success'] ?? false) {
                        $successCount++;
                    } else {
                        $failedCount++;
                    }
                }
            });

            $this->smsLog->update([
                'successful_count' => $successCount,
                'failed_count'     => $failedCount,
                'status'           => 'completed',
            ]);
        } catch (\Throwable $e) {
            Log::error('SendMassSmsJob xatolik yuz berdi: ' . $e->getMessage(), [
                'sms_log_id' => $this->smsLog->id,
                'trace'      => $e->getTraceAsString(),
            ]);

            $this->smsLog->update([
                'successful_count' => $successCount,
                'failed_count'     => $failedCount,
                'status'           => 'failed',
            ]);

            throw $e;
        }
    }
}
