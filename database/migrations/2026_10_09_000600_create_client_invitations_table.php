<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Partner invitations (US-02, D-01 to D-03): one row per invited e-mail address,
 * created before the invited person has an account. The users row is created
 * only when the person sets a password through the link.
 *
 * Only the SHA-256 hash of the 256-bit random token is stored. The state of an
 * invitation (pending, accepted, revoked, expired) is never stored: it is
 * derived from accepted_at, revoked_at and expires_at, so it cannot drift, and
 * now() could not appear in an index predicate anyway. An open invitation
 * (neither accepted nor revoked, expired or not) occupies its e-mail address
 * through the partial unique index; resending rewrites the same row.
 *
 * The foreign keys restrict deletion, like every key that points at a client or
 * a user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            $table->foreignUuid('client_id')->constrained('clients')->restrictOnDelete();

            $table->string('name', 255);
            $table->string('email', 255);
            $table->char('token_hash', 64);

            $table->timestampTz('expires_at');
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('last_sent_at');
            $table->integer('send_count')->default(1);

            $table->timestampTz('accepted_at')->nullable();
            $table->foreignUuid('accepted_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('revoked_at')->nullable();

            $table->timestampsTz();

            $table->unique('token_hash', 'client_invitations_token_hash_unique');
        });

        DB::statement('ALTER TABLE client_invitations ADD CONSTRAINT client_invitations_email_lower_check CHECK (email = lower(email))');
        DB::statement('ALTER TABLE client_invitations ADD CONSTRAINT client_invitations_send_count_check CHECK (send_count >= 1)');
        DB::statement('ALTER TABLE client_invitations ADD CONSTRAINT client_invitations_single_outcome_check CHECK (NOT (accepted_at IS NOT NULL AND revoked_at IS NOT NULL))');
        DB::statement('ALTER TABLE client_invitations ADD CONSTRAINT client_invitations_accepted_pair_check CHECK ((accepted_at IS NULL) = (accepted_user_id IS NULL))');

        DB::statement('CREATE UNIQUE INDEX client_invitations_open_email_unique ON client_invitations (email) WHERE accepted_at IS NULL AND revoked_at IS NULL');
    }
};
