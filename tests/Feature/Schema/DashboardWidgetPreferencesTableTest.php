<?php

declare(strict_types=1);

use App\Domain\Dashboard\Enums\DashboardWidget;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Canary;

/*
 * The preference table refuses what the Action and the owner scope cannot: an unknown card, a second row for one
 * user and card, and a row without a user. Each case runs in its own savepoint, because a failed statement aborts a
 * PostgreSQL transaction.
 */

/**
 * Runs the statement and returns the SQLSTATE it failed with, or null when it succeeded.
 */
function dashPrefSchemaState(Closure $statement): ?string
{
    try {
        DB::transaction($statement);
    } catch (QueryException $exception) {
        return $exception->errorInfo[0] ?? null;
    }

    return null;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function dashPrefSchemaInsert(string $userId, string $widgetKey, array $overrides = []): bool
{
    return DB::table('dashboard_widget_preferences')->insert(array_merge([
        'user_id' => $userId,
        'widget_key' => $widgetKey,
        'collapsed' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

it('refuses a widget key outside the seven cards with a check violation', function (): void {
    $admin = Canary::admin();

    expect(dashPrefSchemaState(fn () => dashPrefSchemaInsert($admin->id, 'unknown_card')))->toBe('23514');
});

it('accepts the receivables card key and gives it back to the old list on rollback', function (): void {
    $admin = Canary::admin();

    expect(dashPrefSchemaState(fn () => dashPrefSchemaInsert($admin->id, 'unpaid_invoices')))->toBeNull();

    $migration = require base_path('database/migrations/2026_10_17_000600_add_unpaid_invoices_dashboard_widget.php');

    $migration->down();

    // The rollback removed the row of the new card and the old list refuses the key again.
    expect(DB::table('dashboard_widget_preferences')->where('widget_key', 'unpaid_invoices')->count())->toBe(0)
        ->and(dashPrefSchemaState(fn () => dashPrefSchemaInsert($admin->id, 'unpaid_invoices')))->toBe('23514')
        ->and(dashPrefSchemaState(fn () => dashPrefSchemaInsert($admin->id, 'today_plan')))->toBeNull();

    $migration->up();

    expect(dashPrefSchemaState(fn () => dashPrefSchemaInsert($admin->id, 'unpaid_invoices')))->toBeNull();
});

it('refuses a second row for the same user and card, and accepts the same card for another user', function (): void {
    $admin = Canary::admin();
    $other = Canary::admin();

    dashPrefSchemaInsert($admin->id, 'hours_stats');

    expect(dashPrefSchemaState(fn () => dashPrefSchemaInsert($admin->id, 'hours_stats', ['collapsed' => false])))->toBe('23505')
        ->and(dashPrefSchemaState(fn () => dashPrefSchemaInsert($other->id, 'hours_stats')))->toBeNull();
});

it('refuses a row whose user does not exist with a foreign key violation', function (): void {
    expect(dashPrefSchemaState(fn () => dashPrefSchemaInsert((string) Str::uuid7(), 'hours_stats')))->toBe('23503');
});

it('refuses a row without the collapsed flag', function (): void {
    $admin = Canary::admin();

    expect(dashPrefSchemaState(fn () => dashPrefSchemaInsert($admin->id, 'hours_stats', ['collapsed' => null])))->toBe('23502');
});

it('accepts every card of the enum for one user and gives a version 7 id', function (): void {
    $admin = Canary::admin();

    foreach (DashboardWidget::cases() as $widget) {
        expect(dashPrefSchemaState(fn () => dashPrefSchemaInsert($admin->id, $widget->value)))->toBeNull();
    }

    $ids = DB::table('dashboard_widget_preferences')->where('user_id', $admin->id)->pluck('id')->all();

    expect($ids)->toHaveCount(count(DashboardWidget::cases()));

    foreach ($ids as $id) {
        expect(substr((string) $id, 14, 1))->toBe('7');
    }
});

it('removes the preferences of a user together with the user', function (): void {
    $admin = Canary::admin();

    dashPrefSchemaInsert($admin->id, 'hours_stats');
    DB::table('users')->where('id', $admin->id)->delete();

    expect(DB::table('dashboard_widget_preferences')->where('user_id', $admin->id)->count())->toBe(0);
});
