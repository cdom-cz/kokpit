<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The people of a client (CL-02, D-12): any number per client, any number of
 * them billing contacts, at most one primary.
 *
 * The partial unique index guarantees at most one primary per client. "Exactly
 * one" is kept by the domain Actions (the first contact becomes primary, a new
 * primary demotes the old one first), because a partial unique index cannot be
 * deferred. Contacts have no soft delete: they are plain data, later invoices
 * snapshot the recipient and the activity log records the delete. The client
 * foreign key restricts deletion, like every key that points at a client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();

            $table->string('name', 255);
            $table->string('email', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('position', 255)->nullable();

            $table->boolean('is_primary')->default(false);
            $table->boolean('is_billing')->default(false);

            $table->timestampsTz();
        });

        DB::statement('CREATE UNIQUE INDEX contacts_one_primary_per_client ON contacts (client_id) WHERE is_primary');
    }
};
