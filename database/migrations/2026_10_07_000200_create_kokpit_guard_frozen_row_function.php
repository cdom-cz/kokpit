<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Generic immutability guards, installed once and attached per table by later
 * migrations through App\Domain\Shared\Database\Immutability.
 *
 * kokpit_guard_frozen_row() is a row trigger function parameterised through
 * TG_ARGV: [0] the lifecycle column, [1] the only editable state, [2] a
 * comma-separated list of operational columns that may still change after the
 * row is frozen. Any other change to a frozen row, and any delete of it, is
 * refused with the user-defined SQLSTATE KP001, whichever writer sends it
 * (application, package code, console, raw SQL).
 *
 * kokpit_refuse_truncate() is the statement-level companion: row triggers do
 * not fire on TRUNCATE, so a guarded table additionally gets a BEFORE TRUNCATE
 * trigger that refuses with KP001.
 *
 * Limits (see Immutability): the table owner can disable triggers and
 * session_replication_role = replica skips them, so the production database
 * role must not be a superuser.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_guard_frozen_row() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
              frozen_when text := TG_ARGV[0];
              open_state  text := TG_ARGV[1];
              allowed     text[] := string_to_array(coalesce(TG_ARGV[2], ''), ',');
            BEGIN
              IF TG_OP = 'DELETE' THEN
                IF to_jsonb(OLD) ->> frozen_when IS DISTINCT FROM open_state THEN
                  RAISE EXCEPTION 'row in %.% is immutable (state %): delete refused', TG_TABLE_SCHEMA, TG_TABLE_NAME, to_jsonb(OLD) ->> frozen_when USING ERRCODE = 'KP001';
                END IF;
                RETURN OLD;
              END IF;
              IF to_jsonb(OLD) ->> frozen_when IS DISTINCT FROM open_state THEN
                IF (to_jsonb(NEW) - allowed) IS DISTINCT FROM (to_jsonb(OLD) - allowed) THEN
                  RAISE EXCEPTION 'row in %.% is immutable (state %): update refused', TG_TABLE_SCHEMA, TG_TABLE_NAME, to_jsonb(OLD) ->> frozen_when USING ERRCODE = 'KP001';
                END IF;
              END IF;
              RETURN NEW;
            END $$
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_refuse_truncate() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              RAISE EXCEPTION 'table %.% is guarded: truncate refused', TG_TABLE_SCHEMA, TG_TABLE_NAME USING ERRCODE = 'KP001';
              RETURN NULL;
            END $$
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS kokpit_refuse_truncate()');
        DB::unprepared('DROP FUNCTION IF EXISTS kokpit_guard_frozen_row()');
    }
};
