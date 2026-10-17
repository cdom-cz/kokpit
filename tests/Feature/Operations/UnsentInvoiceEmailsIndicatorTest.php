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
