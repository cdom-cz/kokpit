<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Clients\Models\Client;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Actions\UpdateDraft;
use App\Domain\Invoicing\Actions\UpdateInternalNote;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;

/**
 * A full set of billing documents for two fictional clients, built through the domain Actions (public repository,
 * fictional data only): for each client a draft, an issued invoice made from tracked time, an issued invoice that
 * nobody paid, a fully paid proforma, a cancelled invoice with its storno credit note and an invoice in EUR.
 *
 * Every secret the Partner must never see carries a canary assembled at runtime from fragments: the internal note of
 * the draft and of the invoice, the note of the payment, the tracked time behind the draft and the invoice (its
 * description, the project name of the draft and the durations), and the CNB rate and the CZK total of the EUR
 * invoice. A test then proves that none of them reaches a Partner surface and that the Admin sees them.
 *
 * The tracked time is September 2026 and the documents are issued on the day the clock stands on, so a test sets the
 * clock (to October 2026) before the call and may move it afterwards to make a document overdue. The clients must be
 * built with {@see InvoiceFixtures::client()} (a complete billing address) and the supplier configured with
 * {@see InvoiceFixtures::configureSupplier()}. The call leaves the test acting as the Admin.
 */
final class InvoiceScenario
{
    /** The CNB rate of the EUR list, shared by both clients. */
    public const string EUR_RATE = '24.317';

    /** Durations of the two blocks of tracked time, the invoice and the draft, as an H:MM text. */
    public const string INVOICE_DURATION = '2:17';

    public const string DRAFT_DURATION = '1:13';

    /**
     * The documents and canaries of the two clients.
     *
     * @return array{a: array<string, mixed>, b: array<string, mixed>}
     */
    public static function forClients(string $clientA, string $clientB): array
    {
        $admin = Canary::admin();
        test()->actingAs($admin);

        self::eurList();

        return [
            'a' => self::forClient($admin, $clientA, 'a', '100.00'),
            'b' => self::forClient($admin, $clientB, 'b', '200.00'),
        ];
    }

    /**
     * The documents of one client. The keys are `draft`, `invoice` (from time, partly paid, with an internal note),
     * `unpaid`, `proforma` (fully paid), `cancelled`, `credit_note` (the storno of `cancelled`), `eur`, and
     * `canaries` with `draft_note`, `draft_time`, `draft_project`, `invoice_note`, `invoice_time`, `payment_note`,
     * `cnb` (the rate and the CZK total of the EUR invoice as texts), `durations` and `lines` (the printed line texts of
     * the issued documents, which belong to this client alone).
     *
     * @return array<string, mixed>
     */
    private static function forClient(User $admin, string $clientId, string $label, string $eurAmount): array
    {
        $client = app(PartnerContext::class)->runAsSystem(static fn (): Client => Client::query()->findOrFail($clientId));

        $canaries = [
            'draft_note' => Canary::canary('draft_note_'.$label),
            'draft_time' => Canary::canary('draft_time_'.$label),
            'draft_project' => Canary::canary('draft_project_'.$label),
            'invoice_note' => Canary::canary('invoice_note_'.$label),
            'invoice_time' => Canary::canary('invoice_time_'.$label),
            'payment_note' => Canary::canary('payment_note_'.$label),
            'durations' => [self::INVOICE_DURATION, self::DRAFT_DURATION],
            // The printed lines of the issued documents: texts that belong to this client alone.
            'lines' => ['Scenario project '.$label, 'Unpaid work '.$label, 'Advance '.$label, 'Cancelled work '.$label, 'Euro work '.$label],
        ];

        $invoiceProject = self::project($client, 'Scenario project '.$label);
        $draftProject = self::project($client, $canaries['draft_project']);

        self::entry($admin, $invoiceProject, '2026-09-10 08:00:00', '2026-09-10 10:17:00', $canaries['invoice_time']);
        self::entry($admin, $draftProject, '2026-09-11 08:00:00', '2026-09-11 09:13:00', $canaries['draft_time']);

        $invoice = app(IssueDocument::class)->handle($admin, self::timeDraft($admin, $client, $invoiceProject));
        app(UpdateInternalNote::class)->handle($admin, $invoice, $canaries['invoice_note']);
        app(RecordPayment::class)->handle($admin, $invoice, ['amount' => '1000.00', 'paid_on' => '2026-10-14', 'note' => $canaries['payment_note']]);

        $draft = self::timeDraft($admin, $client, $draftProject);
        app(UpdateInternalNote::class)->handle($admin, $draft, $canaries['draft_note']);

        $unpaid = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Unpaid work '.$label, '1', '4000.00')]);

        $proforma = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Advance '.$label, '1', '2500.00')], InvoiceKind::Proforma);
        app(RecordPayment::class)->handle($admin, $proforma, ['amount' => '2500.00', 'paid_on' => '2026-10-14']);

        $cancelled = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Cancelled work '.$label, '1', '3000.00')]);
        $creditNote = app(IssueCreditNote::class)->storno($admin, $cancelled);

        $eur = self::eurInvoice($admin, $client, $label, $eurAmount);

        $czk = number_format((float) $eurAmount * (float) self::EUR_RATE, 2, ',', ' ');
        $canaries['cnb'] = [self::EUR_RATE, str_replace('.', ',', self::EUR_RATE), $czk, str_replace(' ', "\u{a0}", $czk), (string) (int) round((float) $eurAmount * (float) self::EUR_RATE * 100)];

        return [
            'client' => $client,
            'draft' => $draft,
            'invoice' => $invoice,
            'unpaid' => $unpaid,
            'proforma' => $proforma,
            'cancelled' => $cancelled,
            'credit_note' => $creditNote,
            'eur' => $eur,
            'canaries' => $canaries,
        ];
    }

    /**
     * The EUR list of the day the clock stands on, once.
     */
    private static function eurList(): void
    {
        $day = CarbonImmutable::now(FilamentTimezone::get())->format('Y-m-d');

        $exists = app(PartnerContext::class)->runAsSystem(static fn (): bool => ExchangeRate::query()->where('rate_date', $day)->where('currency', 'EUR')->exists());

        if (! $exists) {
            InvoiceFixtures::cnbRate('EUR', $day, self::EUR_RATE);
        }
    }

    private static function project(Client $client, string $name): Project
    {
        return app(CreateProject::class)->handle($client, [
            'name' => $name,
            'key' => Canary::projectKey(),
            'billing_type' => 'hourly',
            'hourly_rate' => '1000',
        ]);
    }

    private static function entry(User $admin, Project $project, string $start, string $end, string $description): TimeEntry
    {
        $factory = TimeEntry::factory()->forProject($project);

        return app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($factory->create([
            'user_id' => $admin->id,
            'started_at' => CarbonImmutable::parse($start, 'UTC'),
            'ended_at' => CarbonImmutable::parse($end, 'UTC'),
            'description' => $description,
        ])->id));
    }

    /**
     * A draft of the September time of one project.
     */
    private static function timeDraft(User $admin, Client $client, Project $project): Invoice
    {
        return app(CreateDraft::class)->handle($admin, [
            'client_id' => $client->id,
            'kind' => 'invoice',
            'source' => 'time',
            'period_from' => '2026-09-01',
            'period_to' => '2026-09-30',
            'project_ids' => [$project->id],
            'without_project' => false,
            'grouping' => 'project',
        ]);
    }

    private static function eurInvoice(User $admin, Client $client, string $label, string $amount): Invoice
    {
        $draft = app(CreateDraft::class)->handle($admin, ['client_id' => $client->id, 'kind' => 'invoice', 'currency' => 'EUR']);
        $draft = app(UpdateDraft::class)->handle($admin, $draft, ['items' => [InvoiceFixtures::item('Euro work '.$label, '1', $amount)]]);

        return app(IssueDocument::class)->handle($admin, $draft);
    }
}
