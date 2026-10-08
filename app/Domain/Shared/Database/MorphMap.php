<?php

declare(strict_types=1);

namespace App\Domain\Shared\Database;

use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\ClientInvitation;
use App\Domain\Clients\Models\Contact;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Projects\Models\ProjectBilling;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Models\Media;
use App\Domain\Shared\Models\Tag;
use App\Domain\Shared\Models\WebhookCall;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskBilling;
use App\Domain\Tasks\Models\TaskChecklistItem;
use App\Domain\Tasks\Models\TaskComment;

/**
 * The single source of morph aliases.
 *
 * Aliases are short singular snake_case table nouns and are what the `*_type`
 * columns store, so renaming one later needs a data migration of every morph
 * column. Later phases add one line each (`client`, `project`, `task`, ...).
 */
final class MorphMap
{
    /** @var array<string, class-string> */
    public const array MAP = [
        'user' => User::class,
        'role' => Role::class,
        'permission' => Permission::class,
        'personal_access_token' => PersonalAccessToken::class,
        'media' => Media::class,
        'tag' => Tag::class,
        'activity' => Activity::class,
        'webhook_call' => WebhookCall::class,
        'client' => Client::class,
        'client_invitation' => ClientInvitation::class,
        'contact' => Contact::class,
        'project' => Project::class,
        'project_billing' => ProjectBilling::class,
        'task' => Task::class,
        'task_billing' => TaskBilling::class,
        'task_checklist_item' => TaskChecklistItem::class,
        'task_comment' => TaskComment::class,
    ];
}
