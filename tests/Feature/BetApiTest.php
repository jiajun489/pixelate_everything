<?php

namespace Tests\Feature;

use App\Models\Bet;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BetApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_placing_a_bet_debits_the_stake_through_the_ledger(): void
    {
        $wallet = $this->fundedWallet(10_000);

        $response = $this->placeBet($wallet, 1_000, 18_500)
            ->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.stake', 1_000)
            ->assertJsonPath('data.odds_bps', 18_500)
            ->assertJsonPath('data.payout', null);

        $this->assertSame(9_000, $wallet->fresh()->balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'wallet_id' => $wallet->id,
            'type' => 'bet_stake',
            'amount' => 1_000,
            'reference' => "bet:{$response->json('data.id')}:stake",
        ]);
        $this->assertLedgerExplainsBalance($wallet);
    }

    public function test_a_bet_the_wallet_cannot_cover_writes_nothing(): void
    {
        $wallet = $this->fundedWallet(999);

        $this->placeBet($wallet, 1_000)
            ->assertUnprocessable()
            ->assertJsonPath('error', 'insufficient_funds');

        $this->assertSame(0, Bet::count());
        $this->assertSame(999, $wallet->fresh()->balance);
        $this->assertSame(1, $wallet->transactions()->count());
    }

    public function test_a_closed_wallet_cannot_place_a_bet(): void
    {
        $wallet = Wallet::factory()->create();
        app(WalletService::class)->close($wallet);

        $this->placeBet($wallet, Bet::MIN_STAKE)
            ->assertConflict()
            ->assertJsonPath('error', 'wallet_closed');

        $this->assertSame(0, Bet::count());
    }

    public function test_a_player_without_a_wallet_cannot_place_a_bet(): void
    {
        $this->postJson(route('api.v1.bets.store', User::factory()->create()), [
            'stake' => 1_000,
            'odds_bps' => 20_000,
        ])->assertNotFound()->assertJsonPath('error', 'wallet_not_found');

        $this->getJson(route('api.v1.bets.show', 999_999))
            ->assertNotFound()
            ->assertExactJson(['error' => 'bet_not_found', 'message' => 'This bet does not exist.']);
    }

    #[DataProvider('invalidBets')]
    public function test_stake_and_odds_are_validated(string $field, mixed $value): void
    {
        $wallet = $this->fundedWallet(10_000_000);

        $this->postJson(route('api.v1.bets.store', $wallet->user), [
            'stake' => 1_000,
            'odds_bps' => 20_000,
            $field => $value,
        ])->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertSame(0, Bet::count());
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function invalidBets(): array
    {
        return [
            'stake below minimum' => ['stake', Bet::MIN_STAKE - 1],
            'stake above maximum' => ['stake', Bet::MAX_STAKE + 1],
            'fractional stake' => ['stake', 100.5],
            'string stake' => ['stake', '1000'],
            'odds below minimum' => ['odds_bps', Bet::MIN_ODDS_BPS - 1],
            'odds above maximum' => ['odds_bps', Bet::MAX_ODDS_BPS + 1],
            'float odds' => ['odds_bps', 1.85],
        ];
    }

    public function test_a_won_bet_credits_the_payout_rounded_down(): void
    {
        $wallet = $this->fundedWallet(10_000);
        $betId = $this->placeBet($wallet, 333, 15_055)->json('data.id');

        $this->resolve($betId, 'settle', 'WON')
            ->assertOk()
            ->assertJsonPath('data.status', 'WON')
            ->assertJsonPath('data.payout', 501);

        $this->assertSame(10_000 - 333 + 501, $wallet->fresh()->balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'type' => 'bet_payout',
            'amount' => 501,
            'reference' => "bet:{$betId}:settlement",
        ]);
        $this->assertLedgerExplainsBalance($wallet);
    }

    public function test_the_largest_allowed_bet_pays_out_exactly(): void
    {
        $wallet = $this->fundedWallet(Bet::MAX_STAKE);
        $betId = $this->placeBet($wallet, Bet::MAX_STAKE, Bet::MAX_ODDS_BPS)->json('data.id');

        $this->resolve($betId, 'settle', 'WON')->assertJsonPath('data.payout', 100_000_000);

        $this->assertSame(100_000_000, $wallet->fresh()->balance);
    }

    public function test_a_lost_bet_credits_nothing_and_writes_no_transaction(): void
    {
        $wallet = $this->fundedWallet(10_000);
        $betId = $this->placeBet($wallet, 1_000)->json('data.id');

        $this->resolve($betId, 'settle', 'LOST')
            ->assertOk()
            ->assertJsonPath('data.status', 'LOST')
            ->assertJsonPath('data.payout', 0);

        $this->assertSame(9_000, $wallet->fresh()->balance);
        $this->assertSame(2, $wallet->transactions()->count());
        $this->assertLedgerExplainsBalance($wallet);
    }

    public function test_a_cancelled_bet_refunds_the_stake(): void
    {
        $wallet = $this->fundedWallet(10_000);
        $betId = $this->placeBet($wallet, 1_000)->json('data.id');

        $this->resolve($betId, 'cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.payout', null);

        $this->assertSame(10_000, $wallet->fresh()->balance);
        $this->assertDatabaseHas('wallet_transactions', [
            'type' => 'bet_refund',
            'amount' => 1_000,
            'reference' => "bet:{$betId}:settlement",
        ]);
        $this->assertLedgerExplainsBalance($wallet);
    }

    #[DataProvider('resolutions')]
    public function test_a_resolved_bet_cannot_transition_again(string $action, ?string $outcome): void
    {
        $wallet = $this->fundedWallet(10_000);
        $betId = $this->placeBet($wallet, 1_000)->json('data.id');
        $this->resolve($betId, $action, $outcome)->assertOk();

        $balance = $wallet->fresh()->balance;
        $transactionCount = $wallet->transactions()->count();

        foreach (self::resolutions() as [$nextAction, $nextOutcome]) {
            $this->resolve($betId, $nextAction, $nextOutcome)
                ->assertConflict()
                ->assertJsonPath('error', 'bet_already_resolved');
        }

        $this->assertSame($balance, $wallet->fresh()->balance);
        $this->assertSame($transactionCount, $wallet->transactions()->count());
        $this->assertDatabaseHas('audit_events', [
            'event' => 'wallet.rejected',
            'wallet_id' => $wallet->id,
            'bet_id' => $betId,
        ]);
    }

    /**
     * @return array<string, array{string, ?string}>
     */
    public static function resolutions(): array
    {
        return [
            'won' => ['settle', 'WON'],
            'lost' => ['settle', 'LOST'],
            'cancelled' => ['cancel', null],
        ];
    }

    #[DataProvider('invalidOutcomes')]
    public function test_settle_only_accepts_won_or_lost(mixed $outcome): void
    {
        $wallet = $this->fundedWallet(10_000);
        $betId = $this->placeBet($wallet, 1_000)->json('data.id');

        $this->postJson(route('api.v1.bets.settle', $betId), ['outcome' => $outcome])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('outcome');

        $this->assertSame('PENDING', Bet::findOrFail($betId)->status->value);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidOutcomes(): array
    {
        return [
            'pending' => ['PENDING'],
            'cancelled' => ['CANCELLED'],
            'lowercase' => ['won'],
            'missing' => [null],
        ];
    }

    public function test_a_wallet_can_close_after_its_last_bet_is_resolved(): void
    {
        $wallet = $this->fundedWallet(1_000);
        $betId = $this->placeBet($wallet, 1_000)->json('data.id');
        $this->resolve($betId, 'settle', 'LOST')->assertOk();

        $this->postJson(route('api.v1.wallet.close', $wallet->user))->assertOk();

        $this->assertNotNull($wallet->fresh()->closed_at);
    }

    public function test_get_and_list_return_bets_for_the_right_player(): void
    {
        $wallet = $this->fundedWallet(10_000);
        $otherWallet = $this->fundedWallet(10_000);
        $firstBetId = $this->placeBet($wallet, 1_000)->json('data.id');
        $secondBetId = $this->placeBet($wallet, 2_000)->json('data.id');
        $this->placeBet($otherWallet, 3_000);

        $this->getJson(route('api.v1.bets.show', $firstBetId))
            ->assertOk()
            ->assertJsonPath('data.stake', 1_000);

        $this->getJson(route('api.v1.bets.index', $wallet->user))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $secondBetId)
            ->assertJsonPath('data.1.id', $firstBetId);
    }

    private function fundedWallet(int $balance): Wallet
    {
        $wallet = Wallet::factory()->create();
        app(WalletService::class)->deposit($wallet, $balance, 'seed');

        return $wallet;
    }

    private function placeBet(Wallet $wallet, int $stake, int $oddsBps = 20_000): TestResponse
    {
        return $this->postJson(route('api.v1.bets.store', $wallet->user), [
            'stake' => $stake,
            'odds_bps' => $oddsBps,
        ]);
    }

    private function resolve(int $betId, string $action, ?string $outcome = null): TestResponse
    {
        return $this->postJson(
            route("api.v1.bets.{$action}", $betId),
            $outcome === null ? [] : ['outcome' => $outcome],
        );
    }
}
