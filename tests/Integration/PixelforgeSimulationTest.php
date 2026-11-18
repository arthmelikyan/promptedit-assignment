<?php

use App\Actions\ApplyGenerationUpdate;
use App\Actions\RequestGeneration;
use App\Actions\SubmitGeneration;
use App\Data\GenerationData;
use App\Enums\GenerationStatus;
use App\Enums\SubmissionState;
use App\Models\GenerationCharge;
use App\Models\User;
use App\Models\Wallet;
use App\Services\GenerationPricing;
use App\Services\PixelforgeClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class);

test('real queued HTTP submissions preserve funds and idempotency after an accepted timeout', function () {
    $schema = 'assessment_test_'.bin2hex(random_bytes(6));
    $connection = config('database.connections.pgsql');
    $url = (string) getenv('PIXELFORGE_SIMULATION_URL');
    DB::connection('pgsql')->statement('CREATE SCHEMA "'.$schema.'"');
    try {
        config(['database.connections.pgsql.search_path' => $schema, 'database.default' => 'pgsql', 'services.pixelforge.key' => 'simulation-key', 'services.pixelforge.url' => $url, 'queue.default' => 'database', 'queue.connections.database.connection' => 'pgsql', 'broadcasting.default' => 'null', 'cache.default' => 'array']);
        DB::purge('pgsql');
        $this->artisan('migrate', ['--database' => 'pgsql', '--force' => true])->assertSuccessful();
        Http::withToken('simulation-key')->post(str_replace('/v1', '/__reset', $url))->throw();
        $user = User::factory()->create();
        Wallet::query()->create(['user_id' => $user->id, 'balance' => 10_000_000_000_000]);
        $client = app(PixelforgeClient::class);
        expect($client->pricing())->toBe(json_decode(file_get_contents(base_path('tests/Fixtures/PixelforgeMock/pricing.json')), true, flags: JSON_THROW_ON_ERROR));
        $runWorker = static function () use ($schema, $url): void {
            $result = Process::env(['PIXELFORGE_TEST_SCHEMA' => $schema, 'PIXELFORGE_TEST_OPERATION' => 'work', 'PIXELFORGE_TEST_ID' => '', 'PIXELFORGE_SIMULATION_URL' => $url])->timeout(40)->run([PHP_BINARY, base_path('tests/Fixtures/ConcurrentOperation.php')]);
            expect($result->successful())->toBeTrue($result->errorOutput());
        };
        $generation = app(RequestGeneration::class)->handle($user->id, (string) Str::uuid(), new GenerationData('[timeout] A quiet ocean', '1024x1024', 'low'));
        $runWorker();
        $generation->refresh();
        expect($generation->submission_state)->toBe(SubmissionState::Uncertain);
        expect(Wallet::query()->firstOrFail()->reserved)->toBe($generation->estimated_max);
        $this->assertDatabaseCount('generation_charges', 0);
        $generation->update(['next_attempt_at' => now()->subSecond()]);
        app(SubmitGeneration::class)->handle($generation->id);
        expect($generation->fresh()->status)->toBe(GenerationStatus::Succeeded);
        $this->assertDatabaseCount('generation_charges', 1);
        expect(Wallet::query()->firstOrFail()->reserved)->toBe(0);
        $providerRecords = $client->listing()['data'];
        expect(collect($providerRecords)->where('metadata.local_generation_id', $generation->id))->toHaveCount(1);
        app(ApplyGenerationUpdate::class)->handle($generation->id, $client->retrieve($generation->fresh()->provider_id));
        $this->assertDatabaseCount('generation_charges', 1);
        $this->assertDatabaseCount('wallet_transactions', 1);
        $rejected = app(RequestGeneration::class)->handle($user->id, (string) Str::uuid(), new GenerationData('[blocked] A quiet ocean', '1024x1024', 'low'));
        app(SubmitGeneration::class)->handle($rejected->id);
        expect($rejected->fresh()->status)->toBe(GenerationStatus::Failed);
        expect(Wallet::query()->firstOrFail()->reserved)->toBe(0);
        $this->assertDatabaseCount('generation_charges', 1);
        $usage = $client->usage(now()->toDateString(), now()->toDateString());
        expect(app(GenerationPricing::class)->fromUsd($usage['data'][0]['cost_usd']))->toBe(GenerationCharge::query()->firstOrFail()->provider_cost);
    } finally {
        config(['database.connections.pgsql' => $connection]);
        DB::purge('pgsql');
        DB::connection('pgsql')->statement('DROP SCHEMA "'.$schema.'" CASCADE');
    }
})->skip(fn (): bool => ! getenv('PIXELFORGE_SIMULATION_URL'), 'Run with the local Postman Pixelforge simulation URL.');
