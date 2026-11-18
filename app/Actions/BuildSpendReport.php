<?php

namespace App\Actions;

use App\Contracts\GenerationProvider;
use App\Contracts\SpendRepository;
use App\Data\ReportFilterData;
use App\Services\GenerationPricing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Throwable;

final readonly class BuildSpendReport
{
    public function __construct(
        private SpendRepository $spend,
        private GenerationProvider $provider,
        private GenerationPricing $pricing,
    ) {}

    /** @return array<string, mixed> */
    public function handle(ReportFilterData $filter): array
    {
        $local = $this->spend->report($filter, $this->provider->fingerprint());
        $key = 'pixelforge.usage.'.$this->provider->fingerprint().'.'.$filter->startDate.'.'.$filter->endDate;
        $provider = null;
        $error = null;
        try {
            if ($filter->refresh) {
                Cache::forget($key);
            }
            $provider = Cache::remember($key, 60, fn (): array => [
                'data' => $this->provider->usage($filter->startDate, $filter->endDate),
                'synced_at' => now()->toIso8601String(),
            ]);
        } catch (Throwable) {
            $error = 'Provider totals are temporarily unavailable. Local charges are still shown.';
        }
        $byDate = [];
        foreach ($local['daily'] as $row) {
            $byDate[$row['date']] = $row;
        }
        $providerDays = [];
        foreach ($provider['data']['data'] ?? [] as $row) {
            $providerDays[$row['date']] = $row;
        }
        $daily = [];
        for ($day = CarbonImmutable::parse($filter->startDate); $day->toDateString() <= $filter->endDate; $day = $day->addDay()) {
            $date = $day->toDateString();
            $row = $byDate[$date] ?? [
                'date' => $date,
                'generations' => 0,
                'provider_cost' => '0',
                'user_charge' => '0',
                'profit' => '0',
            ];
            $reported = $providerDays[$date] ?? null;
            $reportedCost = $reported !== null ? (string) $this->pricing->fromUsd($reported['cost_usd']) : null;
            $daily[] = [
                ...$row,
                'reported_provider_cost' => $reportedCost,
                'difference' => $reportedCost !== null ? bcsub($row['provider_cost'], $reportedCost, 0) : null,
                'reported_generations' => $reported['generations'] ?? null,
            ];
        }
        $totals = ['provider_cost' => '0', 'user_charge' => '0', 'profit' => '0'];
        foreach ($daily as $row) {
            foreach (array_keys($totals) as $field) {
                $totals[$field] = bcadd($totals[$field], $row[$field], 0);
            }
        }

        return [
            'daily' => $daily,
            'users' => $local['users'],
            'totals' => $totals,
            'provider_synced_at' => $provider['synced_at'] ?? null,
            'provider_error' => $error,
            'filters' => $filter->toArray(),
        ];
    }
}
