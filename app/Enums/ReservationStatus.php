<?php

namespace App\Enums;

enum ReservationStatus: string
{
    case Held = 'held';
    case Captured = 'captured';
    case Released = 'released';
}
