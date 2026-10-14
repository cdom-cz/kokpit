<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Filament\Support\ProjectColumns;
use App\Filament\Support\TaskColumns;
use App\Filament\Widgets\PartnerTasks;
use Filament\Facades\Filament;
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
