<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Database\CzechCollation;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\TimeTracking\Queries\EntryContextOptions;
use Illuminate\Support\Facades\DB;
use Tests\Support\Canary;

/*
 * Text that is sorted for people uses the Czech collation (CONTRIBUTING "Ordering",
 * research Pitfall 3), and the pickers of the entry form offer only combinations the
 * database accepts. Every name is a fictional ordinary Czech noun.
 */

/** The names in the order a Czech reader expects: ch sorts after h, č after c, ř after r, š after s. */
const CZECH_ORDER = ['Cihla', 'Čočka', 'Dub', 'Hrad', 'Chata', 'Řeka', 'Sova', 'Šiška', 'Zima'];

/**
 * Creates one client per name, in the given order.
 *
 * @param  list<string>  $names
 */
function czechClients(array $names): void
{
    foreach ($names as $name) {
        Client::factory()->create(['name' => $name]);
    }
}

/**
 * A project of the client with the given key and name, written as a system run.
 */
function czechProject(Client $client, string $key, string $name): Project
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Project => Project::factory()->create([
        'client_id' => $client->id,
        'key' => $key,
        'name' => $name,
    ]));
}

beforeEach(function (): void {
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('sorts the client picker in Czech order', function (): void {
    czechClients(['Zima', 'Šiška', 'Sova', 'Řeka', 'Chata', 'Hrad', 'Dub', 'Čočka', 'Cihla']);

    expect(array_values(app(EntryContextOptions::class)->clients()))->toBe(CZECH_ORDER);
});

it('shows the default collation would sort the same names differently', function (): void {
    czechClients(['Zima', 'Šiška', 'Sova', 'Řeka', 'Chata', 'Hrad', 'Dub', 'Čočka', 'Cihla']);

    $default = Client::query()->orderBy('name')->pluck('name')->all();
    $czech = CzechCollation::orderBy(Client::query(), 'clients.name')->pluck('name')->all();

    expect($czech)->toBe(CZECH_ORDER)
        ->and($default)->not->toBe(CZECH_ORDER);
});

it('can sort descending', function (): void {
    czechClients(['Dub', 'Chata', 'Cihla']);

    expect(CzechCollation::orderBy(Client::query(), 'clients.name', 'DESC')->pluck('name')->all())->toBe(['Chata', 'Dub', 'Cihla']);
});

it('refuses a sort direction that is not asc or desc', function (): void {
    CzechCollation::orderBy(Client::query(), 'clients.name', 'asc; DROP TABLE clients');
})->throws(InvalidArgumentException::class);

it('has the Czech collation in the database', function (): void {
    expect(DB::selectOne('select count(*) as n from pg_collation where collname = ?', [CzechCollation::NAME])->n)->toBeGreaterThan(0);
});

it('leaves an archived client out of the client picker', function (): void {
    czechClients(['Cihla', 'Dub']);
    Client::query()->where('name', 'Dub')->firstOrFail()->delete();

    expect(array_values(app(EntryContextOptions::class)->clients()))->toBe(['Cihla']);
});

it('offers the selectable projects of a client, labelled with the key and in Czech order', function (): void {
    $client = Client::factory()->create();
    $other = Client::factory()->create();
    $chata = czechProject($client, 'CHA', 'Chata');
    $hrad = czechProject($client, 'HRA', 'Hrad');
    $cihla = czechProject($client, 'CIH', 'Cihla');
    $archived = czechProject($client, 'ARC', 'Archiv');
    $archived->delete();
    czechProject($other, 'OTH', 'Dub');

    expect(app(EntryContextOptions::class)->projects($client->id))->toBe([
        $cihla->id => 'CIH · Cihla',
        $hrad->id => 'HRA · Hrad',
        $chata->id => 'CHA · Chata',
    ]);
});

it('offers no project of an archived client', function (): void {
    $client = Client::factory()->create();
    czechProject($client, 'CIH', 'Cihla');
    $client->delete();

    expect(app(EntryContextOptions::class)->projects($client->id))->toBe([]);
});

it('offers the active tasks of the chosen project, or of the selectable projects of the client', function (): void {
    $client = Client::factory()->create();
    $one = czechProject($client, 'ONE', 'Cihla');
    $two = czechProject($client, 'TWO', 'Dub');
    $gone = czechProject($client, 'OLD', 'Hrad');
    $foreign = czechProject(Client::factory()->create(), 'FOR', 'Sova');

    $create = fn (Project $project, string $title) => app(CreateTask::class)->handle($this->admin, $project, ['title' => $title]);
    $a = $create($one, 'First');
    $b = $create($two, 'Second');
    $c = $create($one, 'Third');
    $create($gone, 'Hidden by archive')->delete();
    $gone->delete();
    $create($foreign, 'Of another client');
    $create($one, 'Archived task')->delete();

    $options = app(EntryContextOptions::class);

    expect($options->tasks($client->id, $one->id))->toBe([
        $a->id => 'ONE-1 · First',
        $c->id => 'ONE-2 · Third',
    ])->and($options->tasks($client->id, null))->toBe([
        $a->id => 'ONE-1 · First',
        $c->id => 'ONE-2 · Third',
        $b->id => 'TWO-1 · Second',
    ])->and($options->tasks($client->id, $gone->id))->toBe([]);
});
