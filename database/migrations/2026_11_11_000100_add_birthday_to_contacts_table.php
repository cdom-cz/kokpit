<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A contact can carry a birth date whose year is optional (CL-02).
 *
 * Three nullable smallint columns instead of one `date`: a date column would need a dummy year (a fake leap year for
 * 29 February) and a separate "year known" flag. Day and month are always set together, and the year needs both. There
 * is no backfill: every existing contact has an unknown birthday.
 *
 * The CHECKs are the last line behind the ContactInput validation, which also refuses a birth date in the future (that
 * depends on the current day and cannot live in a CHECK). The year range is only a coarse backstop. The leap-day CHECK
 * refuses 29 February of a known year that is not a Gregorian leap year; 29 February without a year is allowed.
 *
 * The partial index serves the "who has a birthday on this day" lookup of the dashboard card and the API. Contacts are
 * personal data of the Admin only (D-06), so nothing here is visible to a Partner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->smallInteger('birth_day')->nullable();
            $table->smallInteger('birth_month')->nullable();
            $table->smallInteger('birth_year')->nullable();
        });

        DB::statement('ALTER TABLE contacts ADD CONSTRAINT contacts_birthday_pair_check CHECK ((birth_day IS NULL) = (birth_month IS NULL))');
        DB::statement('ALTER TABLE contacts ADD CONSTRAINT contacts_birth_year_needs_date_check CHECK (birth_year IS NULL OR birth_month IS NOT NULL)');
        DB::statement('ALTER TABLE contacts ADD CONSTRAINT contacts_birth_month_check CHECK (birth_month IS NULL OR birth_month BETWEEN 1 AND 12)');
        DB::statement(<<<'SQL'
            ALTER TABLE contacts ADD CONSTRAINT contacts_birth_day_check CHECK (
                birth_day IS NULL OR birth_day BETWEEN 1 AND CASE
                    WHEN birth_month IN (4, 6, 9, 11) THEN 30
                    WHEN birth_month = 2 THEN 29
                    ELSE 31
                END
            )
            SQL);
        DB::statement('ALTER TABLE contacts ADD CONSTRAINT contacts_birth_year_check CHECK (birth_year IS NULL OR birth_year BETWEEN 1900 AND 2100)');
        DB::statement(<<<'SQL'
            ALTER TABLE contacts ADD CONSTRAINT contacts_birth_leap_day_check CHECK (
                birth_month IS DISTINCT FROM 2
                OR birth_day IS DISTINCT FROM 29
                OR birth_year IS NULL
                OR (birth_year % 4 = 0 AND (birth_year % 100 <> 0 OR birth_year % 400 = 0))
            )
            SQL);

        DB::statement('CREATE INDEX contacts_birthday_index ON contacts (birth_month, birth_day) WHERE birth_month IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS contacts_birthday_index');

        // Dropping the columns drops the CHECK constraints that reference them.
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn(['birth_day', 'birth_month', 'birth_year']);
        });
    }
};
