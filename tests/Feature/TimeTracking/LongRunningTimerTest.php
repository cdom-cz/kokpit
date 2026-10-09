<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\TimeTracking\Jobs\NotifyLongRunningTimers;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Resources\TimeEntryResource;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
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
