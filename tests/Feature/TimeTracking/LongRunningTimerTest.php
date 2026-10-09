<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\TimeTracking\Jobs\NotifyLongRunningTimers;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Resources\TimeEntryResource;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Canary;

/*
 * The forgotten-timer notice (TI-09, D-07): a scheduled, idempotent job puts one bell
 * notification per running entry past the threshold into the Admin's bell, and never stops
 * the timer. Every name is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-09 12:00:00', 'UTC'));
    $this->admin = Canary::admin();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * Creates an entry as a system run, started the given number of seconds before the frozen now.
 *
 * @param  array<string, mixed>  $state
 */
function forgottenEntry(string $userId, int $startedSecondsAgo, array $state = []): TimeEntry
{
    return app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::factory()->create([
        'user_id' => $userId,
        'started_at' => CarbonImmutable::now()->subSeconds($startedSecondsAgo),
        'ended_at' => null,
        ...$state,
    ]));
}

/**
 * The entry as stored, read as a system run.
 */
function forgottenEntryFresh(TimeEntry $entry): TimeEntry
{
    return app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($entry->id));
}

it('puts one notice in the bell of the Admin for a timer past the threshold', function (): void {
    $client = Client::factory()->create(['name' => 'Cihla']);
    $entry = forgottenEntry($this->admin->id, 12 * 3600 + 3 * 60, ['client_id' => $client->id]);

    NotifyLongRunningTimers::dispatchSync();

    $rows = DB::table('notifications')->where('notifiable_id', $this->admin->id)->get();
    expect($rows)->toHaveCount(1);

    $data = json_decode((string) $rows->first()->data, true, flags: JSON_THROW_ON_ERROR);

    expect($data['title'])->toBe('Časovač běží příliš dlouho')
        ->and($data['body'])->toBe('Časovač u klienta Cihla běží už 12:03. Zkontrolujte ho a případně zastavte.')
        ->and($data['actions'][0]['label'])->toBe('Otevřít záznam')
        ->and($data['actions'][0]['url'])->toBe(TimeEntryResource::getUrl('view', ['record' => $entry]));

    $stored = forgottenEntryFresh($entry);

    expect($stored->long_running_notified_at)->not->toBeNull()
        ->and($stored->isRunning())->toBeTrue();
});

/**
 * The stored bell rows of a user, decoded.
 *
 * @return list<array<string, mixed>>
 */
function forgottenBell(string $userId): array
{
    return DB::table('notifications')
        ->where('notifiable_id', $userId)
        ->pluck('data')
        ->map(static fn (string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR))
        ->all();
}

it('sends nothing on a second run of the job', function (): void {
    $entry = forgottenEntry($this->admin->id, 13 * 3600);

    NotifyLongRunningTimers::dispatchSync();
    $claimedAt = forgottenEntryFresh($entry)->long_running_notified_at;

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(5));
    NotifyLongRunningTimers::dispatchSync();

    expect(forgottenBell($this->admin->id))->toHaveCount(1)
        ->and(forgottenEntryFresh($entry)->long_running_notified_at?->equalTo($claimedAt))->toBeTrue();
});

it('sends nothing for an entry another process has already claimed', function (): void {
    $entry = forgottenEntry($this->admin->id, 13 * 3600, ['long_running_notified_at' => CarbonImmutable::now()->subMinute()]);

    NotifyLongRunningTimers::dispatchSync();

    expect(forgottenBell($this->admin->id))->toBe([])
        ->and(forgottenEntryFresh($entry)->isRunning())->toBeTrue();
});

it('sends nothing for an entry just under the threshold', function (): void {
    forgottenEntry($this->admin->id, 12 * 3600 - 60);

    NotifyLongRunningTimers::dispatchSync();

    expect(forgottenBell($this->admin->id))->toBe([]);
});

it('notifies an entry that is exactly at the threshold', function (): void {
    forgottenEntry($this->admin->id, 12 * 3600);

    NotifyLongRunningTimers::dispatchSync();

    expect(forgottenBell($this->admin->id))->toHaveCount(1);
});

it('sends nothing for a finished entry however long it was', function (): void {
    $entry = forgottenEntry($this->admin->id, 30 * 3600, ['ended_at' => CarbonImmutable::now()->subHours(10)]);

    NotifyLongRunningTimers::dispatchSync();

    expect(forgottenBell($this->admin->id))->toBe([])
        ->and(forgottenEntryFresh($entry)->long_running_notified_at)->toBeNull();
});

it('skips a running entry of a deactivated Admin without claiming it, so a reactivated Admin is still told', function (): void {
    $entry = forgottenEntry($this->admin->id, 13 * 3600);
    $this->admin->forceFill(['deactivated_at' => CarbonImmutable::now()->subDay()])->save();

    NotifyLongRunningTimers::dispatchSync();

    expect(forgottenBell($this->admin->id))->toBe([])
        ->and(forgottenEntryFresh($entry)->long_running_notified_at)->toBeNull();

    $this->admin->forceFill(['deactivated_at' => null])->save();
    NotifyLongRunningTimers::dispatchSync();

    expect(forgottenBell($this->admin->id))->toHaveCount(1);
});

it('sends nothing for a running entry whose owner is not the Admin', function (): void {
    [$clientId] = Canary::twoClients();
    $partner = Canary::partnerFor($clientId);
    $entry = forgottenEntry($partner->id, 13 * 3600, ['client_id' => $clientId]);

    NotifyLongRunningTimers::dispatchSync();

    expect(forgottenBell($partner->id))->toBe([])
        ->and(forgottenBell($this->admin->id))->toBe([])
        ->and(forgottenEntryFresh($entry)->long_running_notified_at)->toBeNull();
});

it('notifies an entry of 2 h 1 min when the threshold is set to 2 hours', function (): void {
    config(['kokpit.time.long_running_hours' => 2]);
    forgottenEntry($this->admin->id, 2 * 3600 + 60);

    NotifyLongRunningTimers::dispatchSync();

    $bell = forgottenBell($this->admin->id);

    expect($bell)->toHaveCount(1)
        ->and($bell[0]['body'])->toContain('2:01');
});

it('tells each owner once about the own timer when two accounts forgot one', function (): void {
    $other = Canary::admin();
    forgottenEntry($this->admin->id, 14 * 3600);
    forgottenEntry($other->id, 13 * 3600);

    NotifyLongRunningTimers::dispatchSync();
    NotifyLongRunningTimers::dispatchSync();

    expect(forgottenBell($this->admin->id))->toHaveCount(1)
        ->and(forgottenBell($other->id))->toHaveCount(1);
});

it('never stops the timer, sends no e-mail and writes no activity row for the claim', function (): void {
    Mail::fake();
    $entry = forgottenEntry($this->admin->id, 13 * 3600);
    $before = DB::table('activity_log')->count();

    NotifyLongRunningTimers::dispatchSync();

    $stored = forgottenEntryFresh($entry);

    expect($stored->isRunning())->toBeTrue()
        ->and($stored->duration_seconds)->toBeNull()
        ->and($stored->long_running_notified_at)->not->toBeNull()
        ->and(DB::table('activity_log')->count())->toBe($before);

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

it('keeps the description, the task and the amounts out of the bell body', function (): void {
    $word = Canary::canary('description');
    forgottenEntry($this->admin->id, 13 * 3600, ['description' => $word]);

    NotifyLongRunningTimers::dispatchSync();

    expect(json_encode(forgottenBell($this->admin->id), JSON_THROW_ON_ERROR))->not->toContain($word);
});

it('escapes a client name with markup exactly once in the stored bell body', function (): void {
    $markup = implode('', ['<', 'script', '>']);
    $client = Client::factory()->create(['name' => 'A '.$markup.' & B']);
    forgottenEntry($this->admin->id, 13 * 3600, ['client_id' => $client->id]);

    NotifyLongRunningTimers::dispatchSync();

    $body = forgottenBell($this->admin->id)[0]['body'];

    expect($body)->toContain('A &lt;script&gt; &amp; B')
        ->and($body)->not->toContain($markup)
        ->and($body)->not->toContain('&amp;lt;')
        ->and($body)->not->toContain('&amp;amp;');
});

it('rolls the claim back when the notification fails, so the next run tries again', function (): void {
    $entry = forgottenEntry($this->admin->id, 13 * 3600);

    Event::listen(NotificationSending::class, static function (): never {
        throw new RuntimeException('The bell is down.');
    });

    expect(fn () => NotifyLongRunningTimers::dispatchSync())->toThrow(RuntimeException::class);

    expect(forgottenEntryFresh($entry)->long_running_notified_at)->toBeNull()
        ->and(forgottenBell($this->admin->id))->toBe([]);

    Event::forget(NotificationSending::class);
    NotifyLongRunningTimers::dispatchSync();

    expect(forgottenBell($this->admin->id))->toHaveCount(1)
        ->and(forgottenEntryFresh($entry)->long_running_notified_at)->not->toBeNull();
});
