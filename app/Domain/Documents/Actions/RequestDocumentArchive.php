<?php

declare(strict_types=1);

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Jobs\BuildDocumentArchive;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentArchive;
use App\Domain\Identity\Models\User;
use App\Filament\Support\DocumentColumns;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Records a request for a ZIP of selected files and queues its build (DO-02, D-08).
 *
 * The actor needs the `create` ability on document archives, which only the Admin has (AdminOnlyPolicy); a
 * Partner is refused before anything else is read. The selection is client-controlled, so it is checked here
 * before any work is queued: it must not be empty, every value must be a distinct UUID of an existing document,
 * and the number of files and their summed size must stay within `kokpit.documents.archive.max_files` and
 * `kokpit.documents.archive.max_bytes` (research A6, T-09-54). A refused request creates nothing and pushes
 * nothing; the message is Czech and carries no file name.
 *
 * The record stores the validated ids as a JSON array in the order given, their count and total size, and a
 * server-built summary. It is created in one transaction and the build job is pushed after that transaction
 * has committed, so the worker can never look for a record that is not there yet.
 */
final class RequestDocumentArchive
{
    private const int DEFAULT_MAX_FILES = 200;

    private const int DEFAULT_MAX_BYTES = 209715200;

    /**
     * @param  array<array-key, mixed>  $documentIds  the ids of the selected files, in the order they go into the ZIP
     *
     * @throws ValidationException under the key 'documents' for an empty selection, an id that is not an existing
     *                             document, a duplicated id, too many files or too many bytes
     */
    public function handle(User $actor, array $documentIds): DocumentArchive
    {
        Gate::forUser($actor)->authorize('create', DocumentArchive::class);

        $ids = $this->distinctUuids($documentIds);

        $maxFiles = max(1, (int) config('kokpit.documents.archive.max_files', self::DEFAULT_MAX_FILES));
        $maxBytes = max(1, (int) config('kokpit.documents.archive.max_bytes', self::DEFAULT_MAX_BYTES));

        // Checked before the database is asked, so a huge selection never becomes a huge query.
        if (count($ids) > $maxFiles) {
            $this->refuse(__('documents.archives.errors.too_many', ['max' => $maxFiles]));
        }

        $totals = Document::query()
            ->selectRaw('COUNT(*) AS files, COALESCE(SUM(documents.size_bytes), 0) AS bytes')
            ->whereIn('documents.id', $ids)
            ->toBase()
            ->first();

        if ((int) ($totals->files ?? 0) !== count($ids)) {
            $this->refuse(__('documents.archives.errors.unknown'));
        }

        $bytes = (int) ($totals->bytes ?? 0);

        if ($bytes > $maxBytes) {
            $this->refuse(__('documents.archives.errors.too_large', ['max' => DocumentColumns::humanSize($maxBytes)]));
        }

        $archive = DB::transaction(function () use ($actor, $ids, $bytes): DocumentArchive {
            $archive = new DocumentArchive([
                'summary' => trans_choice('documents.archives.summary', count($ids), ['count' => count($ids), 'size' => DocumentColumns::humanSize($bytes)]),
                'document_ids' => $ids,
                'document_count' => count($ids),
                'total_bytes' => $bytes,
            ]);
            // The owner is the actor, not whoever the session happens to say.
            $archive->forceFill(['user_id' => $actor->getKey()])->save();

            return $archive;
        });

        BuildDocumentArchive::dispatch($archive->getKey());

        return $archive;
    }

    /**
     * The selection as a list of distinct, lower-case UUIDs.
     *
     * @param  array<array-key, mixed>  $documentIds
     * @return non-empty-list<string>
     *
     * @throws ValidationException
     */
    private function distinctUuids(array $documentIds): array
    {
        if ($documentIds === []) {
            $this->refuse(__('documents.archives.errors.empty'));
        }

        $ids = [];

        foreach ($documentIds as $id) {
            if (! is_string($id) || ! Str::isUuid($id)) {
                $this->refuse(__('documents.archives.errors.unknown'));
            }

            $ids[] = strtolower($id);
        }

        if (count(array_unique($ids)) !== count($ids)) {
            $this->refuse(__('documents.archives.errors.unknown'));
        }

        return $ids;
    }

    /**
     * @throws ValidationException
     */
    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['documents' => [$message]]);
    }
}
