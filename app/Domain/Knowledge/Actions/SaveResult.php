<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Actions;

use App\Domain\Knowledge\Models\KnowledgeArticle;

/**
 * The result of a save: what happened, the stored article and whether this save marked it sensitive through the
 * acknowledgment of an ambiguous secret finding (true only on that path).
 */
final readonly class SaveResult
{
    public function __construct(
        public SaveOutcome $outcome,
        public KnowledgeArticle $article,
        public bool $markedSensitive,
    ) {}
}
