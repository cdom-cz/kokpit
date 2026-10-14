<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Support\ProjectColumns;
use App\Filament\Support\TaskColumns;
use App\Filament\Widgets\PartnerProjects;
use App\Filament\Widgets\PartnerTasks;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The Partner dashboard (D-12, UI-SPEC Surface F): the open tasks of the Partner's own visible
 * projects with the ones waiting for the Partner's review first and highlighted. Every name, key
 * and text is fictional and assembled at runtime; rows are written in system runs.
 */

/**
 * The inline style of the highlight of a task in review, as the view writes it.
 */
const PARTNER_DASH_BORDER = 'border-left: 4px solid var(--primary-600)';

const PARTNER_DASH_TINT = 'background: color-mix(in srgb, var(--primary-600) 8%, transparent)';

/**
 * Creates a project of the given client in a system run.
 *
 * @param  array<string, mixed>  $attributes
 */
function partnerDashProject(string $clientId, array $attributes = []): Project
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Project => app(CreateProject::class)->handle(Client::query()->findOrFail($clientId), [
        'name' => Canary::canary('project'),
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'client_visible' => true,
        ...$attributes,
    ]));
}

/**
 * Creates a task of the project as the given actor, in a system run.
 *
 * @param  array<string, mixed>  $data
 */
function partnerDashTask(User $actor, Project $project, array $data = []): Task
{
    return app(PartnerContext::class)->runAsSystem(
        static fn (): Task => app(CreateTask::class)->handle($actor, $project, ['title' => Canary::canary('task'), ...$data]),
    );
}

/**
 * The position of a text in the body, or a failure that names the text.
 */
function partnerDashPosition(string $body, string $needle): int
{
    $position = strpos($body, $needle);

    expect($position)->not->toBeFalse('Missing in the dashboard: '.$needle);

    return (int) $position;
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->admin = Canary::admin();
    [$this->clientA, $this->clientB] = Canary::twoClients();
    $this->projectA = partnerDashProject($this->clientA);
    $this->projectB = partnerDashProject($this->clientB);
    $this->partnerA = Canary::partnerFor($this->clientA);
});

describe('the open tasks of a Partner', function (): void {
    it('lists the open tasks with the one in review first, badged and highlighted, and nothing of client B', function (): void {
        $review = partnerDashTask($this->admin, $this->projectA, ['status' => 'in_review', 'priority' => 'low']);
        $high = partnerDashTask($this->admin, $this->projectA, ['status' => 'planned', 'priority' => 'high']);
        $normal = partnerDashTask($this->admin, $this->projectA, ['status' => 'in_progress']);
        $done = partnerDashTask($this->admin, $this->projectA, ['status' => 'done']);
        $foreign = partnerDashTask($this->admin, $this->projectB, ['status' => 'in_review']);

        $body = (string) $this->actingAs($this->partnerA)->get('/admin')->assertOk()->getContent();

        expect($body)->toContain('Otevřené úkoly')
            ->toContain('Nejdřív úkoly, které čekají na vaši kontrolu.')
            ->toContain('Ke kontrole: 1')
            ->toContain('Zkontrolovat úkol')
            ->toContain('/admin/my-tasks/'.$review->reference)
            ->toContain(PARTNER_DASH_BORDER)
            ->toContain(PARTNER_DASH_TINT)
            ->not->toContain($done->title)
            ->not->toContain($foreign->title)
            ->not->toContain($foreign->reference);

        // In review first although its priority is the lowest, then the priority order.
        expect(partnerDashPosition($body, $review->title))->toBeLessThan(partnerDashPosition($body, $high->title))
            ->and(partnerDashPosition($body, $high->title))->toBeLessThan(partnerDashPosition($body, $normal->title));
    });

    it('orders by review, then priority, then due date with the undated last, then reference', function (): void {
        $late = partnerDashTask($this->admin, $this->projectA, ['priority' => 'normal', 'due_date' => '2026-12-24']);
        $undated = partnerDashTask($this->admin, $this->projectA, ['priority' => 'normal']);
        $soon = partnerDashTask($this->admin, $this->projectA, ['priority' => 'normal', 'due_date' => '2026-11-02']);
        $urgent = partnerDashTask($this->admin, $this->projectA, ['priority' => 'urgent']);

        $this->actingAs($this->partnerA);

        expect(Livewire::test(PartnerTasks::class)->instance()->tasks()->pluck('id')->all())
            ->toBe([$urgent->id, $soon->id, $late->id, $undated->id]);
    });

    it('links the task by its reference and shows the project, the assignee and the due date', function (): void {
        $task = partnerDashTask($this->admin, $this->projectA, ['due_date' => '2026-11-02']);

        $html = Livewire::actingAs($this->partnerA)->test(PartnerTasks::class)->html();

        expect($html)->toContain('/admin/my-tasks/'.$task->reference)
            ->toContain($task->reference)
            ->toContain($this->projectA->name)
            ->toContain(e($this->admin->name))
            ->toContain('2. 11. 2026');
    });

    it('follows the number of tasks in review: none, one and many', function (int $count): void {
        for ($i = 0; $i < $count; $i++) {
            partnerDashTask($this->admin, $this->projectA, ['status' => 'in_review']);
        }

        partnerDashTask($this->admin, $this->projectA, ['status' => 'planned']);

        $html = Livewire::actingAs($this->partnerA)->test(PartnerTasks::class)->html();

        expect(substr_count($html, PARTNER_DASH_BORDER))->toBe($count)
            ->and(substr_count($html, 'Zkontrolovat úkol'))->toBe($count);

        if ($count === 0) {
            expect($html)->not->toContain('Ke kontrole')->not->toContain(PARTNER_DASH_TINT);
        } else {
            expect($html)->toContain('Ke kontrole: '.$count);
        }
    })->with([0, 1, 3]);

    it('wraps a long title up to two lines with the full text as tooltip', function (): void {
        $title = 'Example '.str_repeat('Longword', 10).' '.str_repeat('x', 120);
        partnerDashTask($this->admin, $this->projectA, ['title' => $title]);

        $html = Livewire::actingAs($this->partnerA)->test(PartnerTasks::class)->html();

        expect($html)->toContain('-webkit-line-clamp: 2')
            ->toContain('overflow-wrap: anywhere')
            ->toContain('title="'.$title.'"');
    });

    it('escapes stored markup in a title', function (): void {
        $word = 'mk'.bin2hex(random_bytes(4));
        $markup = implode('', ['<', 'script', '>']).$word.implode('', ['<', '/script', '>']);
        partnerDashTask($this->admin, $this->projectA, ['title' => $markup]);

        $html = Livewire::actingAs($this->partnerA)->test(PartnerTasks::class)->html();

        expect($html)->not->toContain($markup)->toContain($word);
    });

    it('refuses the widget to an Admin, also when forged, and to a Partner without a client', function (): void {
        $this->actingAs($this->admin);
        expect(PartnerTasks::canView())->toBeFalse();
        Livewire::test(PartnerTasks::class)->assertForbidden();

        $this->actingAs(Canary::partnerFor(null));
        expect(PartnerTasks::canView())->toBeFalse();
        Livewire::test(PartnerTasks::class)->assertForbidden();

        $this->actingAs($this->partnerA);
        expect(PartnerTasks::canView())->toBeTrue();
    });
});

describe('the fields of the widgets', function (): void {
    it('renders only Partner task fields, the project name being a Partner project field', function (): void {
        foreach (PartnerTasks::FIELDS as $field) {
            $allowed = in_array($field, TaskColumns::PARTNER_COLUMN_NAMES, true)
                || (str_starts_with($field, 'project.') && in_array(substr($field, 8), ProjectColumns::PARTNER_COLUMN_NAMES, true));

            expect($allowed)->toBeTrue('Not a Partner field: '.$field);
        }
    });
});

describe('the visible projects of a Partner', function (): void {
    it('lists the visible, non-archived projects of the own client in Czech order and nothing else', function (): void {
        // In Czech collation Cihla comes before Čočka, and Chata after both.
        $chata = partnerDashProject($this->clientA, ['name' => 'Chata '.Canary::canary('p')]);
        $cocka = partnerDashProject($this->clientA, ['name' => 'Čočka '.Canary::canary('p')]);
        $cihla = partnerDashProject($this->clientA, ['name' => 'Cihla '.Canary::canary('p')]);
        $hidden = partnerDashProject($this->clientA, ['name' => Canary::canary('hidden'), 'client_visible' => false]);
        $archived = partnerDashProject($this->clientA, ['name' => Canary::canary('archived')]);
        app(PartnerContext::class)->runAsSystem(static fn () => $archived->delete());

        $this->actingAs($this->partnerA);

        Livewire::test(PartnerProjects::class)
            ->assertCanSeeTableRecords([$cihla, $cocka, $chata, $this->projectA], inOrder: false)
            ->assertCanNotSeeTableRecords([$hidden, $archived, $this->projectB]);

        $names = Livewire::test(PartnerProjects::class)->instance()->getTableRecords()->pluck('name')->all();
        $own = array_values(array_filter($names, fn (string $name): bool => $name !== $this->projectA->name));

        expect($own)->toBe([$cihla->name, $cocka->name, $chata->name]);
    });

    it('shows Název, Klíč, Stav, Priorita and Konec only, with a dash for an empty end', function (): void {
        $this->actingAs($this->partnerA);

        $html = Livewire::test(PartnerProjects::class)->html();

        foreach (['Moje projekty', 'Název', 'Klíč', 'Stav', 'Priorita', 'Konec'] as $text) {
            expect($html)->toContain($text);
        }

        foreach (['Začátek', 'Štítky', 'Klient', 'Popis'] as $text) {
            expect($html)->not->toContain($text);
        }

        expect($html)->toContain('—')
            ->toContain('/admin/my-projects/'.$this->projectA->getRouteKey())
            ->toContain($this->projectA->key);
    });

    it('shows at most 10 projects', function (): void {
        for ($i = 0; $i < 11; $i++) {
            partnerDashProject($this->clientA);
        }

        $this->actingAs($this->partnerA);

        expect(Livewire::test(PartnerProjects::class)->instance()->getTableRecords())->toHaveCount(10);
        expect(PartnerProjects::visibleProjectCount())->toBe(12);
    });

    it('selects only Partner project fields', function (): void {
        foreach (PartnerProjects::FIELDS as $field) {
            expect(ProjectColumns::PARTNER_COLUMN_NAMES)->toContain($field);
        }
    });

    it('refuses the widget to an Admin, also when forged', function (): void {
        $this->actingAs($this->admin);

        expect(PartnerProjects::canView())->toBeFalse();
        Livewire::test(PartnerProjects::class)->assertForbidden();
    });
});

describe('the number of rows of the task list', function (): void {
    it('shows 20 rows and the link to all tasks when 21 are open', function (): void {
        for ($i = 0; $i < 21; $i++) {
            partnerDashTask($this->admin, $this->projectA);
        }

        $html = Livewire::actingAs($this->partnerA)->test(PartnerTasks::class)->html();

        expect(substr_count($html, 'wire:key="partner-task-'))->toBe(20)
            ->and($html)->toContain('Zobrazit všechny úkoly')
            ->toContain('/admin/my-tasks"');
    });

    it('shows no link when exactly 20 are open', function (): void {
        for ($i = 0; $i < 20; $i++) {
            partnerDashTask($this->admin, $this->projectA);
        }

        $html = Livewire::actingAs($this->partnerA)->test(PartnerTasks::class)->html();

        expect(substr_count($html, 'wire:key="partner-task-'))->toBe(20)
            ->and($html)->not->toContain('Zobrazit všechny úkoly');
    });
});

describe('the empty states', function (): void {
    it('gives the task widget its own empty state when there are projects and no open task', function (): void {
        partnerDashTask($this->admin, $this->projectA, ['status' => 'done']);

        $this->actingAs($this->partnerA);
        $widget = Livewire::test(PartnerTasks::class)->html();
        $body = (string) $this->get('/admin')->assertOk()->getContent();

        expect($widget)->toContain('Žádné otevřené úkoly')->toContain('Jakmile bude potřeba vaše pozornost, objeví se to tady.')
            ->and($body)->toContain('Žádné otevřené úkoly')
            ->toContain($this->projectA->name)
            ->not->toContain(__('kokpit.dashboard.empty_heading'));
    });

    it('gives the project widget its own empty state when no project is visible', function (): void {
        // The only project of the client is archived: it is gone for the Partner, and its tasks with it.
        $this->actingAs($this->partnerA);
        app(PartnerContext::class)->runAsSystem(fn () => $this->projectA->delete());

        $html = Livewire::test(PartnerProjects::class)->html();

        expect($html)->toContain('Zatím žádné projekty')
            ->toContain('Projekty, které pro vás Kokpit zpřístupní, se objeví tady.');
    });

    it('shows the stock empty state instead of two empty widgets when there is nothing', function (): void {
        app(PartnerContext::class)->runAsSystem(fn () => $this->projectA->delete());

        $body = (string) $this->actingAs($this->partnerA)->get('/admin')->assertOk()->getContent();

        expect($body)->toContain(__('kokpit.dashboard.empty_heading'))
            ->toContain(__('kokpit.dashboard.empty_description_partner'))
            ->not->toContain('Žádné otevřené úkoly')
            ->not->toContain('Zatím žádné projekty')
            ->not->toContain('fi-wi-widget');
    });

    it('shows both widgets and no stock empty state for a Partner with a project and a task', function (): void {
        $task = partnerDashTask($this->admin, $this->projectA);

        $body = (string) $this->actingAs($this->partnerA)->get('/admin')->assertOk()->getContent();

        expect($body)->toContain('Otevřené úkoly')
            ->toContain($task->title)
            ->toContain($this->projectA->name)
            ->not->toContain(__('kokpit.dashboard.empty_heading'));
    });

    it('keeps the Admin dashboard and the refusal of a Partner without a client unchanged', function (): void {
        partnerDashTask($this->admin, $this->projectA);

        $this->actingAs($this->admin)->get('/admin')->assertOk()
            ->assertSee(__('kokpit.dashboard.empty_heading'))
            ->assertSee(__('kokpit.dashboard.empty_description_admin'))
            ->assertDontSee('fi-wi-widget', false);

        $this->actingAs(Canary::partnerFor(null))->get('/admin')->assertForbidden();
    });
});

describe('nothing of time or money on the Partner dashboard', function (): void {
    it('contains none of the strings of the never list, no duration and no rate or price', function (): void {
        $project = partnerDashProject($this->clientA, ['billing_type' => 'hourly', 'hourly_rate' => '7391,25']);
        $fixed = partnerDashProject($this->clientA, ['billing_type' => 'fixed_price', 'fixed_price' => '48260']);
        $review = partnerDashTask($this->admin, $project, ['status' => 'in_review']);
        $normal = partnerDashTask($this->admin, $project, ['status' => 'in_progress']);
        $fixedTask = partnerDashTask($this->admin, $fixed);

        app(PartnerContext::class)->runAsSystem(function () use ($normal, $review, $fixedTask): void {
            app(UpdateTask::class)->handle($this->admin, $normal, ['billing_type' => 'hourly', 'hourly_rate' => '6482,75']);
            app(UpdateTask::class)->handle($this->admin, $fixedTask, ['billing_type' => 'fixed_price', 'fixed_price' => '15930']);

            $start = CarbonImmutable::parse('2026-10-09 06:00:00', 'Europe/Prague')->utc();

            // One billed and one unbilled entry of 3:17:41 and 1:02:03.
            TimeEntry::factory()->forTask($review)->billed()->create([
                'user_id' => $this->admin->id,
                'started_at' => $start,
                'ended_at' => $start->addSeconds(3 * 3600 + 17 * 60 + 41),
            ]);
            TimeEntry::factory()->forTask($normal)->create([
                'user_id' => $this->admin->id,
                'started_at' => $start,
                'ended_at' => $start->addSeconds(3600 + 2 * 60 + 3),
            ]);
        });

        $body = (string) $this->actingAs($this->partnerA)->get('/admin')->assertOk()->getContent();

        // The page does show the work: the review task, the other task and the projects.
        expect($body)->toContain($review->title)
            ->toContain($normal->title)
            ->toContain($project->name)
            ->toContain($fixed->name);

        $never = [
            'Odpracováno', 'Nevyfakturováno', 'Vyfakturováno', 'Fakturovatelné', 'Nefakturovatelné', 'Částka', 'Kč', 'EUR',
            'sazba', 'Pevná cena', 'Kurz', 'Report času', 'Exporty', 'Plán na zítřek',
            // The durations of the two entries and every value of a rate or a price, as typed and as formatted.
            '3:17', '1:02', '7391', '7 391', '6482', '6 482', '48260', '48 260', '15930', '15 930',
        ];

        foreach ($never as $text) {
            expect(str_contains($body, $text))->toBeFalse('On the Partner dashboard: '.$text);
        }
    });

    it('selects no time, billing or estimate column', function (): void {
        partnerDashTask($this->admin, $this->projectA);

        $queries = [];

        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->actingAs($this->partnerA)->get('/admin')->assertOk();

        $selects = array_filter($queries, static fn (string $sql): bool => str_starts_with(strtolower($sql), 'select') && str_contains($sql, '"tasks"'));

        expect($selects)->not->toBeEmpty();

        foreach ($selects as $sql) {
            expect($sql)->not->toMatch('/rate|price|estimate|billing|worked|time_entries|description|internal_note/i');
        }
    });
});
