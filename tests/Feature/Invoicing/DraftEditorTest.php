<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Actions\AddEntriesToDraft;
use App\Domain\Invoicing\Actions\AddFixedAmount;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\UpdateDraft;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Pages\SettingsPage;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\Pages\EditInvoice;
use App\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use App\Filament\Resources\InvoiceResource\RelationManagers\InvoiceEntriesRelationManager;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
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

    it('redirects the edit address of a locked issued document to its page', function (): void {
        $locked = InvoiceFixtures::lockedIssued(InvoiceFixtures::client());

        $this->get(InvoiceResource::getUrl('edit', ['record' => $locked]))
            ->assertRedirect(InvoiceResource::getUrl('view', ['record' => $locked]));
    });

    it('opens the editor of an open issued document in issued mode instead of redirecting', function (): void {
        $open = InvoiceFixtures::issued(InvoiceFixtures::client());

        $this->get(InvoiceResource::getUrl('edit', ['record' => $open]))
            ->assertOk()
            ->assertSee('Úprava dokladu '.$open->number)
            ->assertSee('Uložit změny');
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

/**
 * A project of the client written through the domain Action.
 *
 * @param  array<string, mixed>  $data
 */
function editorProject(Client $client, string $name, array $data = []): Project
{
    return app(CreateProject::class)->handle($client, [
        'name' => $name,
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'hourly_rate' => '1000',
        ...$data,
    ]);
}

/**
 * A finished entry of the project from UTC instants.
 *
 * @param  array<string, mixed>  $state
 */
function editorEntry(Project $project, string $start, string $end, array $state = []): TimeEntry
{
    $factory = TimeEntry::factory()->forProject($project);

    return app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($factory->create([
        'user_id' => test()->admin->id,
        'started_at' => CarbonImmutable::parse($start, 'UTC'),
        'ended_at' => CarbonImmutable::parse($end, 'UTC'),
        ...$state,
    ])->id));
}

/**
 * A draft of the September time of the projects, in the currency.
 *
 * @param  list<Project>  $projects
 */
function editorTimeDraft(Client $client, array $projects, string $currency = 'CZK'): Invoice
{
    return app(CreateDraft::class)->handle(test()->admin, [
        'client_id' => $client->id,
        'kind' => 'invoice',
        'source' => 'time',
        'period_from' => '2026-09-01',
        'period_to' => '2026-09-30',
        'project_ids' => array_map(static fn (Project $project): string => $project->id, $projects),
        'without_project' => false,
        'grouping' => 'project',
        'currency' => $currency,
    ]);
}

function editorFreshEntry(TimeEntry $entry): TimeEntry
{
    return app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($entry->id));
}

describe('line origins and previews', function (): void {
    it('shows the origin badge and the exact H:MM of a time line, and none on a manual line', function (): void {
        $client = InvoiceFixtures::client();
        $project = editorProject($client, 'Alpha project');
        editorEntry($project, '2026-09-10 08:00:00', '2026-09-10 10:30:00');
        $draft = editorTimeDraft($client, [$project]);
        app(UpdateDraft::class)->handle($this->admin, $draft, ['items' => [
            ...array_map(static fn (InvoiceItem $item): array => ['id' => $item->id, 'description' => $item->description, 'quantity' => $item->quantity, 'unit' => $item->unit, 'unit_price' => Money::ofMinor($item->unit_price_minor, 'CZK')->toMajor()], storedLines($draft)),
            InvoiceFixtures::item('Example manual', '1', '100.00'),
        ]]);

        $editor = editor($draft);

        expect(substr_count($editor->html(), 'Z času'))->toBe(1);

        $editor->assertSee('2:30')
            ->assertSee('Vygenerováno ze záznamů času')
            ->assertDontSee('Přepočet při vystavení');
    });

    it('shows the preview rate and the conversion badge of a EUR draft of CZK-rated time, and the CZK preview', function (): void {
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.000');
        $client = InvoiceFixtures::client();
        $project = editorProject($client, 'Alpha project', ['hourly_rate' => '1500']);
        editorEntry($project, '2026-09-10 08:00:00', '2026-09-10 10:00:00');
        $draft = editorTimeDraft($client, [$project], 'EUR');

        $editor = editor($draft);

        $editor->assertSee('Přepočet kurzem ČNB (náhled): 25,000')
            ->assertSee('Přepočet při vystavení')
            ->assertSee('Náhled v Kč: '.Money::ofMinor(300000, 'CZK')->format('cs').', kurz ČNB 25,000 ke dni 14. 10. 2026')
            ->assertSee('Konečný kurz a částka v Kč se zmrazí při vystavení.');

        expect(storedLines($draft)[0]->line_total_minor)->toBe(12000);
    });

    it('shows no CZK preview and no VAT row for a CZK draft', function (): void {
        $client = InvoiceFixtures::client();
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '2', '500.00')]);

        editor($draft)
            ->assertDontSee('Náhled v Kč')
            ->assertDontSee('Konečný kurz')
            ->assertDontSee('DPH')
            ->assertDontSee('Mezisoučet')
            ->assertSee('Celkem k úhradě');
    });

    it('drops the conversion badge when a price is typed, and keeps it removed after the save', function (): void {
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.000');
        $client = InvoiceFixtures::client();
        $project = editorProject($client, 'Alpha project', ['hourly_rate' => '1500']);
        editorEntry($project, '2026-09-10 08:00:00', '2026-09-10 10:00:00');
        $draft = editorTimeDraft($client, [$project], 'EUR');

        $editor = editor($draft);
        [$key] = rowKeys($editor);

        $editor->assertSee('Přepočet při vystavení')
            ->set("data.items.{$key}.unit_price", '70')
            ->assertDontSee('Přepočet při vystavení')
            ->assertSee(Money::ofMinor(14000, 'EUR')->format('cs'))
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertDontSee('Přepočet při vystavení')
            ->assertSee('Z času');

        $line = storedLines($draft)[0];

        expect($line->unit_price_minor)->toBe(7000)
            ->and($line->line_total_minor)->toBe(14000)
            ->and($line->source->price_manual)->toBeTrue();
    });

    it('shows the danger message with a link to the settings when the currency has no bank account', function (): void {
        $client = InvoiceFixtures::client(['currency' => 'USD']);
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '1', '100.00')]);

        $editor = editor($draft);

        $editor->assertSee('Pro měnu USD není nastaven bankovní účet. Přidejte ho v')
            ->assertSee(SettingsPage::getUrl());

        expect($editor->html())->toContain('fi-color-danger');
    });

    it('shows the account of the currency when it exists', function (): void {
        $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '100.00')]);

        editor($draft)->assertSee('Example CZK account')->assertDontSee('není nastaven bankovní účet');
    });

    it('asks for the zero price to be confirmed on the line', function (): void {
        $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '100.00')]);

        $editor = editor($draft);
        [$key] = rowKeys($editor);

        $editor->set("data.items.{$key}.unit_price", '0')
            ->call('save')
            ->assertHasFormErrors(["items.{$key}.unit_price"])
            ->assertSee('Jednotkovou cenu nula potvrďte u položky.')
            ->set("data.items.{$key}.confirm_zero_price", true)
            ->call('save')
            ->assertHasNoFormErrors();

        expect(storedLines($draft)[0]->unit_price_minor)->toBe(0);
    });
});

describe('the currency of a draft', function (): void {
    it('asks first when a price was typed by hand, and changes the currency only after the confirmation', function (): void {
        $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '100.00')]);

        $editor = editor($draft)
            ->set('data.currency', 'EUR')
            ->assertActionMounted('changeCurrency')
            ->assertMountedActionModalSee(['Změnit měnu konceptu?', 'Ručně zadané jednotkové ceny se nepřepočítají. Zkontrolujte je po změně.']);

        expect($editor->get('data.currency'))->toBe('CZK');

        $editor->callMountedAction()->assertHasNoActionErrors();

        expect($editor->get('data.currency'))->toBe('EUR');

        $editor->call('save')->assertHasNoFormErrors();

        expect(storedDraft($draft)->currency)->toBe('EUR');
    });

    it('keeps the currency when the confirmation is not given', function (): void {
        $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '100.00')]);

        $editor = editor($draft)->set('data.currency', 'EUR')->assertActionMounted('changeCurrency')->unmountAction();

        expect($editor->get('data.currency'))->toBe('CZK');
    });

    it('changes the currency at once when no price was typed by hand, and shows the converted price', function (): void {
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.000');
        $client = InvoiceFixtures::client();
        $project = editorProject($client, 'Alpha project', ['hourly_rate' => '1500']);
        editorEntry($project, '2026-09-10 08:00:00', '2026-09-10 10:00:00');
        $draft = editorTimeDraft($client, [$project]);

        $editor = editor($draft)->set('data.currency', 'EUR')->assertActionNotMounted();
        [$key] = rowKeys($editor);

        expect($editor->get('data.currency'))->toBe('EUR')
            ->and($editor->get("data.items.{$key}.unit_price"))->toBe('60,00');

        $editor->assertSee('Přepočet při vystavení')->call('save')->assertHasNoFormErrors();

        expect(storedDraft($draft)->currency)->toBe('EUR')
            ->and(storedLines($draft)[0]->line_total_minor)->toBe(12000);
    });
});

describe('fixed amounts', function (): void {
    /**
     * A client with a fixed-price project holding 1:30 of unbilled time in two entries.
     *
     * @return array{0: Client, 1: Project, 2: list<TimeEntry>}
     */
    function editorFixedPrice(): array
    {
        $client = InvoiceFixtures::client();
        $project = editorProject($client, 'Fixed project', ['billing_type' => 'fixed_price', 'fixed_price' => '50000']);
        $entries = [
            editorEntry($project, '2026-09-10 08:00:00', '2026-09-10 09:00:00'),
            editorEntry($project, '2026-09-11 08:00:00', '2026-09-11 08:30:00'),
        ];

        return [$client, $project, $entries];
    }

    it('shows the price and what is billed so far, and the count and H:MM of the project time', function (): void {
        [$client, $project] = editorFixedPrice();
        $draft = InvoiceFixtures::draft($client);

        editor($draft)
            ->mountAction(TestAction::make('addFixedAmount')->schemaComponent('itemActions', schema: 'form'))
            ->setActionData(['project_id' => $project->id, 'link_time' => true])
            ->assertMountedActionModalSee('Pevná cena projektu: '.Money::ofMinor(5000000, 'CZK')->format('cs').'. Dosud vyfakturováno: '.Money::ofMinor(0, 'CZK')->format('cs').'.')
            ->assertMountedActionModalSee('2 záznamy, 1:30.');
    });

    it('adds a paušál line and keeps the unsaved edits of the form', function (): void {
        [$client, $project] = editorFixedPrice();
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example work', '2', '500.00')]);

        $editor = editor($draft);
        [$key] = rowKeys($editor);
        $editor->set("data.items.{$key}.description", 'Edited but not saved');

        $editor->callAction(TestAction::make('addFixedAmount')->schemaComponent('itemActions', schema: 'form'), [
            'project_id' => $project->id,
            'amount' => '20 000,00',
        ])->assertHasNoActionErrors();

        $lines = storedLines($draft);

        expect($lines)->toHaveCount(2)
            ->and($lines[1]->description)->toBe('Fixed project')
            ->and($lines[1]->unit)->toBe('paušál')
            ->and($lines[1]->quantity)->toBe('1.0000')
            ->and($lines[1]->unit_price_minor)->toBe(2000000)
            ->and($lines[1]->source->origin->value)->toBe('fixed_amount')
            ->and(storedDraft($draft)->total_minor)->toBe(2100000);

        // The form shows the new line next to the edit that was not saved yet.
        expect(array_column($editor->get('data.items'), 'description'))->toBe(['Edited but not saved', 'Fixed project']);
    });

    it('takes the time of the project along when asked', function (): void {
        [$client, $project, $entries] = editorFixedPrice();
        $draft = InvoiceFixtures::draft($client);

        editor($draft)->callAction(TestAction::make('addFixedAmount')->schemaComponent('itemActions', schema: 'form'), [
            'project_id' => $project->id,
            'amount' => '10000',
            'link_time' => true,
        ])->assertHasNoActionErrors();

        expect(editorFreshEntry($entries[0])->invoice_id)->toBe($draft->id)
            ->and(editorFreshEntry($entries[1])->invoice_id)->toBe($draft->id);
    });

    it('leaves the time alone without the option', function (): void {
        [$client, $project, $entries] = editorFixedPrice();
        $draft = InvoiceFixtures::draft($client);

        editor($draft)->callAction(TestAction::make('addFixedAmount')->schemaComponent('itemActions', schema: 'form'), [
            'project_id' => $project->id,
            'amount' => '10000',
        ])->assertHasNoActionErrors();

        expect(editorFreshEntry($entries[0])->invoice_id)->toBeNull();
    });

    it('shows the field error of the Action under the modal field', function (): void {
        [$client, $project] = editorFixedPrice();
        $draft = InvoiceFixtures::draft($client);

        editor($draft)->callAction(TestAction::make('addFixedAmount')->schemaComponent('itemActions', schema: 'form'), [
            'project_id' => $project->id,
            'amount' => '0',
        ])->assertHasActionErrors(['amount']);

        expect(storedLines($draft))->toBe([]);
    });

    it('refuses a project of another client', function (): void {
        [, $project] = editorFixedPrice();
        $draft = InvoiceFixtures::draft(InvoiceFixtures::client());

        editor($draft)->callAction(TestAction::make('addFixedAmount')->schemaComponent('itemActions', schema: 'form'), [
            'project_id' => $project->id,
            'amount' => '1000',
        ])->assertHasActionErrors(['project_id']);

        expect(storedLines($draft))->toBe([]);
    });
});

describe('the table of items', function (): void {
    it('shows the empty row text for a draft without items', function (): void {
        $draft = InvoiceFixtures::draft(InvoiceFixtures::client());

        editor($draft)->assertSee('Zatím žádné položky. Přidejte položku nebo připojte čas.');
    });

    it('hides the add buttons and names the cap at the most lines', function (): void {
        config(['kokpit.invoicing.max_items' => 3]);
        $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), [
            InvoiceFixtures::item('Example one', '1', '10.00'),
            InvoiceFixtures::item('Example two', '1', '10.00'),
            InvoiceFixtures::item('Example three', '1', '10.00'),
        ]);

        editor($draft)
            ->assertSee('Doklad může mít nejvýše 3 položek.')
            ->assertDontSee('Přidat položku')
            ->assertDontSee('Přidat pevnou částku')
            ->assertDontSee('Přidat nový čas');
    });

    it('offers the add buttons below the cap, and no cap note', function (): void {
        $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Example one', '1', '10.00')]);

        editor($draft)
            ->assertSee('Přidat položku')
            ->assertSee('Přidat pevnou částku')
            ->assertSee('Přidat nový čas')
            ->assertDontSee('Doklad může mít nejvýše');
    });

    it('moves a line down for keyboard users and stores the positions in the visible order', function (): void {
        $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), [
            InvoiceFixtures::item('Example first', '1', '10.00'),
            InvoiceFixtures::item('Example second', '1', '20.00'),
        ]);
        $before = storedLines($draft);

        $editor = editor($draft);
        [$first] = rowKeys($editor);

        $editor->assertSee('Posunout dolů')
            ->assertSee('Posunout nahoru')
            ->callAction(TestAction::make('moveDown')->schemaComponent('items', schema: 'form')->arguments(['item' => $first]))
            ->call('save')
            ->assertHasNoFormErrors();

        $lines = storedLines($draft);

        expect(array_map(static fn (InvoiceItem $line): string => $line->id, $lines))->toBe([$before[1]->id, $before[0]->id])
            ->and(array_map(static fn (InvoiceItem $line): int => $line->position, $lines))->toBe([1, 2]);
    });

    it('gives each delete button the name of its row', function (): void {
        $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), [
            InvoiceFixtures::item('Example first', '1', '10.00'),
            InvoiceFixtures::item('Example second', '1', '20.00'),
        ]);

        $html = editor($draft)->html();

        expect($html)->toContain('aria-label="Smazat položku 1"')
            ->and($html)->toContain('aria-label="Smazat položku 2"');
    });

    it('deletes a line on save and keeps the linked time as it is', function (): void {
        $client = InvoiceFixtures::client();
        $project = editorProject($client, 'Alpha project');
        $entry = editorEntry($project, '2026-09-10 08:00:00', '2026-09-10 10:00:00');
        $draft = editorTimeDraft($client, [$project]);

        $editor = editor($draft);
        [$key] = rowKeys($editor);

        $editor->callAction(TestAction::make('delete')->schemaComponent('items', schema: 'form')->arguments(['item' => $key]))
            ->call('save')
            ->assertHasNoFormErrors();

        expect(storedLines($draft))->toBe([])
            ->and(editorFreshEntry($entry)->invoice_id)->toBe($draft->id)
            ->and(storedDraft($draft)->total_minor)->toBe(0);
    });

    it('keeps a manual, a time and a fixed-amount line together', function (): void {
        [$client, $project] = editorFixedPrice();
        $time = editorProject($client, 'Alpha project');
        editorEntry($time, '2026-09-10 08:00:00', '2026-09-10 10:00:00');
        $draft = editorTimeDraft($client, [$time]);
        app(UpdateDraft::class)->handle($this->admin, $draft, ['items' => [
            ...array_map(static fn (InvoiceItem $item): array => ['id' => $item->id, 'description' => $item->description, 'quantity' => $item->quantity, 'unit' => $item->unit, 'unit_price' => Money::ofMinor($item->unit_price_minor, 'CZK')->toMajor()], storedLines($draft)),
            InvoiceFixtures::item('Example manual', '1', '100.00'),
        ]]);

        $editor = editor($draft);
        $editor->callAction(TestAction::make('addFixedAmount')->schemaComponent('itemActions', schema: 'form'), ['project_id' => $project->id, 'amount' => '500']);
        $editor->call('save')->assertHasNoFormErrors();

        $origins = array_map(static fn (InvoiceItem $line): string => $line->source->origin->value, storedLines($draft));

        expect($origins)->toBe(['time', 'manual', 'fixed_amount']);
    });
});

describe('deleting a draft', function (): void {
    it('asks with the destructive copy', function (): void {
        $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '10.00')]);

        editor($draft)
            ->mountAction('deleteDraft')
            ->assertMountedActionModalSee(['Smazat koncept?', 'Koncept se trvale smaže a všechny připojené záznamy času se vrátí mezi nevyfakturované.', 'Ponechat koncept']);
    });

    it('deletes the draft through DeleteDraft, frees its time and returns to the list with the toast', function (): void {
        $client = InvoiceFixtures::client();
        $project = editorProject($client, 'Alpha project');
        $entry = editorEntry($project, '2026-09-10 08:00:00', '2026-09-10 10:00:00');
        $draft = editorTimeDraft($client, [$project]);

        editor($draft)
            ->callAction('deleteDraft')
            ->assertNotified('Koncept byl smazán, záznamy času jsou znovu nevyfakturované')
            ->assertRedirect(InvoiceResource::getUrl('index'));

        expect(app(PartnerContext::class)->runAsSystem(static fn (): bool => Invoice::query()->whereKey($draft->id)->exists()))->toBeFalse()
            ->and(editorFreshEntry($entry)->invoice_id)->toBeNull();
    });
});

/**
 * The tab "Záznamy času" of a draft.
 */
function entriesTab(Invoice $document): Testable
{
    return Livewire::test(InvoiceEntriesRelationManager::class, ['ownerRecord' => $document, 'pageClass' => EditInvoice::class]);
}

describe('the tab of linked time', function (): void {
    it('lists the linked entries with the duration, the rate, the amount and the total', function (): void {
        $client = InvoiceFixtures::client();
        $project = editorProject($client, 'Alpha project');
        $fixed = editorProject($client, 'Fixed project', ['billing_type' => 'fixed_price', 'fixed_price' => '50000']);
        $one = editorEntry($project, '2026-09-10 08:00:00', '2026-09-10 09:00:00', ['description' => 'Example one']);
        $two = editorEntry($project, '2026-09-11 08:00:00', '2026-09-11 10:30:00', ['description' => 'Example two']);
        $three = editorEntry($fixed, '2026-09-12 08:00:00', '2026-09-12 08:30:00', ['description' => 'Example fixed']);
        $draft = editorTimeDraft($client, [$project]);
        app(AddFixedAmount::class)->handle($this->admin, $draft, ['project_id' => $fixed->id, 'amount' => '1000', 'link_time' => true]);

        $tab = entriesTab($draft)
            ->assertCanSeeTableRecords([$one, $two, $three], inOrder: true)
            ->assertSee('Záznamy času')
            ->assertSee('1:00')
            ->assertSee('2:30')
            ->assertSee(Money::ofMinor(100000, 'CZK')->format('cs'))
            ->assertSee(Money::ofMinor(250000, 'CZK')->format('cs'))
            ->assertTableColumnStateSet('amount', '—', $three)
            ->assertTableColumnStateSet('rate', '—', $three);

        // The footer holds the total of all three entries: 1:00 + 2:30 + 0:30.
        expect($tab->html())->toContain('4:00')
            ->and($tab->instance()->getTable()->getDefaultPaginationPageOption())->toBe(25);
    });

    it('shows the empty state of a draft without linked time', function (): void {
        $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '10.00')]);

        entriesTab($draft)
            ->assertSee('K faktuře není připojen žádný čas')
            ->assertSee('Čas připojíte tlačítkem Přidat nový čas. Faktura může obsahovat i jen ruční položky.');
    });

    it('offers only unbilled, unreserved, billable entries of the client and links the chosen ones as new lines', function (): void {
        $client = InvoiceFixtures::client();
        $project = editorProject($client, 'Alpha project');
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example kept line', '1', '10.00')]);
        $keptBefore = storedLines($draft);

        $a = editorEntry($project, '2026-09-10 08:00:00', '2026-09-10 09:00:00', ['description' => 'Eligible one']);
        $b = editorEntry($project, '2026-09-11 08:00:00', '2026-09-11 08:30:00', ['description' => 'Eligible two']);
        $other = InvoiceFixtures::draft($client);
        $reserved = editorEntry($project, '2026-09-12 08:00:00', '2026-09-12 09:00:00', ['description' => 'Reserved elsewhere']);
        app(AddEntriesToDraft::class)->handle($this->admin, $other, [$reserved->id]);
        $foreign = editorEntry(editorProject(InvoiceFixtures::client(), 'Foreign project'), '2026-09-13 08:00:00', '2026-09-13 09:00:00', ['description' => 'Another client']);
        $notBillable = editorEntry($project, '2026-09-14 08:00:00', '2026-09-14 09:00:00', ['description' => 'Not billable', 'billable' => false]);

        $data = ['period' => 'custom', 'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'project_ids' => [$project->id], 'without_project' => false];

        entriesTab($draft)
            ->mountAction(TestAction::make('addTime')->table())
            ->setActionData($data)
            ->assertMountedActionModalSee(['Eligible one', 'Eligible two'])
            ->assertMountedActionModalDontSee(['Reserved elsewhere', 'Another client', 'Not billable']);

        entriesTab($draft)
            ->callAction(TestAction::make('addTime')->table(), [...$data, 'entry_ids' => [$a->id, $b->id]])
            ->assertHasNoActionErrors()
            ->assertNotified('Připojeno 2 záznamů, přidáno 1 položek');

        $lines = storedLines($draft);

        expect($lines)->toHaveCount(2)
            ->and($lines[0]->id)->toBe($keptBefore[0]->id)
            ->and($lines[0]->description)->toBe('Example kept line')
            ->and($lines[1]->quantity)->toBe('1.5000')
            ->and(editorFreshEntry($a)->invoice_id)->toBe($draft->id)
            ->and(editorFreshEntry($b)->invoice_id)->toBe($draft->id)
            ->and(editorFreshEntry($foreign)->invoice_id)->toBeNull()
            ->and(editorFreshEntry($notBillable)->invoice_id)->toBeNull();
    });

    it('refuses the id of an entry of another client and changes nothing', function (): void {
        $client = InvoiceFixtures::client();
        $draft = InvoiceFixtures::draft($client);
        $foreign = editorEntry(editorProject(InvoiceFixtures::client(), 'Foreign project'), '2026-09-13 08:00:00', '2026-09-13 09:00:00');

        entriesTab($draft)
            ->callAction(TestAction::make('addTime')->table(), ['period' => 'custom', 'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'without_project' => true, 'entry_ids' => [$foreign->id]])
            ->assertHasActionErrors(['entry_ids.0']);

        expect(storedLines($draft))->toBe([])
            ->and(editorFreshEntry($foreign)->invoice_id)->toBeNull();
    });

    it('unlinks the selected entries after the warning and leaves the lines as they are', function (): void {
        $client = InvoiceFixtures::client();
        $project = editorProject($client, 'Alpha project');
        $one = editorEntry($project, '2026-09-10 08:00:00', '2026-09-10 09:00:00');
        $two = editorEntry($project, '2026-09-11 08:00:00', '2026-09-11 09:00:00');
        $keep = editorEntry($project, '2026-09-12 08:00:00', '2026-09-12 09:00:00');
        $draft = editorTimeDraft($client, [$project]);
        $linesBefore = array_map(static fn (InvoiceItem $line): array => [$line->id, $line->quantity, $line->line_total_minor], storedLines($draft));

        entriesTab($draft)
            ->selectTableRecords([$one->id, $two->id])
            ->mountAction(TestAction::make('unlink')->table()->bulk())
            ->assertMountedActionModalSee(['Odpojit záznamy od faktury?', '2 záznamů se vrátí mezi nevyfakturované. Položky faktury se nezmění, upravte nebo smažte je ručně.']);

        entriesTab($draft)
            ->selectTableRecords([$one->id, $two->id])
            ->callAction(TestAction::make('unlink')->table()->bulk())
            ->assertNotified('Záznamy byly odpojeny');

        expect(editorFreshEntry($one)->invoice_id)->toBeNull()
            ->and(editorFreshEntry($two)->invoice_id)->toBeNull()
            ->and(editorFreshEntry($keep)->invoice_id)->toBe($draft->id)
            ->and(array_map(static fn (InvoiceItem $line): array => [$line->id, $line->quantity, $line->line_total_minor], storedLines($draft)))->toBe($linesBefore);
    });

    it('is read only on a locked issued document: no add, no unlink', function (): void {
        $client = InvoiceFixtures::client();
        $project = editorProject($client, 'Alpha project');
        editorEntry($project, '2026-09-10 08:00:00', '2026-09-10 09:00:00');
        $issued = InvoiceFixtures::lock(app(IssueDocument::class)->handle($this->admin, editorTimeDraft($client, [$project])));

        entriesTab($issued)
            ->assertSee('Navázaný čas')
            ->assertDontSee('Přidat nový čas')
            ->assertDontSee('Odpojit od faktury')
            ->assertCountTableRecords(1);
    });

    it('answers a Partner with 403', function (): void {
        $client = InvoiceFixtures::client();
        $draft = InvoiceFixtures::draft($client);

        $this->actingAs(Canary::partnerFor($client->id));

        entriesTab($draft)->assertForbidden();
    });

    it('opens the add dialog from the button under the table of lines', function (): void {
        $draft = InvoiceFixtures::draft(InvoiceFixtures::client());

        editor($draft)
            ->callAction(TestAction::make('addTime')->schemaComponent('itemActions', schema: 'form'))
            ->assertDispatched('draft-add-time');
    });

    it('lets the editor pick up the lines that the tab appended', function (): void {
        $client = InvoiceFixtures::client();
        $project = editorProject($client, 'Alpha project');
        $entry = editorEntry($project, '2026-09-10 08:00:00', '2026-09-10 09:00:00');
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example kept line', '1', '10.00')]);

        $editor = editor($draft);
        [$key] = rowKeys($editor);
        $editor->set("data.items.{$key}.description", 'Edited but not saved');

        app(AddEntriesToDraft::class)->handle($this->admin, $draft, [$entry->id]);

        $editor->dispatch('draft-lines-changed');

        expect(array_column($editor->get('data.items'), 'description'))->toBe(['Edited but not saved', 'Alpha project']);
    });
});
