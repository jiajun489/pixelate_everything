<?php

namespace App\Exceptions;

use App\Models\Bet;
use App\Models\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DomainRuleException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }

    /**
     * Log the rejected request to the wallet log instead of laravel.log.
     */
    public function report(Request $request): void
    {
        $player = $request->route('player');
        $bet = $request->route('bet');

        Log::channel('wallet')->warning('wallet.rejected', [
            'wallet_id' => $bet instanceof Bet ? $bet->wallet_id : ($player instanceof User ? $player->wallet?->id : null),
            'bet_id' => $bet instanceof Bet ? $bet->id : null,
            'player_id' => $player instanceof User ? $player->id : null,
            'error' => $this->errorCode,
            'message' => $this->getMessage(),
            'method' => $request->method(),
            'path' => $request->path(),
            'input' => $request->only(['amount', 'reference', 'stake', 'odds_bps', 'outcome']),
        ]);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => $this->errorCode,
            'message' => $this->getMessage(),
        ], $this->status);
    }
}
