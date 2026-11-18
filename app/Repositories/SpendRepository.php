<?php

namespace App\Repositories;

use App\Contracts\SpendRepository as SpendRepositoryContract;
use App\Data\ReportFilterData;
use App\Models\GenerationCharge;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class SpendRepository implements SpendRepositoryContract
{
    /** @return array<string, mixed> */
    public function report(ReportFilterData $filter, string $fingerprint): array
    {
        $query = GenerationCharge::query()
            ->join('generations', 'generations.id', '=', 'generation_charges.generation_id')
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->where('generations.credential_fingerprint', $fingerprint)
            ->where('generation_charges.completed_at', '>=', $filter->startDate.' 00:00:00+00:00')
            ->where('generation_charges.completed_at', '<', CarbonImmutable::parse($filter->endDate, 'UTC')->addDay()->toIso8601String());
        $dateExpression = DB::connection()->getDriverName() === 'pgsql' ? "DATE(generation_charges.completed_at AT TIME ZONE 'UTC')" : 'DATE(generation_charges.completed_at)';
        $daily = (clone $query)
            ->selectRaw($dateExpression.' as date, COUNT(*) as generations, SUM(provider_cost) as provider_cost, SUM(user_charge) as user_charge')
            ->groupByRaw($dateExpression)
            ->orderBy('date')
            ->get();
        $users = (clone $query)
            ->when($filter->userId !== null, fn ($query) => $query->where('users.id', $filter->userId))
            ->selectRaw('users.id as user_id, users.name, users.email, COUNT(*) as generations, SUM(provider_cost) as provider_cost, SUM(user_charge) as user_charge')
            ->groupBy('users.id', 'users.name', 'users.email')
            ->orderBy('users.name')
            ->get();
        $format = static function ($row): array {
            $providerCost = (string) $row->provider_cost;
            $userCharge = (string) $row->user_charge;
            if (! is_numeric($providerCost) || ! is_numeric($userCharge)) {
                throw new \UnexpectedValueException('Invalid monetary aggregate.');
            }

            return [
                ...$row->getAttributes(),
                'provider_cost' => (string) $row->provider_cost,
                'user_charge' => (string) $row->user_charge,
                'profit' => bcsub($userCharge, $providerCost, 0),
            ];
        };

        return [
            'daily' => $daily->map($format)->all(),
            'users' => $users->map($format)->all(),
        ];
    }
}
