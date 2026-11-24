<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\WalletMovementRequest;
use App\Http\Resources\WalletResource;
use App\Http\Resources\WalletTransactionResource;
use App\Models\User;
use App\Services\WalletService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WalletController extends Controller
{
    public function __construct(private WalletService $wallets) {}

    /**
     * Open a wallet
     *
     * Creates an empty wallet (balance 0) for the player. One wallet per player, ever.
     *
     * Errors: `409 wallet_exists`.
     */
    public function store(User $player): WalletResource
    {
        /** @status 201 */
        return new WalletResource($this->wallets->open($player));
    }

    /**
     * Get a wallet
     *
     * Returns the player's wallet, including its current balance and `closed_at` if closed.
     */
    public function show(User $player): WalletResource
    {
        return new WalletResource($player->wallet()->firstOrFail());
    }

    /**
     * Deposit funds
     *
     * Credits `amount` to the wallet. Returns `201` with the new transaction, or `200` with the
     * original transaction when the `reference` is replayed with the same amount.
     *
     * Errors: `409 wallet_closed`, `409 reference_conflict`, `422 balance_limit_exceeded`.
     */
    public function deposit(WalletMovementRequest $request, User $player): WalletTransactionResource
    {
        $movement = $request->validated();

        /** @status 201 */
        return new WalletTransactionResource(
            $this->wallets->deposit($player->wallet()->firstOrFail(), $movement['amount'], $movement['reference'])
        );
    }

    /**
     * Withdraw funds
     *
     * Debits `amount` from the wallet. Returns `201` with the new transaction, or `200` with the
     * original transaction when the `reference` is replayed with the same amount.
     *
     * Errors: `409 wallet_closed`, `409 reference_conflict`, `422 insufficient_funds`.
     */
    public function withdraw(WalletMovementRequest $request, User $player): WalletTransactionResource
    {
        $movement = $request->validated();

        /** @status 201 */
        return new WalletTransactionResource(
            $this->wallets->withdraw($player->wallet()->firstOrFail(), $movement['amount'], $movement['reference'])
        );
    }

    /**
     * List wallet transactions
     *
     * The wallet's ledger, oldest first, paginated. Includes deposits, withdrawals and bet
     * stakes, payouts and refunds, each with the balance before and after.
     */
    public function transactions(User $player): AnonymousResourceCollection
    {
        $wallet = $player->wallet()->firstOrFail();

        return WalletTransactionResource::collection($wallet->transactions()->orderBy('id')->paginate());
    }

    /**
     * Close a wallet
     *
     * Permanently closes the wallet. It must have a zero balance and no pending bets.
     * A closed wallet rejects all deposits, withdrawals and bets.
     *
     * Errors: `409 wallet_closed`, `409 wallet_not_empty`, `409 wallet_has_pending_bets`.
     */
    public function close(User $player): WalletResource
    {
        return new WalletResource($this->wallets->close($player->wallet()->firstOrFail()));
    }
}
