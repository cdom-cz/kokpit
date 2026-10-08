<?php

declare(strict_types=1);

namespace App\Domain\Operations\Alerts;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends an operational alert to every Admin, independent of the queue (D-11).
 *
 * Throttled per key (D-11): the first alert for a key goes out at once, further
 * alerts inside the window (kokpit.alerts.throttle_seconds) are only counted,
 * and the next alert after the window says how many were held back. If the
 * cache itself fails the alert is sent anyway: a duplicate beats silence.
 *
 * Each channel is sent synchronously and on its own: a failing mail transport
 * must not hide the bell, and a failing bell must not hide the mail. Whatever
 * goes wrong ends in a critical log entry, never in an exception, because the
 * caller is the failure path of a job that must still reach failed_jobs.
 *
 * Not final so that a test can replace it with a double that throws.
 */
class AdminAlerter
{
    /** The channels, each sent separately. The database row comes first: it is the one that cannot be lost to a mail outage. */
    private const array CHANNELS = ['database', 'mail'];

    /** How long the count of held-back alerts survives; it must outlive the window to be reported after it. */
    private const int SUPPRESSED_COUNT_TTL = 86400;

    /**
     * Never throws.
     */
    public function alert(string $throttleKey, OperationalAlert $alert): void
    {
        try {
            $suppressed = $this->suppressedSinceLastAlert($throttleKey);

            if ($suppressed === null) {
                return;
            }

            if ($suppressed > 0) {
                $alert = $alert->withLine(__('kokpit.alerts.suppressed', ['count' => $suppressed]));
            }

            app(PartnerContext::class)->runAsSystem(function () use ($throttleKey, $alert): void {
                $this->deliver($throttleKey, $alert);
            });
        } catch (Throwable $e) {
            $this->logCritical('Admin alert could not be delivered', $throttleKey, null, $e);
        }
    }

    /**
     * Null when the alert falls inside the window of an earlier one and is only
     * counted; otherwise the number of alerts held back since the last one sent.
     * A failing cache answers 0, which sends the alert.
     */
    private function suppressedSinceLastAlert(string $throttleKey): ?int
    {
        $windowKey = 'kokpit:alert:'.hash('sha256', $throttleKey);
        $countKey = $windowKey.':suppressed';

        try {
            $window = max(1, (int) config('kokpit.alerts.throttle_seconds'));

            if (Cache::add($windowKey, true, $window)) {
                return (int) Cache::pull($countKey, 0);
            }

            Cache::add($countKey, 0, self::SUPPRESSED_COUNT_TTL);
            Cache::increment($countKey);

            return null;
        } catch (Throwable) {
            return 0;
        }
    }

    private function deliver(string $throttleKey, OperationalAlert $alert): void
    {
        $admins = User::query()->role(RoleName::Admin->value)->get();

        if ($admins->isEmpty()) {
            $this->logCritical('Admin alert has no recipient', $throttleKey, null, null);

            return;
        }

        foreach (self::CHANNELS as $channel) {
            foreach ($admins as $admin) {
                try {
                    Notification::sendNow($admin, $alert, [$channel]);
                } catch (Throwable $e) {
                    $this->logCritical('Admin alert channel failed', $throttleKey, $channel, $e);
                }
            }
        }
    }

    private function logCritical(string $message, string $throttleKey, ?string $channel, ?Throwable $e): void
    {
        try {
            Log::critical($message.($channel !== null ? ' ('.$channel.')' : ''), [
                'alert' => $throttleKey,
                'channel' => $channel,
                'exception' => $e !== null ? $e::class : null,
                'error' => $e?->getMessage(),
            ]);
        } catch (Throwable) {
            // The log itself is down; there is nothing left to try.
        }
    }
}
