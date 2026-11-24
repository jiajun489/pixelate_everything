<?php

namespace App\Services;

use App\Enums\BetStatus;
use App\Enums\TransactionType;
use App\Exceptions\DomainRuleException;
use App\Models\Bet;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BetService
{
    public function __construct(private WalletService $wallets) {}

    public function place(Wallet $wallet, int $stake, int $oddsBps): Bet
    {
        return DB::transaction(function () use ($wallet, $stake, $oddsBps): Bet {
            $lockedWallet = $this->wallets->lock($wallet->id);
            $bet = $lockedWallet->bets()->create(['stake' => $stake, 'odds_bps' => $oddsBps]);

            $this->wallets->post($lockedWallet, TransactionType::BetStake, $stake, "bet:{$bet->id}:stake");

            DB::afterCommit(fn () => Log::channel('wallet')->info('bet.placed', [
                'bet_id' => $bet->id,
                'wallet_id' => $lockedWallet->id,
                'stake' => $stake,
                'odds_bps' => $oddsBps,
            ]));

            return $bet;
        });
    }

    public function settle(Bet $bet, BetStatus $outcome): Bet
    {
        return $this->resolve($bet, $outcome);
    }

    public function cancel(Bet $bet): Bet
    {
        return $this->resolve($bet, BetStatus::Cancelled);
    }

    /**
     * Move a pending bet to its final status. One settlement reference per bet caps it at one credit.
     */
    private function resolve(Bet $bet, BetStatus $finalStatus): Bet
    {
        return DB::transaction(function () use ($bet, $finalStatus): Bet {
            $lockedWallet = $this->wallets->lock($bet->wallet_id);
            $bet->refresh();

            if ($bet->status !== BetStatus::Pending) {
                throw new DomainRuleException('bet_already_resolved', "This bet is already {$bet->status->value}.");
            }

            $payout = match ($finalStatus) {
                BetStatus::Won => $bet->winningPayout(),
                BetStatus::Lost => 0,
                default => null,
            };

            if ($finalStatus === BetStatus::Won) {
                $this->wallets->post($lockedWallet, TransactionType::BetPayout, $payout, "bet:{$bet->id}:settlement");
            }

            if ($finalStatus === BetStatus::Cancelled) {
                $this->wallets->post($lockedWallet, TransactionType::BetRefund, $bet->stake, "bet:{$bet->id}:settlement");
            }

            $bet->update(['status' => $finalStatus, 'payout' => $payout, 'resolved_at' => now()]);

            DB::afterCommit(fn () => Log::channel('wallet')->info('bet.resolved', [
                'bet_id' => $bet->id,
                'wallet_id' => $lockedWallet->id,
                'status' => $finalStatus->value,
                'payout' => $payout,
            ]));

            return $bet;
        });
    }
}
