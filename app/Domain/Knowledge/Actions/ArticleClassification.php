<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Actions;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The validated classification of one article save: the folder, the client, the project and the knowledge tag names
 * (ORG-01 to ORG-04, D-04, D-06). Like {@see ArticleInput} it holds no Filament type, so a screen, an API or a console
 * command can build it.
 *
 * The three ids are null when nothing is chosen. A value that is not a string, or an empty one, is null; a string that
 * is not a UUID is a field error here, so PostgreSQL never has to raise on a malformed key. Whether a folder, a client
 * or a project exists, and whether the project belongs to the client, is decided by SaveArticle under the row lock.
 * A project needs a client (D-06).
 *
 * The tag names are trimmed, empty ones dropped, and duplicates (compared case-insensitively) removed with the first
 * spelling kept. At most MAX_TAGS names of at most MAX_TAG_LENGTH characters stay, so a huge list never reaches a query.
 * A name that contains a NUL byte is refused with a field error, never stripped.
 */
final readonly class ArticleClassification
{
    public const int MAX_TAGS = 20;

    public const int MAX_TAG_LENGTH = 40;

    /**
     * @param  list<string>  $tags  the validated, de-duplicated tag names
     */
    private function __construct(
        public ?string $folderId,
        public ?string $clientId,
        public ?string $projectId,
        public array $tags,
    ) {}

    /**
     * @throws ValidationException a field error on `folder_id`, `client_id`, `project_id` or `tags`
     * @throws InvalidArgumentException a tag entry that is not a string (a caller error, never typed by a user)
     */
    public static function from(mixed $folderId, mixed $clientId, mixed $projectId, mixed $tags): self
    {
        $folderId = self::id($folderId, 'folder_id', 'knowledge.links.errors.folder_missing');
        $clientId = self::id($clientId, 'client_id', 'knowledge.links.errors.client_missing');
        $projectId = self::id($projectId, 'project_id', 'knowledge.links.errors.project_mismatch');

        if ($projectId !== null && $clientId === null) {
            throw ValidationException::withMessages(['project_id' => __('knowledge.links.project_needs_client')]);
        }

        return new self($folderId, $clientId, $projectId, self::tagNames($tags));
    }

    /**
     * A null, an empty or a non-string value is "nothing chosen"; any other string must be a UUID.
     */
    private static function id(mixed $value, string $field, string $errorKey): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (! Str::isUuid($value)) {
            throw ValidationException::withMessages([$field => __($errorKey)]);
        }

        // Stored keys are lowercase, so the ids are compared and written in that spelling.
        return strtolower($value);
    }

    /**
     * @return list<string>
     */
    private static function tagNames(mixed $tags): array
    {
        if (! is_array($tags)) {
            return [];
        }

        $names = [];
        $seen = [];

        foreach ($tags as $tag) {
            if (! is_string($tag)) {
                throw new InvalidArgumentException('Every tag name must be a string.');
            }

            // Checked on the raw text: trim() strips a NUL at either end, which would hide it instead of refusing it.
            if (str_contains($tag, "\0")) {
                throw ValidationException::withMessages(['tags' => __('knowledge.errors.nul_character')]);
            }

            $name = trim($tag);

            if ($name === '') {
                continue;
            }

            if (mb_strlen($name) > self::MAX_TAG_LENGTH) {
                throw ValidationException::withMessages(['tags' => __('knowledge.tags.errors.too_long', ['max' => self::MAX_TAG_LENGTH])]);
            }

            $key = mb_strtolower($name);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $names[] = $name;
        }

        if (count($names) > self::MAX_TAGS) {
            throw ValidationException::withMessages(['tags' => __('knowledge.tags.errors.too_many', ['max' => self::MAX_TAGS])]);
        }

        return $names;
    }
}
