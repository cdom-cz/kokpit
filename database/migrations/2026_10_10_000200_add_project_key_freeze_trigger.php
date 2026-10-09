<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The project key is frozen as soon as the project has any task.
 *
 * A task reference is KEY-N, so changing the key afterwards would leave every
 * existing reference pointing at a name the project no longer has. The guard is
 * a row trigger, so it holds for every writer (application, package code,
 * console, raw SQL), like the KP001 guards. It refuses with the user-defined
 * SQLSTATE KP002 (KP001 belongs to the frozen-row guard).
 *
 * Soft-deleted tasks count: an archived task keeps its reference reserved, so
 * the key stays frozen.
 *
 * Race (a task insert in flight while the key is updated): CreateTask holds the
 * project row FOR SHARE, which conflicts with the row lock a key UPDATE takes,
 * so the UPDATE waits until the creating transaction ends and then sees its
 * task row. The trigger needs no lock of its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_guard_project_key() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
              IF NEW.key IS DISTINCT FROM OLD.key AND EXISTS (SELECT 1 FROM tasks WHERE project_id = OLD.id) THEN
                RAISE EXCEPTION 'project key % is frozen: the project has tasks', OLD.key USING ERRCODE = 'KP002';
              END IF;
              RETURN NEW;
            END $$
            SQL);

        DB::unprepared('CREATE TRIGGER projects_key_frozen_guard BEFORE UPDATE OF key ON projects FOR EACH ROW EXECUTE FUNCTION kokpit_guard_project_key()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS projects_key_frozen_guard ON projects');
        DB::unprepared('DROP FUNCTION IF EXISTS kokpit_guard_project_key()');
    }
};
