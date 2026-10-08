<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The Filament bell filters notifications with the JSON operator on "data"
 * (data->>'format' = 'filament'), which PostgreSQL does not define for a text
 * column. Once the bell is on (D-11), every panel page would fail with a 500.
 * The stored payload is always JSON, so the column becomes jsonb in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE jsonb USING data::jsonb');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text');
    }
};
