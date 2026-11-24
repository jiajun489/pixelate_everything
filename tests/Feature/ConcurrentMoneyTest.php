<?php

namespace Tests\Feature;

use App\Enums\BetStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Bet;
use App\Models\Wallet;
use App\Services\BetService;
use App\Services\WalletService;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runs real parallel processes, because SQLite ignores row locks and a shared test transaction hides races.
 */
class ConcurrentMoneyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql' || ! str_ends_with((string) DB::getDatabaseName(), '_test')) {
            $this->markTestSkipped('Requires a dedicated PostgreSQL database whose name ends in _test.');
        }

        Artisan::call('migrate', ['--force' => true]);

        $this->beforeApplicationDestroyed(function (): void {
            DB::statement('TRUNCATE api_logs, audit_events, bets, wallet_transactions, wallets, users RESTART IDENTITY CASCADE');
        });
    }

    public function test_parallel_withdrawals_never_overdraw(): void
    {
        $wallet = $this->fundedWallet(1_000);
        $walletId = $wallet->id;

        $attempts = [];
        foreach (range(1, 8) as $attempt) {
            $attempts[] = static fn () => app(WalletService::class)
                ->withdraw(Wallet::findOrFail($walletId), 300, "withdraw-{$attempt}");
        }

        $results = array_count_values($this->race($attempts));

        $this->assertSame(3, $results['ok']);
        $this->assertSame(5, $results['insufficient_funds']);
        $this->assertSame(100, $wallet->fresh()->balance);
        $this->assertLedgerExplainsBalance($wallet);
    }

    public function test_parallel_deposits_with_one_reference_move_money_once(): void
    {
        $wallet = Wallet::factory()->create();
        $walletId = $wallet->id;

        $attempts = array_fill(0, 8, static fn () => app(WalletService::class)
            ->deposit(Wallet::findOrFail($walletId), 500, 'same-reference'));

        $results = array_count_values($this->race($attempts));

        $this->assertSame(8, $results['ok']);
        $this->assertSame(500, $wallet->fresh()->balance);
        $this->assertSame(1, $wallet->transactions()->count());
    }

    public function test_parallel_bets_cannot_overspend_the_wallet(): void
    {
        $wallet = $this->fundedWallet(1_000);
        $walletId = $wallet->id;

        $attempts = array_fill(0, 6, static fn () => app(BetService::class)
            ->place(Wallet::findOrFail($walletId), 400, 20_000));

        $results = array_count_values($this->race($attempts));

        $this->assertSame(2, $results['ok']);
        $this->assertSame(4, $results['insufficient_funds']);
        $this->assertSame(2, Bet::count());
        $this->assertSame(200, $wallet->fresh()->balance);
        $this->assertLedgerExplainsBalance($wallet);
    }

    public function test_parallel_settle_and_cancel_resolve_a_bet_exactly_once(): void
    {
        $wallet = $this->fundedWallet(1_000);
        $bet = app(BetService::class)->place($wallet, 1_000, 20_000);
        $betId = $bet->id;

        $attempts = [];
        foreach (range(1, 4) as $ignored) {
            $attempts[] = static fn () => app(BetService::class)->settle(Bet::findOrFail($betId), BetStatus::Won);
            $attempts[] = static fn () => app(BetService::class)->cancel(Bet::findOrFail($betId));
        }

        $results = array_count_values($this->race($attempts));
        $finalStatus = $bet->fresh()->status;

        $this->assertSame(1, $results['ok']);
        $this->assertSame(7, $results['bet_already_resolved']);
        $this->assertSame($finalStatus === BetStatus::Won ? 2_000 : 1_000, $wallet->fresh()->balance);
        $this->assertSame(3, $wallet->transactions()->count());
        $this->assertLedgerExplainsBalance($wallet);
    }

    public function test_a_wallet_never_closes_with_money_in_it(): void
    {
        $wallet = Wallet::factory()->create();
        $walletId = $wallet->id;

        $attempts = [];
        foreach (range(1, 4) as $attempt) {
            $attempts[] = static fn () => app(WalletService::class)
                ->deposit(Wallet::findOrFail($walletId), 100, "deposit-{$attempt}");
            $attempts[] = static fn () => app(WalletService::class)->close(Wallet::findOrFail($walletId));
        }

        $this->race($attempts);
        $wallet->refresh();

        $this->assertTrue($wallet->closed_at === null || $wallet->balance === 0);
        $this->assertLedgerExplainsBalance($wallet);
    }

    private function fundedWallet(int $balance): Wallet
    {
        $wallet = Wallet::factory()->create();
        app(WalletService::class)->deposit($wallet, $balance, 'seed');

        return $wallet;
    }

    /**
     * Run every attempt in its own process, released together after all processes have booted.
     *
     * @param  list<Closure>  $attempts
     * @return list<string>
     */
    private function race(array $attempts): array
    {
        $startAt = microtime(true) + 2.0;

        $tasks = [];

        foreach ($attempts as $attempt) {
            $tasks[] = static function () use ($attempt, $startAt): string {
                while (microtime(true) < $startAt) {
                    usleep(200);
                }

                try {
                    $attempt();

                    return 'ok';
                } catch (DomainRuleException $exception) {
                    return $exception->errorCode;
                }
            };
        }

        return array_values(Concurrency::driver('process')->run($tasks));
    }
}
