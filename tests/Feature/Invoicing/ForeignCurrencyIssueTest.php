<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\ExchangeRates\ExchangeRateConverter;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\UpdateDraft;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoiceItemSource;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\InvoiceFixtures;

/*
 * Invoicing in a currency other than the tracked rates (IN-07; D-09, D-12, ruling R-17-1): time lines are converted
 * at issue with the CNB lists of the issue date, the line amount rounded once from the exact conversion, the printed
 * unit price a display figure, and every quote frozen. The preview of the draft uses the lists of the day it is made
 * on and is never the final value. Every name, rate and instant is fictional.
 */

beforeEach(function (): void {
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    InvoiceFixtures::configureSupplier();
    // Wednesday 2026-10-14, 09:30 in Prague: the day the draft is made.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

/**
 * A project of the client with the hourly rate (in the currency of the client unless the data says otherwise).
 */
function foreignIssueProject(Client $client, string $name, string $rate): Project
{
    return app(CreateProject::class)->handle($client, [
        'name' => $name,
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'hourly_rate' => $rate,
    ]);
}

/**
 * One finished entry of the project on a day of September, `$hours` long.
 */
function foreignIssueEntry(Project $project, string $day, int $hours): TimeEntry
{
    $factory = TimeEntry::factory()->forProject($project);
    $start = CarbonImmutable::parse($day.' 08:00:00', 'UTC');

    return app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($factory->create([
        'user_id' => test()->admin->id,
        'started_at' => $start,
        'ended_at' => $start->addHours($hours),
    ])->id));
}

/**
 * A EUR draft of the September time of the projects.
 *
 * @param  list<Project>  $projects
 */
function foreignIssueDraft(Client $client, array $projects, string $currency = 'EUR'): Invoice
{
    return app(CreateDraft::class)->handle(test()->admin, [
        'client_id' => $client->id,
        'kind' => 'invoice',
        'source' => 'time',
        'period_from' => '2026-09-01',
        'period_to' => '2026-09-30',
        'project_ids' => array_map(static fn (Project $project): string => $project->id, $projects),
        'without_project' => false,
        'grouping' => 'project',
        'currency' => $currency,
    ]);
}

/**
 * The stored lines of the document in order, with their sources.
 *
 * @return list<InvoiceItem>
 */
function foreignIssueLines(Invoice $document): array
{
    return app(PartnerContext::class)->runAsSystem(static fn (): array => InvoiceItem::query()->where('invoice_id', $document->id)->with('source')->orderBy('position')->get()->all());
}

function foreignIssueStored(Invoice $document): Invoice
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Invoice => Invoice::query()->findOrFail($document->id));
}

/**
 * The state of the number counters, to prove that a refused issue consumed nothing.
 *
 * @return list<array<string, mixed>>
 */
function foreignIssueSequences(): array
{
    return DB::table('number_sequences')->orderBy('scope_key')->get()->map(static fn (object $row): array => (array) $row)->all();
}

describe('the tracer: a EUR invoice built from CZK-rated time', function (): void {
    it('is issued with the lists of the issue day, one rounding per line, and freezes every quote', function (): void {
        $client = InvoiceFixtures::client();
        $project = foreignIssueProject($client, 'Alpha project', '1500');
        foreignIssueEntry($project, '2026-09-10', 10);

        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.000');
        $draft = foreignIssueDraft($client, [$project]);

        [$preview] = foreignIssueLines($draft);

        // The draft preview uses the list of the day it was made on: 1 500 CZK at 25,000 is 60,00 EUR an hour.
        expect($preview->unit_price_minor)->toBe(6000)
            ->and($preview->line_total_minor)->toBe(60000);

        // Another day, another list: the issue looks the rate up again and never reuses the preview.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:30:00', 'Europe/Prague'));
        InvoiceFixtures::cnbRate('EUR', '2026-10-15', '25.125');

        $issued = app(IssueDocument::class)->handle($this->admin, $draft);
        [$line] = foreignIssueLines($issued);

        // 1 500 CZK at 25,125 is 59,70 EUR for display; the amount is rounded once from the exact 597,0149 EUR.
        // 10 x 59,70 = 597,00 is the two-rounding figure that must not appear.
        expect($line->unit_price_minor)->toBe(5970)
            ->and($line->line_total_minor)->toBe(59701)
            ->and($line->line_total_minor)->not->toBe(10 * 5970)
            ->and($line->price_converted)->toBeTrue()
            ->and($line->quantity)->toBe('10.0000');

        $stored = foreignIssueStored($issued);

        expect($stored->subtotal_minor)->toBe(59701)
            ->and($stored->total_minor)->toBe(59701)
            ->and($stored->vat_minor)->toBe(0);

        $quote = app(ExchangeRateConverter::class)->rateFor('EUR', '2026-10-15');
        $snapshot = $line->source?->conversion_snapshot;

        expect($snapshot)->toBeArray()
            ->and($snapshot['source_currency'])->toBe('CZK')
            ->and($snapshot['source_rate'])->toBe('1')
            ->and($snapshot['source_unit'])->toBe(1)
            ->and($snapshot['target_currency'])->toBe('EUR')
            ->and($snapshot['target_rate'])->toBe($quote['rate'])
            ->and($snapshot['target_unit'])->toBe(1)
            ->and($snapshot['rate_date'])->toBe('2026-10-15');

        $admin = InvoiceAdmin::query()->where('invoice_id', $issued->id)->firstOrFail();

        // 597,01 EUR at 25,125 is 14 999,88 CZK (1 499 987,625 haléřů rounded once).
        expect((float) $admin->exchange_rate)->toBe(25.125)
            ->and($admin->exchange_unit_amount)->toBe(1)
            ->and($admin->exchange_rate_date?->format('Y-m-d'))->toBe('2026-10-15')
            ->and($admin->czk_total_minor)->toBe(1499988);
    });

    it('keeps the unit price typed by hand and converts the other line', function (): void {
        $client = InvoiceFixtures::client();
        $alpha = foreignIssueProject($client, 'Alpha project', '1500');
        $bravo = foreignIssueProject($client, 'Bravo project', '900');
        foreignIssueEntry($alpha, '2026-09-10', 10);
        foreignIssueEntry($bravo, '2026-09-11', 2);

        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.125');
        $draft = foreignIssueDraft($client, [$alpha, $bravo]);

        $items = array_map(static fn (InvoiceItem $item): array => [
            'id' => $item->id,
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit' => $item->unit,
            'unit_price' => $item->description === 'Bravo project' ? '40.00' : Money::ofMinor($item->unit_price_minor, 'EUR')->toMajor(),
        ], foreignIssueLines($draft));

        app(UpdateDraft::class)->handle($this->admin, $draft, ['items' => $items]);

        $issued = app(IssueDocument::class)->handle($this->admin, $draft);
        [$converted, $typed] = foreignIssueLines($issued);

        expect($converted->description)->toBe('Alpha project')
            ->and($converted->line_total_minor)->toBe(59701)
            ->and($converted->price_converted)->toBeTrue()
            ->and($typed->description)->toBe('Bravo project')
            ->and($typed->unit_price_minor)->toBe(4000)
            ->and($typed->line_total_minor)->toBe(8000)
            ->and($typed->price_converted)->toBeFalse()
            ->and($typed->source?->price_manual)->toBeTrue()
            ->and($typed->source?->conversion_snapshot)->toBeNull()
            ->and(foreignIssueStored($issued)->total_minor)->toBe(67701);
    });

    it('converts a USD-rated line on a EUR invoice through both quotes with one rounding', function (): void {
        $client = InvoiceFixtures::client(['currency' => 'USD', 'hourly_rate' => Money::ofMinor(5000, 'USD')]);
        $project = foreignIssueProject($client, 'Usd project', '50');
        foreignIssueEntry($project, '2026-09-10', 3);

        InvoiceFixtures::cnbRate('USD', '2026-10-14', '22.5');
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.125');
        $draft = foreignIssueDraft($client, [$project]);

        // The EUR list of the issue day is newer than the USD one, so the snapshot carries the later date.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:30:00', 'Europe/Prague'));
        InvoiceFixtures::cnbRate('EUR', '2026-10-15', '25.125');

        $issued = app(IssueDocument::class)->handle($this->admin, $draft);
        [$line] = foreignIssueLines($issued);

        // 50,00 USD x 22,5 / 25,125 = 44,7761 EUR an hour, shown as 44,78; 3 h are 134,3284 EUR, rounded once to 134,33
        // and not 3 x 44,78 = 134,34.
        expect($line->unit_price_minor)->toBe(4478)
            ->and($line->line_total_minor)->toBe(13433)
            ->and($line->price_converted)->toBeTrue();

        $snapshot = $line->source?->conversion_snapshot;

        expect($snapshot)->toBeArray()
            ->and($snapshot['source_currency'])->toBe('USD')
            ->and($snapshot['source_unit'])->toBe(1)
            ->and((float) $snapshot['source_rate'])->toBe(22.5)
            ->and($snapshot['target_currency'])->toBe('EUR')
            ->and((float) $snapshot['target_rate'])->toBe(25.125)
            ->and($snapshot['rate_date'])->toBe('2026-10-15');
    });

    it('refuses the issue naming the currency when a list is missing and consumes nothing', function (): void {
        $client = InvoiceFixtures::client();
        $project = foreignIssueProject($client, 'Alpha project', '1500');
        foreignIssueEntry($project, '2026-09-10', 2);

        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.125');
        $draft = foreignIssueDraft($client, [$project]);

        // The rate of the line is now in a currency without any list (the rows of a draft are still writable).
        app(PartnerContext::class)->runAsSystem(static fn (): int => InvoiceItemSource::query()
            ->where('invoice_id', $draft->id)
            ->update(['source_rate_currency' => 'GBP']));

        $sequences = foreignIssueSequences();

        try {
            app(IssueDocument::class)->handle($this->admin, $draft);
            $this->fail('The issue had to be refused.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('cnb_missing')
                ->and($exception->errors()['cnb_missing'][0])->toContain('GBP')->toContain('14. 10. 2026');
        }

        expect(foreignIssueStored($draft)->state->value)->toBe('draft')
            ->and(foreignIssueSequences())->toBe($sequences);
    });
});
