<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable|null $expires_at
 */
class GenerationOutput extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'position' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
