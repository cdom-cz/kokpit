<?php

declare(strict_types=1);

namespace App\Livewire\Signal;

use App\Domain\Identity\Models\User;
use App\Domain\Signal\Exceptions\SignalRuleViolation;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;

/**
 * What every planner screen shares when it runs a domain Action: the signed-in actor and one place
 * that turns a refused rule into a notification instead of an error page.
 *
 * A rule violation (a full day, a locked day, a fourth goal, a stale order ...) carries a message for
 * the user. A record that is gone (deleted in another tab) or not the user's own is reported as "not
 * found" and the screen simply reads again. Anything else is a real error and is not caught.
 */
trait RunsSignalActions
{
    private function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * Runs the callback; returns whether it was allowed. A refusal is shown as a notification.
     *
     * @param  callable(User): mixed  $callback
     */
    private function attempt(callable $callback): bool
    {
        try {
            $callback($this->actor());
        } catch (SignalRuleViolation $violation) {
            Notification::make()->title($violation->getMessage())->danger()->send();

            return false;
        } catch (ModelNotFoundException) {
            Notification::make()->title(__('kokpit.signal.errors.not_found'))->danger()->send();

            return false;
        }

        return true;
    }
}
