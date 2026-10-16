<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

/**
 * What a file can hang on. The values are the morph aliases stored in `documents.owner_type`
 * (`transaction` is added in the finance plans, `invoice` in Phase 10).
 *
 * Only a project, a task and a task comment can be shared with a Partner (D-03); the database
 * CHECK `documents_visible_owner_check` mirrors that list.
 */
enum DocumentOwnerType: string
{
    case Client = 'client';
    case Project = 'project';
    case Task = 'task';
    case TaskComment = 'task_comment';

    public function isShareable(): bool
    {
        return match ($this) {
            self::Project, self::Task, self::TaskComment => true,
            self::Client => false,
        };
    }
}
