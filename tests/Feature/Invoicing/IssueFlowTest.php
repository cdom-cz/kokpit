<?php

declare(strict_types=1);

use App\Domain\Documents\Models\Document;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Settings\Settings\BankAccountSettings;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Pages\EditInvoice;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\NotificationMailbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The issue flow of the panel (IN-01, IN-03, IN-07; D-10 to D-12, D-23, UI-SPEC Surface D): "Vystavit" saves the draft,
 * runs the pre-issue check and either lists every blocker without opening a modal or confirms with the expected number;
 * the submit goes through IssueDocument and opens the issued detail. Every name and amount is fictional.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    InvoiceFixtures::configureSupplier();
    // Wednesday 2026-10-14, 12:00 in Prague.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

/**
 * The editor of a draft.
 */
function issueEditor(Invoice $draft): Testable
{
    return Livewire::test(EditInvoice::class, ['record' => $draft->getRouteKey()]);
}

/**
 * The invoice row as system, whatever its state.
 */
function issueReload(Invoice $invoice): Invoice
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Invoice => Invoice::query()->findOrFail($invoice->id));
}

/**
 * The notifications flashed to the session so far: title and body, danger ones included.
 *
 * @return list<array{title: string, body: string, status: string}>
 */
function issueNotifications(): array
{
    app(NotificationMailbox::class)->flush();

    $panel = new Notifications;
    $panel->mount();
    $panel->pullNotificationsFromSession();

    $sent = [];

    foreach ($panel->notifications as $notification) {
        $sent[] = [
            'title' => (string) $notification->getTitle(),
            'body' => (string) $notification->getBody(),
            'status' => (string) $notification->getStatus(),
        ];
    }

    return $sent;
}

describe('the tracer: Vystavit on a complete draft', function (): void {
    it('confirms with the summary and the expected number, issues, and opens the issued detail with its PDF', function (): void {
        $client = InvoiceFixtures::client(['name' => 'Example issue client']);
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '2', '500.00')]);

        $editor = issueEditor($draft)
            ->mountAction('issue')
            ->assertActionMounted('issue')
            ->assertMountedActionModalSee(['Vystavit fakturu?', 'Example issue client', Money::ofMinor(100000, 'CZK')->format('cs'), '14. 10. 2026', '28. 10. 2026', 'CZK', 'Číslo se přidělí při vystavení', 'očekávané číslo']);

        // The callout names exactly what the counter would hand out now.
        $expected = app(IssueDocument::class)->expectedNumber($draft);

        $editor->assertMountedActionModalSee($expected);

        $editor->callMountedAction()
            ->assertNotified('Faktura byla vystavena');

        $issued = issueReload($draft);

        expect($issued->isIssued())->toBeTrue()
            ->and($issued->number)->toBe($expected);

        $editor->assertRedirect(InvoiceResource::getUrl('view', ['record' => $issued, 'send' => 1]));

        $view = Livewire::test(ViewInvoice::class, ['record' => $issued->getRouteKey()]);
        $view->assertSee($issued->number);

        $pdf = app(PartnerContext::class)->runAsSystem(static fn (): ?Document => InvoiceAdmin::query()->where('invoice_id', $issued->id)->first()?->pdfDocument);

        expect($pdf)->toBeInstanceOf(Document::class);
    });

    it('titles a proforma confirmation and toast with the proforma wording', function (): void {
        $client = InvoiceFixtures::client();
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '100.00')], InvoiceKind::Proforma);

        issueEditor($draft)
            ->mountAction('issue')
            ->assertMountedActionModalSee('Vystavit zálohovou fakturu?')
            ->callMountedAction()
            ->assertNotified('Zálohová faktura byla vystavena');

        expect(issueReload($draft)->isIssued())->toBeTrue();
    });

    it('saves the unsaved edits of the form before it checks', function (): void {
        $client = InvoiceFixtures::client();
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '100.00')]);

        $editor = issueEditor($draft);
        $key = array_key_first($editor->get('data.items'));
        $editor->set('data.items.'.$key.'.unit_price', '300.00')->mountAction('issue')->assertMountedActionModalSee(Money::ofMinor(30000, 'CZK')->format('cs'));

        expect(issueReload($draft)->total_minor)->toBe(30000);
    });

    it('shows the CNB preview for a foreign currency', function (): void {
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '24.500');
        $client = InvoiceFixtures::client(['currency' => 'EUR']);
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '2', '100.00')]);

        issueEditor($draft)
            ->mountAction('issue')
            ->assertActionMounted('issue')
            ->assertMountedActionModalSee(['Kurz ČNB ke dni vystavení', '24,500', Money::ofMinor(490000, 'CZK')->format('cs')]);
    });
});

describe('the pre-issue check', function (): void {
    it('lists a draft without items in a danger notification and opens no modal', function (): void {
        $client = InvoiceFixtures::client();
        $draft = InvoiceFixtures::draft($client);

        issueEditor($draft)
            ->mountAction('issue')
            ->assertActionNotMounted('issue')
            ->assertNotified(__('invoices.issue.blockers_title'));

        expect(issueReload($draft)->isDraft())->toBeTrue();
    });

    it('names every blocker with its message', function (): void {
        $client = InvoiceFixtures::client();
        $draft = InvoiceFixtures::draft($client);

        issueEditor($draft)->mountAction('issue')->assertActionNotMounted('issue');

        $sent = issueNotifications();

        expect($sent)->toHaveCount(1)
            ->and($sent[0]['status'])->toBe('danger')
            ->and($sent[0]['title'])->toBe(__('invoices.issue.blockers_title'))
            ->and($sent[0]['body'])->toContain('<li>Doklad nemá žádnou položku.</li>')
            ->and($sent[0]['body'])->toContain('<li>Celková částka musí být větší než nula.</li>');
    });

    it('names the missing bank account of a transfer document', function (): void {
        $client = InvoiceFixtures::client(['currency' => 'EUR']);
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '24.500');
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '100.00')]);

        app(PartnerContext::class)->runAsSystem(static function (): void {
            $banks = app(BankAccountSettings::class);
            $banks->accounts = array_values(array_filter($banks->accounts, static fn ($account): bool => $account->currency !== 'EUR'));
            $banks->save();
        });
        app()->forgetScopedInstances();

        issueEditor($draft)->mountAction('issue')->assertActionNotMounted('issue');

        expect(issueNotifications()[0]['body'])->toContain('<li>Doklad s úhradou převodem nemá vybraný bankovní účet. Vyberte ho na dokladu, nebo ho přidejte v Nastavení.</li>');
    });
});

describe('a failed issue', function (): void {
    it('shows the fixed copy, never the exception text, and keeps the draft', function (): void {
        $client = InvoiceFixtures::client();
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '100.00')]);

        $secret = 'The renderer fell over at '.str_repeat('x', 6);
        $sequences = DB::table('number_sequences')->orderBy('scope_key')->get()->map(static fn (object $row): array => (array) $row)->all();

        View::composer('pdf.invoice', static function () use ($secret): void {
            throw new RuntimeException($secret);
        });

        $editor = issueEditor($draft)
            ->mountAction('issue')
            ->callMountedAction();

        $sent = issueNotifications();

        expect($sent)->toHaveCount(1)
            ->and($sent[0]['status'])->toBe('danger')
            ->and($sent[0]['title'])->toBe(__('invoices.issue.failed_title'))
            ->and($sent[0]['body'])->toBe(__('invoices.issue.failed_body'))
            ->and(json_encode($sent))->not->toContain($secret);

        $editor->assertNoRedirect();

        expect($editor->html())->not->toContain($secret);

        $after = issueReload($draft);

        expect($after->isDraft())->toBeTrue()
            ->and($after->number)->toBeNull()
            ->and(DB::table('number_sequences')->orderBy('scope_key')->get()->map(static fn (object $row): array => (array) $row)->all())->toBe($sequences);
    });

    it('refuses a document that was issued in the meantime without a second number', function (): void {
        $client = InvoiceFixtures::client();
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '100.00')]);

        $editor = issueEditor($draft)->mountAction('issue');

        // Another tab issues the same draft between the confirmation and the submit.
        $number = app(IssueDocument::class)->handle(Canary::admin(), issueReload($draft))->number;

        $editor->callMountedAction();

        expect(issueReload($draft)->number)->toBe($number);
    });
});

describe('access', function (): void {
    it('refuses a Partner the editor page', function (): void {
        $client = InvoiceFixtures::client();
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '100.00')]);

        $this->actingAs(Canary::partnerFor($client->id));

        Livewire::test(EditInvoice::class, ['record' => $draft->getRouteKey()])->assertForbidden();
    });
});
