<?php

use App\Actions\ProcessWebhook;
use App\Jobs\ProcessWebhookJob;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Queue;

test('signed duplicate webhooks are recorded and settled only once', function () {
    config(['services.pixelforge.webhook_secret' => 'whsec_test']);
    $generation = reservedGeneration();
    Queue::fake([ProcessWebhookJob::class]);
    $payload = ['id' => 'evt_test', 'type' => 'generation.succeeded', 'data' => ['object' => pixelforgeObject($generation)]];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = now()->getTimestamp();
    $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_test');

    $this->call('POST', route('webhooks.pixelforge'), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => $signature], content: $body)->assertNoContent();
    $this->call('POST', route('webhooks.pixelforge'), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => $signature], content: $body)->assertNoContent();
    app(ProcessWebhook::class)->handle(WebhookEvent::query()->firstOrFail()->id);
    app(ProcessWebhook::class)->handle(WebhookEvent::query()->firstOrFail()->id);

    $this->assertDatabaseCount('webhook_events', 1);
    $this->assertDatabaseCount('generation_charges', 1);
    expect(WebhookEvent::query()->firstOrFail()->processed_at)->not->toBeNull();
    Queue::assertPushed(ProcessWebhookJob::class);
});

test('rejects invalid and expired webhook signatures', function (string $secret, int $offset) {
    config(['services.pixelforge.webhook_secret' => 'whsec_test']);
    $body = '{"id":"evt_ping","type":"ping"}';
    $timestamp = now()->getTimestamp() + $offset;
    $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    $this->call('POST', route('webhooks.pixelforge'), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => $signature], content: $body)->assertBadRequest();

    $this->assertDatabaseCount('webhook_events', 0);
})->with([['wrong', 0], ['whsec_test', -301], ['whsec_test', 301]]);

test('signed ping events are acknowledged and processed without a generation', function () {
    config(['services.pixelforge.webhook_secret' => 'whsec_test']);
    Queue::fake([ProcessWebhookJob::class]);
    $body = '{"id":"evt_ping","type":"ping"}';
    $timestamp = now()->getTimestamp();
    $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_test');

    $this->call('POST', route('webhooks.pixelforge'), server: ['CONTENT_TYPE' => 'application/json', 'HTTP_PIXELFORGE_SIGNATURE' => $signature], content: $body)->assertNoContent();
    app(ProcessWebhook::class)->handle(WebhookEvent::query()->firstOrFail()->id);

    expect(WebhookEvent::query()->firstOrFail()->processed_at)->not->toBeNull();
    Queue::assertPushed(ProcessWebhookJob::class);
});

test('rejects missing or malformed signatures before reading captures', function (string $signature) {
    config(['services.pixelforge.webhook_secret' => 'whsec_test']);

    $this->call('POST', route('webhooks.pixelforge'), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => $signature,
    ], content: '{"id":"evt_ping","type":"ping"}')->assertBadRequest();

    $this->assertDatabaseCount('webhook_events', 0);
})->with([
    'missing signature' => [''],
    'malformed signature' => ['t=invalid,v1=invalid'],
]);
