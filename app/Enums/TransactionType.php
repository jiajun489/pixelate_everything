<?php

namespace App\Enums;

enum TransactionType: string
{
    case Deposit = 'deposit';
    case Withdrawal = 'withdrawal';
    case BetStake = 'bet_stake';
    case BetPayout = 'bet_payout';
    case BetRefund = 'bet_refund';

    public function isDebit(): bool
    {
        return $this === self::Withdrawal || $this === self::BetStake;
    }
}
