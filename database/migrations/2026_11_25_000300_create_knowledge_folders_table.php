<?php

declare(strict_types=1);

use App\Domain\Knowledge\Models\KnowledgeFolder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The folder tree of the knowledge base (Phase 18; KB-01; ruling R-18-01-6 of plan 18-01; research Open Question 3 and
 * Pattern 6). The table and its integrity trigger land now because the roadmap puts "the folder and version tables with
 * their integrity triggers" into this phase; the folder screens are Phase 19.
 *
 * Folders hold general articles only (the articles table refuses a folder on any other kind). A folder has a parent
 * (`parent_id`, NULL for a root) and a name.
 *
 * Rules enforced here, for every writer (Actions, console, raw SQL):
 * - Sibling names are unique, case-insensitive, and also among root folders: the unique index over `(parent_id,
 *   lower(name))` is NULLS NOT DISTINCT, so two NULL parents count as the same parent. A name is not blank.
 * - The tree stays a tree. The row trigger `knowledge_folders_tree_guard` runs BEFORE INSERT and BEFORE an UPDATE of
 *   `parent_id`, takes a transaction-level advisory lock first (two concurrent moves, each checked alone against the
 *   committed tree, could together form a cycle, so the moves serialise), and then raises KP003 for
 *   (1) a folder that is its own parent, (2) a move into the folder's own subtree (a cycle), and (3) a tree deeper than
 *   KnowledgeFolder::MAX_DEPTH. KP003 is a SQLSTATE of its own next to KP001 (frozen rows) and KP002 (project key).
 * - The depth arithmetic: a root folder is level 1, so a root parent has depth 1 and no parent has depth 0. The parent
 *   depth is the number of ancestors of the new parent, itself included, counted by a recursive CTE upward. The subtree
 *   height is 1 for an insert or a leaf and grows by one per level of descendants, counted by a recursive CTE downward
 *   from the moved folder (bounded by the cap plus one, so a long chain costs nothing). The move is refused when the
 *   parent depth plus the subtree height exceeds the cap. Measuring only the new position of the moved folder would let a
 *   deep subtree move under a deep parent and break the cap (the gap the research spike found).
 * - The cap is the one constant KnowledgeFolder::MAX_DEPTH, interpolated into the trigger below; the Phase 19 MoveFolder
 *   Action reuses it for its message and its form rule.
 * - Every foreign key is RESTRICT: a folder with a child or an article cannot be deleted. The article-to-folder foreign key
 *   of knowledge_articles is added here, because the folders table is created after the articles table.
 *
 * Changing the cap later means replacing the function (CREATE OR REPLACE) with the new constant; no data changes unless a
 * lower cap is already exceeded by stored folders.
 *
 * Limits are the ones of every guard here: the table owner can disable triggers and `session_replication_role = replica`
 * skips them, so the production application role must not be a superuser.
 *
 * down() drops the article foreign key first, then the trigger, the table and the function.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_folders', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));

            // Self foreign key added below.
            $table->uuid('parent_id')->nullable();
            $table->text('name');

            $table->timestampsTz();

            $table->index('parent_id', 'knowledge_folders_parent_id_index');
        });

        DB::statement('ALTER TABLE knowledge_folders ADD CONSTRAINT knowledge_folders_parent_fk FOREIGN KEY (parent_id) REFERENCES knowledge_folders (id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE knowledge_folders ADD CONSTRAINT knowledge_folders_name_check CHECK (name ~ '[^[:space:]]')");
        DB::statement('CREATE UNIQUE INDEX knowledge_folders_sibling_name_unique ON knowledge_folders (parent_id, lower(name)) NULLS NOT DISTINCT');

        $function = <<<'SQL'
            CREATE OR REPLACE FUNCTION kokpit_guard_knowledge_folder_tree() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
              max_depth      constant integer := __MAX_DEPTH__;
              parent_depth   integer := 0;
              in_subtree     boolean := false;
              subtree_height integer := 1;
            BEGIN
              PERFORM pg_advisory_xact_lock(hashtext('knowledge_folders_tree'));

              IF NEW.parent_id IS NULL THEN
                -- A root folder keeps its whole subtree, which was valid below its old parent; a root is never too deep.
                RETURN NEW;
              END IF;

              IF NEW.parent_id = NEW.id THEN
                RAISE EXCEPTION 'a knowledge folder cannot be its own parent' USING ERRCODE = 'KP003';
              END IF;

              -- The ancestors of the new parent, itself included: their count is the parent depth, and the moved folder
              -- among them means the new parent lies in its own subtree.
              WITH RECURSIVE ancestors(id, parent_id) AS (
                SELECT f.id, f.parent_id FROM knowledge_folders f WHERE f.id = NEW.parent_id
                UNION ALL
                SELECT f.id, f.parent_id FROM knowledge_folders f JOIN ancestors a ON f.id = a.parent_id
              )
              SELECT count(*), coalesce(bool_or(id = NEW.id), false) INTO parent_depth, in_subtree FROM ancestors;

              IF in_subtree THEN
                RAISE EXCEPTION 'a knowledge folder cannot move into its own subtree' USING ERRCODE = 'KP003';
              END IF;

              -- The height of the moved subtree: 1 for an insert or a leaf; bounded by the cap plus one.
              WITH RECURSIVE subtree(id, level) AS (
                SELECT NEW.id, 1
                UNION ALL
                SELECT f.id, s.level + 1 FROM knowledge_folders f JOIN subtree s ON f.parent_id = s.id WHERE s.level <= max_depth
              )
              SELECT max(level) INTO subtree_height FROM subtree;

              IF parent_depth + subtree_height > max_depth THEN
                RAISE EXCEPTION 'knowledge folders nest at most % levels', max_depth USING ERRCODE = 'KP003';
              END IF;

              RETURN NEW;
            END $$
            SQL;

        DB::unprepared(str_replace('__MAX_DEPTH__', (string) KnowledgeFolder::MAX_DEPTH, $function));

        DB::unprepared('CREATE TRIGGER knowledge_folders_tree_guard BEFORE INSERT OR UPDATE OF parent_id ON knowledge_folders FOR EACH ROW EXECUTE FUNCTION kokpit_guard_knowledge_folder_tree()');

        DB::statement('ALTER TABLE knowledge_articles ADD CONSTRAINT knowledge_articles_folder_fk FOREIGN KEY (folder_id) REFERENCES knowledge_folders (id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE knowledge_articles DROP CONSTRAINT IF EXISTS knowledge_articles_folder_fk');
        DB::unprepared('DROP TRIGGER IF EXISTS knowledge_folders_tree_guard ON knowledge_folders');

        Schema::dropIfExists('knowledge_folders');

        DB::unprepared('DROP FUNCTION IF EXISTS kokpit_guard_knowledge_folder_tree()');
    }
};
