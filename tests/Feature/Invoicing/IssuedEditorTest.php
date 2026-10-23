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
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
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
use Tests\Support\DocumentFixtures;
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

    it('offers the currency, the dates, the language and the customer lines for editing', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '2', '500.00')]);

        iedEditor($issued)
            ->assertSee('Odběratel a dodavatel')
            ->assertFormFieldExists('issue_date', fn ($field): bool => ! $field->isDisabled())
            ->assertFormFieldExists('due_date', fn ($field): bool => ! $field->isDisabled())
            ->assertFormFieldExists('language', fn ($field): bool => ! $field->isDisabled())
            ->assertFormFieldExists('customer.name', fn ($field): bool => ! $field->isDisabled())
            ->assertFormFieldExists('currency', fn ($field): bool => ! $field->isDisabled())
            ->assertFormSet(['customer.name' => iedRow($issued)->customer_snapshot['name']]);
    });

    it('does not show the parties section on a draft', function (): void {
        $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '2', '500.00')]);

        iedEditor($draft)->assertDontSee('Odběratel a dodavatel');
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

    it('shows no "Upravit" on the detail of a document that cannot be edited', function (): void {
        $locked = InvoiceFixtures::lockedIssued(InvoiceFixtures::client());
        $credit = InvoiceFixtures::stornoed(InvoiceFixtures::issued(InvoiceFixtures::client()));

        iedDetail($locked)->assertActionHidden('edit');
        iedDetail($credit)->assertActionHidden('edit');
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
