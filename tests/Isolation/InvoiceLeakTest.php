<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\ConvertProforma;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\DeletePayment;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\LockCashReceipt;
use App\Domain\Invoicing\Actions\LockInvoice;
use App\Domain\Invoicing\Actions\QueueInvoiceEmail;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Actions\UnlockInvoice;
use App\Domain\Invoicing\Actions\UpdateDraft;
use App\Domain\Invoicing\Actions\UpdateIssuedDocument;
use App\Domain\Invoicing\Enums\InvoiceEmailState;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Jobs\SendInvoiceEmail;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Queries\ReceivablesReader;
use App\Domain\Invoicing\Support\InvoicePdfUrl;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Support\PayToken;
use App\Domain\Payments\Support\PayUrl;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Partner\Resources\PartnerInvoiceResource;
use App\Filament\Partner\Resources\PartnerInvoiceResource\Pages\ListPartnerInvoices;
use App\Filament\Partner\Resources\PartnerInvoiceResource\Pages\ViewPartnerInvoice;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ClientResource\Pages\ViewClient;
use App\Filament\Resources\ClientResource\RelationManagers\ClientInvoicesRelationManager;
use App\Filament\Resources\ClientResource\Widgets\ClientOverviewStats;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Pages\EditInvoice;
use App\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Resources\InvoiceResource\RelationManagers\InvoiceEmailsRelationManager;
use App\Filament\Resources\InvoiceResource\RelationManagers\InvoiceEntriesRelationManager;
use App\Filament\Resources\InvoiceResource\RelationManagers\InvoicePaymentsRelationManager;
use App\Filament\Resources\TransactionResource;
use App\Filament\Widgets\UnpaidInvoices;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\DatabaseNotifications;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\FictionalIban;
use Tests\Support\InvoiceFixtures;
use Tests\Support\InvoiceScenario;
use Tests\Support\PdfText;

/*
 * Roadmap Phase 10 success criterion 5 (part), threats T-10-69 and T-10-91: a Partner who opens every surface of the
 * invoicing that reaches them meets only the issued documents of the own client, as they print. The scenario gives
 * each of two clients a draft, an invoice from tracked time, an unpaid invoice, a paid proforma, a cancelled invoice
 * with its credit note and a EUR invoice, and every secret carries a canary: the internal note of the draft and of the
 * invoice, the note of a payment, the tracked time and its project, and the CNB rate and CZK total of the EUR invoice.
 * The list and every detail page are matched as plain text, the Livewire snapshots included (decoded, so a unicode
 * escape cannot hide a string), against the strings of the UI-SPEC "Never on a Partner surface" list. Every refusal
 * below is paired with a positive check on the Admin side, so the sweep cannot pass vacuously. Every value is fictional
 * and assembled at runtime from fragments.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));

    $this->clientA = InvoiceFixtures::client();
    $this->clientB = InvoiceFixtures::client();

    $scenario = InvoiceScenario::forClients($this->clientA->id, $this->clientB->id);
    $this->a = $scenario['a'];
    $this->b = $scenario['b'];
    $this->admin = Canary::admin();
    $this->partnerA = Canary::partnerFor($this->clientA->id);

    // The documents of client A a Partner of A may open, and every document that must stay unseen.
    $this->ownKeys = ['invoice', 'unpaid', 'proforma', 'cancelled', 'credit_note', 'eur'];
    $this->foreign = [
        $this->a['draft'],
        $this->b['draft'], $this->b['invoice'], $this->b['unpaid'], $this->b['proforma'], $this->b['cancelled'], $this->b['credit_note'], $this->b['eur'],
    ];
});

/**
 * The strings of the UI-SPEC "Never on a Partner surface" list (Koncept to Další akce): drafts, the internal note,
 * payments, linked time and durations, rates, CNB data, income and transactions, the e-mail log, the work lists of the
 * Admin and every Admin action.
 *
 * @return list<string>
 */
function invoiceLeakForbidden(): array
{
    return [
        'Koncept', 'Koncepty', 'Interní poznámka', 'Platby', 'Navázaný čas', 'Záznamy času', 'Odpracováno', 'Nevyfakturováno',
        'Sazba', 'Kurz ČNB', 'Příjem', 'Transakce', 'Kategorie příjmu', 'Odeslání', 'Zálohy k vyfakturování',
        'Zaznamenat platbu', 'Smazat platbu', 'Stornovat', 'Další akce',
    ];
}

/**
 * Every canary, figure and name of one client's documents that no Partner of the other client may meet, as the
 * strings to look for.
 *
 * @param  array<string, mixed>  $side  one entry of the scenario
 * @return list<string>
 */
function invoiceLeakValues(array $side): array
{
    $values = [];

    foreach ($side['canaries'] as $key => $canary) {
        if ($key === 'durations') {
            continue;
        }

        array_push($values, ...(array) $canary);
    }

    foreach (['draft', 'invoice', 'unpaid', 'proforma', 'cancelled', 'credit_note', 'eur'] as $key) {
        // The numbers are not looked for: a credit note and an invoice of two clients may share a number text.
        $values[] = $side[$key]->id;
    }

    $values[] = $side['client']->id;
    $values[] = $side['client']->name;

    return $values;
}

/**
 * The text a browser or a script could read from a response: the HTML, and the decoded Livewire snapshots written
 * with unescaped unicode, so a `í` cannot hide a string.
 */
function invoiceLeakSurface(string $html): string
{
    $surface = $html;

    if (preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches) > 0) {
        foreach ($matches[1] as $snapshot) {
            $decoded = json_decode(html_entity_decode($snapshot, ENT_QUOTES), true);

            if (is_array($decoded)) {
                $surface .= "\n".json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
    }

    return $surface;
}

/**
 * The spellings in which a string can sit in a response: as written, JSON-escaped and HTML-escaped.
 *
 * @return list<string>
 */
function invoiceLeakForms(string $text): array
{
    return array_values(array_unique([$text, trim((string) json_encode($text), '"'), e($text)]));
}

/**
 * Asserts that a Partner response holds none of the forbidden strings, none of the given values and no duration of
 * tracked time.
 *
 * @param  list<string>  $values
 */
function invoiceLeakAssertClean(string $html, string $where, array $values): void
{
    $surface = invoiceLeakSurface($html);

    foreach ([...invoiceLeakForbidden(), ...$values] as $text) {
        foreach (invoiceLeakForms($text) as $form) {
            expect(str_contains($surface, $form))->toBeFalse("{$where} must not hold \"{$text}\"");
        }
    }

    foreach ([InvoiceScenario::INVOICE_DURATION, InvoiceScenario::DRAFT_DURATION] as $duration) {
        expect(preg_match('/(?<![\d:])'.preg_quote($duration, '/').'(?![\d:])/', $surface))->toBe(0, "{$where} must not hold the duration {$duration}");
    }
}

/**
 * The values no Partner of client A may meet: everything of the draft and the secrets of client A, and everything
 * of client B.
 *
 * @return list<string>
 */
function invoiceLeakHidden(): array
{
    $a = test()->a;

    return [
        ...invoiceLeakValues(test()->b),
        $a['canaries']['draft_note'], $a['canaries']['draft_time'], $a['canaries']['draft_project'],
        $a['canaries']['invoice_note'], $a['canaries']['invoice_time'], $a['canaries']['payment_note'],
        ...$a['canaries']['cnb'],
        $a['draft']->id,
    ];
}

/**
 * Runs the callback as a system run (all rows visible).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function invoiceLeakSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

describe('proves the fixtures exist: the Admin sees what the Partner does not', function (): void {
    it('lists the drafts and every document of both clients for the Admin, with the draft tab the Partner never gets', function (): void {
        $this->actingAs($this->admin);

        $list = (string) $this->get(InvoiceResource::getUrl())->assertOk()->getContent();

        expect($list)->toContain('Koncepty');

        Livewire::test(ListInvoices::class)
            ->assertCanSeeTableRecords([$this->a['draft'], $this->a['invoice'], $this->a['eur'], $this->b['draft'], $this->b['invoice'], $this->b['credit_note']]);
    });

    it('shows the Admin the internal note of the draft in its editor and the linked time of the invoice', function (): void {
        $this->actingAs($this->admin);

        $this->get(InvoiceResource::getUrl('edit', ['record' => $this->a['draft']]))
            ->assertOk()
            ->assertSee($this->a['canaries']['draft_note'])
            ->assertSee('Interní poznámka');

        Livewire::test(InvoiceEntriesRelationManager::class, ['ownerRecord' => $this->a['invoice'], 'pageClass' => ViewInvoice::class])
            ->assertSee($this->a['canaries']['invoice_time']);
    });

    it('lets the Admin read the internal note, the payment note, the linked time and the CNB figures of the documents', function (): void {
        $this->actingAs($this->admin);

        $note = InvoiceAdmin::query()->where('invoice_id', $this->a['invoice']->id)->value('internal_note');
        $draftNote = InvoiceAdmin::query()->where('invoice_id', $this->a['draft']->id)->value('internal_note');
        $payments = InvoicePayment::query()->where('invoice_id', $this->a['invoice']->id)->pluck('note')->all();
        $entries = TimeEntry::query()->where('invoice_id', $this->a['invoice']->id)->pluck('description')->all();
        $cnb = InvoiceAdmin::query()->where('invoice_id', $this->a['eur']->id)->first();

        expect($note)->toBe($this->a['canaries']['invoice_note'])
            ->and($draftNote)->toBe($this->a['canaries']['draft_note'])
            ->and($payments)->toBe([$this->a['canaries']['payment_note']])
            ->and($entries)->toBe([$this->a['canaries']['invoice_time']])
            ->and($cnb?->exchange_rate)->not->toBeNull()
            ->and($cnb?->czk_total_minor)->not->toBeNull();
    });
});

describe('what a Partner can read of the data', function (): void {
    it('reads no draft, no Admin-only row, no payment and no tracked time of the own documents, and nothing of client B', function (): void {
        $this->actingAs($this->partnerA);

        $ids = Invoice::query()->pluck('id')->all();
        sort($ids);
        $own = array_map(fn (string $key): string => $this->a[$key]->id, $this->ownKeys);
        sort($own);

        expect($ids)->toBe($own)
            ->and(InvoiceAdmin::query()->count())->toBe(0)
            ->and(InvoicePayment::query()->count())->toBe(0)
            ->and(InvoiceEmail::query()->count())->toBe(0)
            ->and(TimeEntry::query()->count())->toBe(0);

        $invoice = Invoice::query()->whereKey($this->a['invoice']->id)->firstOrFail();

        expect($invoice->admin)->toBeNull()
            ->and($invoice->payments->all())->toBe([])
            ->and($invoice->entries->all())->toBe([])
            ->and($invoice->emails->all())->toBe([]);
    });
});

describe('what a Partner sees', function (): void {
    it('keeps the list of the own documents free of every forbidden string, canary and figure', function (): void {
        $this->actingAs($this->partnerA);

        $html = (string) $this->get(PartnerInvoiceResource::getUrl())->assertOk()->assertSee($this->a['invoice']->number)->getContent();

        invoiceLeakAssertClean($html, 'the Partner invoice list', invoiceLeakHidden());

        $livewire = Livewire::test(ListPartnerInvoices::class)->assertCanSeeTableRecords(array_map(fn (string $key): Invoice => $this->a[$key], $this->ownKeys));

        invoiceLeakAssertClean($livewire->html(), 'the Partner invoice list component', invoiceLeakHidden());
    });

    it('keeps every detail page of the own documents free of every forbidden string, canary and figure', function (): void {
        $this->actingAs($this->partnerA);

        foreach ($this->ownKeys as $key) {
            $document = $this->a[$key];
            $html = (string) $this->get(PartnerInvoiceResource::getUrl('view', ['record' => $document]))->assertOk()->assertSee($document->number)->getContent();

            invoiceLeakAssertClean($html, "the Partner detail of {$key}", invoiceLeakHidden());
        }
    });

    it('keeps the overdue detail pages clean as well, with the callout and the amounts owed', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:30:00', 'Europe/Prague'));
        $this->actingAs($this->partnerA);

        foreach (['invoice', 'unpaid', 'eur'] as $key) {
            $html = (string) $this->get(PartnerInvoiceResource::getUrl('view', ['record' => $this->a[$key]]))
                ->assertOk()
                ->assertSee('Faktura je po splatnosti o 8 dní')
                ->getContent();

            invoiceLeakAssertClean($html, "the overdue Partner detail of {$key}", invoiceLeakHidden());
        }

        invoiceLeakAssertClean((string) $this->get(PartnerInvoiceResource::getUrl())->assertOk()->getContent(), 'the overdue Partner list', invoiceLeakHidden());
    });

    it('keeps the other pages a Partner can open free of the invoicing strings and of both clients\' secrets', function (): void {
        $this->actingAs($this->partnerA);

        foreach (['/admin', '/admin/my-projects', '/admin/my-tasks', '/admin/profile'] as $url) {
            $response = $this->get($url);

            expect($response->getStatusCode())->toBeIn([200, 302, 403]);

            invoiceLeakAssertClean((string) $response->getContent(), $url, invoiceLeakHidden());
        }
    });

    it('refuses every Admin invoice route and component, and leaks nothing in the refusal', function (): void {
        $this->actingAs($this->partnerA);

        $urls = [
            '/admin/invoices',
            '/admin/invoices/'.$this->a['invoice']->id,
            '/admin/invoices/'.$this->a['draft']->id.'/edit',
            '/admin/invoices/'.$this->b['invoice']->id,
            '/admin/invoices/'.$this->b['draft']->id.'/edit',
        ];

        foreach ($urls as $url) {
            $response = $this->get($url);
            $response->assertForbidden();

            invoiceLeakAssertClean((string) $response->getContent(), $url, invoiceLeakHidden());
        }

        Livewire::test(ListInvoices::class)->assertForbidden();
    });

    it('answers 404 to the detail of a draft and of the other client\'s document, naming nothing', function (): void {
        $this->actingAs($this->partnerA);

        foreach ($this->foreign as $document) {
            $response = $this->get(PartnerInvoiceResource::getUrl('view', ['record' => $document->id]));

            expect($response->getStatusCode())->toBe(404);

            invoiceLeakAssertClean((string) $response->getContent(), '404 of '.$document->id, invoiceLeakHidden());
        }
    });
});

describe('bell and search', function (): void {
    it('tells the Admin of a failed e-mail of an invoice and a Partner of the client nothing', function (): void {
        Queue::fake();
        $this->actingAs($this->admin);
        $invoice = $this->a['invoice'];
        $defaults = app(QueueInvoiceEmail::class)->defaults($invoice);
        $row = app(QueueInvoiceEmail::class)->handle($this->admin, $invoice, [
            'recipient' => (string) $defaults['recipient'],
            'subject' => $defaults['subject'],
            'body' => $defaults['body'],
            'attach_work_report' => false,
        ]);

        (new SendInvoiceEmail($row->id))->failed(new TransportException('relay refused'));

        expect(invoiceLeakSystem(static fn (): string => InvoiceEmail::query()->findOrFail($row->id)->state->value))->toBe('failed');

        $adminNotices = (string) json_encode($this->admin->notifications()->get()->pluck('data'));

        expect($adminNotices)->toContain($invoice->number);

        $this->actingAs($this->partnerA);

        $encoded = (string) json_encode(User::query()->whereKey($this->partnerA->id)->firstOrFail()->notifications()->get()->pluck('data'));
        $bell = Livewire::test(DatabaseNotifications::class)->html();

        expect($this->partnerA->notifications()->count())->toBe(0)
            ->and($encoded)->not->toContain($invoice->number)
            ->and($bell)->not->toContain($invoice->number)
            ->and($bell)->not->toContain(__('invoices.email.notice.title'));
    });

    it('finds no document for a Partner in the global search, by number, by canary or by client', function (): void {
        $this->actingAs($this->partnerA);

        $terms = [
            $this->a['invoice']->number, $this->a['credit_note']->number, $this->b['invoice']->number,
            $this->a['canaries']['invoice_note'], $this->a['canaries']['draft_note'], $this->b['canaries']['invoice_note'],
            $this->clientA->name,
        ];

        foreach ($terms as $term) {
            $categories = Filament::getCurrentOrDefaultPanel()->getGlobalSearchProvider()?->getResults($term)?->getCategories();

            expect($categories === null || $categories->isEmpty())->toBeTrue("global search for {$term}");
        }

        // The panel's search is opt-in per resource: neither invoice resource opts in.
        expect(PartnerInvoiceResource::canGloballySearch())->toBeFalse()
            ->and(InvoiceResource::canGloballySearch())->toBeFalse();
    });

    it('lists no document activity for a Partner: the history of the documents is the Admin\'s', function (): void {
        $this->actingAs($this->partnerA);

        $this->get('/admin/activity')->assertForbidden();
    });
});

describe('the Admin invoicing surfaces, each refused to a Partner and open to the Admin', function (): void {
    it('refuses the transactions screen and the income of a payment to a Partner, and shows them to the Admin', function (): void {
        $income = invoiceLeakSystem(fn (): Transaction => Transaction::query()->where('invoice_id', $this->a['invoice']->id)->firstOrFail());

        $this->actingAs($this->admin);
        $this->get(TransactionResource::getUrl('index'))->assertOk();
        $this->get(TransactionResource::getUrl('edit', ['record' => $income]))->assertOk();

        $this->actingAs($this->partnerA);

        foreach ([TransactionResource::getUrl('index'), TransactionResource::getUrl('edit', ['record' => $income]), TransactionResource::getUrl('create')] as $url) {
            $response = $this->get($url);
            $response->assertForbidden();

            invoiceLeakAssertClean((string) $response->getContent(), $url, invoiceLeakHidden());
        }
    });

    it('refuses every relation manager of an invoice and the client panels, and the dashboard card, to a Partner', function (): void {
        $invoice = $this->a['invoice'];
        $client = $this->clientA;
        $managers = [InvoiceEntriesRelationManager::class, InvoicePaymentsRelationManager::class, InvoiceEmailsRelationManager::class];

        $this->actingAs($this->admin);

        foreach ($managers as $manager) {
            Livewire::test($manager, ['ownerRecord' => $invoice, 'pageClass' => ViewInvoice::class])->assertSuccessful();
        }

        Livewire::test(ClientInvoicesRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])->assertSuccessful();
        Livewire::test(ClientOverviewStats::class, ['record' => $client])->assertSuccessful();
        Livewire::test(UnpaidInvoices::class)->assertSuccessful();
        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])->assertSuccessful();

        $this->actingAs($this->partnerA);

        foreach ($managers as $manager) {
            Livewire::test($manager, ['ownerRecord' => $invoice, 'pageClass' => ViewInvoice::class])->assertForbidden();
        }

        Livewire::test(ClientInvoicesRelationManager::class, ['ownerRecord' => $client, 'pageClass' => ViewClient::class])->assertForbidden();
        Livewire::test(ClientOverviewStats::class, ['record' => $client])->assertForbidden();
        Livewire::test(UnpaidInvoices::class)->assertForbidden();
        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])->assertForbidden();

        $this->get(ClientResource::getUrl('view', ['record' => $client->id]))->assertForbidden();
    });

    it('refuses the Admin creation, edit and activity routes of the invoicing to a Partner, with an Admin positive on each', function (): void {
        $draft = $this->a['draft'];
        $urls = [
            InvoiceResource::getUrl('index'),
            InvoiceResource::getUrl('view', ['record' => $this->a['invoice']]),
            InvoiceResource::getUrl('edit', ['record' => $draft]),
            '/admin/activity',
        ];

        $this->actingAs($this->admin);

        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }

        $this->actingAs($this->partnerA);

        foreach ($urls as $url) {
            $this->get($url)->assertForbidden();
        }
    });

    it('lets a Partner meet no invoice number, amount or note on the dashboard, and nothing in the bell or the activity views', function (): void {
        $this->actingAs($this->admin);

        $adminDashboard = (string) $this->get('/admin')->assertOk()->getContent();

        expect($adminDashboard)->toContain('Nezaplacené faktury');

        $this->actingAs($this->partnerA);

        $dashboard = invoiceLeakSurface((string) $this->get('/admin')->assertOk()->getContent());

        foreach (['invoice', 'unpaid', 'proforma', 'cancelled', 'credit_note', 'eur'] as $key) {
            expect($dashboard)->not->toContain($this->a[$key]->number);
        }

        foreach ([Money::ofMinor($this->a['unpaid']->total_minor, 'CZK')->format('cs'), $this->a['canaries']['invoice_note'], $this->a['canaries']['payment_note']] as $text) {
            expect($dashboard)->not->toContain($text);
        }

        expect($this->partnerA->notifications()->count())->toBe(0)
            ->and(Livewire::test(DatabaseNotifications::class)->html())->not->toContain($this->a['invoice']->number);
    });
});

/**
 * One finished block of tracked time on a project, as the Admin's own time.
 */
function invoiceFlowEntry(object $project, string $day, string $from, string $to, string $description): TimeEntry
{
    $factory = TimeEntry::factory()->forProject($project);

    return invoiceLeakSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($factory->create([
        'user_id' => test()->admin->id,
        'started_at' => CarbonImmutable::parse($day.' '.$from, 'UTC'),
        'ended_at' => CarbonImmutable::parse($day.' '.$to, 'UTC'),
        'description' => $description,
    ])->id));
}

/**
 * A time draft of one project and September.
 */
function invoiceFlowDraft(User $admin, Client $client, string $projectId, string $kind): Invoice
{
    return app(CreateDraft::class)->handle($admin, [
        'client_id' => $client->id,
        'kind' => $kind,
        'source' => 'time',
        'period_from' => '2026-09-01',
        'period_to' => '2026-09-30',
        'project_ids' => [$projectId],
        'without_project' => false,
        'grouping' => 'project',
    ]);
}

/**
 * The stored row of a time entry, read as a system run.
 */
function invoiceFlowFresh(TimeEntry $entry): TimeEntry
{
    return invoiceLeakSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($entry->id));
}

describe('the whole phase, end to end', function (): void {
    it('turns tracked time into a paid, converted and cancelled set of documents and shows the Partner of the client only the issued result', function (): void {
        Mail::getSymfonyTransport()->flush();
        $admin = $this->admin;
        $this->actingAs($admin);

        $client = InvoiceFixtures::client(['invoice_email' => 'billing@example.com']);
        $secrets = [Canary::canary('flow_note'), Canary::canary('flow_time'), Canary::canary('flow_payment'), Canary::canary('flow_mistake')];

        $work = app(CreateProject::class)->handle($client, ['name' => 'Flow work project', 'key' => Canary::projectKey(), 'billing_type' => 'hourly', 'hourly_rate' => '1000']);
        $advance = app(CreateProject::class)->handle($client, ['name' => 'Flow advance project', 'key' => Canary::projectKey(), 'billing_type' => 'hourly', 'hourly_rate' => '1000']);

        $workEntries = [
            invoiceFlowEntry($work, '2026-09-10', '08:00:00', '10:00:00', $secrets[1]),
            invoiceFlowEntry($work, '2026-09-11', '08:00:00', '09:30:00', $secrets[1]),
        ];
        $advanceEntry = invoiceFlowEntry($advance, '2026-09-15', '08:00:00', '10:00:00', $secrets[1]);

        // 1. A draft from time, a manual line added, issued with its PDF.
        $draft = invoiceFlowDraft($admin, $client, $work->id, 'invoice');
        $lines = $draft->items()->orderBy('position')->get()->map(static fn ($line): array => [
            'id' => $line->id,
            'description' => $line->description,
            'quantity' => $line->quantity,
            'unit' => $line->unit,
            'unit_price' => Money::ofMinor($line->unit_price_minor, 'CZK')->toMajor(),
        ])->all();
        $draft = app(UpdateDraft::class)->handle($admin, $draft, ['internal_note' => $secrets[0], 'items' => [...$lines, InvoiceFixtures::item('Flow consulting', '1', '500.00', 'pcs')]]);

        expect($draft->total_minor)->toBe(400000);

        $invoice = app(IssueDocument::class)->handle($admin, $draft);

        expect($invoice->isIssued())->toBeTrue()
            ->and($invoice->number)->not->toBeEmpty()
            ->and(invoiceLeakSystem(static fn (): ?InvoiceAdmin => InvoiceAdmin::query()->where('invoice_id', $invoice->id)->first())?->pdfDocument)->not->toBeNull();

        foreach ($workEntries as $entry) {
            expect(invoiceFlowFresh($entry)->billing_state)->toBe(BillingState::Billed)
                ->and(invoiceFlowFresh($entry)->invoice_id)->toBe($invoice->id);
        }

        // 2. The e-mail is queued and sent once, with the PDF.
        $defaults = app(QueueInvoiceEmail::class)->defaults($invoice);
        $mail = app(QueueInvoiceEmail::class)->handle($admin, $invoice, [
            'recipient' => (string) $defaults['recipient'],
            'subject' => $defaults['subject'],
            'body' => $defaults['body'],
            'attach_work_report' => false,
        ]);

        $documentMails = Mail::getSymfonyTransport()->messages()->filter(static function (SentMessage $sent) use ($defaults): bool {
            $original = $sent->getOriginalMessage();

            return $original instanceof Email
                && $original->getTo()[0]->getAddress() === (string) $defaults['recipient']
                && $original->getSubject() === $defaults['subject'];
        });

        expect($documentMails)->toHaveCount(1)
            ->and($documentMails->first())->toBeInstanceOf(SentMessage::class)
            ->and(invoiceLeakSystem(static fn (): InvoiceEmailState => InvoiceEmail::query()->findOrFail($mail->id)->state))->toBe(InvoiceEmailState::Sent);

        // 3. A partial payment, then a mistaken second one that is deleted together with its income.
        $proformaDraft = invoiceFlowDraft($admin, $client, $advance->id, 'proforma');
        $proforma = app(IssueDocument::class)->handle($admin, $proformaDraft);

        $first = app(RecordPayment::class)->handle($admin, $invoice, ['amount' => '1500.00', 'paid_on' => '2026-10-14', 'note' => $secrets[2]]);
        $mistake = app(RecordPayment::class)->handle($admin, $invoice, ['amount' => '1000.00', 'paid_on' => '2026-10-14', 'note' => $secrets[3]]);

        expect(Transaction::query()->where('invoice_id', $invoice->id)->count())->toBe(2);

        app(DeletePayment::class)->handle($admin, $mistake);

        expect(Transaction::query()->where('invoice_id', $invoice->id)->count())->toBe(1)
            ->and(Transaction::query()->where('invoice_payment_id', $mistake->id)->exists())->toBeFalse()
            ->and(InvoicePayment::query()->where('invoice_id', $invoice->id)->pluck('id')->all())->toBe([$first->id]);

        // 4. The due date passes: the invoice is overdue in the reader and on the dashboard card.
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:30:00', 'Europe/Prague'));

        $status = app(ReceivablesReader::class)->statuses([Invoice::query()->findOrFail($invoice->id)], ReceivablesReader::today())[$invoice->id];

        expect($status['status'])->toBe(InvoiceStatus::Overdue)
            ->and($status['overdue_days'])->toBe(8)
            ->and($status['remaining']?->minor)->toBe(250000)
            ->and(app(ReceivablesReader::class)->summary(ReceivablesReader::today())['overdue']['count'])->toBeGreaterThan(0);

        Livewire::test(UnpaidInvoices::class)->assertSee('Po splatnosti');

        // 5. The rest is paid: nothing is owed and the income sits in the system category.
        $rest = app(RecordPayment::class)->handle($admin, $invoice, ['amount' => '2500.00', 'paid_on' => '2026-11-05']);
        $status = app(ReceivablesReader::class)->statuses([Invoice::query()->findOrFail($invoice->id)], ReceivablesReader::today())[$invoice->id];
        $income = Transaction::query()->where('invoice_payment_id', $rest->id)->sole();

        expect($status['status'])->toBe(InvoiceStatus::Paid)
            ->and($income->amount_minor)->toBe(250000)
            ->and($income->transaction_date->toDateString())->toBe('2026-11-05')
            ->and($income->isPaymentIncome())->toBeTrue();

        // 6. The proforma is paid (no income yet) and converted: an invoice, its income at the payment date, time moved.
        app(RecordPayment::class)->handle($admin, $proforma, ['amount' => '2000.00', 'paid_on' => '2026-11-04']);

        expect(Transaction::query()->where('invoice_id', $proforma->id)->count())->toBe(0);

        $converted = app(ConvertProforma::class)->handle($admin, $proforma);
        $carried = Transaction::query()->where('invoice_id', $converted->id)->sole();

        expect($converted->isIssued())->toBeTrue()
            ->and($converted->proforma_id)->toBe($proforma->id)
            ->and($carried->amount_minor)->toBe(200000)
            ->and($carried->transaction_date->toDateString())->toBe('2026-11-04')
            ->and(invoiceFlowFresh($advanceEntry)->invoice_id)->toBe($converted->id)
            ->and(invoiceFlowFresh($advanceEntry)->billing_state)->toBe(BillingState::Billed);

        // 7. The first invoice is cancelled: its time is unbilled again and a credit note is issued.
        $credit = app(IssueCreditNote::class)->storno($admin, $invoice);

        expect($credit->isIssued())->toBeTrue()
            ->and($credit->credited_invoice_id)->toBe($invoice->id)
            ->and(Invoice::query()->findOrFail($invoice->id)->isCancelled())->toBeTrue();

        foreach ($workEntries as $entry) {
            expect(invoiceFlowFresh($entry)->billing_state)->toBe(BillingState::Unbilled)
                ->and(invoiceFlowFresh($entry)->invoice_id)->toBeNull();
        }

        // A draft that stays a draft, to prove the Partner never meets it.
        $leftover = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Flow leftover draft', '1', '777.00')]);

        // 8. The Partner of the client sees the issued documents in their Partner states, and nothing else.
        $partner = Canary::partnerFor($client->id);
        $this->actingAs($partner);

        $seen = Invoice::query()->where('client_id', $client->id)->pluck('id')->all();
        sort($seen);
        $expected = [$invoice->id, $proforma->id, $converted->id, $credit->id];
        sort($expected);

        expect($seen)->toBe($expected)
            ->and(Invoice::query()->whereKey($leftover->id)->exists())->toBeFalse()
            ->and(InvoicePayment::query()->count())->toBe(0)
            ->and(TimeEntry::query()->count())->toBe(0);

        // The project names are the printed line texts of the issued documents, so they belong on the Partner's page.
        $hidden = [...$secrets, $leftover->id, 'Flow leftover draft'];
        $list = (string) $this->get(PartnerInvoiceResource::getUrl())->assertOk()->getContent();

        invoiceLeakAssertClean($list, 'the flow list', $hidden);

        $states = [$invoice->id => 'Stornováno', $proforma->id => 'Zaplaceno', $converted->id => 'Zaplaceno', $credit->id => 'Dobropis'];

        foreach ([$invoice, $proforma, $converted, $credit] as $document) {
            $html = (string) $this->get(PartnerInvoiceResource::getUrl('view', ['record' => $document]))
                ->assertOk()
                ->assertSee($document->number)
                ->assertSee($states[$document->id])
                ->getContent();

            invoiceLeakAssertClean($html, 'the flow detail of '.$document->number, $hidden);
        }

        $this->get(PartnerInvoiceResource::getUrl('view', ['record' => $leftover->id]))->assertNotFound();

        $component = Livewire::test(ViewPartnerInvoice::class, ['record' => $converted->getKey()])->callAction('downloadPdf');

        expect(parse_url((string) ($component->effects['redirect'] ?? ''), PHP_URL_PATH))->toBe(parse_url(InvoicePdfUrl::download($converted, $partner), PHP_URL_PATH));

        $this->get((string) $component->effects['redirect'])->assertOk()->assertHeader('content-type', 'application/pdf');

        // The same flow's secrets are the Admin's: the Admin reads the note and the payment rows.
        $this->actingAs($admin);

        expect(invoiceLeakSystem(static fn (): ?string => InvoiceAdmin::query()->where('invoice_id', $invoice->id)->value('internal_note')))->toBe($secrets[0])
            ->and(InvoicePayment::query()->where('invoice_id', $invoice->id)->pluck('note')->all())->toContain($secrets[2])->not->toContain($secrets[3]);
    });
});

describe('an invoice that was sent, unlocked and edited (Phase 10.1)', function (): void {
    /**
     * Sends the unpaid invoice of client A, unlocks it with a reason and corrects its line, quantity and due date, as
     * the Admin. The texts are assembled at runtime. The quantity doubles, so the total grows from 4 000 to 8 000.
     *
     * @return array{invoice: Invoice, reason: string, old: string, new: string}
     */
    function invoiceLeakEdit(): array
    {
        $admin = test()->admin;
        $invoice = test()->a['unpaid'];
        $reason = implode(' ', ['Unlock', 'reason', bin2hex(random_bytes(4))]);
        $new = implode(' ', ['Corrected', 'line', bin2hex(random_bytes(4))]);

        test()->actingAs($admin);

        $line = invoiceLeakSystem(static fn (): array => $invoice->items()->orderBy('position')->get()->all())[0];
        $old = $line->description;

        $defaults = app(QueueInvoiceEmail::class)->defaults($invoice);
        app(QueueInvoiceEmail::class)->handle($admin, $invoice, [
            'recipient' => 'invoice@example.com',
            'subject' => $defaults['subject'],
            'body' => $defaults['body'],
            'attach_work_report' => false,
        ]);

        app(UnlockInvoice::class)->handle($admin, $invoice, $reason);

        app(UpdateIssuedDocument::class)->handle($admin, $invoice, [
            'items' => [[
                'id' => $line->id,
                'description' => $new,
                'quantity' => '2',
                'unit' => $line->unit,
                'unit_price' => $line->unitPrice()->toMajor(),
            ]],
            'due_date' => '2026-12-15',
        ]);

        return ['invoice' => $invoice, 'reason' => $reason, 'old' => $old, 'new' => $new];
    }

    it('shows a Partner only the current version: list, detail and PDF, never the reason, the old text or the history', function (): void {
        $edit = invoiceLeakEdit();
        $invoice = $edit['invoice'];
        $total = Money::ofMinor(800_000, 'CZK')->format('cs');
        $hidden = [...invoiceLeakHidden(), $edit['reason']];

        // The Admin side of the pairing: the history holds the reason and the edit, the editor opens.
        $unlocked = Activity::query()->where('subject_id', $invoice->id)->where('event', 'invoice_unlocked')->get();
        $edited = Activity::query()->where('subject_id', $invoice->id)->where('event', 'invoice_edited')->get();

        expect($unlocked)->toHaveCount(1)
            ->and((string) json_encode($unlocked[0]->attribute_changes))->toContain($edit['reason'])
            ->and($edited)->toHaveCount(1);

        Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])->assertSuccessful()->assertNoRedirect();

        // The Partner of the client.
        $this->actingAs($this->partnerA);

        $list = (string) $this->get(PartnerInvoiceResource::getUrl())->assertOk()->assertSee($total)->getContent();
        $detail = (string) $this->get(PartnerInvoiceResource::getUrl('view', ['record' => $invoice]))
            ->assertOk()
            ->assertSee($edit['new'])
            ->assertSee($total)
            ->assertSee('15. 12. 2026')
            ->assertDontSee($edit['old'])
            ->getContent();

        invoiceLeakAssertClean($list, 'the list with an edited invoice', $hidden);
        invoiceLeakAssertClean($detail, 'the detail of an edited invoice', $hidden);

        // The PDF route serves the current PDF.
        $download = Livewire::test(ViewPartnerInvoice::class, ['record' => $invoice->getKey()])->callAction('downloadPdf');
        $response = $this->get((string) $download->effects['redirect'])->assertOk()->assertHeader('content-type', 'application/pdf');
        $text = PdfText::flat($response->streamedContent());

        if ($text !== null) {
            expect($text)->toContain($edit['new'])
                ->and($text)->not->toContain($edit['old'])
                ->and($text)->not->toContain($edit['reason']);
        }

        // Every other page a Partner can open stays clean.
        foreach (['/admin', '/admin/my-projects', '/admin/my-tasks', '/admin/profile'] as $url) {
            invoiceLeakAssertClean((string) $this->get($url)->getContent(), $url, $hidden);
        }
    });

    it('refuses a Partner the editor, the unlock action, the entries tab, the history and both Actions, and tells the bell nothing', function (): void {
        $edit = invoiceLeakEdit();
        $invoice = $edit['invoice'];
        $hidden = [...invoiceLeakHidden(), $edit['reason'], $edit['new']];

        // Admin positives: the unlock action sits on the locked detail and the entries tab opens.
        $locked = $this->a['invoice'];
        $this->actingAs($this->admin);
        app(QueueInvoiceEmail::class)->handle($this->admin, $locked, [
            'recipient' => 'invoice@example.com',
            'subject' => 'Example subject',
            'body' => 'Example body',
            'attach_work_report' => false,
        ]);

        expect(Invoice::query()->whereKey($locked->id)->firstOrFail()->isLocked())->toBeTrue();

        Livewire::test(ViewInvoice::class, ['record' => $locked->getRouteKey()])
            ->assertSuccessful()
            ->assertActionVisible(TestAction::make('unlock')->schemaComponent('locked', schema: 'infolist'));
        Livewire::test(InvoiceEntriesRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewInvoice::class])->assertSuccessful();
        $this->get(InvoiceResource::getUrl('edit', ['record' => $invoice]))->assertOk();

        // The Partner of the client.
        $this->actingAs($this->partnerA);

        foreach ([$invoice, $locked] as $document) {
            $response = $this->get(InvoiceResource::getUrl('edit', ['record' => $document]));
            $response->assertForbidden();

            invoiceLeakAssertClean((string) $response->getContent(), 'the editor address of '.$document->id, $hidden);

            Livewire::test(EditInvoice::class, ['record' => $document->getRouteKey()])->assertForbidden();
            Livewire::test(ViewInvoice::class, ['record' => $document->getRouteKey()])->assertForbidden();
        }

        Livewire::test(InvoiceEntriesRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewInvoice::class])->assertForbidden();

        // The domain refuses as well, whatever the page does.
        $partner = User::query()->whereKey($this->partnerA->id)->firstOrFail();

        expect(fn (): mixed => app(UnlockInvoice::class)->handle($partner, $locked, 'A reason long enough to pass'))->toThrow(AuthorizationException::class)
            ->and(fn (): mixed => app(UpdateIssuedDocument::class)->handle($partner, $invoice, ['note' => 'Partner note']))->toThrow(AuthorizationException::class)
            ->and(Invoice::query()->whereKey($locked->id)->firstOrFail()->isLocked())->toBeTrue()
            ->and(Invoice::query()->whereKey($invoice->id)->firstOrFail()->note)->toBeNull();

        // The history of the edit and of the unlock is the Admin's.
        expect(Activity::query()->where('subject_id', $invoice->id)->count())->toBe(0)
            ->and(Activity::query()->where('event', 'invoice_unlocked')->count())->toBe(0);

        $this->get('/admin/activity')->assertForbidden();

        // The bell of the Partner holds nothing of it.
        $bell = Livewire::test(DatabaseNotifications::class)->html();

        expect($this->partnerA->notifications()->count())->toBe(0)
            ->and($bell)->not->toContain($edit['reason'])
            ->and($bell)->not->toContain($edit['new']);
    });

    it('shows the Partner of another client nothing of an edited invoice', function (): void {
        $edit = invoiceLeakEdit();
        $invoice = $edit['invoice'];
        $partnerB = Canary::partnerFor($this->clientB->id);

        $this->actingAs($partnerB);

        $detail = $this->get(PartnerInvoiceResource::getUrl('view', ['record' => $invoice->id]));

        expect($detail->getStatusCode())->toBe(404);

        invoiceLeakAssertClean((string) $detail->getContent(), 'the 404 of an edited invoice', [$edit['reason'], $edit['new'], $edit['old'], $invoice->id]);

        $list = (string) $this->get(PartnerInvoiceResource::getUrl())->assertOk()->getContent();

        invoiceLeakAssertClean($list, 'the list of another client', [$edit['reason'], $edit['new'], $edit['old'], $invoice->id]);

        $this->get(InvoiceResource::getUrl('edit', ['record' => $invoice]))->assertForbidden();
        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])->assertForbidden();
    });

    it('keeps the pay button of an edited card invoice for the Partner, on the same stable address', function (): void {
        CardPaymentSetup::enable();
        invoiceLeakSystem(fn (): bool => Client::query()->whereKey($this->clientA->id)->update(['online_payment_enabled' => true]) > 0);

        $this->actingAs($this->admin);
        $invoice = InvoiceFixtures::issued(Client::query()->findOrFail($this->clientA->id), [InvoiceFixtures::item('Card work', '1', '1000.00')]);
        $live = static fn (): ?PaymentLink => invoiceLeakSystem(static fn (): ?PaymentLink => PaymentLink::query()->where('invoice_id', $invoice->id)->get()->first(static fn (PaymentLink $link): bool => $link->isLive()));

        expect($live()?->amount_minor)->toBe(100_000);

        $url = PayUrl::forInvoice($invoice);
        $line = invoiceLeakSystem(static fn (): array => $invoice->items()->orderBy('position')->get()->all())[0];

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['items' => [[
            'id' => $line->id,
            'description' => $line->description,
            'quantity' => '3',
            'unit' => $line->unit,
            'unit_price' => $line->unitPrice()->toMajor(),
        ]]]);

        expect($live()?->state)->toBe(PaymentLinkState::Active)
            ->and($live()->amount_minor)->toBe(300_000)
            ->and(PayUrl::forInvoice($invoice))->toBe($url);

        $this->actingAs($this->partnerA);

        Livewire::test(ViewPartnerInvoice::class, ['record' => $invoice->getRouteKey()])
            ->assertSee('Zaplatit kartou')
            ->assertSee(Money::ofMinor(300_000, 'CZK')->format('cs'));
    });
});

describe('the payment method (Phase 14)', function (): void {
    it('shows a Partner the method of an own online document and never the account id, the token or an account number', function (): void {
        $this->actingAs($this->admin);
        $invoice = InvoiceFixtures::issued(Client::query()->findOrFail($this->clientA->id), [InvoiceFixtures::item('Online work', '1', '1000.00')]);
        $accountId = (string) Str::uuid7();
        $token = PayToken::generate();

        // The way the database allows a switch of an open document: the Admin-only row holds the chosen account id, the
        // card switch and the token. No payment link exists, so the Partner is due no pay button.
        invoiceLeakSystem(static function () use ($invoice, $accountId, $token): void {
            DB::update("update invoices set payment_method = 'online', bank_snapshot = null where id = ?", [$invoice->id]);
            DB::update('update invoice_admin set bank_account_id = ?, card_payment_enabled = true, pay_token = ? where invoice_id = ?', [$accountId, $token, $invoice->id]);
        });

        // The Admin side of the pairing: the Admin row really holds what the Partner must not meet.
        $admin = invoiceLeakSystem(static fn (): InvoiceAdmin => InvoiceAdmin::query()->where('invoice_id', $invoice->id)->firstOrFail());
        expect($admin->bank_account_id)->toBe($accountId)
            ->and($admin->pay_token)->toBe($token);

        $this->actingAs($this->partnerA);
        $pages = [
            'the list' => $this->get(PartnerInvoiceResource::getUrl())->assertOk()->getContent(),
            'the detail' => $this->get(PartnerInvoiceResource::getUrl('view', ['record' => $invoice]))->assertOk()->assertSee('Online platba kartou')->getContent(),
        ];
        $ibans = [FictionalIban::czech('12'), FictionalIban::czech('34')];

        foreach ($pages as $where => $html) {
            $surface = invoiceLeakSurface((string) $html);

            foreach ([$accountId, $token, 'bank_account_id', 'pay_token', 'card_payment_enabled', ...$ibans, ...array_map(static fn (string $iban): string => trim(chunk_split($iban, 4, ' ')), $ibans)] as $text) {
                foreach (invoiceLeakForms($text) as $form) {
                    expect(str_contains($surface, $form))->toBeFalse("{$where} must not hold \"{$text}\"");
                }
            }
        }

        expect(invoiceLeakSurface((string) $pages['the detail']))->not->toContain('Bankovní účet')
            ->and(invoiceLeakSurface((string) $pages['the detail']))->not->toContain('Zaplatit kartou');
    });
});

describe('a hand-served client (Phase 16)', function (): void {
    /**
     * Switches the document e-mails of client A off, issues two documents of the client, records a cash payment on each
     * and, as the Admin, locks the first document and its receipt by hand. The second document and its receipt stay open.
     *
     * @return array{locked: Invoice, open: Invoice, lockedReceipt: CashReceipt, openReceipt: CashReceipt}
     */
    function invoiceLeakHandServed(): array
    {
        $admin = test()->admin;
        $clientId = test()->clientA->id;

        Queue::fake();
        invoiceLeakSystem(static fn (): bool => Client::query()->whereKey($clientId)->update(['document_emails_enabled' => false]) > 0);
        test()->actingAs($admin);

        $client = Client::query()->findOrFail($clientId);
        $locked = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Hand locked work', '1', '2000.00')]);
        $open = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Hand open work', '1', '3000.00')]);

        test()->actingAs($admin);

        $lockedPayment = app(RecordPayment::class)->handle($admin, $locked, ['amount' => '100.00', 'paid_on' => '2026-10-14', 'method' => 'cash']);
        $openPayment = app(RecordPayment::class)->handle($admin, $open, ['amount' => '100.00', 'paid_on' => '2026-10-14', 'method' => 'cash']);
        $lockedReceipt = CashReceipt::query()->where('invoice_payment_id', $lockedPayment->id)->sole();
        $openReceipt = CashReceipt::query()->where('invoice_payment_id', $openPayment->id)->sole();

        app(LockInvoice::class)->handle($admin, $locked);
        app(LockCashReceipt::class)->handle($admin, $lockedReceipt);

        return ['locked' => $locked, 'open' => $open, 'lockedReceipt' => $lockedReceipt, 'openReceipt' => $openReceipt];
    }

    it('shows the Partner of the client the own hand-locked invoice with no gray note, no lock action and no manual lock row', function (): void {
        $set = invoiceLeakHandServed();
        $note = __('invoices.detail.banners.email_disabled');

        // The Admin side of the pairing: the note, the unlock and both manual lock rows exist.
        Livewire::test(ViewInvoice::class, ['record' => $set['locked']->getRouteKey()])
            ->assertSee($note)
            ->assertActionVisible(TestAction::make('unlock')->schemaComponent('locked', schema: 'infolist'));

        expect(Activity::query()->where('subject_id', $set['locked']->id)->where('event', 'invoice_locked_manually')->count())->toBe(1)
            ->and(Activity::query()->where('subject_id', $set['lockedReceipt']->id)->where('event', 'cash_receipt_locked_manually')->count())->toBe(1);

        // The Partner of the client.
        $this->actingAs($this->partnerA);

        $hidden = [...invoiceLeakHidden(), $note, 'Uzamknout', 'Odemknout', 'Odesílání dokladů e-mailem', 'document_emails_enabled', 'invoice_locked_manually', 'cash_receipt_locked_manually'];

        $list = (string) $this->get(PartnerInvoiceResource::getUrl())->assertOk()->getContent();
        $detail = (string) $this->get(PartnerInvoiceResource::getUrl('view', ['record' => $set['locked']]))
            ->assertOk()
            ->assertDontSee($note)
            ->assertDontSee('Uzamknout')
            ->assertDontSee('Odemknout')
            ->getContent();
        $openDetail = (string) $this->get(PartnerInvoiceResource::getUrl('view', ['record' => $set['open']]))
            ->assertOk()
            ->assertDontSee($note)
            ->assertDontSee('Uzamknout')
            ->getContent();

        invoiceLeakAssertClean($list, 'the list with a hand-served client', $hidden);
        invoiceLeakAssertClean($detail, 'the detail of a hand-locked invoice', $hidden);
        invoiceLeakAssertClean($openDetail, 'the detail of an open invoice of a hand-served client', $hidden);

        // Neither manual lock row is visible to the Partner.
        expect(Activity::query()->whereIn('event', ['invoice_locked_manually', 'cash_receipt_locked_manually'])->count())->toBe(0);

        $this->get('/admin/activity')->assertForbidden();
    });

    it('refuses a Partner both manual locks in the domain and changes nothing', function (): void {
        $set = invoiceLeakHandServed();
        $partner = User::query()->whereKey($this->partnerA->id)->firstOrFail();
        $this->actingAs($partner);

        expect(fn (): mixed => app(LockInvoice::class)->handle($partner, $set['open']))->toThrow(AuthorizationException::class)
            ->and(fn (): mixed => app(LockCashReceipt::class)->handle($partner, $set['openReceipt']))->toThrow(AuthorizationException::class);

        $this->actingAs($this->admin);

        expect(Invoice::query()->whereKey($set['open']->id)->firstOrFail()->isLocked())->toBeFalse()
            ->and(CashReceipt::query()->whereKey($set['openReceipt']->id)->firstOrFail()->isLocked())->toBeFalse()
            ->and(Activity::query()->where('subject_id', $set['open']->id)->where('event', 'invoice_locked_manually')->count())->toBe(0)
            ->and(Activity::query()->where('subject_id', $set['openReceipt']->id)->where('event', 'cash_receipt_locked_manually')->count())->toBe(0);
    });

    it('refuses a Partner the Admin detail and the client edit page where the switch lives', function (): void {
        $set = invoiceLeakHandServed();

        // Admin positives: both pages open and the edit form holds the switch.
        $this->actingAs($this->admin);
        $this->get(ClientResource::getUrl('edit', ['record' => $this->clientA]))->assertOk();
        Livewire::test(ViewInvoice::class, ['record' => $set['open']->getRouteKey()])->assertSuccessful();

        $this->actingAs($this->partnerA);

        $edit = $this->get(ClientResource::getUrl('edit', ['record' => $this->clientA]));
        $edit->assertForbidden();

        expect(invoiceLeakSurface((string) $edit->getContent()))->not->toContain('document_emails_enabled');

        Livewire::test(ViewInvoice::class, ['record' => $set['open']->getRouteKey()])->assertForbidden();
        Livewire::test(ViewInvoice::class, ['record' => $set['locked']->getRouteKey()])->assertForbidden();
    });
});
