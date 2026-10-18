<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Enums\CardPaymentResolution;
use App\Domain\Payments\Enums\CardPaymentStatus;
use App\Domain\Payments\Enums\CardPaymentTab;
use App\Domain\Payments\Enums\CardPaymentType;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Payments\Queries\UnresolvedPayments;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Filament\Resources\CardPaymentResource;
use App\Filament\Resources\CardPaymentResource\Pages\ListCardPayments;
use App\Filament\Resources\CardPaymentResource\Pages\ViewCardPayment;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\InvoiceResource;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
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

/**
 * One record of every kind and state, received on six consecutive days: a booked payment, a matched one whose payment
 * was deleted, an unresolved duplicate, an unresolved amount mismatch, an unresolved refund and a resolved payment
 * without a document.
 *
 * @return array{invoice: Invoice, client: Client, matched: CardPayment, matchedDeleted: CardPayment, duplicate: CardPayment, unmatched: CardPayment, refund: CardPayment, resolved: CardPayment}
 */
function overviewPortfolio(): array
{
    $client = CardPaymentSetup::client();
    $invoice = CardPaymentSetup::issued($client);
    $other = CardPaymentSetup::issued($client);
    $day = static fn (int $n): array => ['received_at' => CarbonImmutable::parse('2026-10-0'.$n.' 12:00:00', 'Europe/Prague')];

    return [
        'invoice' => $invoice,
        'client' => $client,
        'matched' => CardPaymentFixtures::matched($invoice, $day(1)),
        'matchedDeleted' => CardPaymentFixtures::matchedThenDeleted($other, $day(2)),
        'duplicate' => CardPaymentFixtures::duplicate($invoice, $day(3)),
        'unmatched' => CardPaymentFixtures::unmatched($invoice, $day(4)),
        'refund' => CardPaymentFixtures::refund($invoice, $day(5)),
        'resolved' => CardPaymentFixtures::resolved(null, $day(6)),
    ];
}

/**
 * The records of a tab as the list shows them, and none of the others.
 *
 * @param  list<string>  $names
 * @param  array<string, mixed>  $portfolio
 */
function overviewTabShows(string $tab, array $names, array $portfolio): void
{
    $records = array_filter($portfolio, static fn (mixed $value): bool => $value instanceof CardPayment);
    $shown = array_values(array_intersect_key($records, array_flip($names)));
    $hidden = array_values(array_diff_key($records, array_flip($names)));

    Livewire::test(ListCardPayments::class)
        ->set('activeTab', $tab)
        ->assertCanSeeTableRecords($shown)
        ->assertCanNotSeeTableRecords($hidden);
}

describe('tabs', function (): void {
    it('counts every tab from the records and lists exactly its rows', function (): void {
        $portfolio = overviewPortfolio();

        $counts = app(UnresolvedPayments::class)->tabCounts();

        expect($counts)->toBe([
            'unresolved' => 3,
            'all' => 6,
            'matched' => 2,
            'duplicate' => 1,
            'unmatched' => 1,
            'refund' => 1,
            'resolved' => 1,
        ])->and(app(UnresolvedPayments::class)->counts())->toBe(['total' => 3, 'duplicate' => 1, 'unmatched' => 1, 'refund' => 1]);

        $tabs = Livewire::test(ListCardPayments::class)->instance()->getTabs();

        foreach ($counts as $tab => $count) {
            expect((string) $tabs[$tab]->getBadge())->toBe((string) $count);
        }

        overviewTabShows('unresolved', ['duplicate', 'unmatched', 'refund'], $portfolio);
        overviewTabShows('all', ['matched', 'matchedDeleted', 'duplicate', 'unmatched', 'refund', 'resolved'], $portfolio);
        overviewTabShows('matched', ['matched', 'matchedDeleted'], $portfolio);
        overviewTabShows('duplicate', ['duplicate'], $portfolio);
        overviewTabShows('unmatched', ['unmatched'], $portfolio);
        overviewTabShows('refund', ['refund'], $portfolio);
        overviewTabShows('resolved', ['resolved'], $portfolio);
    });

    it('works the queue from the oldest and every other tab from the newest', function (): void {
        $p = overviewPortfolio();

        Livewire::test(ListCardPayments::class)
            ->assertCanSeeTableRecords([$p['duplicate'], $p['unmatched'], $p['refund']], inOrder: true);

        Livewire::test(ListCardPayments::class)
            ->set('activeTab', 'all')
            ->assertCanSeeTableRecords([$p['resolved'], $p['refund'], $p['unmatched'], $p['duplicate'], $p['matchedDeleted'], $p['matched']], inOrder: true);
    });

    it('hides the queue badge at zero and keeps the other counts', function (): void {
        $invoice = CardPaymentSetup::issued(CardPaymentSetup::client());
        CardPaymentFixtures::matched($invoice);

        $tabs = Livewire::test(ListCardPayments::class)->instance()->getTabs();

        expect($tabs['unresolved']->getBadge())->toBeNull()
            ->and((string) $tabs['matched']->getBadge())->toBe('1')
            ->and($tabs['unresolved']->getBadgeColor())->toBe('gray');
    });
});

describe('columns', function (): void {
    it('shows the type, the document, the client, the amount with the offer, the reason and the state of each row', function (): void {
        $p = overviewPortfolio();
        $number = (string) $p['invoice']->number;
        $clientName = $p['client']->name;
        $offered = Money::ofMinor($p['invoice']->total_minor, $p['invoice']->currency)->format('cs');

        $list = Livewire::test(ListCardPayments::class)->set('activeTab', 'all');

        $list->assertTableColumnStateSet('type', CardPaymentType::Unmatched, $p['unmatched'])
            ->assertTableColumnStateSet('invoice_number', $number, $p['unmatched'])
            ->assertTableColumnStateSet('client_name', $clientName, $p['unmatched'])
            ->assertTableColumnStateSet('amount', $p['unmatched']->amount()->format('cs'), $p['unmatched'])
            ->assertTableColumnHasDescription('amount', 'Nabízeno: '.$offered, $p['unmatched'])
            ->assertTableColumnStateSet('reason', 'Jiná částka než nabízel odkaz', $p['unmatched'])
            ->assertTableColumnStateSet('status', CardPaymentStatus::Unresolved, $p['unmatched'])
            ->assertTableColumnStateSet('status', CardPaymentStatus::Booked, $p['matched'])
            ->assertTableColumnStateSet('status', CardPaymentStatus::PaymentDeleted, $p['matchedDeleted'])
            ->assertTableColumnStateSet('status', CardPaymentStatus::Resolved, $p['resolved'])
            ->assertTableColumnHasDescription('status', 'Vyřešeno poznámkou', $p['resolved'])
            ->assertTableColumnDoesNotHaveDescription('amount', 'Nabízeno: '.$offered, $p['matched'])
            ->assertTableColumnStateSet('reason', 'Doklad už je zaplacený', $p['duplicate']);

        $table = $list->instance()->getTable();

        expect($table->getColumn('type')->record($p['duplicate'])->getColor(CardPaymentType::Duplicate))->toBe('danger')
            ->and($table->getColumn('type')->record($p['matched'])->getColor(CardPaymentType::Matched))->toBe('success')
            ->and($table->getColumn('type')->record($p['refund'])->getColor(CardPaymentType::Refund))->toBe('warning')
            ->and($table->getColumn('invoice_number')->record($p['matched'])->getUrl())->toBe(InvoiceResource::getUrl('view', ['record' => $p['invoice']]))
            ->and($table->getColumn('client_name')->record($p['matched'])->getUrl())->toBe(ClientResource::getUrl('view', ['record' => $p['client']->id]));
    });

    it('shows a dash for the document, the client and the reason of a payment that has none and still opens the record', function (): void {
        $p = overviewPortfolio();
        $empty = '—';

        $list = Livewire::test(ListCardPayments::class)->set('activeTab', 'all');

        $list->assertTableColumnStateSet('invoice_number', null, $p['resolved'])
            ->assertTableColumnStateSet('client_name', null, $p['resolved'])
            ->assertTableColumnStateSet('reason', 'Doklad nelze určit', $p['resolved']);

        $table = $list->instance()->getTable();

        expect($table->getColumn('invoice_number')->record($p['resolved'])->getPlaceholder())->toBe($empty)
            ->and($table->getColumn('client_name')->record($p['resolved'])->getPlaceholder())->toBe($empty)
            ->and($table->getColumn('reason')->record($p['matched'])->getPlaceholder())->toBe($empty)
            ->and($table->getRecordUrl($p['resolved']))->toBe(CardPaymentResource::getUrl('view', ['record' => $p['resolved']]));

        $this->get(CardPaymentResource::getUrl('view', ['record' => $p['resolved']]))->assertOk();
    });

    it('names the document an assignment put the payment on and the resolution on the second line', function (): void {
        $p = overviewPortfolio();
        $target = CardPaymentSetup::issued(CardPaymentSetup::client());

        $assigned = CardPaymentFixtures::unmatched(null);
        app(PartnerContext::class)->runAsSystem(static function () use ($assigned, $target): void {
            $assigned->forceFill([
                'resolution' => CardPaymentResolution::Assigned,
                'resolved_at' => now(),
                'resolved_by' => Canary::admin()->getKey(),
                'assigned_invoice_id' => $target->id,
            ])->save();
        });

        Livewire::test(ListCardPayments::class)
            ->set('activeTab', 'resolved')
            ->assertTableColumnStateSet('invoice_number', (string) $target->number, $assigned->refresh())
            ->assertTableColumnHasDescription('status', 'Přiřazeno k dokladu '.$target->number, $assigned);

        expect($p['resolved'])->toBeInstanceOf(CardPayment::class);
    });

    it('hides the Stripe references by default, shortened, with the full value in the tooltip', function (): void {
        $p = overviewPortfolio();
        $record = $p['unmatched'];

        $table = Livewire::test(ListCardPayments::class)->instance()->getTable();

        foreach (['stripe_payment_intent_id' => $record->stripe_payment_intent_id, 'stripe_event_id' => $record->stripe_event_id] as $name => $full) {
            $column = $table->getColumn($name);
            $column->record($record);

            expect($column->isToggleable())->toBeTrue()
                ->and($column->isToggledHiddenByDefault())->toBeTrue()
                ->and($column->getTooltip())->toBe($full)
                ->and(mb_strlen((string) $column->getState()))->toBeLessThan(mb_strlen($full))
                ->and((string) $column->getState())->toStartWith(substr($full, 0, 8))
                ->and((string) $column->getState())->toEndWith(substr($full, -6))
                ->and($column->getCopyableState($column->getState()))->toBe($full);
        }
    });
});

describe('filters', function (): void {
    it('narrows the list by client, currency and received date and shows indicator chips', function (): void {
        $p = overviewPortfolio();
        $foreign = CardPaymentSetup::issued(CardPaymentSetup::client());
        $other = CardPaymentFixtures::unmatched($foreign, [
            'received_at' => CarbonImmutable::parse('2026-10-20 12:00:00', 'Europe/Prague'),
            'currency' => 'EUR',
            'offered_currency' => 'EUR',
        ]);

        $list = Livewire::test(ListCardPayments::class)->set('activeTab', 'all');

        $list->filterTable('client_id', $p['client']->id)
            ->assertCanSeeTableRecords([$p['matched'], $p['unmatched']])
            ->assertCanNotSeeTableRecords([$other]);

        $list->removeTableFilters()
            ->filterTable('currency', 'EUR')
            ->assertCanSeeTableRecords([$other])
            ->assertCanNotSeeTableRecords([$p['matched'], $p['unmatched'], $p['resolved']]);

        $list->removeTableFilters()
            ->filterTable('received', ['from' => '2026-10-03', 'until' => '2026-10-04'])
            ->assertCanSeeTableRecords([$p['duplicate'], $p['unmatched']])
            ->assertCanNotSeeTableRecords([$p['matched'], $p['matchedDeleted'], $p['refund'], $p['resolved'], $other]);

        $indicators = collect($list->instance()->getTable()->getFilterIndicators())
            ->map(static fn ($indicator): string => (string) $indicator->getLabel())
            ->all();

        expect($indicators)->toContain('Přijato od: 3. 10. 2026')->toContain('Přijato do: 4. 10. 2026');

        $list->removeTableFilters()
            ->filterTable('client_id', $p['client']->id)
            ->filterTable('currency', 'EUR')
            ->assertCanNotSeeTableRecords([$p['matched'], $other]);
    });

    it('marks an archived client in the client filter', function (): void {
        $p = overviewPortfolio();

        app(PartnerContext::class)->runAsSystem(static fn () => $p['client']->delete());

        $options = Livewire::test(ListCardPayments::class)->instance()->getTable()->getFilter('client_id')->getOptions();

        expect($options[$p['client']->id])->toBe($p['client']->name.' (archivovaný)');
    });
});

describe('empty states and paging', function (): void {
    it('explains the empty list once and each empty tab on its own', function (): void {
        Livewire::test(ListCardPayments::class)
            ->assertSee('Zatím žádné platby kartou')
            ->assertSee('Platby přijaté přes Stripe se objeví tady.')
            ->set('activeTab', 'matched')
            ->assertSee('Zatím žádné platby kartou');

        // One booked payment only: nothing is open, and the other tabs have nothing to show.
        CardPaymentFixtures::matched(CardPaymentSetup::issued(CardPaymentSetup::client()));

        $headings = [
            'unresolved' => 'Nic k řešení',
            'duplicate' => 'Žádné duplicitní platby',
            'unmatched' => 'Žádné nespárované platby',
            'refund' => 'Žádná vrácení',
            'resolved' => 'Zatím nic vyřešeného',
        ];

        foreach ($headings as $tab => $heading) {
            Livewire::test(ListCardPayments::class)->set('activeTab', $tab)->assertSee($heading);
        }

        Livewire::test(ListCardPayments::class)->assertSee('Všechny platby kartou jsou spárované nebo vyřešené.');
    });

    it('pages by 25 with the options 25, 50 and 100', function (): void {
        $table = Livewire::test(ListCardPayments::class)->instance()->getTable();

        expect($table->getPaginationPageOptions())->toBe([25, 50, 100])
            ->and($table->getDefaultPaginationPageOption())->toBe(25);
    });

    it('does not issue more queries for 30 rows than for 3', function (): void {
        $invoices = array_map(static fn (): Invoice => CardPaymentSetup::issued(CardPaymentSetup::client()), [1, 2, 3]);
        $count = static function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(ListCardPayments::class);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        foreach ($invoices as $invoice) {
            CardPaymentFixtures::unmatched($invoice);
        }

        $few = $count();

        foreach (range(1, 27) as $n) {
            CardPaymentFixtures::unmatched($invoices[$n % 3]);
        }

        expect($count())->toBe($few);
    });
});
