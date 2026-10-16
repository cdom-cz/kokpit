<?php

declare(strict_types=1);

namespace App\Domain\Documents\Actions;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Support\DocumentFileName;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Renames a file and edits its description (DO-03, D-02, D-06).
 *
 * The actor needs the `update` ability, which the Admin has through the policy's `before()` and a Partner
 * does not have. The name is cleaned exactly like an upload name (control characters, bidirectional overrides,
 * path separators and leading dots are removed) and is required: a name that is blank, or that nothing is
 * left of after cleaning, is refused under 'name' and the old one stays. The description is trimmed, capped
 * like an upload description, and blank means none. Only the display name changes; the stored object keeps
 * its name `<document uuid>.<extension>`, so nothing in storage moves.
 *
 * The name change is written to the activity log through the model; the description never is.
 */
final class RenameDocument
{
    private const int MAX_DESCRIPTION_LENGTH = 2000;

    /**
     * @throws ValidationException under 'name' for a name that is blank or empties out when cleaned
     */
    public function handle(User $actor, Document $document, string $name, ?string $description): Document
    {
        Gate::forUser($actor)->authorize('update', $document);

        $clean = $this->name($name);

        return DB::transaction(function () use ($document, $clean, $description): Document {
            $locked = Document::query()->whereKey($document->getKey())->lockForUpdate()->firstOrFail();

            $locked->fill(['name' => $clean, 'description' => $this->description($description)])->save();

            return $locked;
        });
    }

    /**
     * @throws ValidationException
     */
    private function name(string $name): string
    {
        $clean = DocumentFileName::displayName($name);

        // displayName() falls back to a stock name for nothing usable; a rename must not do that silently.
        if (trim($name) === '' || ($clean === DocumentFileName::FALLBACK && mb_strtolower(trim($name)) !== DocumentFileName::FALLBACK)) {
            throw ValidationException::withMessages(['name' => __('documents.errors.name_required')]);
        }

        return $clean;
    }

    /**
     * Trimmed and capped; blank means none (the same rule as an upload description).
     */
    private function description(?string $description): ?string
    {
        $description = trim((string) $description);

        return $description === '' ? null : trim(mb_substr($description, 0, self::MAX_DESCRIPTION_LENGTH));
    }
}
