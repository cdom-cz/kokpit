<?php

declare(strict_types=1);

namespace App\Domain\Shared\Database;

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Models\Media;
use App\Domain\Shared\Models\Tag;
use App\Domain\Shared\Models\WebhookCall;

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
    ];
}
