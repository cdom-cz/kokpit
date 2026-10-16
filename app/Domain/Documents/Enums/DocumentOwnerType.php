<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What a file can hang on. The values are the morph aliases stored in `documents.owner_type`
 * (`invoice` is added in Phase 10).
 *
 * Only a project, a task and a task comment can be shared with a Partner (D-03); the database
 * CHECK `documents_visible_owner_check` mirrors that list.
 */
enum DocumentOwnerType: string implements HasLabel
{
    case Client = 'client';
    case Project = 'project';
    case Task = 'task';
    case TaskComment = 'task_comment';
    case Transaction = 'transaction';

    public function getLabel(): string
    {
        return __('enums.document_owner_type.'.$this->value);
    }

    public function isShareable(): bool
    {
        return match ($this) {
            self::Project, self::Task, self::TaskComment => true,
            self::Client, self::Transaction => false,
        };
    }
}
