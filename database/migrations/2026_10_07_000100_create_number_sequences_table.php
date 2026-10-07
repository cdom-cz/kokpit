<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Counter rows behind every gap-free number (task keys, invoice numbers).
 *
 * Contract confirmed by the owner (option `next-value`): next_value is the
 * NEXT number to hand out, so an importer sets it directly and the last issued
 * number is next_value - 1. A key is "kind:qualifier", for example
 * invoice:2026 or task:<project uuid>. PostgreSQL sequences and MAX()+1 are
 * deliberately not used: they either leave gaps after a rollback or race.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            $table->string('scope_key', 191)->unique();
            $table->bigInteger('next_value')->default(1);

            $table->timestampsTz();
        });

        DB::statement('ALTER TABLE number_sequences ADD CONSTRAINT number_sequences_next_value_check CHECK (next_value >= 1)');
        DB::statement("ALTER TABLE number_sequences ADD CONSTRAINT number_sequences_scope_key_format_check CHECK (scope_key ~ '^[a-z][a-z0-9_]*:[A-Za-z0-9._-]+\$')");
    }
};
