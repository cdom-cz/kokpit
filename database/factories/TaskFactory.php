<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Settings\Numbering\DocumentNumbering;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Board\TaskBoard;
use App\Domain\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fictional tasks only: the title is assembled at runtime. The ids, number,
 * reference and position are not fillable on the model; factories build models
 * unguarded, so the factory can still set them.
 *
 * The reference and number come from the real per-project counter and the
 * position from the board, allocated after the model is made, unless a state
 * or an override already set them.
 *
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * @var class-string<Task>
     */
    protected $model = Task::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'parent_id' => null,
            'depth' => 0,
            'title' => 'Example task '.Str::lower(Str::random(8)),
            'status' => 'planned',
            'priority' => 'normal',
            'assignee_id' => User::factory(),
            'requester_id' => User::factory(),
        ];
    }

    public function done(): static
    {
        return $this->state(['status' => 'done', 'completed_at' => now()]);
    }

    public function configure(): static
    {
        return $this->afterMaking(static function (Task $task): void {
            if ($task->getAttribute('number') === null) {
                $project = app(PartnerContext::class)->runAsSystem(
                    static fn (): Project => Project::query()->findOrFail($task->project_id),
                );

                $reference = DB::transaction(
                    static fn (): string => app(DocumentNumbering::class)->nextTaskNumber($project->id, $project->key),
                );

                $task->forceFill([
                    'number' => (int) substr($reference, (int) strrpos($reference, '-') + 1),
                    'reference' => $reference,
                ]);
            }

            if ($task->getAttribute('position') === null) {
                $task->forceFill(['position' => app(TaskBoard::class)->nextPosition($task->status)]);
            }

            if ($task->status === ProjectStatus::Done && $task->completed_at === null) {
                $task->forceFill(['completed_at' => now()]);
            }
        });
    }
}
