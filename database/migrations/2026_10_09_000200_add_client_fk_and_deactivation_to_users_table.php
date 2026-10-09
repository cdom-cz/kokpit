<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Completes the Partner link and the account lifecycle (Phase 4 D-01, D-03, D-04).
 *
 * - users.client_id (created in the first users migration, indexed, no foreign
 *   key until the clients table existed) now references clients(id) with
 *   ON DELETE RESTRICT: a client with accounts is archived, never hard deleted.
 * - deactivated_at switches an account off without deleting it; the panel gate
 *   (User::canAccessPanel) reads it on every request.
 * - e-mail uniqueness is case-insensitive: two addresses that differ only in
 *   letter case are the same account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('deactivated_at')->nullable();
        });

        DB::statement('ALTER TABLE users ADD CONSTRAINT users_client_id_foreign FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE RESTRICT');
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');
    }
};
