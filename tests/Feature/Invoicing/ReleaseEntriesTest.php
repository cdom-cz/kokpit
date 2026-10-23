<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\ReleaseEntries;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\InvoiceFixtures;

/*
 * Unlinking time from a draft (IN-10, D-05): the entries go back to unbilled and unreserved, the lines stay. Every
 * name and amount is fictional.
 */

beforeEach(function (): void {
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    InvoiceFixtures::configureSupplier();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

/**
 * A client with an hourly project and three finished entries of September, and the draft that holds them.
 *
 * @return array{client: Client, project: Project, entries: list<TimeEntry>, draft: Invoice}
 */
function releaseSetup(): array
{
    $client = InvoiceFixtures::client();
    $project = app(CreateProject::class)->handle($client, [
        'name' => 'Release project',
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'hourly_rate' => '1000',
    ]);

    $entries = [];

    foreach ([['2026-09-10 08:00:00', '2026-09-10 09:00:00'], ['2026-09-11 08:00:00', '2026-09-11 09:00:00'], ['2026-09-12 08:00:00', '2026-09-12 09:00:00']] as [$start, $end]) {
        $factory = TimeEntry::factory()->forProject($project);
        $entries[] = app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($factory->create([
            'user_id' => test()->admin->id,
            'started_at' => CarbonImmutable::parse($start, 'UTC'),
            'ended_at' => CarbonImmutable::parse($end, 'UTC'),
        ])->id));
    }

    $draft = app(CreateDraft::class)->handle(test()->admin, [
        'client_id' => $client->id,
        'kind' => 'invoice',
        'source' => 'time',
        'period_from' => '2026-09-01',
        'period_to' => '2026-09-30',
        'project_ids' => [$project->id],
        'without_project' => false,
        'grouping' => 'project',
    ]);

    return ['client' => $client, 'project' => $project, 'entries' => $entries, 'draft' => $draft];
}

function releaseFresh(TimeEntry $entry): TimeEntry
{
    return app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($entry->id));
}

/**
 * The stored lines as plain rows, for before and after comparisons.
 *
 * @return list<array<string, mixed>>
 */
function releaseLines(Invoice $draft): array
{
    return app(PartnerContext::class)->runAsSystem(static fn (): array => InvoiceItem::query()
        ->where('invoice_id', $draft->id)
        ->orderBy('position')
        ->get()
        ->map(static fn (InvoiceItem $item): array => $item->getAttributes())
        ->all());
}

it('returns the unlinked entries to unbilled and unreserved and leaves the lines as they are', function (): void {
    ['entries' => $entries, 'draft' => $draft] = releaseSetup();
    $linesBefore = releaseLines($draft);
    $totalBefore = app(PartnerContext::class)->runAsSystem(static fn (): int => Invoice::query()->findOrFail($draft->id)->total_minor);

    $count = app(ReleaseEntries::class)->handle($this->admin, $draft, [$entries[0]->id, $entries[1]->id]);

    expect($count)->toBe(2);

    foreach ([$entries[0], $entries[1]] as $released) {
        $stored = releaseFresh($released);

        expect($stored->invoice_id)->toBeNull()
            ->and($stored->billing_state)->toBe(BillingState::Unbilled);
    }

    expect(releaseFresh($entries[2])->invoice_id)->toBe($draft->id)
        ->and(releaseLines($draft))->toBe($linesBefore)
        ->and(app(PartnerContext::class)->runAsSystem(static fn (): int => Invoice::query()->findOrFail($draft->id)->total_minor))->toBe($totalBefore);
});

it('lets another draft take an entry that was unlinked', function (): void {
    ['client' => $client, 'project' => $project, 'entries' => $entries, 'draft' => $draft] = releaseSetup();

    app(ReleaseEntries::class)->handle($this->admin, $draft, [$entries[0]->id]);

    $second = app(CreateDraft::class)->handle($this->admin, [
        'client_id' => $client->id,
        'kind' => 'invoice',
        'source' => 'time',
        'period_from' => '2026-09-10',
        'period_to' => '2026-09-10',
        'project_ids' => [$project->id],
        'without_project' => false,
        'grouping' => 'project',
    ]);

    expect(releaseFresh($entries[0])->invoice_id)->toBe($second->id);
});

it('ignores the entries of another draft and the free ones', function (): void {
    ['entries' => $entries, 'draft' => $draft] = releaseSetup();
    $other = releaseSetup();

    $count = app(ReleaseEntries::class)->handle($this->admin, $draft, [$other['entries'][0]->id, 'not-an-id', '']);

    expect($count)->toBe(0)
        ->and(releaseFresh($other['entries'][0])->invoice_id)->toBe($other['draft']->id)
        ->and(releaseFresh($entries[0])->invoice_id)->toBe($draft->id);

    app(ReleaseEntries::class)->handle($this->admin, $draft, [$entries[2]->id]);

    expect(app(ReleaseEntries::class)->handle($this->admin, $draft, [$entries[2]->id]))->toBe(0);
});

it('refuses to unlink from a locked issued document and leaves its time billed', function (): void {
    ['entries' => $entries, 'draft' => $draft] = releaseSetup();
    $issued = InvoiceFixtures::lock(app(IssueDocument::class)->handle($this->admin, $draft));

    expect(fn () => app(ReleaseEntries::class)->handle($this->admin, $issued, [$entries[0]->id]))
        ->toThrow(ValidationException::class, __('invoices.errors.document_locked'));

    $stored = releaseFresh($entries[0]);

    expect($stored->billing_state)->toBe(BillingState::Billed)
        ->and($stored->invoice_id)->toBe($issued->id);
});

it('refuses a Partner', function (): void {
    ['client' => $client, 'entries' => $entries, 'draft' => $draft] = releaseSetup();
    $partner = Canary::partnerFor($client->id);
    $this->actingAs($partner);

    expect(fn () => app(ReleaseEntries::class)->handle($partner, $draft, [$entries[0]->id]))->toThrow(AuthorizationException::class);
});
