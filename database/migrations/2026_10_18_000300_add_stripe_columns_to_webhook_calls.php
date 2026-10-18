<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The Stripe webhook intake (Phase 11, plan 11-04, rulings R11-04-3 and R11-04-4) stores each handled event once
 * in webhook_calls and tracks its processing:
 *
 * - event_id holds Stripe's own text event id (not a uuid) and event_name the Stripe event type (not called
 *   event_type, because the schema rules read an <x>_type column with an <x>_id sibling as a morph pair);
 * - processed_at is set by the processing job; failed_at and failure_class record a final failure with a fixed
 *   class and never the exception text;
 * - webhook_calls_name_event_id_unique is the last line of defence against a repeated delivery: the store uses
 *   INSERT ... ON CONFLICT DO NOTHING against it (D-12), and it is partial so rows of other names, which carry no
 *   event id, are not affected;
 * - webhook_calls_unprocessed_index serves the "waiting events" reads (the lost-dispatch repair and the System page);
 * - webhook_calls_stripe_event_check makes a stripe row without an event id and type impossible;
 * - webhook_calls_failed_check makes a row that is both failed and processed impossible.
 *
 * down() drops them again; a stripe row would be left without its identity, so it refuses to run while one exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_calls', function (Blueprint $table): void {
            $table->string('event_id', 255)->nullable();
            $table->string('event_name', 100)->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('failure_class', 32)->nullable();
        });

        DB::statement('CREATE UNIQUE INDEX webhook_calls_name_event_id_unique ON webhook_calls (name, event_id) WHERE event_id IS NOT NULL');
        DB::statement('CREATE INDEX webhook_calls_unprocessed_index ON webhook_calls (created_at) WHERE processed_at IS NULL');
        DB::statement("ALTER TABLE webhook_calls ADD CONSTRAINT webhook_calls_stripe_event_check CHECK (name <> 'stripe' OR (event_id IS NOT NULL AND event_name IS NOT NULL))");
        DB::statement('ALTER TABLE webhook_calls ADD CONSTRAINT webhook_calls_failed_check CHECK (failed_at IS NULL OR processed_at IS NULL)');
    }

    public function down(): void
    {
        $blocking = DB::selectOne('SELECT count(*) AS total FROM webhook_calls WHERE event_id IS NOT NULL');

        if ((int) ($blocking->total ?? 0) > 0) {
            throw new RuntimeException('Cannot roll back: stored webhook events exist, and their identity columns would be lost.');
        }

        DB::statement('ALTER TABLE webhook_calls DROP CONSTRAINT webhook_calls_failed_check');
        DB::statement('ALTER TABLE webhook_calls DROP CONSTRAINT webhook_calls_stripe_event_check');
        DB::statement('DROP INDEX webhook_calls_unprocessed_index');
        DB::statement('DROP INDEX webhook_calls_name_event_id_unique');

        Schema::table('webhook_calls', function (Blueprint $table): void {
            $table->dropColumn(['event_id', 'event_name', 'processed_at', 'failed_at', 'failure_class']);
        });
    }
};
