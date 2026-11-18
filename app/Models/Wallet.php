<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $balance
 * @property int $reserved
 */
class Wallet extends Model
{
    protected $guarded = [];

    public function canReserve(int $amount): bool
    {
        return $this->balance >= 0 && $this->balance - $this->reserved >= $amount;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'balance' => 'integer',
            'reserved' => 'integer',
        ];
    }
}
