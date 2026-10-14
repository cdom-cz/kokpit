<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A change made through the REST API is logged with the source 'api' (OD-4), so the audit trail does
 * not show it as a browser edit. The CHECK is replaced by name: the old one only knew web, console,
 * job and webhook. The label is set on the server by the API middleware, never from input (D-08).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE activity_log DROP CONSTRAINT activity_log_source_check');
        DB::statement("ALTER TABLE activity_log ADD CONSTRAINT activity_log_source_check CHECK (source IN ('web', 'console', 'job', 'webhook', 'api'))");
    }
};
