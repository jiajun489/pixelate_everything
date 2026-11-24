<?php

namespace App\Models;

use App\Enums\BetStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['stake', 'odds_bps', 'status', 'payout', 'resolved_at'])]
class Bet extends Model
{
    public const MIN_STAKE = 100;

    public const MAX_STAKE = 1_000_000;

    public const ODDS_SCALE = 10_000;

    public const MIN_ODDS_BPS = 10_100;

    public const MAX_ODDS_BPS = 1_000_000;

    /**
     * @var array<string, string>
     */
    protected $attributes = ['status' => 'PENDING'];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'stake' => 'integer',
            'odds_bps' => 'integer',
            'status' => BetStatus::class,
            'payout' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Wallet, $this>
     */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    /**
     * Calculate the total payout, including the stake, rounded down to the smallest unit.
     */
    public function winningPayout(): int
    {
        return intdiv($this->stake * $this->odds_bps, self::ODDS_SCALE);
    }
}
