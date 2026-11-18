<?php

use App\Actions\ReconcileGenerations;
use App\Actions\SubmitGeneration;
use App\Enums\GenerationStatus;
use App\Models\Wallet;
use Illuminate\Support\Facades\Http;

test('lost provider responses retain credits and reconcile by local metadata', function (string $state) {
    config(['services.pixelforge.key' => 'test-key']);
    $generation = reservedGeneration();
    $generation->update(['submission_state' => $state, 'submission_attempts' => 5]);
    Http::preventStrayRequests();
    Http::fake(['https://pixelforge.promptedit.dev/v1/generations?*' => Http::response(['data' => [pixelforgeObject($generation)], 'has_more' => false])]);

    app(ReconcileGenerations::class)->handle();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Succeeded);
    expect(Wallet::query()->firstOrFail()->reserved)->toBe(0);
    Http::assertSentCount(1);
})->with(['uncertain', 'submitting']);

test('accepted failed generations are recovered by polling', function () {
    config(['services.pixelforge.key' => 'test-key']);
    $generation = reservedGeneration();
    $generation->update(['provider_id' => 'gen_poll', 'submission_state' => 'accepted']);
    Http::preventStrayRequests();
    Http::fake(['https://pixelforge.promptedit.dev/v1/generations/gen_poll' => Http::response(pixelforgeObject($generation, 'failed'))]);

    app(ReconcileGenerations::class)->handle();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Failed);
    expect(Wallet::query()->firstOrFail()->reserved)->toBe(0);
});

test('exhausted confirmed rejections release funds', function () {
    config(['services.pixelforge.key' => 'test-key']);
    $generation = reservedGeneration();
    $generation->update(['submission_attempts' => 4]);
    Http::preventStrayRequests();
    Http::fake(['https://pixelforge.promptedit.dev/v1/pricing' => Http::response(pixelforgePricing()), 'https://pixelforge.promptedit.dev/v1/generations' => Http::response(['error' => ['code' => 'overloaded']], 503)]);

    app(SubmitGeneration::class)->handle($generation->id);

    expect($generation->fresh()->status)->toBe(GenerationStatus::Failed);
    expect(Wallet::query()->firstOrFail()->reserved)->toBe(0);
});

test('repeated pricing outages exhaust retries and release an unsubmitted reservation', function () {
    config(['services.pixelforge.key' => 'test-key']);
    $generation = reservedGeneration();
    $generation->update(['submission_attempts' => 4]);
    Http::preventStrayRequests();
    Http::fake(['https://pixelforge.promptedit.dev/v1/pricing' => Http::response(['error' => ['code' => 'overloaded']], 503)]);

    app(SubmitGeneration::class)->handle($generation->id);

    expect($generation->fresh()->status)->toBe(GenerationStatus::Failed);
    expect($generation->fresh()->submission_attempts)->toBe(5);
    expect(Wallet::query()->firstOrFail()->reserved)->toBe(0);
});

test('scheduler releases an unsubmitted final attempt after a worker crash', function () {
    config(['services.pixelforge.key' => 'test-key']);
    $generation = reservedGeneration();
    $generation->update(['submission_state' => 'pending', 'submission_attempts' => 5]);
    Http::preventStrayRequests();

    app(ReconcileGenerations::class)->handle();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Failed);
    expect(Wallet::query()->firstOrFail()->reserved)->toBe(0);
    Http::assertNothingSent();
});
