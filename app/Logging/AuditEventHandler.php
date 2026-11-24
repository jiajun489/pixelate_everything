<?php

namespace App\Logging;

use Illuminate\Support\Facades\DB;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;

/**
 * Stores each wallet log record as a queryable audit_events row.
 */
class AuditEventHandler extends AbstractProcessingHandler
{
    protected function write(LogRecord $record): void
    {
        DB::table('audit_events')->insert([
            'event' => $record->message,
            'level' => $record->level->toPsrLogLevel(),
            'wallet_id' => $record->context['wallet_id'] ?? null,
            'bet_id' => $record->context['bet_id'] ?? null,
            'context' => json_encode($record->context),
            'created_at' => $record->datetime,
        ]);
    }
}
