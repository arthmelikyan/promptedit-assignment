<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * @property ReservationStatus $status
 * @property int $amount
 */
class GenerationReservation extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'amount' => 'integer',
        ];
    }
}
