<?php

namespace App\Repositories;

use App\Contracts\WalletRepository as WalletRepositoryContract;
use App\Enums\ReservationStatus;
use App\Enums\WalletTransactionType;
use App\Models\GenerationReservation;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;

final class WalletRepository implements WalletRepositoryContract
{
    public function forUser(int $userId): Wallet
    {
        return Wallet::query()->firstOrCreate(['user_id' => $userId]);
    }

    public function lockForUser(int $userId): Wallet
    {
        User::query()->lockForUpdate()->findOrFail($userId);
        $this->forUser($userId);

        return Wallet::query()->where('user_id', $userId)->lockForUpdate()->firstOrFail();
    }

    public function hasPurchase(int $walletId, string $requestId): bool
    {
        return WalletTransaction::query()
            ->where('wallet_id', $walletId)
            ->where('request_id', $requestId)
            ->exists();
    }

    public function recordPurchase(Wallet $wallet, string $requestId, int $amount): void
    {
        $wallet->balance += $amount;
        $wallet->save();

        WalletTransaction::query()->create([
            'wallet_id' => $wallet->id,
            'request_id' => $requestId,
            'amount' => $amount,
            'type' => WalletTransactionType::Purchase,
        ]);
    }

    public function captureReservation(Wallet $wallet, GenerationReservation $reservation, int $charge): void
    {
        WalletTransaction::query()->create([
            'wallet_id' => $wallet->id,
            'generation_id' => $reservation->generation_id,
            'amount' => -$charge,
            'type' => WalletTransactionType::Generation,
        ]);
        $wallet->balance -= $charge;
        $wallet->reserved -= $reservation->amount;
        $wallet->save();
        $reservation->update(['status' => ReservationStatus::Captured]);
    }

    public function createReservation(Wallet $wallet, string $generationId, int $amount): void
    {
        $wallet->reserved += $amount;
        $wallet->save();

        GenerationReservation::query()->create([
            'wallet_id' => $wallet->id,
            'generation_id' => $generationId,
            'amount' => $amount,
        ]);
    }

    public function lockReservation(string $generationId): GenerationReservation
    {
        return GenerationReservation::query()
            ->where('generation_id', $generationId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function increaseReservation(Wallet $wallet, GenerationReservation $reservation, int $extra): void
    {
        $wallet->reserved += $extra;
        $wallet->save();
        $reservation->amount += $extra;
        $reservation->save();
    }

    public function releaseReservation(Wallet $wallet, GenerationReservation $reservation): void
    {
        $wallet->reserved -= $reservation->amount;
        $wallet->save();
        $reservation->update(['status' => ReservationStatus::Released]);
    }
}
