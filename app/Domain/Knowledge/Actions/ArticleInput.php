<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Actions;

use App\Domain\Knowledge\Models\KnowledgeArticle;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The validated input of one article save: the title, the Markdown body and the two requests about the
 * sensitive flag. It holds no Filament type, so a screen, an API or a console command can build it (R-18-05-1).
 *
 * `sensitive` true is the request to mark the article sensitive by hand. `clearSensitive` true is the explicit
 * manual clear of D-04 and the only input that can set the flag false. Both false means "keep the stored flag",
 * so a stale or defaulted toggle never clears a flag silently. Asking for both is a caller error.
 *
 * The title is trimmed and must be 1 to MAX_TITLE_LENGTH characters. The body is the raw text as typed
 * (normalised later by SaveArticle) and may be empty, but a text over MAX_BODY_LENGTH characters is refused
 * here, before any parser sees it.
 */
final readonly class ArticleInput
{
    private function __construct(
        public string $title,
        public string $body,
        public bool $sensitive,
        public bool $clearSensitive,
    ) {}

    /**
     * @throws ValidationException a field error on `title` or `body`
     * @throws InvalidArgumentException when the sensitive flag is asked to be marked and cleared at once
     */
    public static function from(mixed $title, mixed $body, mixed $sensitive, mixed $clearSensitive = false): self
    {
        $sensitive = (bool) $sensitive;
        $clearSensitive = (bool) $clearSensitive;

        if ($sensitive && $clearSensitive) {
            throw new InvalidArgumentException('Marking and clearing the sensitive flag cannot be requested together.');
        }

        $title = is_string($title) ? trim($title) : '';

        if ($title === '') {
            throw ValidationException::withMessages(['title' => __('knowledge.errors.title_required')]);
        }

        if (mb_strlen($title) > KnowledgeArticle::MAX_TITLE_LENGTH) {
            throw ValidationException::withMessages(['title' => __('knowledge.errors.title_too_long', ['max' => KnowledgeArticle::MAX_TITLE_LENGTH])]);
        }

        $body = is_string($body) ? $body : '';

        if (mb_strlen($body) > KnowledgeArticle::MAX_BODY_LENGTH) {
            throw ValidationException::withMessages(['body' => __('knowledge.errors.body_too_long', ['max' => KnowledgeArticle::MAX_BODY_LENGTH])]);
        }

        return new self($title, $body, $sensitive, $clearSensitive);
    }
}
