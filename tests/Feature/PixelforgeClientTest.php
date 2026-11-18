<?php

use App\Exceptions\ProviderException;
use App\Models\Generation;
use App\Services\PixelforgeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('submission sends only supported fields and a stable idempotency key', function () {
    config(['services.pixelforge.key' => 'test-key']);
    $generation = Generation::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['https://pixelforge.promptedit.dev/v1/generations' => Http::response(pixelforgeObject($generation, 'queued'), 202)]);

    app(PixelforgeClient::class)->submit($generation);

    Http::assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key', $generation->id) && $request->hasHeader('Authorization', 'Bearer test-key') && array_keys($request->data()) === ['prompt', 'size', 'quality', 'reference_images', 'metadata'] && $request['metadata']['local_generation_id'] === $generation->id);
});

test('provider failures are classified for safe retry decisions', function (int $status, string $code, bool $retryable, bool $uncertain) {
    config(['services.pixelforge.key' => 'test-key']);
    $generation = Generation::factory()->create();
    Http::preventStrayRequests();
    Http::fake(['https://pixelforge.promptedit.dev/v1/generations' => Http::response(['error' => ['code' => $code]], $status, ['Retry-After' => '5'])]);

    try {
        app(PixelforgeClient::class)->submit($generation);
        test()->fail('Expected a provider exception');
    } catch (ProviderException $exception) {
        expect($exception->providerCode)->toBe($code);
        expect($exception->retryable)->toBe($retryable);
        expect($exception->uncertain)->toBe($uncertain);
        expect($exception->retryAfter)->toBe(5);
    }
})->with([[429, 'concurrency_limit_exceeded', true, false], [429, 'daily_limit_exceeded', false, false], [503, 'overloaded', true, false], [502, 'provider_error', true, true], [400, 'content_policy_violation', false, false], [401, 'invalid_api_key', false, false]]);
