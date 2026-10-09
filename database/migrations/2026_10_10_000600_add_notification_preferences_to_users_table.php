<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Per-user notification switches (TA-07, D-15).
 *
 * One jsonb object per user, shaped { "<event>": { "<channel>": true|false } }.
 * A missing key means the channel is on, so the default of every user is the
 * empty object and the column never needs a backfill. The CHECK keeps the value an
 * object: a JSON array or a string would make every reader guess. Who may write
 * which keys is the application's job (UpdateNotificationPreferences); the
 * database only guards the shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users ADD COLUMN notification_preferences jsonb NOT NULL DEFAULT '{}'::jsonb");
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_notification_preferences_check CHECK (jsonb_typeof(notification_preferences) = 'object')");
    }
};
