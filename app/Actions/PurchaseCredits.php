<?php

namespace App\Actions;

use App\Contracts\WalletRepository;
use App\Data\PurchaseCreditsData;
use App\Models\Wallet;
use App\Services\GenerationPricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class PurchaseCredits
{
    public function __construct(private WalletRepository $wallets) {}

    /**
     * @throws Throwable
     */
    public function handle(int $userId, PurchaseCreditsData $data): Wallet
    {
        return DB::transaction(function () use ($userId, $data): Wallet {
            $wallet = $this->wallets->lockForUser($userId);
            if ($this->wallets->hasPurchase($wallet->id, $data->requestId)) {
                return $wallet;
            }
            if ($wallet->balance > PHP_INT_MAX - GenerationPricing::PURCHASE) {
                throw ValidationException::withMessages(['credits' => 'The maximum supported balance has been reached.']);
            }
            $this->wallets->recordPurchase($wallet, $data->requestId, GenerationPricing::PURCHASE);

            return $wallet;
        }, 3);
    }
}
