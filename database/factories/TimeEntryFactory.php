<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Support\TimerClock;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fictional time entries only: the description is assembled at runtime. The ids,
 * the instants and the billing state are not fillable on the model; factories
 * build models unguarded, so the factory can still set them.
 *
 * The default entry is finished (started two hours ago, ended one hour ago) for
 * a new client alone. The instants are truncated to the whole second, as every
 * real writer does.
 *
 * @extends Factory<TimeEntry>
 */
class TimeEntryFactory extends Factory
{
    /**
     * @var class-string<TimeEntry>
     */
    protected $model = TimeEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'client_id' => Client::factory(),
            'project_id' => null,
            'task_id' => null,
            'description' => 'Example work '.Str::lower(Str::random(8)),
            'started_at' => now()->subHours(2),
            'ended_at' => now()->subHour(),
            'billable' => true,
        ];
    }

    /**
     * A running entry: started a minute ago, not ended.
     */
    public function running(): static
    {
        return $this->state(['started_at' => now()->subMinute(), 'ended_at' => null]);
    }

    /**
     * An entry on the task, with the project and the client of that task.
     */
    public function forTask(Task $task): static
    {
        return $this->state(function () use ($task): array {
            $project = $this->projectOf($task->project_id);

            return ['task_id' => $task->id, 'project_id' => $project->id, 'client_id' => $project->client_id];
        });
    }

    /**
     * An entry on the project, with the client of that project.
     */
    public function forProject(Project $project): static
    {
        return $this->state(['project_id' => $project->id, 'client_id' => $project->client_id]);
    }

    /**
     * A billed entry. The database refuses a billed entry that is running or
     * not billable, so this is a finished billable one.
     */
    public function billed(): static
    {
        return $this->state(['billing_state' => 'billed', 'billed_at' => now(), 'billable' => true]);
    }

    public function configure(): static
    {
        return $this->afterMaking(static function (TimeEntry $entry): void {
            $entry->forceFill(array_filter([
                'started_at' => TimerClock::truncate($entry->started_at),
                'ended_at' => $entry->ended_at === null ? null : TimerClock::truncate($entry->ended_at),
                'billed_at' => $entry->billed_at === null ? null : TimerClock::truncate($entry->billed_at),
            ]));
        });
    }

    private function projectOf(string $projectId): Project
    {
        return app(PartnerContext::class)->runAsSystem(
            static fn (): Project => Project::query()->withTrashed()->findOrFail($projectId),
        );
    }
}
