<?php

use App\Actions\ApplyGenerationUpdate;
use App\Enums\GenerationStatus;
use App\Events\GenerationUpdated;
use App\Models\GenerationCharge;
use App\Models\GenerationOutput;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Support\Facades\Event;

test('success settles the exact charge once despite duplicate and out of order updates', function () {
    $generation = reservedGeneration();
    $object = pixelforgeObject($generation);
    Event::fake([GenerationUpdated::class]);
    $action = app(ApplyGenerationUpdate::class);

    $action->handle($generation->id, $object);
    $action->handle($generation->id, $object);
    $action->handle($generation->id, pixelforgeObject($generation, 'processing'));

    $charge = GenerationCharge::query()->firstOrFail();
    expect($charge->provider_cost)->toBe(305_510_000_000);
    expect($charge->user_charge)->toBe(381_887_500_000);
    expect($generation->fresh()->status)->toBe(GenerationStatus::Succeeded);
    expect(Wallet::query()->firstOrFail()->balance)->toBe(9_618_112_500_000);
    expect(Wallet::query()->firstOrFail()->reserved)->toBe(0);
    $this->assertDatabaseCount('generation_charges', 1);
    $this->assertDatabaseCount('generation_outputs', 1);
    $this->assertDatabaseCount('wallet_transactions', 1);
    Event::assertDispatched(GenerationUpdated::class, 1);
});

test('failed generation releases its reservation and charges nothing', function () {
    $generation = reservedGeneration();

    app(ApplyGenerationUpdate::class)->handle($generation->id, pixelforgeObject($generation, 'failed'));

    $this->assertDatabaseCount('generation_charges', 0);
    $this->assertDatabaseHas('wallets', ['balance' => 10_000_000_000_000, 'reserved' => 0]);
    $this->assertDatabaseHas('generation_reservations', ['status' => 'released']);
});

test('unexpected final rate increases preserve exact billing and flag the deficit', function () {
    $generation = reservedGeneration(balance: 500_000_000_000);
    $object = pixelforgeObject($generation);
    $object['rates']['output_tokens'] = '400.00';

    app(ApplyGenerationUpdate::class)->handle($generation->id, $object);

    expect(Wallet::query()->firstOrFail()->balance)->toBe(-2_784_387_500_000);
    expect(GenerationCharge::query()->firstOrFail()->user_charge)->toBe(3_284_387_500_000);
});

test('malformed success rolls back state and retains the reservation', function () {
    $generation = reservedGeneration();
    $object = pixelforgeObject($generation);
    $object['usage'] = null;

    expect(fn () => app(ApplyGenerationUpdate::class)->handle($generation->id, $object))->toThrow(InvalidArgumentException::class);

    $this->assertDatabaseCount('generation_charges', 0);
    $this->assertDatabaseCount('generation_outputs', 0);
    expect($generation->fresh()->status)->toBe(GenerationStatus::Queued);
    $this->assertDatabaseHas('generation_reservations', ['status' => 'held']);
});

test('multiple outputs are returned in order without multiplying the generation charge', function () {
    $generation = reservedGeneration();
    app(ApplyGenerationUpdate::class)->handle($generation->id, pixelforgeObject($generation));
    GenerationOutput::query()->create(['generation_id' => $generation->id, 'position' => 1, 'media_type' => 'image/png', 'url' => 'https://example.com/second.png', 'expires_at' => now()->subSecond()]);

    $this->actingAs(User::query()->findOrFail($generation->user_id))->getJson(route('generations.show', $generation))->assertOk()->assertJsonCount(2, 'data.outputs')->assertJsonPath('data.outputs.0.position', 0)->assertJsonPath('data.outputs.1.expired', true)->assertJsonPath('data.actual_charge', '381887500000');

    $this->assertDatabaseCount('generation_charges', 1);
});
