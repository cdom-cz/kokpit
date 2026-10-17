<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\QueueInvoiceEmail;
use App\Domain\Invoicing\Jobs\SendInvoiceEmail;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Partner\Resources\PartnerInvoiceResource;
use App\Filament\Partner\Resources\PartnerInvoiceResource\Pages\ListPartnerInvoices;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Resources\InvoiceResource\RelationManagers\InvoiceEntriesRelationManager;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\DatabaseNotifications;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\InvoiceScenario;

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
