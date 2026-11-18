<?php

namespace App\Contracts;

use App\Models\GenerationReservation;
use App\Models\Wallet;

interface WalletRepository
{
    public function forUser(int $userId): Wallet;

    public function lockForUser(int $userId): Wallet;

    public function hasPurchase(int $walletId, string $requestId): bool;

    public function recordPurchase(Wallet $wallet, string $requestId, int $amount): void;

    public function captureReservation(Wallet $wallet, GenerationReservation $reservation, int $charge): void;

    public function createReservation(Wallet $wallet, string $generationId, int $amount): void;

    public function lockReservation(string $generationId): GenerationReservation;

    public function increaseReservation(Wallet $wallet, GenerationReservation $reservation, int $extra): void;

    public function releaseReservation(Wallet $wallet, GenerationReservation $reservation): void;
}
