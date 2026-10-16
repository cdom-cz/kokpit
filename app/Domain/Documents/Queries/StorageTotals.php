<?php

declare(strict_types=1);

namespace App\Domain\Documents\Queries;

use App\Domain\Documents\Models\Document;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Database\CzechCollation;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * How much the stored files take (DO-02, D-11): the number and the size of every file.
 *
 * The figures describe the storage, not the listing (D-21): every `documents` row counts, the files of archived
 * clients, projects and tasks included, because archiving deletes no stored object. The listing filters never
 * reach this reader. The size is the sum of the original files only (`size_bytes`); thumbnails and conversions
 * are not part of it.
 *
 * The reads go through the Document model, whose Partner scope applies as well, but the reader also refuses
 * everybody but the Admin and a system run on its own: the existence and volume of other clients' files is exactly
 * what a Partner must not learn (T-09-53).
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

        $row = Document::query()
            ->selectRaw('COUNT(*) AS files, COALESCE(SUM(documents.size_bytes), 0) AS bytes')
            ->toBase()
            ->first();

        return [
            'count' => (int) ($row->files ?? 0),
            'bytes' => (int) ($row->bytes ?? 0),
        ];
    }

    /**
     * The files and bytes per client, in Czech order of the client name, the row of the files without a client last.
     *
     * A file counts for the client in its denormalized `client_id` (D-11), so the files of a client's projects,
     * tasks and comments and the receipts of its transactions roll up to the client, while an unattached file and
     * the receipt of a transaction without a client form the one row whose `client_id` and `name` come back null
     * and empty (the widget words it). A client without files has no row. Archived clients keep their row and are
     * flagged, because their files still occupy the bucket (D-21).
     *
     * @return list<array{client_id: ?string, name: string, archived: bool, count: int, bytes: int}>
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     */
    public function byClient(): array
    {
        $this->authorise();

        // The clients table is joined by name, not through the Client model, so an archived client keeps its name.
        $query = Document::query()
            ->leftJoin('clients AS c', 'c.id', '=', 'documents.client_id')
            ->selectRaw('documents.client_id AS client_id, c.name AS name, (c.deleted_at IS NOT NULL) AS archived, COUNT(*) AS files, COALESCE(SUM(documents.size_bytes), 0) AS bytes')
            ->groupBy('documents.client_id', 'c.name', 'c.deleted_at')
            ->orderByRaw('(documents.client_id IS NULL)')
            ->toBase();

        CzechCollation::orderBy($query, 'c.name');
        $query->orderBy('documents.client_id');

        $rows = [];

        foreach ($query->get() as $row) {
            $rows[] = [
                'client_id' => is_string($row->client_id) ? $row->client_id : null,
                'name' => is_string($row->name) ? $row->name : '',
                'archived' => (bool) $row->archived,
                'count' => (int) $row->files,
                'bytes' => (int) $row->bytes,
            ];
        }

        return $rows;
    }

    private function authorise(): void
    {
        $context = app(PartnerContext::class);

        if (! $context->isAdmin() && ! $context->isSystem()) {
            throw new AuthorizationException('The storage totals are available to the Admin only.');
        }
    }
}
