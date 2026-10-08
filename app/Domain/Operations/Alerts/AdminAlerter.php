<?php

declare(strict_types=1);

namespace App\Domain\Operations\Alerts;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Sends an operational alert to every Admin, independent of the queue (D-11).
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

    /**
     * Never throws.
     */
    public function alert(string $throttleKey, OperationalAlert $alert): void
    {
        try {
            app(PartnerContext::class)->runAsSystem(function () use ($throttleKey, $alert): void {
                $this->deliver($throttleKey, $alert);
            });
        } catch (Throwable $e) {
            $this->logCritical('Admin alert could not be delivered', $throttleKey, null, $e);
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
