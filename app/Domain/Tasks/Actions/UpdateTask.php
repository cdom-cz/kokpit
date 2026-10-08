<?php

declare(strict_types=1);

namespace App\Domain\Tasks\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\ProjectInput;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Tags\TagType;
use App\Domain\Tasks\Board\TaskBoard;
use App\Domain\Tasks\Enums\TaskBillingType;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskBilling;
use App\Domain\Tasks\TaskInput;
use App\Domain\Tasks\TaskPeople;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Updates a task as the Admin (TA-01).
 *
 * A key that is absent from the data leaves the stored value unchanged. Status
 * switches freely between all six values with no workflow (D-01). Every write
 * runs in one transaction under the board lock with the task row locked, so a
 * status change cannot interleave with a creation or a move: a changed status
 * goes through TaskBoard::appendToColumn, which appends the task to the end of
 * the new column and sets or clears `completed_at` (D-02, Pitfall 1b). Every
 * other attribute goes out in the same save, so the activity log gets one row.
 *
 * People (D-04, D-05): a changed assignee or requester must be in the allowed set
 * of TaskPeople (the active Admin and the active Partners of the project's
 * client), recomputed on every write, otherwise the error is keyed `assignee_id`
 * or `requester_id` and nothing is written. An unchanged person is not checked
 * again, so a task keeps a person who was deactivated since.
 *
 * The description is cleaned with RichText::clean (through TaskInput::description)
 * before it is stored, whoever wrote it (D-10); text over the length limit is a
 * field error on `description`. Dates must be calendar days and the due date may not precede the start date,
 * checked against the stored value of the date the data does not name. Tags are
 * a list of names synced as task tags.
 *
 * Billing (TA-06, D-12 to D-14): `billing_type`, `hourly_rate`, `fixed_price`
 * (amounts as text in the client's currency, never rounded), `estimate_hours`
 * and `internal_note` are stored only in the Admin-only task_billing row. A key
 * that is absent leaves the stored value as it is; a task has a row only while
 * it overrides something, so saving everything back to inherit and empty
 * removes the row, and a task without a row inherits everything. Billing type
 * fixed price needs a price.
 *
 * Errors are ValidationExceptions keyed by the data key; the Admin form maps
 * them to its state paths.
 *
 * @phpstan-type TaskUpdateData array{
 *     title?: string,
 *     description?: string|null,
 *     status?: string|null,
 *     priority?: string|null,
 *     start_date?: string|null,
 *     due_date?: string|null,
 *     assignee_id?: string|null,
 *     requester_id?: string|null,
 *     tags?: list<string>|null,
 *     billing_type?: mixed,
 *     hourly_rate?: string|null,
 *     fixed_price?: string|null,
 *     estimate_hours?: string|null,
 *     internal_note?: string|null,
 * }
 */
final class UpdateTask
{
    /** The data keys that are stored in the Admin-only task_billing row. */
    private const array BILLING_KEYS = ['billing_type', 'hourly_rate', 'fixed_price', 'estimate_hours', 'internal_note'];

    /** The two people columns, validated against the allowed set when they change. */
    private const array PEOPLE_COLUMNS = ['assignee_id', 'requester_id'];

    public function __construct(
        private readonly TaskBoard $board,
        private readonly TaskPeople $people,
    ) {}

    /**
     * @param  TaskUpdateData  $data
     */
    public function handle(User $actor, Task $task, array $data): Task
    {
        Gate::forUser($actor)->authorize('update', $task);

        $title = array_key_exists('title', $data) ? trim((string) $data['title']) : null;

        if ($title === '') {
            throw ValidationException::withMessages(['title' => __('kokpit.tasks.errors.title_required')]);
        }

        $status = TaskInput::status($data['status'] ?? null);
        $priority = TaskInput::priority($data['priority'] ?? null);
        $tags = TaskInput::tags($data['tags'] ?? null);

        $plain = [];

        if (array_key_exists('description', $data)) {
            $plain['description'] = TaskInput::description($data['description']);
        }

        $dates = [];

        foreach (['start_date', 'due_date'] as $column) {
            if (array_key_exists($column, $data)) {
                $dates[$column] = TaskInput::day($data[$column], $column);
            }
        }

        $billing = $this->parseBilling($task, $data);

        return DB::transaction(function () use ($task, $data, $title, $status, $priority, $tags, $plain, $dates, $billing): Task {
            $this->board->lockBoard();

            $locked = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

            $attributes = [...$plain, ...$dates];

            TaskInput::assertDatesInOrder(
                array_key_exists('start_date', $dates) ? $dates['start_date'] : $locked->start_date?->toDateString(),
                array_key_exists('due_date', $dates) ? $dates['due_date'] : $locked->due_date?->toDateString(),
            );

            $people = $this->changedPeople($locked, $data);

            if ($title !== null) {
                $attributes['title'] = $title;
            }

            // Priority is NOT NULL, so a null value leaves it unchanged.
            if ($priority !== null) {
                $attributes['priority'] = $priority;
            }

            // The people columns are not mass assignable; they come from the checked set only.
            $locked->fill($attributes)->forceFill($people);

            if ($status !== null && $status !== $locked->status) {
                $this->board->appendToColumn($locked, $status);
            } else {
                $locked->save();
            }

            if ($tags !== null) {
                $locked->syncTagsWithType($tags, TagType::Task->value);
            }

            if ($billing !== []) {
                $this->saveBilling($locked, $billing);
            }

            return $locked->refresh();
        });
    }

    /**
     * Converts the billing keys the data names before anything is written: the
     * amounts with the client's currency (nothing is rounded), the estimate to
     * whole seconds, the type to a known value. The result holds only the keys
     * the data names, so an absent key keeps the stored value.
     *
     * @param  TaskUpdateData  $data
     * @return array<string, mixed>
     */
    private function parseBilling(Task $task, array $data): array
    {
        if (array_intersect(self::BILLING_KEYS, array_keys($data)) === []) {
            return [];
        }

        $client = Project::query()->withTrashed()->findOrFail($task->project_id)->client()->withTrashed()->firstOrFail();
        $parsed = [];

        // Like priority, the type is never empty: null leaves the stored type, anything that is not a known value is a field error.
        if (($data['billing_type'] ?? null) !== null) {
            $type = is_string($data['billing_type']) ? TaskBillingType::tryFrom($data['billing_type']) : null;

            if ($type === null) {
                throw ValidationException::withMessages(['billing_type' => __('kokpit.tasks.errors.billing_type_invalid')]);
            }

            $parsed['billing_type'] = $type;
        }

        foreach (['hourly_rate', 'fixed_price'] as $field) {
            if (array_key_exists($field, $data)) {
                $parsed[$field] = ProjectInput::money($data[$field], $client, $field);
            }
        }

        if (array_key_exists('estimate_hours', $data)) {
            $parsed['estimate_seconds'] = ProjectInput::estimateSeconds($data['estimate_hours']);
        }

        if (array_key_exists('internal_note', $data)) {
            $note = $data['internal_note'];
            $parsed['internal_note'] = is_string($note) && trim($note) !== '' ? $note : null;
        }

        // Fail before the transaction: the resulting type must carry a price when it is a fixed price.
        $this->resolveBilling($task->billing, $parsed);

        return $parsed;
    }

    /**
     * The billing attributes after the parsed keys replace the stored ones. A
     * task without a row starts from inherit and empty (D-14).
     *
     * @param  array<string, mixed>  $parsed
     * @return array{billing_type: TaskBillingType, hourly_rate: ?Money, fixed_price: ?Money, estimate_seconds: ?int, internal_note: ?string}
     */
    private function resolveBilling(?TaskBilling $stored, array $parsed): array
    {
        $resolved = [
            'billing_type' => $parsed['billing_type'] ?? $stored->billing_type ?? TaskBillingType::Inherit,
            'hourly_rate' => array_key_exists('hourly_rate', $parsed) ? $parsed['hourly_rate'] : $stored?->hourly_rate,
            'fixed_price' => array_key_exists('fixed_price', $parsed) ? $parsed['fixed_price'] : $stored?->fixed_price,
            'estimate_seconds' => array_key_exists('estimate_seconds', $parsed) ? $parsed['estimate_seconds'] : $stored?->estimate_seconds,
            'internal_note' => array_key_exists('internal_note', $parsed) ? $parsed['internal_note'] : $stored?->internal_note,
        ];

        ProjectInput::requireFixedPrice($resolved['billing_type']->value, $resolved['fixed_price']);

        return $resolved;
    }

    /**
     * Creates the billing row on the first override, updates it afterwards and
     * deletes it when everything is back to inherit and empty (D-14).
     *
     * @param  array<string, mixed>  $parsed
     */
    private function saveBilling(Task $task, array $parsed): void
    {
        $stored = $task->billing()->first();
        $resolved = $this->resolveBilling($stored, $parsed);

        $inherits = $resolved['billing_type'] === TaskBillingType::Inherit
            && $resolved['hourly_rate'] === null
            && $resolved['fixed_price'] === null
            && $resolved['estimate_seconds'] === null
            && $resolved['internal_note'] === null;

        if ($inherits) {
            $stored?->delete();

            return;
        }

        if ($stored === null) {
            $task->billing()->create($resolved);

            return;
        }

        $stored->update($resolved);
    }

    /**
     * The assignee and requester columns the data changes. Each changed person
     * must be allowed on the task's project. An archived project still counts:
     * the Admin edits its tasks.
     *
     * @param  TaskUpdateData  $data
     * @return array<string, string>
     */
    private function changedPeople(Task $task, array $data): array
    {
        $project = null;
        $changed = [];

        foreach (self::PEOPLE_COLUMNS as $column) {
            $userId = $data[$column] ?? null;

            if ($userId === null || $userId === $task->getAttribute($column)) {
                continue;
            }

            $project ??= Project::query()->withTrashed()->findOrFail($task->project_id);

            $this->people->assertAllowed($project, (string) $userId, $column);

            $changed[$column] = (string) $userId;
        }

        return $changed;
    }
}
