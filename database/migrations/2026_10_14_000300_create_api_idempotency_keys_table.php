<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Idempotency claims and the stored answers of the REST API (D-06 to D-08, OD-3).
 *
 * A create request that carries an Idempotency-Key first claims (token, key) with one atomic
 * INSERT ... ON CONFLICT DO NOTHING on the unique index below, outside any request transaction; the claim
 * is what keeps two concurrent retries from both creating an entry. When the request succeeds, the row
 * keeps the 2xx status and the body for a byte-identical replay. A request that fails releases (deletes)
 * its claim, so a corrected retry with the same key is a first request again (OD-3); that is why the
 * stored status can only be 2xx, and why a response and its completion time exist together.
 *
 * request_hash is the sha256 of method, path and raw body: the same key with another body is refused,
 * never replayed. A claim without a response older than the stale-lock setting belongs to a crashed
 * request and is taken over by the next retry. A key expires after expires_at (24 hours by default);
 * an expired row counts as absent and is purged by a scheduled job.
 *
 * Keys are scoped to the token that sent them, and revoking the token deletes its keys with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            $table->foreignUuid('token_id')->constrained('personal_access_tokens')->cascadeOnDelete();
            $table->string('key', 255);
            $table->char('request_hash', 64);

            // Whole seconds: the claim time doubles as the version of a claim, and a takeover moves it
            // by at least the stale-lock setting, so two claims never share a value.
            $table->timestampTz('locked_at', 0);
            $table->smallInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->timestampTz('completed_at', 0)->nullable();
            $table->timestampTz('expires_at', 0)->index();

            $table->timestampsTz();

            $table->unique(['token_id', 'key'], 'api_idempotency_keys_token_key_unique');
        });

        DB::statement('ALTER TABLE api_idempotency_keys ADD CONSTRAINT api_idempotency_keys_response_check CHECK (response_status IS NULL OR response_status BETWEEN 200 AND 299)');
        DB::statement('ALTER TABLE api_idempotency_keys ADD CONSTRAINT api_idempotency_keys_completion_check CHECK ((completed_at IS NULL) = (response_status IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};
