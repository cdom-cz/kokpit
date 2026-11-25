<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;

/**
 * The history of one knowledge article: the metadata rows only (created, version number, sensitive flag, secret
 * acknowledgments, archive and restore). The title and the body are never logged, so they never appear here.
 * Admin only.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The history shows who changed what; a Partner never sees it.')]
final class KnowledgeArticleHistoryRelationManager extends ActivityHistoryRelationManager {}
