<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Actions;

/**
 * What a save of SaveArticle did.
 */
enum SaveOutcome: string
{
    /** A new article with its version 1. */
    case Created = 'created';

    /** A content change: the next version was written. */
    case Updated = 'updated';

    /** Nothing changed: no write, no version. */
    case Unchanged = 'unchanged';

    /** Only the sensitive flag changed: no version, the edit time is untouched. */
    case SensitivityChanged = 'sensitivity_changed';

    /** Only the folder, the links or the tags changed (and maybe the sensitive flag): no version, the edit time is untouched. */
    case ClassificationChanged = 'classification_changed';
}
