<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\BetService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class WalletApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_player_can_open_exactly_one_wallet(): void
    {
        $player = User::factory()->create();

        $this->postJson(route('api.v1.wallet.store', $player))
            ->assertCreated()
            ->assertJsonPath('data.balance', 0);

        $this->postJson(route('api.v1.wallet.store', $player))
            ->assertConflict()
            ->assertJsonPath('error', 'wallet_exists');

        $this->assertSame(1, Wallet::whereBelongsTo($player)->count());
    }

    public function test_get_returns_the_balance_and_a_missing_wallet_is_not_found(): void
    {
        $wallet = $this->fundedWallet(2_500);

        $this->getJson(route('api.v1.wallet.show', $wallet->user))
            ->assertOk()
            ->assertJsonPath('data.balance', 2_500);

        $this->getJson(route('api.v1.wallet.show', User::factory()->create()))
            ->assertNotFound()
            ->assertExactJson(['error' => 'wallet_not_found', 'message' => 'This player has no wallet.']);

        $this->getJson(route('api.v1.wallet.show', User::max('id') + 1))
            ->assertNotFound()
            ->assertExactJson(['error' => 'player_not_found', 'message' => 'This player does not exist.']);
    }

    #[TestWith(['/api/v1/players/abc/wallet'])]
    #[TestWith(['/api/v1/players/1.5/wallet'])]
    #[TestWith(['/api/v1/players/0/wallet'])]
    #[TestWith(['/api/v1/players/99999999999999999999/wallet'])]
    #[TestWith(['/api/v1/bets/abc'])]
    public function test_malformed_ids_are_not_found(string $uri): void
    {
        $this->getJson($uri)->assertNotFound()->assertJsonPath('error', 'not_found');
    }

    public function test_deposit_and_withdrawal_write_ledger_entries(): void
    {
        $wallet = Wallet::factory()->create();

        $this->move('deposit', $wallet, 1_000, 'dep-1')
            ->assertCreated()
            ->assertJsonPath('data.type', 'deposit')
            ->assertJsonPath('data.balance_before', 0)
            ->assertJsonPath('data.balance_after', 1_000)
            ->assertJsonPath('data.reference', 'dep-1');

        $this->move('withdraw', $wallet, 400, 'wd-1')
            ->assertCreated()
            ->assertJsonPath('data.type', 'withdrawal')
            ->assertJsonPath('data.balance_before', 1_000)
            ->assertJsonPath('data.balance_after', 600);

        $this->getJson(route('api.v1.wallet.transactions', $wallet->user))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.reference', 'dep-1')
            ->assertJsonPath('data.1.reference', 'wd-1');

        $this->assertSame(600, $wallet->fresh()->balance);
        $this->assertLedgerExplainsBalance($wallet);
    }

    #[DataProvider('movements')]
    public function test_replaying_a_reference_does_not_move_money_twice(string $movement): void
    {
        $wallet = $this->fundedWallet(1_000);

        $first = $this->move($movement, $wallet, 300, 'replay-1')->assertCreated();
        $balanceAfterFirst = $wallet->fresh()->balance;

        $this->move($movement, $wallet, 300, 'replay-1')
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame($balanceAfterFirst, $wallet->fresh()->balance);
        $this->assertSame(2, $wallet->transactions()->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function movements(): array
    {
        return ['deposit' => ['deposit'], 'withdrawal' => ['withdraw']];
    }

    public function test_reusing_a_reference_for_a_different_operation_is_rejected(): void
    {
        $wallet = $this->fundedWallet(1_000);
        $this->move('deposit', $wallet, 300, 'ref-1')->assertCreated();

        $this->move('deposit', $wallet, 301, 'ref-1')
            ->assertConflict()
            ->assertJsonPath('error', 'reference_conflict');
        $this->move('withdraw', $wallet, 300, 'ref-1')
            ->assertConflict()
            ->assertJsonPath('error', 'reference_conflict');

        $this->assertSame(1_300, $wallet->fresh()->balance);
    }

    public function test_the_same_reference_is_independent_across_wallets(): void
    {
        $firstWallet = Wallet::factory()->create();
        $secondWallet = Wallet::factory()->create();

        $this->move('deposit', $firstWallet, 100, 'shared')->assertCreated();
        $this->move('deposit', $secondWallet, 200, 'shared')->assertCreated();

        $this->assertSame(100, $firstWallet->fresh()->balance);
        $this->assertSame(200, $secondWallet->fresh()->balance);
    }

    public function test_an_overdraw_is_rejected_and_writes_nothing(): void
    {
        $wallet = $this->fundedWallet(100);

        $this->move('withdraw', $wallet, 101, 'too-much')
            ->assertUnprocessable()
            ->assertJsonPath('error', 'insufficient_funds');

        $this->assertSame(100, $wallet->fresh()->balance);
        $this->assertSame(1, $wallet->transactions()->count());
    }

    public function test_a_wallet_can_be_withdrawn_to_exactly_zero(): void
    {
        $wallet = $this->fundedWallet(100);

        $this->move('withdraw', $wallet, 100, 'all')
            ->assertCreated()
            ->assertJsonPath('data.balance_after', 0);
    }

    #[DataProvider('invalidAmounts')]
    public function test_amounts_must_be_positive_json_integers(mixed $amount): void
    {
        $wallet = Wallet::factory()->create();

        $this->postJson(route('api.v1.wallet.deposit', $wallet->user), ['amount' => $amount, 'reference' => 'bad'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertSame(0, WalletTransaction::count());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidAmounts(): array
    {
        return [
            'fractional float' => [10.5],
            'numeric string' => ['100'],
            'zero' => [0],
            'negative' => [-1],
            'above maximum' => [Wallet::MAX_AMOUNT + 1],
            'missing' => [null],
        ];
    }

    #[TestWith(['10.0'])]
    #[TestWith(['1e3'])]
    #[TestWith(['99999999999999999999'])]
    public function test_amounts_that_decode_to_floats_are_rejected(string $rawAmount): void
    {
        $wallet = Wallet::factory()->create();

        $this->call('POST', route('api.v1.wallet.deposit', $wallet->user), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: '{"amount":'.$rawAmount.',"reference":"float-notation"}')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertSame(0, WalletTransaction::count());
    }

    #[DataProvider('invalidReferences')]
    public function test_client_references_must_be_well_formed(mixed $reference): void
    {
        $wallet = Wallet::factory()->create();

        $this->postJson(route('api.v1.wallet.deposit', $wallet->user), ['amount' => 100, 'reference' => $reference])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reference');
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidReferences(): array
    {
        return [
            'reserved bet namespace' => ['bet:1:settlement'],
            'trailing newline' => ["abc\n"],
            'too long' => [str_repeat('a', 65)],
            'not a string' => [123],
            'missing' => [null],
        ];
    }

    public function test_a_deposit_cannot_exceed_the_maximum_balance(): void
    {
        $wallet = Wallet::factory()->create();
        $wallet->forceFill(['balance' => Wallet::MAX_BALANCE])->save();

        $this->move('deposit', $wallet, 1, 'over-cap')
            ->assertUnprocessable()
            ->assertJsonPath('error', 'balance_limit_exceeded');
    }

    public function test_committed_movements_and_rejections_are_written_to_the_wallet_log(): void
    {
        $wallet = Wallet::factory()->create();
        $walletLog = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('wallet')->andReturn($walletLog);

        $this->move('deposit', $wallet, 100, 'logged')->assertCreated();
        $this->move('withdraw', $wallet, 101, 'rejected')->assertUnprocessable();

        $walletLog->shouldHaveReceived('info')->with('wallet.transaction', Mockery::any())->once();
        $walletLog->shouldHaveReceived('info')->with('wallet.transaction', Mockery::on(
            fn (array $context): bool => $context['reference'] === 'logged' && $context['balance_after'] === 100,
        ));
        $walletLog->shouldHaveReceived('warning')->with('wallet.rejected', Mockery::on(
            fn (array $context): bool => $context['error'] === 'insufficient_funds' && $context['input']['reference'] === 'rejected',
        ))->once();
    }

    public function test_wallet_events_and_rejections_are_stored_as_audit_events(): void
    {
        $wallet = Wallet::factory()->create();

        $this->move('deposit', $wallet, 100, 'audited')->assertCreated();
        $this->move('withdraw', $wallet, 101, 'rejected')->assertUnprocessable();

        $this->assertDatabaseHas('audit_events', [
            'event' => 'wallet.transaction',
            'level' => 'info',
            'wallet_id' => $wallet->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'event' => 'wallet.rejected',
            'level' => 'warning',
            'wallet_id' => $wallet->id,
        ]);
    }

    public function test_a_broken_audit_table_does_not_break_a_deposit(): void
    {
        Exceptions::fake();
        $wallet = Wallet::factory()->create();
        Schema::drop('audit_events');

        $this->move('deposit', $wallet, 100, 'still-works')->assertCreated();
    }

    public function test_a_wallet_with_a_balance_cannot_be_closed(): void
    {
        $wallet = $this->fundedWallet(1);

        $this->postJson(route('api.v1.wallet.close', $wallet->user))
            ->assertConflict()
            ->assertJsonPath('error', 'wallet_not_empty');

        $this->assertNull($wallet->fresh()->closed_at);
    }

    public function test_a_wallet_with_a_pending_bet_cannot_be_closed_even_at_zero_balance(): void
    {
        $wallet = $this->fundedWallet(1_000);
        app(BetService::class)->place($wallet, 1_000, 20_000);

        $this->postJson(route('api.v1.wallet.close', $wallet->user))
            ->assertConflict()
            ->assertJsonPath('error', 'wallet_has_pending_bets');

        $this->assertNull($wallet->fresh()->closed_at);
    }

    public function test_a_closed_wallet_rejects_new_money_but_replays_old_references(): void
    {
        $wallet = $this->fundedWallet(100);
        $this->move('withdraw', $wallet, 100, 'empty-it')->assertCreated();

        $this->postJson(route('api.v1.wallet.close', $wallet->user))
            ->assertOk()
            ->assertJsonPath('data.balance', 0);

        $this->move('deposit', $wallet, 50, 'after-close')
            ->assertConflict()
            ->assertJsonPath('error', 'wallet_closed');
        $this->move('withdraw', $wallet, 100, 'empty-it')->assertOk();
        $this->postJson(route('api.v1.wallet.close', $wallet->user))
            ->assertConflict()
            ->assertJsonPath('error', 'wallet_closed');
        $this->postJson(route('api.v1.wallet.store', $wallet->user))
            ->assertConflict()
            ->assertJsonPath('error', 'wallet_exists');

        $this->assertSame(0, $wallet->fresh()->balance);
    }

    private function fundedWallet(int $balance): Wallet
    {
        $wallet = Wallet::factory()->create();
        app(WalletService::class)->deposit($wallet, $balance, 'seed');

        return $wallet;
    }

    private function move(string $movement, Wallet $wallet, int $amount, string $reference): TestResponse
    {
        return $this->postJson(route("api.v1.wallet.{$movement}", $wallet->user), [
            'amount' => $amount,
            'reference' => $reference,
        ]);
    }
}
