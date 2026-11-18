<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property CarbonImmutable $completed_at
 */
class GenerationCharge extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider_cost' => 'integer',
            'user_charge' => 'integer',
            'markup_percent' => 'integer',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
