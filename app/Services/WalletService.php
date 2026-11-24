<?php

namespace App\Services;

use App\Enums\BetStatus;
use App\Enums\TransactionType;
use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletService
{
    public function open(User $player): Wallet
    {
        $wallet = Wallet::createOrFirst(['user_id' => $player->id]);

        if (! $wallet->wasRecentlyCreated) {
            throw new DomainRuleException('wallet_exists', 'This player already has a wallet.');
        }

        Log::channel('wallet')->info('wallet.opened', ['wallet_id' => $wallet->id, 'player_id' => $player->id]);

        return $wallet;
    }

    public function deposit(Wallet $wallet, int $amount, string $reference): WalletTransaction
    {
        return $this->applyClientMovement($wallet, TransactionType::Deposit, $amount, $reference);
    }

    public function withdraw(Wallet $wallet, int $amount, string $reference): WalletTransaction
    {
        return $this->applyClientMovement($wallet, TransactionType::Withdrawal, $amount, $reference);
    }

    public function close(Wallet $wallet): Wallet
    {
        return DB::transaction(function () use ($wallet): Wallet {
            $lockedWallet = $this->lock($wallet->id);

            if ($lockedWallet->isClosed()) {
                throw new DomainRuleException('wallet_closed', 'This wallet is already closed.');
            }

            if ($lockedWallet->balance !== 0) {
                throw new DomainRuleException('wallet_not_empty', 'A wallet can only be closed at a zero balance.');
            }

            if ($lockedWallet->bets()->where('status', BetStatus::Pending)->exists()) {
                throw new DomainRuleException('wallet_has_pending_bets', 'A wallet with pending bets cannot be closed.');
            }

            $lockedWallet->closed_at = now();
            $lockedWallet->save();

            DB::afterCommit(fn () => Log::channel('wallet')->info('wallet.closed', ['wallet_id' => $lockedWallet->id]));

            return $lockedWallet;
        });
    }

    /**
     * Lock the wallet row. Every balance or bet state change serializes on this lock.
     */
    public function lock(int $walletId): Wallet
    {
        return Wallet::query()->whereKey($walletId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Move money and write its ledger entry. The caller must hold the wallet lock.
     */
    public function post(Wallet $lockedWallet, TransactionType $type, int $amount, string $reference): WalletTransaction
    {
        if ($lockedWallet->isClosed()) {
            throw new DomainRuleException('wallet_closed', 'This wallet is closed.');
        }

        $balanceBefore = $lockedWallet->balance;
        $balanceAfter = $type->isDebit() ? $balanceBefore - $amount : $balanceBefore + $amount;

        if ($balanceAfter < 0) {
            throw new DomainRuleException('insufficient_funds', 'The wallet balance cannot cover this amount.', 422);
        }

        // ponytail: the cap applies to deposits only, so a winning bet can always settle.
        if ($type === TransactionType::Deposit && $balanceAfter > Wallet::MAX_BALANCE) {
            throw new DomainRuleException('balance_limit_exceeded', 'This deposit would exceed the maximum balance.', 422);
        }

        $lockedWallet->balance = $balanceAfter;
        $lockedWallet->save();

        $transaction = $lockedWallet->transactions()->create([
            'type' => $type,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'reference' => $reference,
        ]);

        DB::afterCommit(fn () => Log::channel('wallet')->info('wallet.transaction', [
            'wallet_id' => $lockedWallet->id,
            'transaction_id' => $transaction->id,
            'type' => $type->value,
            'amount' => $amount,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'reference' => $reference,
        ]));

        return $transaction;
    }

    /**
     * Apply a client-referenced movement, returning the original entry on a replay.
     */
    private function applyClientMovement(Wallet $wallet, TransactionType $type, int $amount, string $reference): WalletTransaction
    {
        return DB::transaction(function () use ($wallet, $type, $amount, $reference): WalletTransaction {
            $lockedWallet = $this->lock($wallet->id);
            $existing = $lockedWallet->transactions()->where('reference', $reference)->first();

            if ($existing === null) {
                return $this->post($lockedWallet, $type, $amount, $reference);
            }

            if ($existing->type !== $type || $existing->amount !== $amount) {
                throw new DomainRuleException('reference_conflict', 'This reference was already used for a different operation.');
            }

            DB::afterCommit(fn () => Log::channel('wallet')->info('wallet.replayed', [
                'wallet_id' => $lockedWallet->id,
                'transaction_id' => $existing->id,
                'reference' => $reference,
            ]));

            return $existing;
        });
    }
}
