<?php

declare(strict_types=1);

namespace App\Domain\Shared\Tags;

/**
 * The typed tag vocabulary (D-07).
 *
 * Every tag is stored with one of these types, so a Partner constraint can tell
 * a project tag (shown on the Partner's own visible projects) from a client tag
 * (Admin only), and a task tag (Admin only, never shown to a Partner). The values are written to `tags.type`. A tag input or column
 * always passes its type: without one the plugin reads and syncs tags of every
 * type.
 */
enum TagType: string
{
    case Client = 'client';
    case Project = 'project';
    case Task = 'task';
}
