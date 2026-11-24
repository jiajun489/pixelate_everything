<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. No foreign keys: an audit row must never block or be blocked by domain writes.
     */
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('event', 50);
            $table->string('level', 10);
            $table->unsignedBigInteger('wallet_id')->nullable()->index();
            $table->unsignedBigInteger('bet_id')->nullable()->index();
            $table->json('context');
            $table->timestamp('created_at')->index();

            $table->index(['event', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.got
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
