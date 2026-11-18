<?php

namespace App\Models;

use App\Enums\WalletTransactionType;
use Illuminate\Database\Eloquent\Model;

/**
 * @property WalletTransactionType $type
 * @property int $amount
 */
class WalletTransaction extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => WalletTransactionType::class,
            'amount' => 'integer',
        ];
    }
}
