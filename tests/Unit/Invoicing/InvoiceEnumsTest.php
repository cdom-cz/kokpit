<?php

declare(strict_types=1);

use App\Domain\Invoicing\Enums\InvoiceEmailState;
use App\Domain\Invoicing\Enums\InvoiceItemOrigin;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Settings\Numbering\DocumentKind;
use Tests\TestCase;

/*
 * The vocabulary of the invoicing module (D-10, D-18, D-20, IN-01, IN-09). Labels come from the
 * translator, so the application is booted.
 */
uses(TestCase::class);

it('labels the document kinds and maps each to its own number series', function (): void {
    expect(InvoiceKind::Invoice->getLabel())->toBe('Faktura')
        ->and(InvoiceKind::Proforma->getLabel())->toBe('Zálohová faktura')
        ->and(InvoiceKind::CreditNote->getLabel())->toBe('Dobropis')
        ->and(InvoiceKind::Invoice->numberingKind())->toBe(DocumentKind::Invoice)
        ->and(InvoiceKind::Proforma->numberingKind())->toBe(DocumentKind::Proforma)
        ->and(InvoiceKind::CreditNote->numberingKind())->toBe(DocumentKind::CreditNote);
});

it('never lets two document kinds share a counter', function (): void {
    $series = array_map(fn (InvoiceKind $kind): string => $kind->numberingKind()->sequenceKind(), InvoiceKind::cases());

    expect($series)->toHaveCount(3)->and(array_unique($series))->toHaveCount(3);
});

it('lets an invoice and a proforma carry a payment but not a credit note', function (): void {
    expect(InvoiceKind::Invoice->carriesPayment())->toBeTrue()
        ->and(InvoiceKind::Proforma->carriesPayment())->toBeTrue()
        ->and(InvoiceKind::CreditNote->carriesPayment())->toBeFalse();
});

it('stores only the draft and issued states', function (): void {
    expect(array_map(fn (InvoiceState $state): string => $state->value, InvoiceState::cases()))->toBe(['draft', 'issued'])
        ->and(InvoiceState::Draft->getLabel())->toBe('Koncept')
        ->and(InvoiceState::Issued->getLabel())->toBe('Vystaveno');
});

it('reads every derived state in Czech for the Admin', function (): void {
    expect(InvoiceStatus::Draft->getLabel())->toBe('Koncept')
        ->and(InvoiceStatus::Unpaid->getLabel())->toBe('Nezaplaceno')
        ->and(InvoiceStatus::PartiallyPaid->getLabel())->toBe('Částečně zaplaceno')
        ->and(InvoiceStatus::Overdue->getLabel())->toBe('Po splatnosti')
        ->and(InvoiceStatus::Paid->getLabel())->toBe('Zaplaceno')
        ->and(InvoiceStatus::Cancelled->getLabel())->toBe('Stornováno')
        ->and(InvoiceStatus::Converted->getLabel())->toBe('Převedeno na fakturu')
        ->and(InvoiceStatus::AwaitingInvoice->getLabel())->toBe('Zaplacená záloha bez faktury')
        ->and(InvoiceStatus::CreditNote->getLabel())->toBe('Dobropis');
});

it('reads the derived states for the Partner as the client sees them', function (): void {
    expect(InvoiceStatus::Unpaid->partnerLabel())->toBe('Čeká na úhradu')
        ->and(InvoiceStatus::PartiallyPaid->partnerLabel())->toBe('Částečně zaplaceno')
        ->and(InvoiceStatus::Overdue->partnerLabel())->toBe('Po splatnosti')
        ->and(InvoiceStatus::Paid->partnerLabel())->toBe('Zaplaceno')
        ->and(InvoiceStatus::Cancelled->partnerLabel())->toBe('Stornováno')
        ->and(InvoiceStatus::CreditNote->partnerLabel())->toBe('Dobropis')
        // a paid proforma, with or without its invoice, is simply paid to the client (T-10-82)
        ->and(InvoiceStatus::Converted->partnerLabel())->toBe('Zaplaceno')
        ->and(InvoiceStatus::AwaitingInvoice->partnerLabel())->toBe('Zaplaceno');
});

it('never shows a draft to a Partner', function (): void {
    InvoiceStatus::Draft->partnerLabel();
})->throws(LogicException::class);

it('colours the derived states by the UI-SPEC map and gives each an icon', function (): void {
    expect(InvoiceStatus::Draft->getColor())->toBe('gray')
        ->and(InvoiceStatus::Unpaid->getColor())->toBe('info')
        ->and(InvoiceStatus::PartiallyPaid->getColor())->toBe('info')
        ->and(InvoiceStatus::Overdue->getColor())->toBe('danger')
        ->and(InvoiceStatus::Paid->getColor())->toBe('success')
        ->and(InvoiceStatus::Cancelled->getColor())->toBe('gray')
        ->and(InvoiceStatus::Converted->getColor())->toBe('gray')
        ->and(InvoiceStatus::AwaitingInvoice->getColor())->toBe('danger');

    foreach (InvoiceStatus::cases() as $status) {
        expect($status->getIcon())->not->toBeNull();
    }
});

it('words no Partner label with a draft, a payment row, an e-mail state or a line source', function (): void {
    $forbidden = ['Koncept', 'Ve frontě', 'Odesílá', 'Odesláno', 'Z času', 'Ruční', 'Pevná částka', 'Převodem', 'Hotově'];

    foreach (InvoiceStatus::cases() as $status) {
        if ($status === InvoiceStatus::Draft) {
            continue;
        }

        foreach ($forbidden as $word) {
            expect($status->partnerLabel())->not->toContain($word);
        }
    }
});

it('labels where a line of a document came from', function (): void {
    expect(InvoiceItemOrigin::Time->getLabel())->toBe('Z času')
        ->and(InvoiceItemOrigin::FixedAmount->getLabel())->toBe('Pevná částka')
        ->and(InvoiceItemOrigin::Manual->getLabel())->toBe('Ruční položka')
        ->and(array_map(fn (InvoiceItemOrigin $origin): string => $origin->value, InvoiceItemOrigin::cases()))
        ->toBe(['time', 'fixed_amount', 'manual']);
});

it('labels the payment methods', function (): void {
    expect(PaymentMethod::Transfer->getLabel())->toBe('Převodem')
        ->and(PaymentMethod::Cash->getLabel())->toBe('Hotově')
        ->and(array_map(fn (PaymentMethod $method): string => $method->value, PaymentMethod::cases()))
        ->toBe(['transfer', 'cash']);
});

it('labels the e-mail states, with the claim state of the sending job', function (): void {
    expect(InvoiceEmailState::Queued->getLabel())->toBe('Ve frontě')
        ->and(InvoiceEmailState::Sending->getLabel())->toBe('Odesílá se')
        ->and(InvoiceEmailState::Sent->getLabel())->toBe('Odesláno')
        ->and(InvoiceEmailState::Failed->getLabel())->toBe('Odeslání selhalo')
        ->and(array_map(fn (InvoiceEmailState $state): string => $state->value, InvoiceEmailState::cases()))
        ->toBe(['queued', 'sending', 'sent', 'failed']);
});
