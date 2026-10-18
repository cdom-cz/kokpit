<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The immutability guard for the rows of a child table, installed once and attached per table by later
 * migrations through App\Domain\Shared\Database\Immutability::childGuardTriggerSql() (D-03, D-13).
 *
 * kokpit_guard_child_of_frozen_parent() is a row trigger function parameterised through TG_ARGV: [0] the parent
 * table, [1] the column of the child that holds the parent id, [2] the lifecycle column of the parent, [3] the only
 * editable state of the parent, [4] a comma-separated list of columns of the child that may still change under a
 * frozen parent. The list is exact: `updated_at` is never implied, so a frozen child refuses even a write that
 * touches nothing but the timestamp, and an empty argument means no column may change.
 *
 * The parent is looked up by id. A parent that does not exist counts as open: an item without a parent cannot
 * exist (the foreign key), and what happens to the parent row itself is decided by its own guard. The lookup takes
 * a share lock on the parent row, so a draft cannot be issued while an item write of another transaction is still
 * running (the issue statement waits for it), and an item write that starts after the issue sees the frozen state.
 *
 * Under a frozen parent:
 *   INSERT - refused, so no item can be added to an issued document;
 *   DELETE - refused;
 *   UPDATE - refused unless nothing but the listed columns changes. Moving a child to another parent checks both
 *            parents: the old and the new one must be open, or the move changes the parent column and is refused.
 * Every refusal carries the user-defined SQLSTATE KP001, whichever writer sends the statement (application,
 * package code, console, raw SQL).
 *
 * Limits are the ones of kokpit_guard_frozen_row(): the table owner can disable triggers and
 * session_replication_role = replica skips them, so the production database role must not be a superuser.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_guard_child_of_frozen_parent() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
              parent_table text := TG_ARGV[0];
              fk_column    text := TG_ARGV[1];
              state_column text := TG_ARGV[2];
              open_state   text := TG_ARGV[3];
              allowed      text[] := string_to_array(coalesce(TG_ARGV[4], ''), ',');
              old_parent   uuid;
              new_parent   uuid;
              old_state    text;
              new_state    text;
            BEGIN
              IF TG_OP <> 'INSERT' THEN
                old_parent := (to_jsonb(OLD) ->> fk_column)::uuid;
              END IF;
              IF TG_OP <> 'DELETE' THEN
                new_parent := (to_jsonb(NEW) ->> fk_column)::uuid;
              END IF;

              IF old_parent IS NOT NULL THEN
                EXECUTE format('SELECT %I FROM %I WHERE id = $1 FOR SHARE', state_column, parent_table) INTO old_state USING old_parent;
              END IF;
              IF new_parent IS NOT NULL THEN
                IF new_parent IS NOT DISTINCT FROM old_parent THEN
                  new_state := old_state;
                ELSE
                  EXECUTE format('SELECT %I FROM %I WHERE id = $1 FOR SHARE', state_column, parent_table) INTO new_state USING new_parent;
                END IF;
              END IF;

              IF TG_OP = 'DELETE' THEN
                IF old_state IS NOT NULL AND old_state <> open_state THEN
                  RAISE EXCEPTION 'row in %.% belongs to a % in state %: delete refused', TG_TABLE_SCHEMA, TG_TABLE_NAME, parent_table, old_state USING ERRCODE = 'KP001';
                END IF;
                RETURN OLD;
              END IF;

              IF TG_OP = 'INSERT' THEN
                IF new_state IS NOT NULL AND new_state <> open_state THEN
                  RAISE EXCEPTION 'row in %.% belongs to a % in state %: insert refused', TG_TABLE_SCHEMA, TG_TABLE_NAME, parent_table, new_state USING ERRCODE = 'KP001';
                END IF;
                RETURN NEW;
              END IF;

              IF (old_state IS NOT NULL AND old_state <> open_state) OR (new_state IS NOT NULL AND new_state <> open_state) THEN
                IF (to_jsonb(NEW) - allowed) IS DISTINCT FROM (to_jsonb(OLD) - allowed) THEN
                  RAISE EXCEPTION 'row in %.% belongs to a % in state %: update refused', TG_TABLE_SCHEMA, TG_TABLE_NAME, parent_table, coalesce(nullif(old_state, open_state), new_state) USING ERRCODE = 'KP001';
                END IF;
              END IF;
              RETURN NEW;
            END $$
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS kokpit_guard_child_of_frozen_parent()');
    }
};
