<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function (): void {
    foreach (['audit_events', 'api_logs'] as $table) {
        DB::table($table)->where('created_at', '<', now()->subDays(30))->delete();
    }
})->daily()->name('prune-logs');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
