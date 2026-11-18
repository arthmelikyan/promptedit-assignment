<?php

namespace App\Http\Resources;

use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Wallet */
class WalletResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'balance' => (string) $this->balance,
            'reserved' => (string) $this->reserved,
            'available' => (string) ($this->balance - $this->reserved),
            'deficit' => $this->balance < 0,
        ];
    }
}
