<?php

declare(strict_types=1);

use App\Domain\Invoicing\Enums\InvoiceEmailState;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Operations\Health\HealthIndicatorRegistry;
use App\Domain\Operations\Health\HealthSlot;
use App\Domain\Operations\Health\HealthStatus;
use App\Domain\Operations\Health\Indicators\PlaceholderIndicator;
use App\Domain\Operations\Health\Indicators\UnsentInvoiceEmailsIndicator;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Tests\Support\Canary;
use Tests\Support\InvoiceFixtures;

/*
 * The unsent invoice e-mails slot of the System page (IN-04; D-22; ruling R-24-1, R-37-1): the state of a document is
 * the state of its latest e-mail row. The indicator reads states and timestamps only, so the rows are inserted as
 * system and no job or mail transport is needed. Every address and amount is invented.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    InvoiceFixtures::configureSupplier();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

/**
 * Logs one e-mail row of the invoice, `$minutesAgo` minutes before the frozen clock (the creation and the last move).
 */
function unsentLogged(Invoice $invoice, InvoiceEmailState $state, int $minutesAgo = 0): void
{
    $moment = now()->subMinutes($minutesAgo);

    app(PartnerContext::class)->runAsSystem(static function () use ($invoice, $state, $moment): void {
        (new InvoiceEmail)->forceFill([
            'invoice_id' => $invoice->id,
            'recipient' => 'jane@'.implode('.', ['example', 'com']),
            'subject' => 'Example subject',
            'body' => 'Example body',
            'state' => $state,
            'sent_at' => $state === InvoiceEmailState::Sent ? $moment : null,
            'failure_code' => $state === InvoiceEmailState::Failed ? 'transport' : null,
            'created_by' => Canary::admin()->getKey(),
            'created_at' => $moment,
            'updated_at' => $moment,
        ])->save();
    });
}

function unsentInvoice(): Invoice
{
    return InvoiceFixtures::issued(InvoiceFixtures::client());
}

it('answers Ok with a count of zero when no e-mail was queued', function (): void {
    $result = (new UnsentInvoiceEmailsIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Ok)
        ->and($result->value)->toBe('0')
        ->and($result->detail)->toBe('Všechny e-maily s fakturou byly odeslány.')
        ->and($result->url)->toBeNull()
        ->and((new UnsentInvoiceEmailsIndicator)->slot())->toBe(HealthSlot::UnsentInvoiceEmails);
});

it('answers Error with the count, the resend hint and the link to the list filtered by the failed e-mails', function (): void {
    unsentLogged(unsentInvoice(), InvoiceEmailState::Failed, 5);

    $result = (new UnsentInvoiceEmailsIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->value)->toBe('1')
        ->and($result->detail)->toBe('Neodesláno: 1. Odešlete je znovu z přehledu faktur.')
        ->and($result->urlLabel)->toBe('Zobrazit neodeslané')
        ->and(urldecode((string) $result->url))->toContain('/admin/invoices')
        ->and(urldecode((string) $result->url))->toContain('tableFilters[email][value]=failed');
});

it('counts every document once and lets a later successful send clear the failure', function (): void {
    $recovered = unsentInvoice();
    unsentLogged($recovered, InvoiceEmailState::Failed, 30);
    unsentLogged($recovered, InvoiceEmailState::Sent, 10);

    expect((new UnsentInvoiceEmailsIndicator)->check()->status)->toBe(HealthStatus::Ok);

    $stuck = unsentInvoice();
    unsentLogged($stuck, InvoiceEmailState::Failed, 30);
    unsentLogged($stuck, InvoiceEmailState::Failed, 20);
    unsentLogged($stuck, InvoiceEmailState::Failed, 10);

    $result = (new UnsentInvoiceEmailsIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->value)->toBe('1');
});

it('is the indicator the application registry runs for the slot, with no placeholder', function (): void {
    unsentLogged(unsentInvoice(), InvoiceEmailState::Failed, 5);

    $registry = app(HealthIndicatorRegistry::class);

    expect($registry->indicators()[HealthSlot::UnsentInvoiceEmails->value])->toBeInstanceOf(UnsentInvoiceEmailsIndicator::class)
        ->and($registry->indicators()[HealthSlot::UnsentInvoiceEmails->value])->not->toBeInstanceOf(PlaceholderIndicator::class)
        ->and($registry->results()[HealthSlot::UnsentInvoiceEmails->value]->status)->toBe(HealthStatus::Error);
});

it('shows the slot to the Admin on the System page with the count and the link', function (): void {
    unsentLogged(unsentInvoice(), InvoiceEmailState::Failed, 5);

    $this->actingAs(Canary::admin())->get('/admin/system')
        ->assertOk()
        ->assertSee(__('enums.health_slot.unsent_invoice_emails'))
        ->assertSee('Neodesláno: 1. Odešlete je znovu z přehledu faktur.')
        ->assertSee('Zobrazit neodeslané')
        ->assertDontSee('jane@')
        ->assertDontSee('transport');
});

it('answers Warning once a queued e-mail has waited past the warning threshold, and Ok before it', function (): void {
    $waiting = unsentInvoice();
    unsentLogged($waiting, InvoiceEmailState::Queued, 5);

    expect((new UnsentInvoiceEmailsIndicator)->check()->status)->toBe(HealthStatus::Ok);

    $late = unsentInvoice();
    unsentLogged($late, InvoiceEmailState::Queued, 20);

    $result = (new UnsentInvoiceEmailsIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Warning)
        ->and($result->value)->toBe('1')
        ->and($result->detail)->toBe('Čekající e-maily s fakturou: 1.')
        ->and($result->urlLabel)->toBe('Zobrazit neodeslané')
        ->and(urldecode((string) $result->url))->toContain('tableFilters[email][value]=queued');
});

it('grades a sending row by the time since the claim: fresh is Ok, slow is Warning, older than the stale threshold counts as failed', function (): void {
    $invoice = unsentInvoice();
    unsentLogged($invoice, InvoiceEmailState::Sending, 10);

    expect((new UnsentInvoiceEmailsIndicator)->check()->status)->toBe(HealthStatus::Ok);

    $slow = unsentInvoice();
    unsentLogged($slow, InvoiceEmailState::Sending, 20);

    $result = (new UnsentInvoiceEmailsIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Warning)
        ->and($result->value)->toBe('1');

    $dead = unsentInvoice();
    unsentLogged($dead, InvoiceEmailState::Sending, 90);

    $result = (new UnsentInvoiceEmailsIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->value)->toBe('1')
        ->and($result->detail)->toBe('Neodesláno: 1. Odešlete je znovu z přehledu faktur.')
        ->and(urldecode((string) $result->url))->toContain('tableFilters[email][value]=failed');
});

it('lets a failure outrank a wait and counts only the rows of the winning grade', function (): void {
    unsentLogged(unsentInvoice(), InvoiceEmailState::Queued, 30);
    unsentLogged(unsentInvoice(), InvoiceEmailState::Queued, 40);
    unsentLogged(unsentInvoice(), InvoiceEmailState::Failed, 50);

    $result = (new UnsentInvoiceEmailsIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->value)->toBe('1');

    unsentLogged(unsentInvoice(), InvoiceEmailState::Sending, 120);

    expect((new UnsentInvoiceEmailsIndicator)->check()->value)->toBe('2');
});

it('ignores an old waiting row once a newer row of the same document was sent', function (): void {
    $invoice = unsentInvoice();
    unsentLogged($invoice, InvoiceEmailState::Queued, 120);
    unsentLogged($invoice, InvoiceEmailState::Sent, 100);

    expect((new UnsentInvoiceEmailsIndicator)->check()->status)->toBe(HealthStatus::Ok);
});

it('takes both thresholds from the configuration', function (): void {
    unsentLogged(unsentInvoice(), InvoiceEmailState::Queued, 20);
    config(['kokpit.health.unsent_invoice_email_warning_after' => 1800]);

    expect((new UnsentInvoiceEmailsIndicator)->check()->status)->toBe(HealthStatus::Ok);

    unsentLogged(unsentInvoice(), InvoiceEmailState::Sending, 20);
    config(['kokpit.health.unsent_invoice_email_warning_after' => 300, 'kokpit.health.unsent_invoice_email_stale_after' => 600]);

    expect((new UnsentInvoiceEmailsIndicator)->check()->status)->toBe(HealthStatus::Error);
});

it('never lets the stale threshold sit below the warning one', function (): void {
    unsentLogged(unsentInvoice(), InvoiceEmailState::Sending, 20);
    config(['kokpit.health.unsent_invoice_email_warning_after' => 1800, 'kokpit.health.unsent_invoice_email_stale_after' => 60]);

    expect((new UnsentInvoiceEmailsIndicator)->check()->status)->toBe(HealthStatus::Ok);
});

it('never shows a recipient, a subject, an amount or a failure code in any grade', function (): void {
    $recipient = 'jane@'.implode('.', ['example', 'com']);
    unsentLogged(unsentInvoice(), InvoiceEmailState::Queued, 30);
    $warning = (new UnsentInvoiceEmailsIndicator)->check();

    unsentLogged(unsentInvoice(), InvoiceEmailState::Failed, 30);
    $error = (new UnsentInvoiceEmailsIndicator)->check();

    foreach ([$warning, $error] as $result) {
        $text = (string) $result->value.' '.$result->detail.' '.$result->urlLabel.' '.urldecode((string) $result->url);

        expect($text)->not->toContain($recipient)
            ->and($text)->not->toContain('jane')
            ->and($text)->not->toContain('Example subject')
            ->and($text)->not->toContain('transport')
            ->and($text)->not->toContain('1000');
    }
});
