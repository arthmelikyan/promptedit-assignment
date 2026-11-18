<?php

use App\Actions\ApplyGenerationUpdate;
use App\Actions\BuildSpendReport;
use App\Data\ReportFilterData;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('spend is grouped by provider completion date and reconciled exactly', function () {
    config(['services.pixelforge.key' => 'test-key']);
    $this->travelTo(now()->utc()->startOfDay()->addMinutes(1));
    $generation = reservedGeneration();
    $object = pixelforgeObject($generation);
    $object['created_at'] = now()->subMinutes(2)->toIso8601String();
    app(ApplyGenerationUpdate::class)->handle($generation->id, $object);
    Http::preventStrayRequests();
    Http::fake(['https://pixelforge.promptedit.dev/v1/usage?*' => Http::response(['data' => [['date' => now()->toDateString(), 'generations' => 1, 'cost_usd' => '0.305510000']]])]);

    $report = app(BuildSpendReport::class)->handle(new ReportFilterData(now()->toDateString(), now()->toDateString()));

    expect($report['daily'][0]['difference'])->toBe('0');
    expect($report['totals']['profit'])->toBe('76377500000');
    expect($report['users'][0]['user_id'])->toBe($generation->user_id);
});

test('ordinary users cannot open the admin report', function () {
    $this->actingAs(User::factory()->create())->get(route('admin.spend'))->assertForbidden();
});

test('reports reject ranges over ninety two days', function () {
    $this->actingAs(User::factory()->admin()->create())->getJson(route('admin.spend', ['start_date' => now()->subDays(92)->toDateString(), 'end_date' => now()->toDateString()]))->assertUnprocessable()->assertJsonValidationErrors('end_date');
});
