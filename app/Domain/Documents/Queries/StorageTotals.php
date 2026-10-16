<?php

declare(strict_types=1);

namespace App\Domain\Documents\Queries;

use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * How much the stored files take (DO-02, D-11): the number and the size of every file.
 *
 * The figures describe the storage, not the listing (D-21): every `documents` row counts, the files of archived
 * clients, projects and tasks included, because archiving deletes no stored object. The listing filters never
 * reach this reader. The size is the sum of the original files only (`size_bytes`); thumbnails and conversions
 * are not part of it.
 *
 * The reads go over the raw `documents` table, which bypasses the Partner scope of the model, so the reader
 * refuses everybody but the Admin and a system run on its own: the existence and volume of other clients' files
 * is exactly what a Partner must not learn (T-09-53).
 */
final class StorageTotals
{
    /**
     * The number of all stored files and their summed size in bytes.
     *
     * @return array{count: int, bytes: int}
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     */
    public function overall(): array
    {
        $this->authorise();

        $row = DB::table('documents')
            ->selectRaw('COUNT(*) AS files, COALESCE(SUM(size_bytes), 0) AS bytes')
            ->first();

        return [
            'count' => (int) ($row->files ?? 0),
            'bytes' => (int) ($row->bytes ?? 0),
        ];
    }

    private function authorise(): void
    {
        $context = app(PartnerContext::class);

        if (! $context->isAdmin() && ! $context->isSystem()) {
            throw new AuthorizationException('The storage totals are available to the Admin only.');
        }
    }
}
