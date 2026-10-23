<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\QueueInvoiceEmail;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Actions\UnlockInvoice;
use App\Domain\Invoicing\Actions\UpdateIssuedDocument;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceLockState;
use App\Domain\Invoicing\Jobs\SendInvoiceEmail;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceItemSource;
use App\Domain\Invoicing\Pdf\InvoicePdf;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Settings\Banking\BankAccount;
use App\Domain\Settings\Banking\BankAccountFormat;
use App\Domain\Settings\Settings\BankAccountSettings;
use App\Domain\Settings\Settings\NumberingSettings;
use App\Domain\Settings\Settings\SupplierSettings;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Pages\EditInvoice;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\FictionalIban;
use Tests\Support\InvoiceFixtures;
use Tests\Support\PdfText;
use Tests\Support\QrDecode;

/*
 * The header of an open issued invoice or proforma in the editor (IN-13, IN-15; D-06, D-13; assumptions A5, A6, A9,
 * A14): the dates, the language, the customer and supplier lines and the bank account. Every name, address and number
 * is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
    Mail::getSymfonyTransport()->flush();
});

/**
 * The editor of a document.
 */
function hedEditor(Invoice $invoice): Testable
{
    return Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()]);
}

/**
 * The document row, read fresh.
 */
function hedRow(Invoice $invoice): Invoice
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Invoice => Invoice::query()->whereKey($invoice->id)->firstOrFail());
}

/**
 * The Admin-only row of the document, read fresh.
 */
function hedAdmin(Invoice $invoice): InvoiceAdmin
{
    return app(PartnerContext::class)->runAsSystem(static fn (): InvoiceAdmin => InvoiceAdmin::query()->where('invoice_id', $invoice->id)->firstOrFail());
}

/**
 * An issued invoice that was sent once (so it was locked) and opened again with a reason.
 */
function hedSentAndUnlocked(?Invoice $issued = null): Invoice
{
    $invoice = $issued ?? InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '2', '500.00')]);

    $row = app(QueueInvoiceEmail::class)->handle(test()->admin, $invoice, [
        'recipient' => 'jane@example.com',
        'subject' => 'Example invoice',
        'body' => 'Example body',
        'attach_work_report' => false,
    ]);

    SendInvoiceEmail::dispatchSync($row->id);

    expect(hedRow($invoice)->lock_state)->toBe(InvoiceLockState::Locked);

    return app(UnlockInvoice::class)->handle(test()->admin, $invoice, 'Wrong example due date and customer name');
}

/**
 * The text of the one stored PDF, white space reduced; the test is incomplete without Ghostscript.
 */
function hedPdfText(): string
{
    $files = Storage::disk('local')->allFiles();

    expect($files)->toHaveCount(1);

    $text = PdfText::flat(Storage::disk('local')->get($files[0]) ?? '');

    if ($text === null) {
        test()->markTestIncomplete('Ghostscript is not installed, the printed text cannot be read back.');
    }

    return $text;
}

/**
 * The rows of the activity log of a document of one event, oldest first.
 *
 * @return list<Activity>
 */
function hedAudit(Invoice $invoice, string $event = 'invoice_edited'): array
{
    return Activity::query()
        ->where('subject_type', 'invoice')
        ->where('subject_id', $invoice->id)
        ->where('event', $event)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get()
        ->all();
}

describe('the tracer: a due date and a customer name corrected in the editor', function (): void {
    it('stores both, prints both on the new PDF and lists both in the history of a sent document', function (): void {
        $invoice = hedSentAndUnlocked();
        $editor = hedEditor($invoice);

        $editor->assertFormSet(['due_date' => '2026-10-28', 'customer.name' => hedRow($invoice)->customer_snapshot['name']])
            ->set('data.due_date', '2026-11-20')
            ->set('data.customer.name', 'Corrected Example Client')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(InvoiceResource::getUrl('view', ['record' => $invoice]));

        $row = hedRow($invoice);
        $text = hedPdfText();
        $changes = hedAudit($invoice)[0]->attribute_changes?->all() ?? [];

        expect($row->due_date?->format('Y-m-d'))->toBe('2026-11-20')
            ->and($row->customer_snapshot['name'])->toBe('Corrected Example Client')
            ->and($row->number)->toBe($invoice->number)
            ->and($text)->toContain('Corrected Example Client')
            ->and($text)->toContain('20. 11. 2026')
            ->and(hedAudit($invoice))->toHaveCount(1)
            ->and($changes['attributes'])->toBe(['due_date' => '2026-11-20', 'customer.name' => 'Corrected Example Client'])
            ->and($changes['old']['due_date'])->toBe('2026-10-28');
    });
});

describe('the customer lines', function (): void {
    it('keeps a field the input does not mention, ignores an unknown key and trims what it takes', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);
        $before = hedRow($invoice)->customer_snapshot;

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, [
            'customer' => ['name' => '  Renamed Example Client  ', 'street' => '', 'unknown_key' => 'x', 'country' => 'sk'],
        ]);

        $after = hedRow($invoice)->customer_snapshot;

        expect($after['name'])->toBe('Renamed Example Client')
            ->and($after['street'])->toBeNull()
            ->and($after['country'])->toBe('SK')
            ->and($after['city'])->toBe($before['city'])
            ->and($after['company_id'])->toBe($before['company_id'])
            ->and($after)->not->toHaveKey('unknown_key');
    });

    it('refuses a name that is blank or too long and a country that is not two capitals, and writes nothing', function (string $field, mixed $value, string $message): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);
        $row = hedRow($invoice);

        try {
            app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['customer' => [$field => $value]]);
            $this->fail('The customer line should have been refused.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey("customer.{$field}")
                ->and($exception->errors()["customer.{$field}"][0])->toBe($message);
        }

        expect(hedRow($invoice)->customer_snapshot)->toBe($row->customer_snapshot);
    })->with([
        'blank name' => ['name', '   ', 'Vyplňte název.'],
        'name of 256 characters' => ['name', str_repeat('a', 256), 'Zadejte nejvýše 255 znaků.'],
        'country of three letters' => ['country', 'CZE', 'Zadejte kód země ze dvou velkých písmen, například CZ.'],
        'postal code of 21 characters' => ['postal_code', str_repeat('1', 21), 'Zadejte nejvýše 20 znaků.'],
        'a list instead of text' => ['city', ['a'], 'Hodnota nemá platný formát.'],
    ]);

    it('shows a refused customer line under its own field in the editor', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);

        hedEditor($invoice)
            ->set('data.customer.name', str_repeat('a', 256))
            ->call('save')
            ->assertHasFormErrors(['customer.name']);

        expect(hedRow($invoice)->customer_snapshot['name'])->not->toStartWith('aaaa');
    });
});

describe('the due date and the language', function (): void {
    it('refuses a due date before the issue date and one that is not a day', function (mixed $due): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);

        hedEditor($invoice)
            ->set('data.due_date', $due)
            ->call('save')
            ->assertHasFormErrors(['due_date']);

        expect(hedRow($invoice)->due_date?->format('Y-m-d'))->toBe('2026-10-28');
    })->with([
        'before the issue date' => ['2026-10-13'],
        'not a day' => ['not-a-date'],
    ]);

    it('answers a due date before the issue date with the existing copy', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);

        try {
            app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['due_date' => '2026-10-13']);
            $this->fail('The due date should have been refused.');
        } catch (ValidationException $exception) {
            expect($exception->errors()['due_date'])->toBe(['Datum splatnosti nesmí být dříve než datum vystavení.']);
        }
    });

    it('stores the language and prints the English copy on the new PDF', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);

        hedEditor($invoice)->set('data.language', 'en')->call('save')->assertHasNoFormErrors();

        $text = hedPdfText();

        expect(hedRow($invoice)->language->value)->toBe('en')
            ->and($text)->toContain('INVOICE')
            ->and($text)->toContain('Due date')
            ->and($text)->toContain('2026-10-28');
    });

    it('refuses a language that is not offered', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);

        try {
            app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['language' => 'de']);
            $this->fail('The language should have been refused.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('language');
        }

        expect(hedRow($invoice)->language->value)->toBe('cs');
    });

    it('writes no history row for a header edit of a document that was never sent, like a draft', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['due_date' => '2026-11-02']);

        expect(hedAudit($invoice))->toBe([])
            ->and(hedRow($invoice)->due_date?->format('Y-m-d'))->toBe('2026-11-02');
    });
});

/**
 * One invoice issued on the given day (the clock travels there first), and the clock stays on that day.
 */
function hedIssuedOn(string $day, string $price = '500.00', ?InvoiceKind $kind = null): Invoice
{
    test()->travelTo(CarbonImmutable::parse($day.' 09:30:00', 'Europe/Prague'));

    return InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', $price)], $kind ?? InvoiceKind::Invoice);
}

/**
 * Moves the issue date through the Action and returns the messages under `issue_date`, or an empty list when accepted.
 *
 * @return list<string>
 */
function hedMoveIssueDate(Invoice $invoice, string $day, array $more = []): array
{
    try {
        app(UpdateIssuedDocument::class)->handle(test()->admin, $invoice, ['issue_date' => $day, ...$more]);
    } catch (ValidationException $exception) {
        return $exception->errors()['issue_date'] ?? array_values(array_merge(...array_values($exception->errors())));
    }

    return [];
}

/**
 * The invoice pattern of the settings, saved as system.
 */
function hedInvoicePattern(string $pattern): void
{
    app(PartnerContext::class)->runAsSystem(static function () use ($pattern): void {
        $numbering = app(NumberingSettings::class);
        $numbering->invoice_pattern = $pattern;
        $numbering->save();
    });
    app()->forgetScopedInstances();
}

describe('the issue date and the numbering period', function (): void {
    it('moves inside the period of a yearly pattern and refuses a day outside it or after today', function (): void {
        $invoice = hedIssuedOn('2026-10-14');

        expect(hedMoveIssueDate($invoice, '2026-10-10'))->toBe([])
            ->and(hedRow($invoice)->issue_date?->format('Y-m-d'))->toBe('2026-10-10');

        $messages = hedMoveIssueDate($invoice, '2027-01-02');

        expect($messages)->toContain(__('invoices.errors.issue_date_future'))
            ->and($messages)->toContain(__('invoices.errors.issue_date_scope'))
            ->and(hedRow($invoice)->issue_date?->format('Y-m-d'))->toBe('2026-10-10');

        expect(hedMoveIssueDate($invoice, '2026-10-15'))->toBe([__('invoices.errors.issue_date_future')]);
    });

    it('refuses a day of another year even when it is not in the future', function (): void {
        $invoice = hedIssuedOn('2026-10-14');
        $this->travelTo(CarbonImmutable::parse('2027-02-01 09:30:00', 'Europe/Prague'));

        expect(hedMoveIssueDate($invoice, '2027-01-02'))->toBe([__('invoices.errors.issue_date_scope')])
            ->and(hedMoveIssueDate($invoice, '2025-12-30'))->toBe([__('invoices.errors.issue_date_scope')])
            ->and(hedRow($invoice)->issue_date?->format('Y-m-d'))->toBe('2026-10-14');
    });

    it('reads the period from the pattern the series has now', function (): void {
        $invoice = hedIssuedOn('2026-10-14');
        hedInvoicePattern('{YYYY}{MM}{NNNN}');

        expect(hedMoveIssueDate($invoice, '2026-09-30'))->toBe([__('invoices.errors.issue_date_scope')])
            ->and(hedMoveIssueDate($invoice, '2026-10-01'))->toBe([])
            ->and(hedRow($invoice)->issue_date?->format('Y-m-d'))->toBe('2026-10-01');
    });

    it('shows a refused issue date under its field in the editor and audits an accepted one after the first send', function (): void {
        $invoice = hedSentAndUnlocked();

        hedEditor($invoice)
            ->set('data.issue_date', '2027-01-02')
            ->call('save')
            ->assertHasFormErrors(['issue_date']);

        expect(hedRow($invoice)->issue_date?->format('Y-m-d'))->toBe('2026-10-14');

        hedEditor($invoice)
            ->set('data.issue_date', '2026-10-12')
            ->call('save')
            ->assertHasNoFormErrors();

        $changes = hedAudit($invoice)[0]->attribute_changes?->all() ?? [];

        expect($changes['attributes'])->toBe(['issue_date' => '2026-10-12'])
            ->and($changes['old'])->toBe(['issue_date' => '2026-10-14']);
    });

    it('never changes the number', function (): void {
        $invoice = hedIssuedOn('2026-10-14');

        hedMoveIssueDate($invoice, '2026-10-02');

        expect(hedRow($invoice)->number)->toBe($invoice->number);
    });
});

describe('the issue date between the neighbours of the series', function (): void {
    it('keeps the middle document between its neighbours, bounds included, and names both dates', function (): void {
        $first = hedIssuedOn('2026-10-05');
        $middle = hedIssuedOn('2026-10-10');
        $last = hedIssuedOn('2026-10-14');

        expect(hedMoveIssueDate($middle, '2026-10-04'))->toBe(['Datum vystavení musí ležet mezi 5. 10. 2026 a 14. 10. 2026, daty sousedních dokladů řady.'])
            ->and(hedMoveIssueDate($middle, '2026-10-05'))->toBe([])
            ->and(hedMoveIssueDate($middle, '2026-10-14'))->toBe([]);

        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:30:00', 'Europe/Prague'));

        expect(hedMoveIssueDate($middle, '2026-10-16'))->toBe(['Datum vystavení musí ležet mezi 5. 10. 2026 a 14. 10. 2026, daty sousedních dokladů řady.'])
            ->and(hedRow($middle)->issue_date?->format('Y-m-d'))->toBe('2026-10-14')
            ->and(hedRow($first)->issue_date?->format('Y-m-d'))->toBe('2026-10-05')
            ->and(hedRow($last)->issue_date?->format('Y-m-d'))->toBe('2026-10-14');
    });

    it('leaves the lower bound of the first document and the upper bound of the last one open', function (): void {
        $first = hedIssuedOn('2026-10-05');
        hedIssuedOn('2026-10-10');
        $last = hedIssuedOn('2026-10-14');
        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:30:00', 'Europe/Prague'));

        expect(hedMoveIssueDate($first, '2026-01-02'))->toBe([])
            ->and(hedMoveIssueDate($first, '2026-10-12'))->toBe(['Datum vystavení nesmí být později než 10. 10. 2026, datum následujícího dokladu řady.'])
            ->and(hedMoveIssueDate($last, '2026-10-19'))->toBe([])
            ->and(hedMoveIssueDate($last, '2026-10-09'))->toBe(['Datum vystavení nesmí být dříve než 10. 10. 2026, datum předchozího dokladu řady.']);
    });

    it('compares a document only with the documents of its own kind', function (): void {
        $invoice = hedIssuedOn('2026-10-05');
        hedIssuedOn('2026-10-12', kind: InvoiceKind::Proforma);
        hedIssuedOn('2026-10-14');
        $proforma = hedIssuedOn('2026-10-14', kind: InvoiceKind::Proforma);

        // The proforma has the earlier proforma as its only neighbour; the invoices around it do not count.
        expect(hedMoveIssueDate($proforma, '2026-10-12'))->toBe([])
            ->and(hedMoveIssueDate($proforma, '2026-10-11'))->toBe(['Datum vystavení nesmí být dříve než 12. 10. 2026, datum předchozího dokladu řady.'])
            ->and(hedMoveIssueDate($invoice, '2026-10-14'))->toBe([]);
    });
});

describe('the due date against the new issue date', function (): void {
    it('refuses a due date before the new issue date, sent or kept', function (): void {
        $client = InvoiceFixtures::client(['payment_terms_days' => 0]);
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:30:00', 'Europe/Prague'));
        $invoice = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Design', '1', '500.00')]);
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));

        expect(hedRow($invoice)->due_date?->format('Y-m-d'))->toBe('2026-10-05');

        $kept = [];
        $typed = [];

        try {
            app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['issue_date' => '2026-10-10']);
        } catch (ValidationException $exception) {
            $kept = $exception->errors();
        }

        try {
            app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['issue_date' => '2026-10-10', 'due_date' => '2026-10-09']);
        } catch (ValidationException $exception) {
            $typed = $exception->errors();
        }

        expect($kept['due_date'] ?? null)->toBe(['Datum splatnosti nesmí být dříve než datum vystavení.'])
            ->and($typed['due_date'] ?? null)->toBe(['Datum splatnosti nesmí být dříve než datum vystavení.'])
            ->and(hedRow($invoice)->issue_date?->format('Y-m-d'))->toBe('2026-10-05');

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['issue_date' => '2026-10-10', 'due_date' => '2026-10-24']);

        expect(hedRow($invoice)->issue_date?->format('Y-m-d'))->toBe('2026-10-10')
            ->and(hedRow($invoice)->due_date?->format('Y-m-d'))->toBe('2026-10-24');
    });

    it('answers a blank issue date as an invalid date, never as null', function (): void {
        $invoice = hedIssuedOn('2026-10-14');

        expect(hedMoveIssueDate($invoice, ''))->toBe(['Zadejte platné datum.'])
            ->and(hedRow($invoice)->issue_date?->format('Y-m-d'))->toBe('2026-10-14');
    });
});

describe('the frozen rates when the issue date moves', function (): void {
    /**
     * An invoice in EUR issued on 2026-10-14 with the list of that day.
     */
    function hedEurInvoice(): Invoice
    {
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.125');
        InvoiceFixtures::configureSupplier();

        return InvoiceFixtures::issued(InvoiceFixtures::client(['currency' => 'EUR']), [InvoiceFixtures::item('Design', '2', '100.00')]);
    }

    /**
     * The Admin-only exchange figures of the document.
     *
     * @return array<string, mixed>
     */
    function hedAdminFigures(Invoice $invoice): array
    {
        $admin = app(PartnerContext::class)->runAsSystem(static fn (): ?InvoiceAdmin => InvoiceAdmin::query()->where('invoice_id', $invoice->id)->first());

        return [
            'rate' => (string) $admin?->exchange_rate,
            'date' => $admin?->exchange_rate_date?->format('Y-m-d'),
            'unit' => $admin?->exchange_unit_amount,
            'czk' => $admin?->czk_total_minor,
        ];
    }

    it('keeps the CNB trio and the CZK total of a EUR invoice and warns in the editor', function (): void {
        $invoice = hedEurInvoice();
        $figures = hedAdminFigures($invoice);

        expect($figures['czk'])->not->toBeNull();

        InvoiceFixtures::cnbRate('EUR', '2026-10-10', '26.000');

        hedEditor($invoice)
            ->set('data.issue_date', '2026-10-10')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Kurz ČNB a převedené položky zůstávají z původního data vystavení.');

        expect(hedRow($invoice)->issue_date?->format('Y-m-d'))->toBe('2026-10-10')
            ->and(hedAdminFigures($invoice))->toBe($figures);
    });

    it('sends no such warning for a CZK invoice without a converted line, nor for an unchanged issue date', function (): void {
        $invoice = hedIssuedOn('2026-10-14');

        hedEditor($invoice)
            ->set('data.issue_date', '2026-10-10')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotNotified('Kurz ČNB a převedené položky zůstávají z původního data vystavení.');

        $eur = hedEurInvoice();

        hedEditor($eur)
            ->set('data.note', 'A note only.')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotNotified('Kurz ČNB a převedené položky zůstávají z původního data vystavení.');
    });

    it('keeps every frozen conversion of a converted time line', function (): void {
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.125');
        $client = InvoiceFixtures::client();
        $project = app(CreateProject::class)->handle($client, [
            'name' => 'Alpha conversion project',
            'key' => Canary::projectKey(),
            'billing_type' => 'hourly',
            'hourly_rate' => '1500',
        ]);
        $start = CarbonImmutable::parse('2026-09-10 08:00:00', 'UTC');
        $factory = TimeEntry::factory()->forProject($project);
        app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => $factory->create(['user_id' => Canary::admin()->id, 'started_at' => $start, 'ended_at' => $start->addHours(10)]));

        $draft = app(CreateDraft::class)->handle($this->admin, [
            'client_id' => $client->id,
            'kind' => 'invoice',
            'source' => 'time',
            'period_from' => '2026-09-01',
            'period_to' => '2026-09-30',
            'project_ids' => [$project->id],
            'without_project' => false,
            'grouping' => 'project',
            'currency' => 'EUR',
        ]);
        $invoice = app(IssueDocument::class)->handle($this->admin, $draft);
        $snapshots = static fn (): array => app(PartnerContext::class)->runAsSystem(static fn (): array => InvoiceItemSource::query()->where('invoice_id', $invoice->id)->pluck('conversion_snapshot')->all());
        $before = $snapshots();
        $figures = hedAdminFigures($invoice);

        expect($before[0])->not->toBeNull();

        InvoiceFixtures::cnbRate('EUR', '2026-10-10', '26.000');
        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['issue_date' => '2026-10-10']);

        expect($snapshots())->toBe($before)
            ->and(hedAdminFigures($invoice))->toBe($figures)
            ->and(hedRow($invoice)->total_minor)->toBe($invoice->total_minor);
    });
});

describe('the taxable supply date', function (): void {
    it('stays empty in the non-payer mode: a day is refused, blank is accepted, and the editor does not render the field', function (): void {
        $invoice = hedIssuedOn('2026-10-14');

        try {
            app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['taxable_supply_date' => '2026-10-14']);
            $this->fail('A taxable supply date should have been refused.');
        } catch (ValidationException $exception) {
            expect($exception->errors()['taxable_supply_date'] ?? null)->toBe([__('invoices.errors.taxable_supply_not_payer')]);
        }

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['taxable_supply_date' => '', 'note' => 'Blank accepted.']);

        expect(hedRow($invoice)->taxable_supply_date)->toBeNull();

        hedEditor($invoice)->assertFormFieldHidden('taxable_supply_date');
    });
});

describe('the supplier lines', function (): void {
    it('stores a corrected supplier name, street and phone and keeps the VAT payer flag whatever the request carries', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);
        $before = hedRow($invoice)->supplier_snapshot;

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, [
            'supplier' => ['name' => 'Corrected Supplier s.r.o.', 'street' => 'Other Street 5', 'phone' => '+420 000 000 000', 'vat_payer' => true],
        ]);

        $after = hedRow($invoice)->supplier_snapshot;

        expect($after['name'])->toBe('Corrected Supplier s.r.o.')
            ->and($after['street'])->toBe('Other Street 5')
            ->and($after['phone'])->toBe('+420 000 000 000')
            ->and($after['vat_payer'])->toBeFalse()
            ->and($after['vat_payer'])->toBe($before['vat_payer'])
            ->and($after['company_id'])->toBe($before['company_id'])
            ->and($after['email'])->toBe($before['email']);
    });

    it('takes company_name as the name', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['supplier' => ['company_name' => 'Aliased Supplier s.r.o.']]);

        expect(hedRow($invoice)->supplier_snapshot['name'])->toBe('Aliased Supplier s.r.o.');
    });

    it('refuses a bad e-mail, a country of three letters and a customer name that is too long, each under its own field, and writes nothing', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);
        $row = hedRow($invoice);

        try {
            app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, [
                'note' => 'Must not be written.',
                'customer' => ['name' => str_repeat('a', 256)],
                'supplier' => ['email' => 'not-an-address', 'country' => 'CZE', 'company_id' => '12'],
            ]);
            $this->fail('The party lines should have been refused.');
        } catch (ValidationException $exception) {
            expect(array_keys($exception->errors()))->toEqualCanonicalizing(['customer.name', 'supplier.email', 'supplier.country', 'supplier.company_id']);
        }

        $after = hedRow($invoice);

        expect($after->supplier_snapshot)->toBe($row->supplier_snapshot)
            ->and($after->customer_snapshot)->toBe($row->customer_snapshot)
            ->and($after->note)->toBe($row->note);
    });

    it('refuses a supplier name over the limit of the settings and a website that is not an address', function (string $field, string $value): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);

        try {
            app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['supplier' => [$field => $value]]);
            $this->fail('The supplier line should have been refused.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey("supplier.{$field}");
        }
    })->with([
        'name of 201 characters' => ['name', str_repeat('a', 201)],
        'website' => ['website', 'example'],
        'vat id' => ['vat_id', 'not a vat id'],
    ]);

    it('lets a stored value that today\'s rules would refuse stay while something else is corrected', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);
        app(PartnerContext::class)->runAsSystem(static function () use ($invoice): void {
            $row = Invoice::query()->whereKey($invoice->id)->firstOrFail();
            $snapshot = $row->supplier_snapshot;
            $snapshot['phone'] = str_repeat('1', 60);
            $row->forceFill(['supplier_snapshot' => $snapshot])->save();
        });

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['supplier' => ['phone' => str_repeat('1', 60), 'street' => 'New Street 1']]);

        expect(hedRow($invoice)->supplier_snapshot['street'])->toBe('New Street 1');
    });

    it('edits the supplier in the editor and prints it on the new PDF', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);

        hedEditor($invoice)
            ->assertFormSet(['supplier.name' => 'Example Supplier s.r.o.'])
            ->set('data.supplier.name', 'Printed Supplier s.r.o.')
            ->call('save')
            ->assertHasNoFormErrors();

        expect(hedPdfText())->toContain('Printed Supplier s.r.o.');
    });
});

describe('the refill actions', function (): void {
    it('fills the customer from the client card as it is now and stores it with the save', function (): void {
        $client = InvoiceFixtures::client();
        $invoice = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Design', '1', '500.00')]);
        app(PartnerContext::class)->runAsSystem(static function () use ($client): void {
            $client->forceFill(['name' => 'Renamed On The Card', 'city' => 'Newtown', 'tax_number' => 'CZ12345678'])->save();
        });

        $editor = hedEditor($invoice)
            ->callAction(TestAction::make('refillCustomer')->schemaComponent('customerActions', schema: 'form'))
            ->assertFormSet(['customer.name' => 'Renamed On The Card', 'customer.city' => 'Newtown', 'customer.vat_id' => 'CZ12345678']);

        // Nothing is written until the save.
        expect(hedRow($invoice)->customer_snapshot['name'])->not->toBe('Renamed On The Card');

        $editor->call('save')->assertHasNoFormErrors();

        $stored = hedRow($invoice)->customer_snapshot;

        expect($stored['name'])->toBe('Renamed On The Card')
            ->and($stored['city'])->toBe('Newtown')
            ->and($stored['vat_id'])->toBe('CZ12345678');
    });

    it('fills the supplier from the settings as they are now', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);
        app(PartnerContext::class)->runAsSystem(static function (): void {
            $supplier = app(SupplierSettings::class);
            $supplier->company_name = 'Moved Supplier s.r.o.';
            $supplier->city = 'Movedtown';
            $supplier->save();
        });
        app()->forgetScopedInstances();

        hedEditor($invoice)
            ->callAction(TestAction::make('refillSupplier')->schemaComponent('supplierActions', schema: 'form'))
            ->assertFormSet(['supplier.name' => 'Moved Supplier s.r.o.', 'supplier.city' => 'Movedtown'])
            ->call('save')
            ->assertHasNoFormErrors();

        $stored = hedRow($invoice)->supplier_snapshot;

        expect($stored['name'])->toBe('Moved Supplier s.r.o.')
            ->and($stored['city'])->toBe('Movedtown')
            ->and($stored['vat_payer'])->toBeFalse();
    });
});

describe('the bank account', function (): void {
    /**
     * Replaces the accounts of the settings by one CZK account with the fragment, as system.
     */
    function hedCzkAccount(?string $fragment): void
    {
        app(PartnerContext::class)->runAsSystem(static function () use ($fragment): void {
            $banks = app(BankAccountSettings::class);
            $banks->accounts = $fragment === null
                ? [new BankAccount(BankAccountFormat::Europe2, 'Example EUR account', 'EUR', iban: FictionalIban::czech('34'))]
                : [new BankAccount(BankAccountFormat::Europe2, 'Replacement CZK account', 'CZK', iban: FictionalIban::czech($fragment))];
            $banks->save();
        });
        app()->forgetScopedInstances();
    }

    it('is refilled from the chosen account as the settings hold it now, not from the first account of the currency, and the QR code of the new PDF names it', function (): void {
        $accounts = InvoiceFixtures::configureAccounts(
            InvoiceFixtures::account('First CZK', 'CZK', '12'),
            InvoiceFixtures::account('Second CZK', 'CZK', '56'),
        );
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);
        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['bank_account_id' => $accounts[1]->id]);
        $old = hedRow($invoice)->bank_snapshot;

        // The second account keeps its id and changes its number in the settings.
        $moved = new BankAccount(BankAccountFormat::Europe2, 'Second CZK', 'CZK', iban: FictionalIban::czech('78'), id: $accounts[1]->id);
        InvoiceFixtures::configureAccounts($accounts[0], $moved);

        hedEditor($invoice)
            ->assertSet('data.bank_account_id', $accounts[1]->id)
            ->callAction(TestAction::make('refillBank')->schemaComponent('bankActions', schema: 'form'))
            ->assertSet('data.reload_bank', true)
            ->assertSee('Po uložení se použije účet:')
            ->call('save')
            ->assertHasNoFormErrors();

        $row = hedRow($invoice);

        preg_match_all('#<img src="(data:image/png;base64,[^"]+)"#', app(InvoicePdf::class)->html($row), $images);

        expect($row->bank_snapshot)->toEqual($moved->toArray())
            ->and($row->bank_snapshot)->not->toBe($old)
            ->and(hedAdmin($invoice)->bank_account_id)->toBe($accounts[1]->id)
            ->and($images[1])->toHaveCount(1)
            ->and(QrDecode::payload($images[1][0]))->toContain(FictionalIban::czech('78'));
    });

    it('is refilled from the first account of the currency when the stored account was removed from the settings', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);
        $old = hedRow($invoice)->bank_snapshot;
        hedCzkAccount('56');

        hedEditor($invoice)
            ->assertSet('data.bank_account_id', null)
            ->callAction(TestAction::make('refillBank')->schemaComponent('bankActions', schema: 'form'))
            ->call('save')
            ->assertHasNoFormErrors();

        $expected = app(PartnerContext::class)->runAsSystem(static fn (): array => app(BankAccountSettings::class)->firstForCurrency('CZK')?->toArray() ?? []);

        expect(hedRow($invoice)->bank_snapshot)->toEqual($expected)
            ->and(hedRow($invoice)->bank_snapshot)->not->toBe($old);
    });

    it('keeps the stored account when the editor saves without the refill', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);
        $old = hedRow($invoice)->bank_snapshot;
        hedCzkAccount('56');

        hedEditor($invoice)->set('data.note', 'Only a note.')->call('save')->assertHasNoFormErrors();

        expect(hedRow($invoice)->bank_snapshot)->toBe($old);
    });

    it('is never taken from the data: a typed account is ignored and a refill needs a real boolean', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);
        $old = hedRow($invoice)->bank_snapshot;

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, [
            'bank_snapshot' => ['iban' => FictionalIban::czech('78')],
            'bank' => ['iban' => FictionalIban::czech('78')],
            'note' => 'Forged account ignored.',
        ]);

        expect(hedRow($invoice)->bank_snapshot)->toBe($old);

        expect(fn () => app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['reload_bank' => 'yes']))
            ->toThrow(InvalidArgumentException::class);
    });

    it('refuses a refill when the settings hold no account of the currency, under bank_account_id, and writes nothing', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);
        $old = hedRow($invoice)->bank_snapshot;
        hedCzkAccount(null);

        try {
            app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['reload_bank' => true, 'note' => 'Must not be written.']);
            $this->fail('A currency without an account should have been refused.');
        } catch (ValidationException $exception) {
            expect($exception->errors()['bank_account_id'] ?? null)->toBe([__('invoices.errors.bank_account_required')]);
        }

        expect(hedRow($invoice)->bank_snapshot)->toBe($old)
            ->and(hedRow($invoice)->note)->toBeNull();

        hedEditor($invoice)
            ->callAction(TestAction::make('refillBank')->schemaComponent('bankActions', schema: 'form'))
            ->assertNotified(__('invoices.errors.bank_account_required'));
    });

    it('is refilled from the chosen account when it is still configured, and from the first account of the currency when it was removed', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);
        $accounts = InvoiceFixtures::configureAccounts(
            InvoiceFixtures::account('First CZK', 'CZK', '12'),
            InvoiceFixtures::account('Second CZK', 'CZK', '56'),
        );

        // The stored account is gone from the settings: the refill takes the first CZK account.
        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['reload_bank' => true]);

        expect(hedRow($invoice)->bank_snapshot)->toEqual($accounts[0]->toArray())
            ->and(hedAdmin($invoice)->bank_account_id)->toBe($accounts[0]->id);

        // The chosen account is the second one: the refill keeps it.
        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['bank_account_id' => $accounts[1]->id]);
        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['reload_bank' => true]);

        expect(hedRow($invoice)->bank_snapshot)->toEqual($accounts[1]->toArray())
            ->and(hedAdmin($invoice)->bank_account_id)->toBe($accounts[1]->id);
    });
});

describe('the payment method and the card switch (Phase 14)', function (): void {
    beforeEach(function (): void {
        CardPaymentSetup::enable();
        $this->accounts = InvoiceFixtures::configureAccounts(
            InvoiceFixtures::account('First CZK', 'CZK', '12'),
            InvoiceFixtures::account('Second CZK', 'CZK', '56'),
            InvoiceFixtures::account('Example EUR', 'EUR', '34'),
        );
    });

    /**
     * An open issued transfer invoice of a client with online payment on.
     */
    function hedCardInvoice(bool $clientFlag = true): Invoice
    {
        return InvoiceFixtures::issued(CardPaymentSetup::client(['online_payment_enabled' => $clientFlag]), [InvoiceFixtures::item('Design', '1', '500.00')]);
    }

    /**
     * What the update refused: the field errors, or null when it went through.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, list<string>>|null
     */
    function hedRefusal(Invoice $invoice, array $data): ?array
    {
        try {
            app(UpdateIssuedDocument::class)->handle(test()->admin, $invoice, $data);
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        return null;
    }

    it('refuses the switch turned on while online payments are off in the settings, and writes nothing', function (): void {
        $invoice = hedCardInvoice(false);
        CardPaymentSetup::onlinePayments(false);
        $before = hedRow($invoice)->updated_at;

        $errors = hedRefusal($invoice, ['card_payment_enabled' => true, 'note' => 'Must not be written.']);

        expect($errors['card_payment_enabled'] ?? null)->toBe([__('invoices.errors.card_payment_unavailable')])
            ->and(hedAdmin($invoice)->card_payment_enabled)->toBeFalse()
            ->and(hedRow($invoice)->note)->toBeNull()
            ->and(hedRow($invoice)->updated_at->equalTo($before))->toBeTrue();
    });

    it('refuses the switch that is not a boolean', function (): void {
        $invoice = hedCardInvoice();

        expect(hedRefusal($invoice, ['card_payment_enabled' => 'yes'])['card_payment_enabled'] ?? null)->toBe([__('invoices.errors.card_payment_invalid')]);
    });

    it('refuses online while the client has online payment off, under payment_method, and writes nothing', function (): void {
        $invoice = hedCardInvoice(false);

        $errors = hedRefusal($invoice, ['payment_method' => 'online']);

        expect($errors['payment_method'] ?? null)->toBe([__('invoices.errors.online_unavailable')])
            ->and(hedRow($invoice)->payment_method->value)->toBe('transfer')
            ->and(hedRow($invoice)->bank_snapshot)->not->toBeNull();
    });

    it('refuses a method that is neither transfer nor online', function (): void {
        $invoice = hedCardInvoice();

        expect(hedRefusal($invoice, ['payment_method' => 'cash'])['payment_method'] ?? null)->toBe([__('invoices.errors.payment_method_invalid')]);
    });

    it('changes the account to the second CZK account and to the EUR account: the snapshot of that account, nothing else, no link change', function (): void {
        $invoice = hedCardInvoice();
        $link = app(PartnerContext::class)->runAsSystem(static fn () => PaymentLink::query()->where('invoice_id', $invoice->id)->firstOrFail());
        $before = hedRow($invoice);

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['bank_account_id' => $this->accounts[1]->id]);

        $row = hedRow($invoice);

        expect($row->bank_snapshot)->toEqual($this->accounts[1]->toArray())
            ->and(hedAdmin($invoice)->bank_account_id)->toBe($this->accounts[1]->id)
            ->and($row->total_minor)->toBe($before->total_minor)
            ->and($row->currency)->toBe('CZK')
            ->and($row->customer_snapshot)->toEqual($before->customer_snapshot)
            ->and(app(PartnerContext::class)->runAsSystem(static fn (): int => PaymentLink::query()->where('invoice_id', $invoice->id)->count()))->toBe(1)
            ->and(app(PartnerContext::class)->runAsSystem(static fn () => PaymentLink::query()->whereKey($link->id)->firstOrFail()->isLive()))->toBeTrue();

        // An account of another currency than the document is allowed: the account is chosen, not derived (D-05).
        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['bank_account_id' => $this->accounts[2]->id]);

        expect(hedRow($invoice)->bank_snapshot)->toEqual($this->accounts[2]->toArray())
            ->and(hedRow($invoice)->currency)->toBe('CZK')
            ->and(hedAdmin($invoice)->bank_account_id)->toBe($this->accounts[2]->id);
    });

    it('refuses an account sent as null on a transfer document, and an account the settings do not know', function (): void {
        $invoice = hedCardInvoice();
        $old = hedRow($invoice)->bank_snapshot;

        expect(hedRefusal($invoice, ['bank_account_id' => null])['bank_account_id'] ?? null)->toBe([__('invoices.errors.bank_account_required')])
            ->and(hedRefusal($invoice, ['bank_account_id' => '01900000-0000-7000-8000-000000000000'])['bank_account_id'] ?? null)->toBe([__('invoices.errors.bank_account_unknown')])
            ->and(hedRefusal($invoice, ['bank_account_id' => 'not-an-id'])['bank_account_id'] ?? null)->toBe([__('invoices.errors.bank_account_unknown')])
            ->and(hedRow($invoice)->bank_snapshot)->toBe($old);
    });

    it('accepts the method and the account of a document with a recorded payment, and still refuses its currency', function (): void {
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.125');
        $invoice = hedCardInvoice();

        app(RecordPayment::class)->handle($this->admin, $invoice, ['amount' => '100.00', 'paid_on' => '2026-10-14']);

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['bank_account_id' => $this->accounts[1]->id, 'payment_method' => 'online']);

        expect(hedRow($invoice)->payment_method->value)->toBe('online')
            ->and(hedRow($invoice)->bank_snapshot)->toBeNull()
            ->and(hedAdmin($invoice)->bank_account_id)->toBe($this->accounts[1]->id);

        expect(hedRefusal($invoice, ['currency' => 'EUR'])['currency'] ?? null)->toBe([__('invoices.errors.currency_has_payments')])
            ->and(hedRow($invoice)->currency)->toBe('CZK');
    });

    it('refuses the method, the account and the switch of a locked document under document, and accepts them after the unlock', function (): void {
        $locked = InvoiceFixtures::lockedIssued(CardPaymentSetup::client(), [InvoiceFixtures::item('Design', '1', '500.00')]);

        foreach ([['payment_method' => 'online'], ['bank_account_id' => $this->accounts[1]->id], ['card_payment_enabled' => false]] as $data) {
            expect(hedRefusal($locked, $data)['document'] ?? null)->not->toBeNull();
        }

        expect(hedRow($locked)->payment_method->value)->toBe('transfer')
            ->and(hedAdmin($locked)->card_payment_enabled)->toBeTrue();

        $open = hedSentAndUnlocked(InvoiceFixtures::issued(CardPaymentSetup::client(), [InvoiceFixtures::item('Design', '1', '500.00')]));

        app(UpdateIssuedDocument::class)->handle($this->admin, $open, ['payment_method' => 'online']);

        expect(hedRow($open)->payment_method->value)->toBe('online')
            ->and(hedRow($open)->bank_snapshot)->toBeNull();
    });

    it('never takes the snapshot from the data when the method or the account changes', function (): void {
        $invoice = hedCardInvoice();

        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, [
            'bank_account_id' => $this->accounts[1]->id,
            'bank_snapshot' => ['iban' => FictionalIban::czech('78')],
            'bank' => ['iban' => FictionalIban::czech('78')],
        ]);

        expect(hedRow($invoice)->bank_snapshot)->toEqual($this->accounts[1]->toArray());
    });
});
