<?php

declare(strict_types=1);

use App\Domain\Shared\Database\Immutability;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The transaction categories of the finance module (FI-01, D-15, D-16): two levels, each with a type.
 *
 * The database is the guarantee, whatever code writes the row:
 * - a root has `type` income or expense; a child takes the type of its parent and the parent must be a root.
 *   Both rules are one composite foreign key in the style of `tasks_parent_fk`: `(parent_id, type, parent_depth)`
 *   references `(id, type, depth)`. `parent_depth` is a stored generated column, NULL for a root (the key is
 *   skipped) and 0 for a child (the parent must have depth 0), so a child of a child, a child of the other type
 *   and turning a parent with children into another type are all refused (23503);
 * - the foreign keys use NO ACTION (the default), so a parent that still has children cannot be deleted and the
 *   refusal answers 23503 like every other key of the schema. The `transactions` table of a later plan points at
 *   `UNIQUE (id, type)`, so a transaction takes only a category of its own type, and its restrict key refuses
 *   the deletion of a category that has been used;
 * - a root name is unique per type and a child name is unique under its parent, both case-insensitive, and a
 *   blank name is refused;
 * - the system income category (system_key `invoice_payments`, booked into by Phase 10) is frozen by the generic
 *   guard of Phase 2: a row with is_system true can change only name, position and updated_at, and can never be
 *   deleted (KP001). The CHECKs below keep the system key to system rows and the system row to a root.
 *
 * Archiving is the `archived_at` timestamp and never a soft delete, so the restrict keys keep working: an
 * archived category stays in the table and stays referenced. `position` is the manual order within the siblings
 * of one type and parent.
 *
 * `type` and `parent_depth` belong to the composite keys and are not identifiers; the schema convention test
 * exempts them with that reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaction_categories', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            // The parent link is the composite foreign key added below.
            $table->uuid('parent_id')->nullable();
            $table->string('type', 16);
            $table->smallInteger('depth');
            $table->smallInteger('parent_depth')->nullable()
                ->storedAs('CASE WHEN parent_id IS NULL THEN NULL ELSE 0::smallint END');

            $table->string('name', 120);
            $table->boolean('is_system')->default(false);
            $table->string('system_key', 64)->nullable();
            $table->timestampTz('archived_at', 0)->nullable();
            $table->integer('position');

            $table->timestampsTz();

            $table->unique('system_key', 'transaction_categories_system_key_unique');
            $table->index('parent_id', 'transaction_categories_parent_id_index');
        });

        DB::statement("ALTER TABLE transaction_categories ADD CONSTRAINT transaction_categories_type_check CHECK (type IN ('income', 'expense'))");
        DB::statement('ALTER TABLE transaction_categories ADD CONSTRAINT transaction_categories_depth_check CHECK (depth = CASE WHEN parent_id IS NULL THEN 0 ELSE 1 END)');
        DB::statement('ALTER TABLE transaction_categories ADD CONSTRAINT transaction_categories_name_check CHECK (length(btrim(name)) > 0)');
        DB::statement('ALTER TABLE transaction_categories ADD CONSTRAINT transaction_categories_system_check CHECK (system_key IS NULL OR is_system)');
        DB::statement('ALTER TABLE transaction_categories ADD CONSTRAINT transaction_categories_system_root_check CHECK (NOT is_system OR parent_id IS NULL)');

        // Composite key targets: the parent key (id, type, depth) and the key a transaction points at (id, type).
        DB::statement('ALTER TABLE transaction_categories ADD CONSTRAINT transaction_categories_ident_unique UNIQUE (id, type, depth)');
        DB::statement('ALTER TABLE transaction_categories ADD CONSTRAINT transaction_categories_id_type_unique UNIQUE (id, type)');
        DB::statement('ALTER TABLE transaction_categories ADD CONSTRAINT transaction_categories_parent_fk FOREIGN KEY (parent_id, type, parent_depth) REFERENCES transaction_categories (id, type, depth)');

        DB::statement('CREATE UNIQUE INDEX transaction_categories_root_name_unique ON transaction_categories (type, lower(name)) WHERE parent_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX transaction_categories_child_name_unique ON transaction_categories (parent_id, lower(name)) WHERE parent_id IS NOT NULL');

        DB::unprepared(Immutability::guardTriggerSql('transaction_categories', 'is_system', 'false', ['name', 'position']));
    }

    public function down(): void
    {
        DB::unprepared(Immutability::dropGuardTriggerSql('transaction_categories'));

        Schema::dropIfExists('transaction_categories');
    }
};
