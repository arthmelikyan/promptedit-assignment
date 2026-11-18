<?php

use App\Actions\ApplyGenerationUpdate;
use App\Actions\PurchaseCredits;
use App\Actions\RequestGeneration;
use App\Data\GenerationData;
use App\Data\PurchaseCreditsData;
use App\Models\Generation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
require __DIR__.'/Pixelforge.php';
$schema = (string) getenv('PIXELFORGE_TEST_SCHEMA');
if (! preg_match('/^assessment_test_[a-f0-9]+$/D', $schema)) {
    throw new RuntimeException('An isolated test schema is required.');
}
config(['database.default' => 'pgsql', 'database.connections.pgsql.search_path' => $schema, 'broadcasting.default' => 'null', 'queue.default' => 'sync', 'cache.default' => 'array', 'services.pixelforge.key' => 'concurrency-test']);
$app['db']->purge('pgsql');
$operation = (string) getenv('PIXELFORGE_TEST_OPERATION');
$id = (string) getenv('PIXELFORGE_TEST_ID');
if ($operation === 'work') {
    config(['queue.default' => 'database', 'queue.connections.database.connection' => 'pgsql', 'services.pixelforge.key' => 'simulation-key', 'services.pixelforge.url' => (string) getenv('PIXELFORGE_SIMULATION_URL')]);
    $app->make(Kernel::class)->call('queue:work', ['connection' => 'database', '--queue' => 'provider', '--once' => true, '--tries' => 15]);
} elseif ($operation === 'purchase') {
    app(PurchaseCredits::class)->handle((int) $id, new PurchaseCreditsData('a6d690b8-113a-4d8b-bde8-c0582f209386'));
} elseif ($operation === 'settle') {
    $generation = Generation::query()->findOrFail($id);
    app(ApplyGenerationUpdate::class)->handle($id, pixelforgeObject($generation));
} elseif ($operation === 'reserve') {
    Http::preventStrayRequests();
    Http::fake(['https://pixelforge.promptedit.dev/v1/pricing' => Http::response(pixelforgePricing())]);
    Queue::fake();
    try {
        app(RequestGeneration::class)->handle((int) $id, (string) Str::uuid(), new GenerationData('abcd', '1024x1024', 'low'));
    } catch (ValidationException) {
        echo 'insufficient';
    }
} else {
    throw new RuntimeException('Unknown test operation.');
}
