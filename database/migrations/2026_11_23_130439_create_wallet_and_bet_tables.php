<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $table->bigInteger('balance')->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->bigInteger('amount');
            $table->bigInteger('balance_before');
            $table->bigInteger('balance_after');
            $table->string('reference', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['wallet_id', 'reference']);
        });

        Schema::create('bets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->restrictOnDelete();
            $table->bigInteger('stake');
            $table->integer('odds_bps');
            $table->string('status', 10)->default('PENDING');
            $table->bigInteger('payout')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'status']);
        });

        // ponytail: CHECKs are Postgres-only because SQLite cannot add them via ALTER TABLE.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_balance_non_negative CHECK (balance >= 0)');
            DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_closed_means_empty CHECK (closed_at IS NULL OR balance = 0)');
            DB::statement("ALTER TABLE wallet_transactions ADD CONSTRAINT wallet_transactions_valid_math CHECK (
                type IN ('deposit', 'withdrawal', 'bet_stake', 'bet_payout', 'bet_refund')
                AND amount > 0
                AND balance_before >= 0
                AND balance_after >= 0
                AND balance_after = balance_before + CASE WHEN type IN ('withdrawal', 'bet_stake') THEN -amount ELSE amount END
            )");
            DB::statement("ALTER TABLE bets ADD CONSTRAINT bets_valid CHECK (
                stake > 0
                AND odds_bps > 10000
                AND status IN ('PENDING', 'WON', 'LOST', 'CANCELLED')
            )");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bets');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
    }
};
