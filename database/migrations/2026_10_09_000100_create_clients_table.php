<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The client: the identity every project, task, time entry and invoice hangs off.
 *
 * Enum-like columns are varchar plus a CHECK constraint (alterable by a
 * migration, unlike a native PostgreSQL enum). The Czech company ID and tax ID
 * are stored as company_number and tax_number, because schema rule R1 demands
 * that a column ending in _id is a uuid.
 *
 * There is deliberately no database default for country, currency, rate, payment
 * terms or invoice language: the creating code copies them from the typed
 * defaults (D-13). The company number is unique per country INCLUDING archived
 * clients, so an archived client keeps reserving its number and a restore can
 * never collide (D-09).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            $table->string('name', 255);
            $table->string('company_number', 32)->nullable();
            $table->string('tax_number', 32)->nullable();
            $table->char('country', 2);
            $table->string('street', 255)->nullable();
            $table->string('city', 255)->nullable();
            $table->string('postal_code', 20)->nullable();

            $table->string('stage', 16)->default('active');

            $table->char('currency', 3);
            $table->bigInteger('hourly_rate_minor');
            $table->char('hourly_rate_currency', 3);
            $table->smallInteger('payment_terms_days');
            $table->string('invoice_email', 255)->nullable();
            $table->string('invoice_language', 5);
            $table->boolean('online_payment_enabled')->default(false);

            $table->softDeletesTz();
            $table->timestampsTz();

            $table->index('name');
            $table->index('stage');
        });

        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_country_check CHECK (country ~ '^[A-Z]{2}\$')");
        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_stage_check CHECK (stage IN ('lead', 'active', 'paused', 'ended'))");
        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_currency_check CHECK (currency ~ '^[A-Z]{3}\$')");
        DB::statement('ALTER TABLE clients ADD CONSTRAINT clients_hourly_rate_minor_check CHECK (hourly_rate_minor >= 0)');
        DB::statement('ALTER TABLE clients ADD CONSTRAINT clients_hourly_rate_currency_check CHECK (hourly_rate_currency = currency)');
        DB::statement('ALTER TABLE clients ADD CONSTRAINT clients_payment_terms_days_check CHECK (payment_terms_days BETWEEN 0 AND 365)');
        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_invoice_language_check CHECK (invoice_language IN ('cs', 'en'))");

        // No deleted_at predicate on purpose: an archived client keeps its number reserved.
        DB::statement('CREATE UNIQUE INDEX clients_country_company_number_unique ON clients (country, company_number) WHERE company_number IS NOT NULL');
    }
};
