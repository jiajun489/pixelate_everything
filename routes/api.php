<?php

use App\Http\Controllers\Api\V1\BetController;
use App\Http\Controllers\Api\V1\WalletController;
use Illuminate\Support\Facades\Route;

// ponytail: at most 18 digits so ids stay inside Postgres bigint; anything else is a 404, not a SQL error.
Route::prefix('v1')->name('api.v1.')->where(['player' => '[1-9][0-9]{0,17}', 'bet' => '[1-9][0-9]{0,17}'])->group(function (): void {
    Route::controller(WalletController::class)
        ->prefix('players/{player}/wallet')
        ->name('wallet.')
        ->group(function (): void {
            Route::post('/', 'store')->name('store');
            Route::get('/', 'show')->name('show');
            Route::post('deposits', 'deposit')->name('deposit');
            Route::post('withdrawals', 'withdraw')->name('withdraw');
            Route::get('transactions', 'transactions')->name('transactions');
            Route::post('close', 'close')->name('close');
        });

    Route::controller(BetController::class)->name('bets.')->group(function (): void {
        Route::get('players/{player}/bets', 'index')->name('index');
        Route::post('players/{player}/bets', 'store')->name('store');
        Route::get('bets/{bet}', 'show')->name('show');
        Route::post('bets/{bet}/settle', 'settle')->name('settle');
        Route::post('bets/{bet}/cancel', 'cancel')->name('cancel');
    });
});
