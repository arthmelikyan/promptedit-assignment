<?php

namespace App\Enums;

enum WalletTransactionType: string
{
    case Purchase = 'purchase';
    case Generation = 'generation';
}
