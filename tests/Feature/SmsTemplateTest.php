<?php

use App\Models\Client;
use App\Models\SmsLog;
use App\Models\SmsTemplate;
use App\Jobs\SendMassSmsJob;
use App\Services\SmsService;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\assertDatabaseHas;

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('can create sms template', function () {
    $template = SmsTemplate::create([
        'content' => 'Chegirma xabari!',
    ]);

    expect($template->content)->toBe('Chegirma xabari!');
    assertDatabaseHas('sms_templates', [
        'content' => 'Chegirma xabari!',
    ]);
});

it('dispatches mass sms job', function () {
    Queue::fake();

    Client::factory()->create(['phone' => '+998901234567']);
    Client::factory()->create(['phone' => '+998909876543']);

    $template = SmsTemplate::create([
        'content' => 'Hurmatli mijoz, chegirmalarimiz boshlandi!',
    ]);

    $smsLog = SmsLog::create([
        'sms_template_id'  => $template->id,
        'content'          => $template->content,
        'total_clients'    => 2,
        'successful_count' => 0,
        'failed_count'     => 0,
        'status'           => 'pending',
    ]);

    SendMassSmsJob::dispatch($smsLog);

    Queue::assertPushed(SendMassSmsJob::class, function ($job) use ($smsLog) {
        return $job->smsLog->id === $smsLog->id;
    });
});

it('processes send mass sms job correctly', function () {
    $smsServiceMock = Mockery::mock(SmsService::class);
    $smsServiceMock->shouldReceive('sendSms')
        ->twice()
        ->andReturn(['success' => true]);

    $this->app->instance(SmsService::class, $smsServiceMock);

    Client::factory()->create(['phone' => '+998901234567']);
    Client::factory()->create(['phone' => '+998909876543']);

    $template = SmsTemplate::create([
        'content' => 'Test SMS',
    ]);

    $smsLog = SmsLog::create([
        'sms_template_id'  => $template->id,
        'content'          => $template->content,
        'total_clients'    => 2,
        'successful_count' => 0,
        'failed_count'     => 0,
        'status'           => 'pending',
    ]);

    $job = new SendMassSmsJob($smsLog);
    $job->handle($smsServiceMock);

    expect($smsLog->fresh()->status)->toBe('completed')
        ->and($smsLog->fresh()->successful_count)->toBe(2)
        ->and($smsLog->fresh()->failed_count)->toBe(0);
});
