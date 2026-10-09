<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Queries;

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Database\CzechCollation;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Models\TimeEntry;
use Illuminate\Database\Eloquent\Builder;

/**
 * The client, project and task a time entry form offers (TI-07).
 *
 * The options mirror the rules of TimeEntryInput::context(), so the form never
 * offers a combination the Action or the database would refuse: no archived
 * client, no project that is archived or whose client is, no archived task, and a
 * project or task only together with the client it belongs to. Names are sorted in
 * Czech order. The options only narrow the picker; the Action checks the combination
 * again on save.
 *
 * Admin and system contexts only, like every read of tracked time.
 */
final class EntryContextOptions
{
    /**
     * The clients an entry may be recorded for: not archived.
     *
     * @return array<string, string> id => name
     */
    public function clients(): array
    {
        $options = [];

        foreach (CzechCollation::orderBy(Client::query(), 'clients.name')->orderBy('clients.id')->get(['clients.id', 'clients.name']) as $client) {
            $options[$client->id] = $client->name;
        }

        return $options;
    }

    /**
     * The clients the timer offers to the user (UI-SPEC Surface A): up to five non-archived
     * clients by the user's most recent entry, then every other non-archived client in Czech
     * order, so no client appears twice. The client of the user's very latest entry is
     * preselected, unless that client is archived.
     *
     * @return array{recent: array<string, string>, all: array<string, string>, preselected: string|null}
     */
    public function timerClients(User $user): array
    {
        $active = Client::query()->select('clients.id');

        // The soft-delete scope of Client leaves archived clients out of the sub-select.
        $recentIds = TimeEntry::query()
            ->where('time_entries.user_id', $user->getKey())
            ->whereIn('time_entries.client_id', $active)
            ->groupBy('time_entries.client_id')
            ->orderByRaw('MAX(time_entries.started_at) DESC')
            ->orderBy('time_entries.client_id')
            ->limit(5)
            ->pluck('time_entries.client_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $names = Client::query()->whereIn('clients.id', $recentIds)->pluck('clients.name', 'clients.id');

        $recent = [];

        foreach ($recentIds as $id) {
            if ($names->has($id)) {
                $recent[$id] = (string) $names->get($id);
            }
        }

        $all = [];

        $others = Client::query()->whereNotIn('clients.id', $recentIds);

        foreach (CzechCollation::orderBy($others, 'clients.name')->orderBy('clients.id')->get(['clients.id', 'clients.name']) as $client) {
            $all[$client->id] = $client->name;
        }

        $latest = TimeEntry::query()
            ->where('time_entries.user_id', $user->getKey())
            ->orderByDesc('time_entries.started_at')
            ->orderByDesc('time_entries.id')
            ->value('client_id');

        $preselected = is_string($latest) && (array_key_exists($latest, $recent) || array_key_exists($latest, $all)) ? $latest : null;

        return ['recent' => $recent, 'all' => $all, 'preselected' => $preselected];
    }

    /**
     * The selectable projects of the client (all clients when none is chosen yet),
     * labelled `KEY · name`.
     *
     * @return array<string, string> id => label
     */
    public function projects(?string $clientId): array
    {
        $options = [];

        $query = Project::query()->selectable()->when($clientId !== null, static fn (Builder $query): Builder => $query->where('projects.client_id', $clientId));

        foreach (CzechCollation::orderBy($query, 'projects.name')->orderBy('projects.id')->get(['projects.id', 'projects.key', 'projects.name']) as $project) {
            $options[$project->id] = $project->key.' · '.$project->name;
        }

        return $options;
    }

    /**
     * The active tasks of the chosen project, or of the selectable projects of the
     * chosen client when no project is chosen, labelled `KEY-N · title`, in the order
     * of the project key and the task number.
     *
     * @return array<string, string> id => label
     */
    public function tasks(?string $clientId, ?string $projectId): array
    {
        $projects = Project::query()->selectable()
            ->when($projectId !== null, static fn (Builder $query): Builder => $query->whereKey($projectId))
            ->when($projectId === null && $clientId !== null, static fn (Builder $query): Builder => $query->where('projects.client_id', $clientId))
            ->select('projects.id');

        $options = [];

        // The soft-delete scope of Task leaves archived tasks out.
        $tasks = Task::query()
            ->whereIn('tasks.project_id', $projects)
            ->orderBy(Project::query()->withTrashed()->select('projects.key')->whereColumn('projects.id', 'tasks.project_id'))
            ->orderBy('tasks.number')
            ->get(['tasks.id', 'tasks.reference', 'tasks.title']);

        foreach ($tasks as $task) {
            $options[$task->id] = $task->reference.' · '.$task->title;
        }

        return $options;
    }

    /**
     * The project of a task, archived or not, or null for an unknown task.
     */
    public function projectIdOfTask(string $taskId): ?string
    {
        $projectId = Task::query()->withTrashed()->whereKey($taskId)->value('project_id');

        return is_string($projectId) ? $projectId : null;
    }

    /**
     * The client of a project, archived or not, or null for an unknown project.
     */
    public function clientIdOfProject(string $projectId): ?string
    {
        $clientId = Project::query()->withTrashed()->whereKey($projectId)->value('client_id');

        return is_string($clientId) ? $clientId : null;
    }
}
