<?php

use App\Actions\PurchaseCredits;
use App\Data\PurchaseCreditsData;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Models\User;
use App\Models\Wallet;
use App\Services\GenerationPricing;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

test('fake purchases credit ten dollars exactly once for the same request', function () {
    $user = User::factory()->create();
    $data = new PurchaseCreditsData((string) Str::uuid());

    $this->actingAs($user)->post(route('credits.purchase'), $data->toArray())->assertRedirect(route('studio'));
    app(PurchaseCredits::class)->handle($user->id, $data);

    $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => GenerationPricing::PURCHASE, 'reserved' => 0]);
    $this->assertDatabaseCount('wallet_transactions', 1);
});

test('reserves credits and deduplicates repeated generation submissions', function () {
    config(['services.pixelforge.key' => 'test-key']);
    Http::preventStrayRequests();
    Http::fake(['https://pixelforge.promptedit.dev/v1/pricing' => Http::response(pixelforgePricing())]);
    Queue::fake([SubmitGenerationJob::class]);
    $user = User::factory()->create();
    Wallet::query()->create(['user_id' => $user->id, 'balance' => GenerationPricing::PURCHASE]);
    $payload = ['request_id' => (string) Str::uuid(), 'type' => 'image', 'prompt' => 'A lighthouse', 'settings' => ['size' => '1024x1024', 'quality' => 'low']];

    $this->actingAs($user)->post(route('generations.store'), $payload)->assertRedirect();
    $this->post(route('generations.store'), $payload)->assertRedirect();

    $this->assertDatabaseCount('generations', 1);
    $this->assertDatabaseCount('generation_reservations', 1);
    expect(Wallet::query()->firstOrFail()->reserved)->toBe(Generation::query()->firstOrFail()->estimated_max);
    Queue::assertPushed(SubmitGenerationJob::class);
    Http::assertSentCount(1);
});

test('outstanding reservations block spending the same credits again', function () {
    config(['services.pixelforge.key' => 'test-key']);
    Http::preventStrayRequests();
    Http::fake(['https://pixelforge.promptedit.dev/v1/pricing' => Http::response(pixelforgePricing())]);
    $user = User::factory()->create();
    Wallet::query()->create(['user_id' => $user->id, 'balance' => 100_000_000_000, 'reserved' => 99_000_000_000]);

    $this->actingAs($user)->postJson(route('generations.store'), ['request_id' => (string) Str::uuid(), 'type' => 'image', 'prompt' => 'A lighthouse', 'settings' => ['size' => '1024x1024', 'quality' => 'low']])->assertUnprocessable()->assertJsonValidationErrors('credits');

    $this->assertDatabaseCount('generations', 0);
});

test('rejects invalid generation settings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('generations.store'), ['request_id' => (string) Str::uuid(), 'type' => 'video', 'prompt' => str_repeat('a', 4001), 'settings' => ['size' => 'bogus', 'quality' => 'ultra', 'reference_images' => ['file:///etc/passwd']]])->assertUnprocessable()->assertJsonValidationErrors(['type', 'prompt', 'settings.size', 'settings.quality', 'settings.reference_images.0']);
});

test('a user cannot read another users generation', function () {
    $generation = Generation::factory()->create();
    $user = User::factory()->create();

    $this->actingAs($user)->getJson(route('generations.show', $generation))->assertNotFound();
});

test('studio history is scoped to its owner', function () {
    $own = Generation::factory()->create();
    Generation::factory()->create();
    $user = User::query()->findOrFail($own->user_id);

    $this->actingAs($user)->get(route('studio'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Studio')->has('generations.data', 1)->where('generations.data.0.id', $own->id));
});

test('same request identifier cannot be reused with different generation input', function () {
    config(['services.pixelforge.key' => 'test-key']);
    Http::preventStrayRequests();
    Http::fake(['https://pixelforge.promptedit.dev/v1/pricing' => Http::response(pixelforgePricing())]);
    Queue::fake([SubmitGenerationJob::class]);
    $user = User::factory()->create();
    Wallet::query()->create(['user_id' => $user->id, 'balance' => GenerationPricing::PURCHASE]);
    $payload = ['request_id' => (string) Str::uuid(), 'type' => 'image', 'prompt' => 'First idea', 'settings' => ['size' => '1024x1024', 'quality' => 'low']];
    $this->actingAs($user)->post(route('generations.store'), $payload)->assertRedirect();

    $this->postJson(route('generations.store'), [...$payload, 'prompt' => 'Different idea'])->assertUnprocessable()->assertJsonValidationErrors('request_id');

    $this->assertDatabaseCount('generations', 1);
    $this->assertDatabaseCount('generation_reservations', 1);
    Queue::assertPushed(SubmitGenerationJob::class, 1);
});

test('negative balances block new generations even when the estimated charge is small', function () {
    config(['services.pixelforge.key' => 'test-key']);
    Http::preventStrayRequests();
    Http::fake(['https://pixelforge.promptedit.dev/v1/pricing' => Http::response(pixelforgePricing())]);
    $user = User::factory()->create();
    Wallet::query()->create(['user_id' => $user->id, 'balance' => -1]);

    $this->actingAs($user)->postJson(route('generations.store'), ['request_id' => (string) Str::uuid(), 'type' => 'image', 'prompt' => 'abcd', 'settings' => ['size' => '1024x1024', 'quality' => 'low']])->assertUnprocessable()->assertJsonValidationErrors('credits');

    $this->assertDatabaseCount('generations', 0);
});

test('private generation channels are restricted to their owner', function () {
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'local-key', 'broadcasting.connections.reverb.secret' => 'local-secret', 'broadcasting.connections.reverb.app_id' => 'local-app']);
    require base_path('routes/channels.php');
    $user = User::factory()->create();
    $another = User::factory()->create();

    $this->actingAs($user)->postJson(route('broadcasting.auth'), ['socket_id' => '123.456', 'channel_name' => 'private-users.'.$another->id])->assertForbidden();
});

test('users can authorize their own private generation channel', function () {
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'local-key', 'broadcasting.connections.reverb.secret' => 'local-secret', 'broadcasting.connections.reverb.app_id' => 'local-app']);
    require base_path('routes/channels.php');
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(route('broadcasting.auth'), ['socket_id' => '123.456', 'channel_name' => 'private-users.'.$user->id])->assertOk()->assertJsonStructure(['auth']);
});
