<?php

namespace App\Enums;

enum BetStatus: string
{
    case Pending = 'PENDING';
    case Won = 'WON';
    case Lost = 'LOST';
    case Cancelled = 'CANCELLED';
}
