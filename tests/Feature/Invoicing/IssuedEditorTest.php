<?php

declare(strict_types=1);

use App\Domain\Documents\Models\Document;
use App\Domain\Invoicing\Actions\ConvertProforma;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\QueueInvoiceEmail;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Actions\UpdateDraft;
use App\Domain\Invoicing\Actions\UpdateIssuedDocument;
use App\Domain\Invoicing\Enums\DocumentPaymentMethod;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Jobs\CreatePaymentLink;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Pages\EditInvoice;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\FictionalIban;
use Tests\Support\InvoiceFixtures;

/*
 * The issued mode of the editor (IN-13, IN-14; D-04, D-05, D-08, D-09, D-10): the open issued invoice or proforma is
 * corrected in the same editor as a draft, reached from its detail, and the page says what the save did. The Actions
 * are tested in UpdateIssuedDocumentTest; here the screen follows them. Every name and amount is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

/**
 * The editor of a document.
 */
function iedEditor(Invoice $invoice): Testable
{
    return Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()]);
}

/**
 * The detail of a document.
 */
function iedDetail(Invoice $invoice): Testable
{
    return Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()]);
}

/**
 * The repeater keys of the rows in the form, in order.
 *
 * @return list<string>
 */
function iedKeys(Testable $editor): array
{
    return array_map('strval', array_keys($editor->get('data.items')));
}

/**
 * The stored lines of the document in order.
 *
 * @return list<InvoiceItem>
 */
function iedLines(Invoice $invoice): array
{
    return array_values(app(PartnerContext::class)->runAsSystem(static fn (): array => InvoiceItem::query()->where('invoice_id', $invoice->id)->orderBy('position')->get()->all()));
}

/**
 * The document row, read fresh.
 */
function iedRow(Invoice $invoice): Invoice
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Invoice => Invoice::query()->whereKey($invoice->id)->firstOrFail());
}

/**
 * The stored PDF document id of the invoice.
 */
function iedPdfId(Invoice $invoice): ?string
{
    $id = DB::table('invoice_admin')->where('invoice_id', $invoice->id)->value('pdf_document_id');

    return is_string($id) ? $id : null;
}

describe('the tracer: from the detail to a corrected invoice', function (): void {
    it('offers "Upravit" on the detail and saves a changed quantity with the message that nothing was sent', function (): void {
        Queue::fake();
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '2', '500.00')]);
        $oldPdf = iedPdfId($issued);
        $viewUrl = InvoiceResource::getUrl('view', ['record' => $issued]);

        iedDetail($issued)
            ->assertActionVisible('edit')
            ->assertActionHasUrl('edit', InvoiceResource::getUrl('edit', ['record' => $issued]));

        $editor = iedEditor($issued);
        [$key] = iedKeys($editor);

        expect($editor->instance()->getCachedHeaderActions())->toBeEmpty();

        $editor->assertSee('Úprava dokladu '.$issued->number)
            ->assertSee('Vystaveno, lze upravit')
            ->assertSee('Uložit změny')
            ->assertSee('Číslo a druh dokladu se nemění.')
            ->set("data.items.{$key}.quantity", '3')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect($viewUrl)
            ->assertNotified('Změny jsou uložené. PDF bylo vytvořeno znovu, doklad nebyl odeslán.');

        $row = iedRow($issued);

        expect(iedLines($issued)[0]->line_total_minor)->toBe(150000)
            ->and($row->total_minor)->toBe(150000)
            ->and($row->number)->toBe($issued->number)
            ->and(iedPdfId($issued))->not->toBeNull()
            ->and(iedPdfId($issued))->not->toBe($oldPdf)
            ->and(app(PartnerContext::class)->runAsSystem(static fn (): int => Document::query()->where('owner_id', $issued->id)->count()))->toBe(1)
            ->and(DB::table('invoice_emails')->where('invoice_id', $issued->id)->count())->toBe(0);

        Queue::assertNothingPushed();
    });

    it('shows the amount of a line from the server while the quantity is typed', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '2', '500.00')]);
        $editor = iedEditor($issued);
        [$key] = iedKeys($editor);

        $editor->assertSee(Money::ofMinor(100000, 'CZK')->format('cs'))
            ->set("data.items.{$key}.quantity", '3')
            ->assertSee(Money::ofMinor(150000, 'CZK')->format('cs'));
    });

    it('edits the printed note of an open proforma', function (): void {
        $proforma = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Advance', '1', '800.00')], InvoiceKind::Proforma);

        iedEditor($proforma)
            ->set('data.note', 'Corrected advance note.')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(InvoiceResource::getUrl('view', ['record' => $proforma]));

        expect(iedRow($proforma)->note)->toBe('Corrected advance note.');
    });
});

describe('what the editor refuses to open', function (): void {
    /**
     * Opens the editor of the document and returns it, so the redirect and the notification can be asserted.
     */
    function iedOpenRefused(Invoice $invoice, string $reason): void
    {
        iedEditor($invoice)
            ->assertRedirect(InvoiceResource::getUrl('view', ['record' => $invoice]))
            ->assertNotified(__('invoices.errors.'.$reason));
    }

    it('sends a locked invoice to its detail with the reason', function (): void {
        iedOpenRefused(InvoiceFixtures::lockedIssued(InvoiceFixtures::client()), 'document_locked');
    });

    it('sends a credit note to its detail with the reason', function (): void {
        $credit = InvoiceFixtures::stornoed(InvoiceFixtures::issued(InvoiceFixtures::client()));

        iedOpenRefused($credit, 'credit_note_locked');
    });

    it('sends a cancelled invoice to its detail with the reason', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client());
        InvoiceFixtures::stornoed($issued);

        iedOpenRefused($issued, 'cancelled_not_editable');
    });

    it('sends a converted proforma to its detail with the reason', function (): void {
        $proforma = InvoiceFixtures::issued(InvoiceFixtures::client(), [], InvoiceKind::Proforma);
        app(RecordPayment::class)->handle($this->admin, $proforma, ['amount' => '1000.00', 'paid_on' => '2026-10-13']);
        app(ConvertProforma::class)->handle($this->admin, $proforma);

        iedOpenRefused($proforma, 'converted_not_editable');
    });

    it('sends a document whose e-mail is on its way to its detail with the reason', function (): void {
        Queue::fake();
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client());
        app(QueueInvoiceEmail::class)->handle($this->admin, $issued, [
            'recipient' => 'jane@example.com',
            'subject' => 'Example invoice',
            'body' => 'Example body',
            'attach_work_report' => false,
        ]);

        iedOpenRefused($issued, 'email_in_flight');
    });

    it('answers a Partner with 403 on the editor of an own open issued invoice, before the record is read', function (): void {
        $client = InvoiceFixtures::client();
        $issued = InvoiceFixtures::issued($client);

        $this->actingAs(Canary::partnerFor($client->id));

        $this->get(InvoiceResource::getUrl('edit', ['record' => $issued]))->assertForbidden();
        Livewire::test(EditInvoice::class, ['record' => $issued->getRouteKey()])->assertForbidden();
    });

    it('answers a Partner with 403 on the save of an editor that was opened by the Admin', function (): void {
        $client = InvoiceFixtures::client();
        $issued = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Design', '2', '500.00')]);
        $editor = iedEditor($issued);

        $this->actingAs(Canary::partnerFor($client->id));

        $editor->call('save')->assertForbidden();

        expect(iedRow($issued)->total_minor)->toBe(100000);
    });

    it('does not save when the document was sent while the editor was open', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '2', '500.00')]);
        $editor = iedEditor($issued);
        [$key] = iedKeys($editor);

        InvoiceFixtures::lock($issued);

        $editor->set("data.items.{$key}.quantity", '9')
            ->call('save')
            ->assertNoRedirect()
            ->assertNotified(__('invoices.errors.document_locked'));

        expect(iedRow($issued)->total_minor)->toBe(100000);
    });
});

describe('a line that a credit note credits', function (): void {
    /**
     * An issued invoice of two lines, the first credited by one unit, and its editor.
     *
     * @return array{0: Invoice, 1: Testable, 2: string, 3: string}
     */
    function iedCreditedEditor(): array
    {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '2', '500.00'), InvoiceFixtures::item('Review', '1', '250.00')]);
        [$design] = iedLines($issued);
        app(IssueCreditNote::class)->partial(Canary::admin(), $issued, [$design->id => '1']);

        $editor = iedEditor($issued);
        [$designKey, $reviewKey] = iedKeys($editor);

        return [$issued, $editor, $designKey, $reviewKey];
    }

    it('shows its quantity, unit and unit price disabled and no delete button, while the other line stays free', function (): void {
        [, $editor, $designKey, $reviewKey] = iedCreditedEditor();

        $editor->assertFormFieldDisabled("items.{$designKey}.quantity")
            ->assertFormFieldDisabled("items.{$designKey}.unit")
            ->assertFormFieldDisabled("items.{$designKey}.unit_price")
            ->assertFormFieldEnabled("items.{$designKey}.description")
            ->assertFormFieldEnabled("items.{$reviewKey}.quantity")
            ->assertFormFieldEnabled("items.{$reviewKey}.unit_price")
            ->assertFormComponentActionHidden('items', 'delete', ['item' => $designKey])
            ->assertFormComponentActionVisible('items', 'delete', ['item' => $reviewKey])
            ->assertSee('Dobropisovaná položka: měnit lze jen text.');
    });

    it('saves an unchanged credited row together with a changed other row', function (): void {
        [$issued, $editor, $designKey, $reviewKey] = iedCreditedEditor();

        $editor->set("data.items.{$designKey}.description", 'Design of the landing page')
            ->set("data.items.{$reviewKey}.quantity", '4')
            ->call('save')
            ->assertHasNoFormErrors();

        $lines = iedLines($issued);

        expect($lines[0]->description)->toBe('Design of the landing page')
            ->and($lines[0]->line_total_minor)->toBe(100000)
            ->and($lines[1]->line_total_minor)->toBe(100000)
            ->and(iedRow($issued)->total_minor)->toBe(200000);
    });

    it('refuses a crafted change of a credited row as a field error and keeps the stored figures', function (): void {
        [$issued, $editor, $designKey] = iedCreditedEditor();

        $editor->set("data.items.{$designKey}.quantity", '9')
            ->set("data.items.{$designKey}.unit_price", '1,00')
            ->call('save')
            ->assertHasFormErrors(["items.{$designKey}.quantity"])
            ->assertSee(__('invoices.errors.credited_line_frozen'));

        $lines = iedLines($issued);

        expect($lines[0]->quantity)->toBe('2.0000')
            ->and($lines[0]->unit_price_minor)->toBe(50000)
            ->and($lines[0]->line_total_minor)->toBe(100000)
            ->and(iedRow($issued)->total_minor)->toBe(125000);
    });

    it('does not let a row be removed from the state either: the Action refuses a missing credited line', function (): void {
        [$issued, $editor, $designKey] = iedCreditedEditor();
        $state = $editor->get('data.items');
        unset($state[$designKey]);

        $editor->set('data.items', $state)->call('save')->assertHasFormErrors(['items']);

        expect(iedLines($issued))->toHaveCount(2)
            ->and(iedRow($issued)->total_minor)->toBe(125000);
    });
});

describe('the preview of a converted time line', function (): void {
    it('prices the amount with the frozen quotes, not with the lists of today, and shows the stored CZK figure', function (): void {
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

        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.125');
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
        $issued = app(IssueDocument::class)->handle($this->admin, $draft);

        // A newer list for a later day: a re-price with today's lists would show another amount.
        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:30:00', 'Europe/Prague'));
        InvoiceFixtures::cnbRate('EUR', '2026-10-20', '27.000');

        $editor = iedEditor($issued);
        [$key] = iedKeys($editor);

        $expected = Money::ofMinor(150000, 'CZK')->timesThroughCzk('3.0000', '1', 1, 'EUR', '25.125', 1);
        $czk = Money::ofMinor($expected->minor, 'EUR')->convert('25.125', 'CZK', 1);
        $wrong = Money::ofMinor(150000, 'CZK')->timesThroughCzk('3.0000', '1', 1, 'EUR', '27.000', 1);

        $editor->set("data.items.{$key}.quantity", '3')
            ->assertSee($expected->format('cs'))
            ->assertDontSee($wrong->format('cs'))
            ->assertSee(__('invoices.detail.summary.czk_total', ['czk' => $czk->format('cs')]))
            ->assertDontSee(__('invoices.draft.summary.czk_frozen'));

        expect($wrong->minor)->not->toBe($expected->minor);

        $editor->call('save')->assertHasNoFormErrors();

        expect(iedLines($issued)[0]->line_total_minor)->toBe($expected->minor)
            ->and(iedRow($issued)->total_minor)->toBe($expected->minor);
    });
});

/**
 * The notifications sent so far, flushed from the mailbox.
 *
 * @return list<array{title: string, status: string}>
 */
function iedNotifications(): array
{
    app(NotificationMailbox::class)->flush();

    $panel = new Notifications;
    $panel->mount();
    $panel->pullNotificationsFromSession();

    $sent = [];

    foreach ($panel->notifications as $notification) {
        $sent[] = ['title' => (string) $notification->getTitle(), 'status' => (string) $notification->getStatus()];
    }

    return $sent;
}

describe('a total below what was paid', function (): void {
    it('saves, says so with a warning after the success message, and does not block', function (): void {
        $paid = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '2', '500.00')]);
        app(RecordPayment::class)->handle($this->admin, $paid, ['amount' => '1000.00', 'paid_on' => '2026-10-13']);
        $editor = iedEditor($paid);
        [$key] = iedKeys($editor);

        $editor->set("data.items.{$key}.quantity", '1')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(InvoiceResource::getUrl('view', ['record' => $paid]));

        $sent = iedNotifications();

        expect($sent)->toHaveCount(2)
            ->and($sent[0])->toBe(['title' => 'Změny jsou uložené. PDF bylo vytvořeno znovu, doklad nebyl odeslán.', 'status' => 'success'])
            ->and($sent[1])->toBe(['title' => 'Celková částka je nižší než už zaplacená částka '.Money::ofMinor(100000, 'CZK')->format('cs').'.', 'status' => 'warning'])
            ->and(iedRow($paid)->total_minor)->toBe(50000);
    });

    it('sends the success message alone after an ordinary edit', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '2', '500.00')]);
        app(RecordPayment::class)->handle($this->admin, $issued, ['amount' => '400.00', 'paid_on' => '2026-10-13']);
        $editor = iedEditor($issued);
        [$key] = iedKeys($editor);

        $editor->set("data.items.{$key}.quantity", '3')->call('save')->assertHasNoFormErrors();

        $sent = iedNotifications();

        expect($sent)->toHaveCount(1)
            ->and($sent[0]['status'])->toBe('success')
            ->and(iedRow($issued)->total_minor)->toBe(150000);
    });

    it('refuses a total that is not positive under the table of lines and writes nothing', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '2', '500.00')]);
        $editor = iedEditor($issued);
        [$key] = iedKeys($editor);

        $editor->set("data.items.{$key}.unit_price", '0')
            ->set("data.items.{$key}.confirm_zero_price", true)
            ->call('save')
            ->assertHasFormErrors(['items'])
            ->assertNoRedirect();

        expect(iedRow($issued)->total_minor)->toBe(100000);
    });
});

/**
 * An issued document of one time line (ten hours at 1 500 an hour, rate in CZK) and, when asked, one typed line of
 * 100,00, in the currency; the CNB list of the issue day is stored for EUR.
 */
function iedCurrencyIssued(string $currency, bool $typed): Invoice
{
    InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.125');

    $client = InvoiceFixtures::client();
    $project = app(CreateProject::class)->handle($client, [
        'name' => 'Alpha currency project',
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'hourly_rate' => '1500',
    ]);
    $start = CarbonImmutable::parse('2026-09-10 08:00:00', 'UTC');
    $factory = TimeEntry::factory()->forProject($project);
    app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => $factory->create(['user_id' => Canary::admin()->id, 'started_at' => $start, 'ended_at' => $start->addHours(10)]));

    $draft = app(CreateDraft::class)->handle(test()->admin, [
        'client_id' => $client->id,
        'kind' => 'invoice',
        'source' => 'time',
        'period_from' => '2026-09-01',
        'period_to' => '2026-09-30',
        'project_ids' => [$project->id],
        'without_project' => false,
        'grouping' => 'project',
        'currency' => $currency,
    ]);

    if ($typed) {
        $items = array_map(static fn (InvoiceItem $item): array => [
            'id' => $item->id,
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit' => $item->unit,
            'unit_price' => Money::ofMinor($item->unit_price_minor, $currency)->toMajor(),
        ], iedLines($draft));
        $items[] = InvoiceFixtures::item('Typed design work', '1', '100.00');

        app(UpdateDraft::class)->handle(test()->admin, $draft, ['items' => $items]);
    }

    return app(IssueDocument::class)->handle(test()->admin, $draft);
}

describe('the currency of an open issued document', function (): void {
    it('asks before typed prices are kept, shows the rate price of the time line and stores what it showed', function (): void {
        $issued = iedCurrencyIssued('EUR', true);
        $editor = iedEditor($issued);
        [$timeKey, $typedKey] = iedKeys($editor);

        expect($editor->get("data.items.{$timeKey}.unit_price"))->toBe('59,70');

        $editor->set('data.currency', 'CZK')
            ->assertActionMounted('changeCurrency')
            ->assertMountedActionModalSee(['Změnit měnu dokladu?', 'Ručně zadané jednotkové ceny a pevné částky se nepřepočítají.']);

        expect($editor->get('data.currency'))->toBe('EUR');

        $editor->callMountedAction()->assertHasNoActionErrors();

        expect($editor->get('data.currency'))->toBe('CZK')
            ->and($editor->get("data.items.{$timeKey}.unit_price"))->toBe('1500,00')
            ->and($editor->get("data.items.{$typedKey}.unit_price"))->toBe('100,00');

        $editor->assertSee(Money::ofMinor(1510000, 'CZK')->format('cs'))
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(InvoiceResource::getUrl('view', ['record' => $issued]));

        $row = iedRow($issued);
        [$time, $typed] = iedLines($issued);

        expect($row->currency)->toBe('CZK')
            ->and($time->unit_price_minor)->toBe(150000)
            ->and($time->line_total_minor)->toBe(1500000)
            ->and($typed->unit_price_minor)->toBe(10000)
            ->and($row->total_minor)->toBe(1510000)
            ->and(DB::table('invoice_admin')->where('invoice_id', $issued->id)->value('czk_total_minor'))->toBeNull();
    });

    it('keeps the currency when the confirmation is not given', function (): void {
        $issued = iedCurrencyIssued('EUR', true);

        $editor = iedEditor($issued)->set('data.currency', 'CZK')->assertActionMounted('changeCurrency')->unmountAction();

        expect($editor->get('data.currency'))->toBe('EUR');
    });

    it('previews a currency change with the lists of the issue date', function (): void {
        $issued = iedCurrencyIssued('CZK', false);

        // A newer list for a later day: a preview with today's list would show another amount.
        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:30:00', 'Europe/Prague'));
        InvoiceFixtures::cnbRate('EUR', '2026-10-20', '27.000');

        $editor = iedEditor($issued)->set('data.currency', 'EUR')->assertActionNotMounted();
        [$key] = iedKeys($editor);

        $expected = Money::ofMinor(150000, 'CZK')->timesThroughCzk('10.0000', '1', 1, 'EUR', '25.125', 1);
        $wrong = Money::ofMinor(150000, 'CZK')->timesThroughCzk('10.0000', '1', 1, 'EUR', '27.000', 1);
        $czk = Money::ofMinor($expected->minor, 'EUR')->convert('25.125', 'CZK', 1);

        expect($wrong->minor)->not->toBe($expected->minor)
            ->and($editor->get("data.items.{$key}.unit_price"))->toBe('59,70');

        $editor->assertSee($expected->format('cs'))
            ->assertDontSee($wrong->format('cs'))
            ->assertSee(__('invoices.detail.summary.czk_total', ['czk' => $czk->format('cs')]))
            ->call('save')
            ->assertHasNoFormErrors();

        $admin = DB::table('invoice_admin')->where('invoice_id', $issued->id)->first();

        expect(iedLines($issued)[0]->line_total_minor)->toBe($expected->minor)
            ->and(iedRow($issued)->currency)->toBe('EUR')
            ->and(iedRow($issued)->total_minor)->toBe($expected->minor)
            ->and($admin?->czk_total_minor)->toBe($czk->minor)
            ->and($admin?->exchange_rate_date)->toBe('2026-10-14');
    });

    it('shows the refusal of a document with a payment under the currency and stores nothing', function (): void {
        $issued = iedCurrencyIssued('CZK', true);
        app(RecordPayment::class)->handle($this->admin, $issued, ['amount' => '400.00', 'paid_on' => '2026-10-14']);
        $before = iedRow($issued)->getAttributes();

        $editor = iedEditor($issued)->set('data.currency', 'EUR')->callMountedAction();
        $editor->call('save')
            ->assertHasFormErrors(['currency'])
            ->assertSee('Měnu nelze změnit, k dokladu jsou zaznamenané platby.')
            ->assertNoRedirect();

        expect(iedRow($issued)->getAttributes())->toBe($before)
            ->and(iedLines($issued)[0]->unit_price_minor)->toBe(150000);
    });
});

/**
 * The IBAN of an account as the document prints it: groups of four.
 */
function iedPrintedIban(string $fragment): string
{
    return trim(chunk_split(FictionalIban::czech($fragment), 4, ' '));
}

describe('the payment method in the issued editor (Phase 14)', function (): void {
    beforeEach(function (): void {
        CardPaymentSetup::enable();
        $this->accounts = InvoiceFixtures::configureAccounts(
            InvoiceFixtures::account('First CZK', 'CZK', '12'),
            InvoiceFixtures::account('Second CZK', 'CZK', '56'),
            InvoiceFixtures::account('Example EUR', 'EUR', '34'),
        );
    });

    /**
     * An open issued transfer invoice of a client with online payment on; the card switch is turned off when asked.
     */
    function iedCardInvoice(bool $cardOn = true, bool $clientFlag = true): Invoice
    {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client(['online_payment_enabled' => $clientFlag]));

        if (! $cardOn) {
            app(UpdateIssuedDocument::class)->handle(test()->admin, $invoice, ['card_payment_enabled' => false]);
        }

        return $invoice;
    }

    /**
     * The payment links of the document, oldest first.
     *
     * @return list<PaymentLink>
     */
    function iedLinks(Invoice $invoice): array
    {
        return array_values(app(PartnerContext::class)->runAsSystem(static fn (): array => PaymentLink::query()->where('invoice_id', $invoice->id)->orderBy('created_at')->orderBy('id')->get()->all()));
    }

    /**
     * The Admin-only row of the document, read fresh.
     */
    function iedAdminRow(Invoice $invoice): object
    {
        return DB::table('invoice_admin')->where('invoice_id', $invoice->id)->firstOrFail();
    }

    it('tracer: switches an unsent card-off transfer invoice to online, and the detail shows the method, no bank rows, a new PDF and a pending link', function (): void {
        $invoice = iedCardInvoice(cardOn: false);
        $oldPdf = iedPdfId($invoice);
        $printed = iedPrintedIban('12');

        iedDetail($invoice)->assertSee('Bankovní převod')->assertSee($printed);

        Queue::fake([CreatePaymentLink::class]);

        iedEditor($invoice)
            ->assertSet('data.payment_method', 'transfer')
            ->assertSet('data.bank_account_id', $this->accounts[0]->id)
            ->assertSet('data.card_payment_enabled', false)
            ->assertFormFieldIsEnabled('card_payment_enabled')
            ->assertFormFieldIsVisible('bank_account_id')
            ->set('data.payment_method', 'online')
            ->assertSet('data.card_payment_enabled', true)
            ->assertFormFieldIsDisabled('card_payment_enabled')
            ->assertFormFieldIsHidden('bank_account_id')
            ->assertSee('Online úhrada: doklad bankovní údaje netiskne.')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect(InvoiceResource::getUrl('view', ['record' => $invoice]));

        $links = iedLinks($invoice);

        expect(iedRow($invoice)->payment_method)->toBe(DocumentPaymentMethod::Online)
            ->and(iedRow($invoice)->bank_snapshot)->toBeNull()
            ->and(iedPdfId($invoice))->not->toBeNull()
            ->and(iedPdfId($invoice))->not->toBe($oldPdf)
            ->and(iedAdminRow($invoice)->card_payment_enabled)->toBeTrue()
            ->and(array_values(array_filter($links, static fn (PaymentLink $link): bool => $link->state === PaymentLinkState::Pending)))->toHaveCount(1);

        Queue::assertPushed(CreatePaymentLink::class);

        iedDetail($invoice)->assertSee('Online platba kartou')->assertDontSee($printed);
    });

    it('goes back to transfer on the second account of the currency: the toggle follows the client flag, the new snapshot is the chosen account', function (): void {
        $invoice = iedCardInvoice();
        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['payment_method' => 'online']);

        $editor = iedEditor($invoice)
            ->assertSet('data.payment_method', 'online')
            ->assertSet('data.card_payment_enabled', true)
            ->assertFormFieldIsDisabled('card_payment_enabled')
            ->assertFormFieldIsHidden('bank_account_id');

        $editor->set('data.payment_method', 'transfer')
            ->assertSet('data.card_payment_enabled', true)
            ->assertFormFieldIsEnabled('card_payment_enabled')
            ->assertFormFieldIsVisible('bank_account_id')
            ->assertSet('data.bank_account_id', $this->accounts[0]->id)
            ->assertSee('Po uložení se použije účet:')
            ->set('data.bank_account_id', $this->accounts[1]->id)
            ->assertSee('Second CZK')
            ->call('save')
            ->assertHasNoFormErrors();

        expect(iedRow($invoice)->payment_method)->toBe(DocumentPaymentMethod::Transfer)
            ->and(iedRow($invoice)->bank_snapshot)->toEqual($this->accounts[1]->toArray())
            ->and(iedAdminRow($invoice)->bank_account_id)->toBe($this->accounts[1]->id);

        iedDetail($invoice)->assertSee('Bankovní převod')->assertSee(iedPrintedIban('56'));
    });

    it('lets the Admin turn the card switch of a transfer invoice off and on again, and the link follows', function (): void {
        $invoice = iedCardInvoice();

        iedEditor($invoice)->assertSet('data.card_payment_enabled', true)->set('data.card_payment_enabled', false)->call('save')->assertHasNoFormErrors();

        expect((bool) iedAdminRow($invoice)->card_payment_enabled)->toBeFalse()
            ->and(array_values(array_filter(iedLinks($invoice), static fn (PaymentLink $link): bool => $link->isLive())))->toHaveCount(0);

        iedEditor($invoice)->assertSet('data.card_payment_enabled', false)->set('data.card_payment_enabled', true)->call('save')->assertHasNoFormErrors();

        expect((bool) iedAdminRow($invoice)->card_payment_enabled)->toBeTrue()
            ->and(array_values(array_filter(iedLinks($invoice), static fn (PaymentLink $link): bool => $link->isLive())))->toHaveCount(1);
    });

    it('disables the online option with the reason, refuses a crafted online value under the field, and writes nothing', function (): void {
        $invoice = iedCardInvoice(clientFlag: false);
        $before = iedRow($invoice)->getAttributes();

        $editor = iedEditor($invoice)
            ->assertSee('Online úhradu nelze zvolit: klient nemá zapnutou online platbu.')
            ->assertFormFieldExists('payment_method', fn ($field): bool => $field->isOptionDisabled('online', 'Online') && ! $field->isOptionDisabled('transfer', 'Převodem'));

        $editor->set('data.payment_method', 'online')->call('save')->assertHasFormErrors(['payment_method'])->assertNoRedirect();

        expect(iedRow($invoice)->getAttributes())->toBe($before);
    });

    it('keeps the online option selectable on a document that is online already', function (): void {
        $invoice = iedCardInvoice();
        app(UpdateIssuedDocument::class)->handle($this->admin, $invoice, ['payment_method' => 'online']);
        CardPaymentSetup::onlinePayments(false);

        iedEditor($invoice)
            ->assertFormFieldExists('payment_method', fn ($field): bool => ! $field->isOptionDisabled('online', 'Online'))
            ->set('data.note', 'Still online.')
            ->call('save')
            ->assertHasNoFormErrors();

        expect(iedRow($invoice)->payment_method)->toBe(DocumentPaymentMethod::Online)
            ->and(iedRow($invoice)->note)->toBe('Still online.');
    });

    it('locks the card toggle while card payment is unavailable and does not send it', function (): void {
        $invoice = iedCardInvoice(cardOn: false);
        CardPaymentSetup::onlinePayments(false);

        iedEditor($invoice)
            ->assertFormFieldIsDisabled('card_payment_enabled')
            ->set('data.note', 'Toggle untouched.')
            ->call('save')
            ->assertHasNoFormErrors();

        expect((bool) iedAdminRow($invoice)->card_payment_enabled)->toBeFalse()
            ->and(iedRow($invoice)->note)->toBe('Toggle untouched.');
    });

    it('shows a refusal of the Action under the account field and writes nothing', function (): void {
        $invoice = iedCardInvoice();
        $before = iedRow($invoice)->bank_snapshot;

        iedEditor($invoice)
            ->set('data.bank_account_id', null)
            ->call('save')
            ->assertHasFormErrors(['bank_account_id'])
            ->assertSee(__('invoices.errors.bank_account_required'))
            ->assertNoRedirect();

        expect(iedRow($invoice)->bank_snapshot)->toBe($before);
    });

    it('keeps the printed account on an unrelated save when the stored account was removed from the settings', function (): void {
        $invoice = iedCardInvoice();
        $printed = iedRow($invoice)->bank_snapshot;

        InvoiceFixtures::configureAccounts(InvoiceFixtures::account('Only EUR', 'EUR', '34'));

        iedEditor($invoice)
            ->assertSet('data.bank_account_id', null)
            ->assertSee(iedPrintedIban('12'))
            ->set('data.note', 'Unrelated change.')
            ->call('save')
            ->assertHasNoFormErrors();

        expect(iedRow($invoice)->note)->toBe('Unrelated change.')
            ->and(iedRow($invoice)->bank_snapshot)->toBe($printed);
    });

    it('preselects the first account of the new currency on a currency change, and any account can be picked afterwards', function (): void {
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.125');
        $invoice = iedCardInvoice();

        // The line is typed by hand, so the change waits for the confirmation; the account follows after it (Pitfall 10).
        iedEditor($invoice)
            ->set('data.currency', 'EUR')
            ->assertActionMounted('changeCurrency')
            ->callMountedAction()
            ->assertSet('data.bank_account_id', $this->accounts[2]->id)
            ->assertSee('Po uložení se použije účet:')
            ->assertSee('Example EUR')
            ->set('data.bank_account_id', $this->accounts[1]->id)
            ->call('save')
            ->assertHasNoFormErrors();

        expect(iedRow($invoice)->currency)->toBe('EUR')
            ->and(iedRow($invoice)->bank_snapshot)->toEqual($this->accounts[1]->toArray())
            ->and(iedAdminRow($invoice)->bank_account_id)->toBe($this->accounts[1]->id);
    });

    it('takes the preselected account of the new currency when the Admin does not pick another', function (): void {
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.125');
        $invoice = iedCardInvoice();

        iedEditor($invoice)->set('data.currency', 'EUR')->callMountedAction()->call('save')->assertHasNoFormErrors();

        expect(iedRow($invoice)->bank_snapshot)->toEqual($this->accounts[2]->toArray())
            ->and(iedAdminRow($invoice)->bank_account_id)->toBe($this->accounts[2]->id);
    });

    it('keeps the stored account when the currency changes to one without an account and the Admin picks the stored account again', function (): void {
        InvoiceFixtures::cnbRate('USD', '2026-10-14', '22.500');
        $invoice = iedCardInvoice();

        iedEditor($invoice)
            ->set('data.currency', 'USD')
            ->assertActionMounted('changeCurrency')
            ->callMountedAction()
            ->assertSet('data.bank_account_id', null)
            ->set('data.bank_account_id', $this->accounts[0]->id)
            ->call('save')
            ->assertHasNoFormErrors();

        expect(iedRow($invoice)->currency)->toBe('USD')
            ->and(iedRow($invoice)->bank_snapshot)->toEqual($this->accounts[0]->toArray())
            ->and(iedAdminRow($invoice)->bank_account_id)->toBe($this->accounts[0]->id);
    });
});
