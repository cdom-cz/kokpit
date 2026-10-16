<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Finance\Support\SqlState;
use App\Domain\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates or edits a transaction category (FI-01, D-15, D-16): the one place that knows the rules of the tree.
 *
 * The tree has two levels. A root has a type; a child takes the type of its parent whatever the data carries.
 * A parent must be an active root. A category with children cannot become a child, and the type of a category
 * that has children or is used cannot change. The system category accepts only a new name.
 *
 * Every refusal is a field error keyed by the data key (`name`, `type`, `parent_id`) with a Czech message from
 * `finance.categories.errors`; the screen maps the key to its modal. The pre-checks run on the row locked for
 * the update, and the database stays the backstop: the composite keys refuse a third level and a type change
 * of a used category (23503), the partial unique indexes refuse a duplicate name (translated outside the
 * transaction by index name, like `CreateProject`) and the guard trigger refuses any change of the system row
 * but its name and order (KP001).
 *
 * @phpstan-type CategoryData array{name?: mixed, type?: mixed, parent_id?: mixed}
 */
final class SaveTransactionCategory
{
    private const string ROOT_NAME_INDEX = 'transaction_categories_root_name_unique';

    private const string CHILD_NAME_INDEX = 'transaction_categories_child_name_unique';

    private const int NAME_MAX_LENGTH = 120;

    /**
     * @param  CategoryData  $data
     */
    public function handle(User $actor, ?TransactionCategory $category, array $data): TransactionCategory
    {
        if ($category === null) {
            Gate::forUser($actor)->authorize('create', TransactionCategory::class);
        } else {
            Gate::forUser($actor)->authorize('update', $category);
        }

        $name = $this->name($data['name'] ?? null);

        // The transaction is caught from the outside: a violation inside it has already rolled the
        // statement back (savepoint when nested) before it is translated.
        try {
            return DB::transaction(fn (): TransactionCategory => $category === null
                ? $this->create($name, $data)
                : $this->update($category, $name, $data));
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), self::ROOT_NAME_INDEX) || str_contains($e->getMessage(), self::CHILD_NAME_INDEX)) {
                throw ValidationException::withMessages(['name' => __('finance.categories.errors.duplicate_name')]);
            }

            throw $e;
        } catch (QueryException $e) {
            $state = SqlState::of($e);

            if ($state === SqlState::FROZEN_ROW) {
                throw ValidationException::withMessages(['name' => __('finance.categories.errors.system_locked')]);
            }

            if ($state === SqlState::FOREIGN_KEY) {
                // A type change refused by the composite keys: the category has children or has been used.
                throw ValidationException::withMessages(['type' => __('finance.categories.errors.type_locked')]);
            }

            throw $e;
        }
    }

    /**
     * @param  CategoryData  $data
     */
    private function create(string $name, array $data): TransactionCategory
    {
        $parentId = $this->parentId($data);

        if ($parentId !== null) {
            $parent = $this->activeRoot($parentId, null);
            $type = $parent->type;
        } else {
            $type = $this->requiredType($data);
        }

        $category = new TransactionCategory;
        $category->fill(['name' => $name]);
        $category->forceFill([
            'type' => $type,
            'parent_id' => $parentId,
            'depth' => $parentId === null ? 0 : 1,
        ]);
        $category->save();

        return $category->refresh();
    }

    /**
     * @param  CategoryData  $data
     */
    private function update(TransactionCategory $category, string $name, array $data): TransactionCategory
    {
        $locked = TransactionCategory::query()->whereKey($category->getKey())->lockForUpdate()->firstOrFail();

        $wantsParent = array_key_exists('parent_id', $data);
        $newParentId = $wantsParent ? $this->parentId($data) : $locked->parent_id;

        if ($locked->is_system) {
            $this->refuseSystemChange($locked, $data, $wantsParent, $newParentId);
            $locked->fill(['name' => $name]);
            $locked->save();

            return $locked->refresh();
        }

        $locked->fill(['name' => $name]);

        if ($newParentId !== $locked->parent_id) {
            $this->reparent($locked, $newParentId, $data);
        } elseif ($locked->parent_id === null && array_key_exists('type', $data)) {
            $this->retype($locked, $data);
        }

        // A category that left its sibling group goes to the end of the new one.
        if ($locked->isDirty(['type', 'parent_id'])) {
            $locked->setHighestOrderNumber();
        }

        $locked->save();

        return $locked->refresh();
    }

    /**
     * The system category is renamed only; asking for another type or parent is a refusal.
     *
     * @param  CategoryData  $data
     */
    private function refuseSystemChange(TransactionCategory $locked, array $data, bool $wantsParent, ?string $newParentId): void
    {
        if ($wantsParent && $newParentId !== $locked->parent_id) {
            throw ValidationException::withMessages(['parent_id' => __('finance.categories.errors.system_locked')]);
        }

        if (array_key_exists('type', $data) && $this->type($data['type']) !== $locked->type) {
            throw ValidationException::withMessages(['type' => __('finance.categories.errors.system_locked')]);
        }
    }

    /**
     * Moves the category under another root, or to the top level.
     *
     * @param  CategoryData  $data
     */
    private function reparent(TransactionCategory $locked, ?string $newParentId, array $data): void
    {
        if ($newParentId === null) {
            // Child to root: the type is the one given, or the one it has.
            $type = array_key_exists('type', $data) ? $this->requiredType($data) : $locked->type;
            $locked->forceFill(['parent_id' => null, 'depth' => 0, 'type' => $type]);

            return;
        }

        if ($locked->children()->exists()) {
            throw ValidationException::withMessages(['parent_id' => __('finance.categories.errors.has_children')]);
        }

        $parent = $this->activeRoot($newParentId, $locked->id);
        $locked->forceFill(['parent_id' => $parent->id, 'depth' => 1, 'type' => $parent->type]);
    }

    /**
     * Changes the type of a root. A root with children keeps its type; a used one is refused by the database.
     *
     * @param  CategoryData  $data
     */
    private function retype(TransactionCategory $locked, array $data): void
    {
        $type = $this->requiredType($data);

        if ($type === $locked->type) {
            return;
        }

        if ($locked->children()->exists()) {
            throw ValidationException::withMessages(['type' => __('finance.categories.errors.type_locked')]);
        }

        $locked->forceFill(['type' => $type]);
    }

    /**
     * An active root to hang a child under; the row is share-locked so it stays active until the commit.
     */
    private function activeRoot(string $id, ?string $selfId): TransactionCategory
    {
        $parent = Str::isUuid($id) && $id !== $selfId
            ? TransactionCategory::query()->whereKey($id)->sharedLock()->first()
            : null;

        if ($parent === null || $parent->depth !== 0 || $parent->isArchived()) {
            throw ValidationException::withMessages(['parent_id' => __('finance.categories.errors.parent_invalid')]);
        }

        return $parent;
    }

    /**
     * @param  CategoryData  $data
     */
    private function parentId(array $data): ?string
    {
        $value = $data['parent_id'] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw ValidationException::withMessages(['parent_id' => __('finance.categories.errors.parent_invalid')]);
        }

        return $value;
    }

    /**
     * @param  CategoryData  $data
     */
    private function requiredType(array $data): TransactionType
    {
        $type = $this->type($data['type'] ?? null);

        if ($type === null) {
            throw ValidationException::withMessages([
                'type' => __('validation.required', ['attribute' => __('finance.categories.fields.type')]),
            ]);
        }

        return $type;
    }

    private function type(mixed $value): ?TransactionType
    {
        if ($value instanceof TransactionType) {
            return $value;
        }

        return is_string($value) ? TransactionType::tryFrom($value) : null;
    }

    private function name(mixed $value): string
    {
        $name = is_string($value) ? trim($value) : '';

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => __('validation.required', ['attribute' => __('finance.categories.fields.name')]),
            ]);
        }

        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw ValidationException::withMessages([
                'name' => __('validation.max.string', ['attribute' => __('finance.categories.fields.name'), 'max' => self::NAME_MAX_LENGTH]),
            ]);
        }

        return $name;
    }
}
