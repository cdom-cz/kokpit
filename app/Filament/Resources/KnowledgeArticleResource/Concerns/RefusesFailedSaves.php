<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticleResource\Concerns;

use App\Domain\Knowledge\Exceptions\ArticleWriteFailed;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;

/**
 * The shared refusal of a database write that failed on a page of the article form (D-09, D-03, IN-04).
 *
 * The form stays exactly as typed: `Halt` ends the Filament submit without a redirect or a second notification, so
 * the Admin keeps every typed character and sees why the save did not happen, instead of Livewire's generic error
 * overlay. The notice carries fixed Czech text only. The exception is reported to the log as it was while it was
 * uncaught: its message is fixed and holds the SQLSTATE only (18-05), never the title or the body.
 */
trait RefusesFailedSaves
{
    /**
     * Reports the content-free exception, tells the Admin that the save failed and ends the submit.
     */
    protected function refuseWriteFailure(ArticleWriteFailed $failed): never
    {
        report($failed);

        Notification::make()
            ->danger()
            ->persistent()
            ->title(trans()->string('knowledge.notifications.write_failed_title'))
            ->body(trans()->string('knowledge.notifications.write_failed_body'))
            ->send();

        throw new Halt;
    }
}
