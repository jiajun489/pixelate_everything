<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BetStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BetResource;
use App\Models\Bet;
use App\Models\User;
use App\Services\BetService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class BetController extends Controller
{
    public function __construct(private BetService $bets) {}

    /**
     * List a player's bets
     *
     * All bets on the player's wallet, newest first, paginated.
     */
    public function index(User $player): AnonymousResourceCollection
    {
        return BetResource::collection($player->bets()->orderByDesc('bets.id')->paginate());
    }

    /**
     * Place a bet
     *
     * Debits `stake` from the wallet and creates a `PENDING` bet. `odds_bps` is decimal odds
     * times 10000, e.g. `25000` = 2.50.
     *
     * Errors: `409 wallet_closed`, `422 insufficient_funds`.
     */
    public function store(Request $request, User $player): BetResource
    {
        $validated = $request->validate([
            'stake' => ['required', 'integer:strict', 'min:'.Bet::MIN_STAKE, 'max:'.Bet::MAX_STAKE],
            'odds_bps' => ['required', 'integer:strict', 'min:'.Bet::MIN_ODDS_BPS, 'max:'.Bet::MAX_ODDS_BPS],
        ]);

        /** @status 201 */
        return new BetResource(
            $this->bets->place($player->wallet()->firstOrFail(), $validated['stake'], $validated['odds_bps'])
        );
    }

    /**
     * Get a bet
     */
    public function show(Bet $bet): BetResource
    {
        return new BetResource($bet);
    }

    /**
     * Settle a bet
     *
     * Resolves a `PENDING` bet as `WON` or `LOST`. `WON` credits `floor(stake * odds_bps / 10000)`
     * (stake included) to the wallet; `LOST` credits nothing.
     *
     * Errors: `409 bet_already_resolved`.
     */
    public function settle(Request $request, Bet $bet): BetResource
    {
        $validated = $request->validate([
            'outcome' => ['required', 'string', Rule::enum(BetStatus::class)->only([BetStatus::Won, BetStatus::Lost])],
        ]);

        return new BetResource($this->bets->settle($bet, BetStatus::from($validated['outcome'])));
    }

    /**
     * Cancel a bet
     *
     * Resolves a `PENDING` bet as `CANCELLED` and refunds the stake to the wallet.
     *
     * Errors: `409 bet_already_resolved`.
     */
    public function cancel(Bet $bet): BetResource
    {
        return new BetResource($this->bets->cancel($bet));
    }
}
