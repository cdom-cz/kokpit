<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Where a change came from (D-08): web, console, job or webhook. Set on the
 * server in KokpitLogActivityAction. The column is nullable only so rows
 * written outside the application (imports) stay possible; the overview
 * filter is a plain indexed predicate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->string('source', 16)->nullable()->index();
        });

        DB::statement("ALTER TABLE activity_log ADD CONSTRAINT activity_log_source_check CHECK (source IN ('web', 'console', 'job', 'webhook'))");
    }
};
