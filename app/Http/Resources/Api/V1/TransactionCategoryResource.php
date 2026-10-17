<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Finance\Models\TransactionCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The JSON of one transaction category: the id, the parent, the name, the type and whether it can still be used.
 *
 * `archived` is true when the category itself or its parent is archived, because the Admin screen refuses a child
 * of an archived parent for a new transaction just like an archived category (`TransactionCategory::scopeSelectable`).
 * The parent must be loaded (`with('parent')`) so the list does not query once per row. The system flag, the
 * position and the timestamps are not part of the API; the keys are listed explicitly.
 *
 * @property-read TransactionCategory $resource
 */
final class TransactionCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $category = $this->resource;

        return [
            'id' => $category->id,
            'parent_id' => $category->parent_id,
            'name' => $category->name,
            'type' => $category->type->value,
            'archived' => $category->archived_at !== null || $category->parent?->archived_at !== null,
        ];
    }
}
