<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The system category and the generic starter set of the finance categories (D-16, research A13).
 *
 * This is a data migration, so a production database gets the system income category without a seeder. The
 * category with system_key `invoice_payments` is the one Phase 10 books invoice payments into; it can be
 * renamed and reordered but never archived, moved or deleted (the guard trigger of the table).
 *
 * Every insert is idempotent: the system row conflicts on its system key, every other row on the unique name of
 * its level, and a conflict changes nothing. A second run therefore adds nothing and never overwrites a name or
 * an order the Admin has changed. The names are starter data, not translations; they hold no business data.
 *
 * down() deletes nothing: the rows are data the Admin may already use.
 */
return new class extends Migration
{
    /**
     * Roots by type in sibling order; the value is the list of child names in sibling order.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const array STARTER = [
        'income' => [
            'Ostatní příjmy' => [],
        ],
        'expense' => [
            'Software' => ['Předplatné', 'Hosting'],
            'Poplatky' => ['Bankovní poplatky'],
            'Daně a odvody' => ['Daň z příjmů', 'Pojištění'],
            'Kancelář' => [],
            'Ostatní výdaje' => [],
        ],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            // The system root is the first income root; the starter income roots follow it.
            DB::statement(
                "INSERT INTO transaction_categories (type, depth, name, is_system, system_key, position, created_at, updated_at) VALUES ('income', 0, ?, true, 'invoice_payments', 1, now(), now()) ON CONFLICT (system_key) DO NOTHING",
                ['Úhrady faktur'],
            );

            foreach (self::STARTER as $type => $roots) {
                // Income roots start after the system root.
                $position = $type === 'income' ? 1 : 0;

                foreach ($roots as $name => $children) {
                    $position++;

                    DB::statement(
                        'INSERT INTO transaction_categories (type, depth, name, position, created_at, updated_at) VALUES (?, 0, ?, ?, now(), now()) ON CONFLICT DO NOTHING',
                        [$type, $name, $position],
                    );

                    $this->seedChildren($type, $name, $children);
                }
            }
        });
    }

    public function down(): void
    {
        // Data migration: nothing to remove.
    }

    /**
     * @param  list<string>  $children
     */
    private function seedChildren(string $type, string $rootName, array $children): void
    {
        if ($children === []) {
            return;
        }

        /** @var object{id: string}|null $parent */
        $parent = DB::selectOne(
            'SELECT id FROM transaction_categories WHERE parent_id IS NULL AND type = ? AND lower(name) = lower(?)',
            [$type, $rootName],
        );

        if ($parent === null) {
            return;
        }

        foreach ($children as $index => $name) {
            DB::statement(
                'INSERT INTO transaction_categories (parent_id, type, depth, name, position, created_at, updated_at) VALUES (?, ?, 1, ?, ?, now(), now()) ON CONFLICT DO NOTHING',
                [$parent->id, $type, $name, $index + 1],
            );
        }
    }
};
