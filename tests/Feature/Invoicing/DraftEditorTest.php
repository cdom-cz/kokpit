<?php

declare(strict_types=1);

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Pages\EditInvoice;
use App\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\InvoiceFixtures;

/*
 * The draft editor (IN-02, IN-07, IN-08, IN-10; D-03, D-06, D-09, UI-SPEC Surface C): a thin layer over the domain
 * Actions. The Actions are tested elsewhere; here the screen follows them, and every figure it shows comes from
 * the server. Every name, rate and amount is fictional.
 */

beforeEach(function (): void {
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
function editor(Invoice $draft): Testable
{
    return Livewire::test(EditInvoice::class, ['record' => $draft->getRouteKey()]);
}

/**
 * The repeater keys of the rows in the form, in order.
 *
 * @return list<string>
 */
function rowKeys(Testable $editor): array
{
    return array_map('strval', array_keys($editor->get('data.items')));
}

/**
 * The stored lines of the draft in order.
 *
 * @return list<InvoiceItem>
 */
function storedLines(Invoice $draft): array
{
    return app(PartnerContext::class)->runAsSystem(static fn (): array => InvoiceItem::query()->where('invoice_id', $draft->id)->with('source')->orderBy('position')->get()->all());
}

function storedDraft(Invoice $draft): Invoice
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Invoice => Invoice::query()->findOrFail($draft->id));
}

describe('the tracer: open a draft, change a line, save, and see the server figures', function (): void {
    it('opens the editor from a draft row of the list', function (): void {
        $client = InvoiceFixtures::client();
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '2', '500.00')]);
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client());

        $table = Livewire::test(ListInvoices::class)->instance()->getTable();

        expect($table->getRecordUrl($draft))->toBe(InvoiceResource::getUrl('edit', ['record' => $draft]))
            ->and($table->getRecordUrl($issued))->toBe(InvoiceResource::getUrl('view', ['record' => $issued]));

        $this->get(InvoiceResource::getUrl('edit', ['record' => $draft]))->assertOk()->assertSee('Koncept faktury');
    });

    it('shows the header and the lines of the draft', function (): void {
        $client = InvoiceFixtures::client(['name' => 'Example editor client']);
        $draft = InvoiceFixtures::draft($client, [
            InvoiceFixtures::item('Example work', '2', '500.00'),
            InvoiceFixtures::item('Example extra', '1', '250.00', 'ks'),
        ]);

        $editor = editor($draft);

        expect($editor->get('data.currency'))->toBe('CZK')
            ->and($editor->get('data.language'))->toBe('cs')
            ->and($editor->get('data.items'))->toHaveCount(2);

        // The inputs hold their values in the state, not in the markup.
        expect(array_column($editor->get('data.items'), 'description'))->toBe(['Example work', 'Example extra']);

        $editor->assertSee('Example editor client')
            ->assertSee(Money::ofMinor(100000, 'CZK')->format('cs'))
            ->assertSee(Money::ofMinor(125000, 'CZK')->format('cs'))
            ->assertSee('Přidělí se při vystavení.')
            ->assertSee('Vidíte jen vy. Na dokladu se nezobrazí.')
            ->assertDontSee('Datum zdanitelného plnění');
    });

    it('shows the dates the issue will derive, and stores only a date the Admin typed', function (): void {
        $client = InvoiceFixtures::client(['payment_terms_days' => 21]);
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '100.00')]);

        $editor = editor($draft);

        expect($editor->get('data.issue_date'))->toBe('2026-10-14')
            ->and($editor->get('data.due_date'))->toBe('2026-11-04');

        $editor->call('save')->assertHasNoFormErrors();

        $stored = storedDraft($draft);

        expect($stored->issue_date)->toBeNull()
            ->and($stored->due_date)->toBeNull();

        $editor->set('data.due_date', '2026-11-30')->call('save')->assertHasNoFormErrors();

        expect(storedDraft($draft)->due_date?->format('Y-m-d'))->toBe('2026-11-30')
            ->and(storedDraft($draft)->issue_date)->toBeNull();
    });

    it('recomputes the line total and the summary on the server when the quantity changes', function (): void {
        $client = InvoiceFixtures::client();
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '2', '500.00')]);

        $editor = editor($draft);
        [$key] = rowKeys($editor);

        $editor->assertSee(Money::ofMinor(100000, 'CZK')->format('cs'))
            ->set("data.items.{$key}.quantity", '3,5')
            ->assertSee(Money::ofMinor(175000, 'CZK')->format('cs'))
            ->assertDontSee(Money::ofMinor(100000, 'CZK')->format('cs'));

        // The summary block holds the same server figure.
        expect($editor->html())->toContain('data-draft-total')
            ->and($editor->html())->toContain(Money::ofMinor(175000, 'CZK')->format('cs'));
    });

    it('saves the form through UpdateDraft: quantity, order of fields and the totals of the draft', function (): void {
        $client = InvoiceFixtures::client();
        $draft = InvoiceFixtures::draft($client, [
            InvoiceFixtures::item('Example work', '2', '500.00'),
            InvoiceFixtures::item('Example extra', '1', '250.00', 'ks'),
        ]);
        $before = storedLines($draft);

        $editor = editor($draft);
        [$first] = rowKeys($editor);

        $editor->set("data.items.{$first}.quantity", '3,5')
            ->set('data.note', 'Example printed note')
            ->set('data.internal_note', 'Example internal note')
            ->call('save')
            ->assertHasNoFormErrors();

        $lines = storedLines($draft);
        $stored = storedDraft($draft);

        expect($lines)->toHaveCount(2)
            ->and($lines[0]->id)->toBe($before[0]->id)
            ->and($lines[0]->quantity)->toBe('3.5000')
            ->and($lines[0]->line_total_minor)->toBe(175000)
            ->and($lines[1]->line_total_minor)->toBe(25000)
            ->and($stored->total_minor)->toBe(200000)
            ->and($stored->note)->toBe('Example printed note');

        // The form is filled again from the stored lines, with the same ids.
        expect(array_column($editor->get('data.items'), 'id'))->toBe([$before[0]->id, $before[1]->id]);
    });

    it('ignores a total that the browser sends', function (): void {
        $client = InvoiceFixtures::client();
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '2', '500.00')]);

        $editor = editor($draft);
        [$key] = rowKeys($editor);

        $editor->set("data.items.{$key}.line_total", '1,00')
            ->set("data.items.{$key}.line_total_minor", 100)
            ->set('data.total_minor', 100)
            ->call('save')
            ->assertHasNoFormErrors();

        expect(storedLines($draft)[0]->line_total_minor)->toBe(100000)
            ->and(storedDraft($draft)->total_minor)->toBe(100000);
    });

    it('refuses the id of a line of another document with a field error and writes nothing', function (): void {
        $client = InvoiceFixtures::client();
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '2', '500.00')]);
        $other = InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Example foreign', '9', '900.00')]);
        $foreign = storedLines($other)[0];

        $editor = editor($draft);
        [$key] = rowKeys($editor);

        $editor->set("data.items.{$key}.id", $foreign->id)
            ->set("data.items.{$key}.quantity", '5')
            ->call('save')
            ->assertHasFormErrors(["items.{$key}.id"]);

        expect(storedLines($draft)[0]->quantity)->toBe('2.0000')
            ->and(storedLines($other)[0]->quantity)->toBe('9.0000');
    });

    it('shows the field error of the Action under the cell of that row', function (): void {
        $client = InvoiceFixtures::client();
        $draft = InvoiceFixtures::draft($client, [
            InvoiceFixtures::item('Example work', '2', '500.00'),
            InvoiceFixtures::item('Example extra', '1', '250.00', 'ks'),
        ]);

        $editor = editor($draft);
        [, $second] = rowKeys($editor);

        $editor->set("data.items.{$second}.quantity", 'abc')
            ->call('save')
            ->assertHasFormErrors(["items.{$second}.quantity"])
            ->assertSee('Zadejte množství větší než nula s nejvýše čtyřmi desetinnými místy.');

        expect(storedLines($draft)[1]->quantity)->toBe('1.0000');
    });

    it('redirects the edit address of an issued document to its page', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client());

        $this->get(InvoiceResource::getUrl('edit', ['record' => $issued]))
            ->assertRedirect(InvoiceResource::getUrl('view', ['record' => $issued]));
    });

    it('answers a Partner with 403 on the editor, before the draft is looked up', function (): void {
        $client = InvoiceFixtures::client();
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '2', '500.00')]);

        $this->actingAs(Canary::partnerFor($client->id));

        $this->get(InvoiceResource::getUrl('edit', ['record' => $draft]))->assertForbidden();
    });

    it('opens the editor of a new draft right after "Nová faktura"', function (): void {
        $client = InvoiceFixtures::client();

        Livewire::test(ListInvoices::class)
            ->callAction('createDraft', ['client_id' => $client->id, 'kind' => 'invoice', 'source' => 'empty'])
            ->assertRedirectContains('/edit');
    });
});
