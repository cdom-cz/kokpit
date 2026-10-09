<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Actions;

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Support\TimerClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Starts a timer for a client and stops the running one of the same user.
 *
 * Starting stops the running timer and keeps its entry, without a question
 * (D-02). The stop and the start share one transaction, one per-user advisory
 * lock and one instant: the clock is read after the lock, truncated to the
 * whole second, so the stopped entry ends exactly when the new one starts and
 * there is no gap or overlap. The partial unique index
 * time_entries_one_running_per_user is the database backstop behind the lock.
 *
 * A running entry whose start lies after the clock (clock skew between
 * containers) is stopped at its own start, never before it, so the
 * `ended_at >= started_at` check cannot fail. A start in the same second as the
 * running entry therefore keeps a zero-length entry.
 *
 * A start needs only a client. A client that is malformed, unknown or archived
 * is the single field error `client_id`; a description over 1000 characters is
 * the field error `description`. Ids and instants are set with `forceFill`, so
 * the payload can never smuggle them in.
 *
 * @phpstan-type TimerData array{client_id?: mixed, description?: mixed}
 */
final class StartTimer
{
    private const int DESCRIPTION_MAX_LENGTH = 1000;

    /**
     * @param  TimerData  $data
     * @return array{entry: TimeEntry, stopped: TimeEntry|null}
     *
     * @throws ValidationException
     */
    public function handle(User $actor, array $data): array
    {
        Gate::forUser($actor)->authorize('create', TimeEntry::class);

        return DB::transaction(function () use ($actor, $data): array {
            $this->lockTimerOf($actor);

            // Read after the lock, so a waiting start sees the instant of its own turn.
            $now = TimerClock::now();

            $clientId = $this->clientId($data['client_id'] ?? null);
            $description = $this->description($data['description'] ?? null);

            $running = TimeEntry::query()
                ->where('user_id', $actor->getKey())
                ->whereNull('ended_at')
                ->lockForUpdate()
                ->first();

            if ($running !== null) {
                $running->forceFill(['ended_at' => $now->max($running->started_at)])->save();
                $running->refresh();
            }

            $entry = (new TimeEntry(['description' => $description, 'billable' => true]))->forceFill([
                'user_id' => $actor->getKey(),
                'client_id' => $clientId,
                'started_at' => $now,
            ]);
            $entry->save();
            $entry->refresh();

            return ['entry' => $entry, 'stopped' => $running];
        });
    }

    /**
     * One timer decision per user at a time; different users never wait on each other.
     */
    private function lockTimerOf(User $user): void
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['kokpit:timer:'.$user->getKey()]);
    }

    /**
     * The id of a live client, read again under a share lock so an archive in
     * flight cannot slip past the check.
     */
    private function clientId(mixed $value): string
    {
        $client = is_string($value) && Str::isUuid($value)
            ? Client::query()->whereKey($value)->sharedLock()->first()
            : null;

        if ($client === null) {
            throw ValidationException::withMessages(['client_id' => __('kokpit.time.errors.client_required')]);
        }

        return $client->getKey();
    }

    private function description(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $description = trim($value);

        if (mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH) {
            throw ValidationException::withMessages(['description' => __('kokpit.time.errors.description_too_long')]);
        }

        return $description === '' ? null : $description;
    }
}
