<?php

namespace Tests;

use App\Models\Wallet;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Replay the ledger from zero and assert that it reproduces the stored balance.
     */
    protected function assertLedgerExplainsBalance(Wallet $wallet): void
    {
        $runningBalance = 0;

        foreach ($wallet->transactions()->orderBy('id')->get() as $transaction) {
            $this->assertSame($runningBalance, $transaction->balance_before);
            $runningBalance += $transaction->type->isDebit() ? -$transaction->amount : $transaction->amount;
            $this->assertSame($runningBalance, $transaction->balance_after);
        }

        $this->assertSame($runningBalance, $wallet->fresh()->balance);
    }
}
