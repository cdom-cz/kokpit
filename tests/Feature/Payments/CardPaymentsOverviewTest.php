<?php

declare(strict_types=1);

use App\Domain\Payments\Enums\CardPaymentTab;
use App\Filament\Resources\CardPaymentResource;
use App\Filament\Resources\CardPaymentResource\Pages\ListCardPayments;
use App\Filament\Resources\CardPaymentResource\Pages\ViewCardPayment;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\CardPaymentFixtures;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The Admin's payments overview "Platby kartou" (PA-07, PA-08; D-11 to D-14, D-17; UI-SPEC Surface H): the queue of
 * every card payment and refund event with the unresolved ones first, and the record view that explains each one.
 * Every name and amount is fictional; Stripe references are assembled at runtime by the fixtures.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    CardPaymentSetup::enable();
    Queue::fake();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

describe('the tracer', function (): void {
    it('lists the unresolved payment first with its count, opens its record and refuses a Partner', function (): void {
        $client = CardPaymentSetup::client();
        $invoice = CardPaymentSetup::issued($client);
        $matched = CardPaymentFixtures::matched($invoice);
        $unmatched = CardPaymentFixtures::unmatched($invoice);

        $this->get(CardPaymentResource::getUrl('index'))->assertOk()->assertSee('Platby kartou');

        $list = Livewire::test(ListCardPayments::class)
            ->assertSet('activeTab', CardPaymentTab::Unresolved->value)
            ->assertSee('Nevyřešené')
            ->assertCanSeeTableRecords([$unmatched])
            ->assertCanNotSeeTableRecords([$matched]);

        $tabs = $list->instance()->getTabs();

        expect(array_keys($tabs)[0])->toBe(CardPaymentTab::Unresolved->value)
            ->and((string) $tabs[CardPaymentTab::Unresolved->value]->getBadge())->toBe('1')
            ->and($tabs[CardPaymentTab::Unresolved->value]->getBadgeColor())->toBe('danger')
            ->and($list->instance()->getTable()->getRecordUrl($unmatched))->toBe(CardPaymentResource::getUrl('view', ['record' => $unmatched]));

        $this->get(CardPaymentResource::getUrl('view', ['record' => $unmatched]))->assertOk();

        Livewire::test(ViewCardPayment::class, ['record' => $unmatched->getRouteKey()])
            ->assertSee($unmatched->amount()->format('cs'));

        expect(CardPaymentResource::getNavigationLabel())->toBe('Platby kartou')
            ->and(CardPaymentResource::getNavigationBadge())->toBe('1')
            ->and(CardPaymentResource::getNavigationBadgeColor())->toBe('danger')
            ->and(CardPaymentResource::getNavigationSort())->toBe(52);

        $this->actingAs(Canary::partnerFor($client->id));

        $this->get(CardPaymentResource::getUrl('index'))->assertForbidden();
        $this->get(CardPaymentResource::getUrl('view', ['record' => $unmatched]))->assertForbidden();
    });
});

describe('access', function (): void {
    it('shows no navigation badge when nothing waits and is not globally searchable', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        CardPaymentFixtures::matched($invoice);

        expect(CardPaymentResource::getNavigationBadge())->toBeNull()
            ->and(CardPaymentResource::canGloballySearch())->toBeFalse();
    });
});
