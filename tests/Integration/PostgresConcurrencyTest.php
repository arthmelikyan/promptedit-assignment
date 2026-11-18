<?php

use App\Models\Generation;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

pest()->extend(TestCase::class);

test('postgres serializes concurrent purchases reservations and settlements', function () {
    $schema = 'assessment_test_'.bin2hex(random_bytes(6));
    $connection = config('database.connections.pgsql');
    DB::connection('pgsql')->statement('CREATE SCHEMA "'.$schema.'"');
    try {
        config(['database.connections.pgsql.search_path' => $schema, 'database.default' => 'pgsql', 'services.pixelforge.key' => 'concurrency-test', 'broadcasting.default' => 'null']);
        DB::purge('pgsql');
        $this->artisan('migrate', ['--database' => 'pgsql', '--force' => true])->assertSuccessful();
        $user = User::factory()->create();
        $run = static function (string $operation, string $id) use ($schema): void {
            $results = Process::pool(function (Pool $pool) use ($schema, $operation, $id): void {
                foreach ([1, 2] as $worker) {
                    $pool->as((string) $worker)->env(['PIXELFORGE_TEST_SCHEMA' => $schema, 'PIXELFORGE_TEST_OPERATION' => $operation, 'PIXELFORGE_TEST_ID' => $id])->command([PHP_BINARY, base_path('tests/Fixtures/ConcurrentOperation.php')]);
                }
            })->start()->wait();
            foreach ($results as $result) {
                expect($result->successful())->toBeTrue($result->errorOutput());
            }
        };
        $run('purchase', (string) $user->id);
        expect(Wallet::query()->where('user_id', $user->id)->firstOrFail()->balance)->toBe(10_000_000_000_000);
        expect(DB::table('wallet_transactions')->count())->toBe(1);
        Wallet::query()->where('user_id', $user->id)->update(['balance' => 30_000_000_000]);
        $run('reserve', (string) $user->id);
        expect(Generation::query()->count())->toBe(1);
        expect(Wallet::query()->firstOrFail()->reserved)->toBe(21_825_000_000);
        $run('settle', Generation::query()->firstOrFail()->id);
        expect(DB::table('generation_charges')->count())->toBe(1);
        expect(DB::table('generation_outputs')->count())->toBe(1);
        expect(DB::table('wallet_transactions')->where('type', 'generation')->count())->toBe(1);
        expect(Wallet::query()->firstOrFail()->reserved)->toBe(0);
    } finally {
        config(['database.connections.pgsql' => $connection]);
        DB::purge('pgsql');
        DB::connection('pgsql')->statement('DROP SCHEMA "'.$schema.'" CASCADE');
    }
})->skip(fn (): bool => ! (bool) getenv('RUN_POSTGRES_TESTS'), 'Enable RUN_POSTGRES_TESTS inside Docker to exercise isolated PostgreSQL concurrency.');
