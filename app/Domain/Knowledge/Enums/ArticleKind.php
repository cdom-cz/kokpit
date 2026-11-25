<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The kind of a knowledge article (A7): a general article, an incident (the hub), an incident
 * sub-page or a retrospective. The kind is fixed at creation and never changes: the database
 * refuses any update of it, and these names are stored in the rows.
 *
 * The values equal the `knowledge_articles_kind_check` constraint.
 */
enum ArticleKind: string implements HasLabel
{
    case General = 'general';
    case Incident = 'incident';
    case IncidentPage = 'incident_page';
    case Retrospective = 'retrospective';

    public function getLabel(): string
    {
        return __('enums.article_kind.'.$this->value);
    }
}
