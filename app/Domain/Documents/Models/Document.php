<?php

declare(strict_types=1);

namespace App\Domain\Documents\Models;

use App\Domain\Audit\LoggedAttributes;
use App\Domain\Audit\LogsAllowlistedActivity;
use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\IsolatesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use App\Domain\Tasks\Models\Task;
use App\Domain\Tasks\Models\TaskComment;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * One file (DO-01, D-01): the identity of an uploaded file, owning exactly one medialibrary media row
 * on the private disk. Queries, the Partner scope and every listing go over this table; the media row is a
 * storage detail.
 *
 * A Partner reads a document exactly when it is flagged visible, belongs to the own client and its owner is
 * a project, a task or a task comment that the Partner-scoped query of that model returns (D-04). Those
 * queries leave out archived projects, archived tasks, projects of archived clients and internal comments
 * themselves, so a file of an archived owner never reaches a Partner while the owner stays archived (D-21).
 * A client-owned, transaction-owned or unattached file has no branch below and is never returned.
 *
 * Only the display name and the description are fillable: the owner, the client, the project, the uploader,
 * the flag and the file facts are set with `forceFill` by AttachDocuments. The owner is immutable after
 * upload. `Media` stays closed to Partners, so every media write and read runs as system after the policy.
 *
 * Changes to the name and the flag, and every deletion, are written to the activity log with the allowlist
 * above; the description is never logged, because it is text the client may or may not be meant to see.
 *
 * @property string $id
 * @property string $name
 * @property string|null $description
 * @property bool $visible_to_client
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string|null $client_id
 * @property string|null $project_id
 * @property string $uploaded_by_id
 * @property string|null $extension
 * @property string $mime_type
 * @property int $size_bytes
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['name', 'description'])]
#[LoggedAttributes(['name', 'visible_to_client', 'owner_type', 'size_bytes'])]
final class Document extends KokpitModel implements HasMedia, PartnerIsolated
{
    use InteractsWithMedia, IsolatesPartners, LogsAllowlistedActivity;

    public const string MEDIA_COLLECTION = 'file';

    /**
     * The owner kinds whose files can belong to an archived owner (D-21): clients, projects, tasks and comments.
     * Not `transaction` (never archived) and not an unattached file.
     */
    private const array ARCHIVABLE_OWNER_TYPES = ['client', 'project', 'task', 'task_comment'];

    /**
     * Flagged visible, of the own client, and hanging on an owner the Partner can see. The client check is
     * belt and braces and never the only rule; the owner pair sits inside the already-scoped id subqueries,
     * never with `withTrashed()`.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function constrainForPartner(Builder $query, string $clientId): void
    {
        $query->where($this->qualifyColumn('visible_to_client'), true)
            ->where($this->qualifyColumn('client_id'), $clientId)
            ->where(function (Builder $owners): void {
                $owners->where(fn (Builder $project) => $project
                    ->where($this->qualifyColumn('owner_type'), 'project')
                    ->whereIn($this->qualifyColumn('owner_id'), Project::query()->select('projects.id')))
                    ->orWhere(fn (Builder $task) => $task
                        ->where($this->qualifyColumn('owner_type'), 'task')
                        ->whereIn($this->qualifyColumn('owner_id'), Task::query()->select('tasks.id')))
                    // The TaskComment scope already drops internal comments and invisible tasks.
                    ->orWhere(fn (Builder $comment) => $comment
                        ->where($this->qualifyColumn('owner_type'), 'task_comment')
                        ->whereIn($this->qualifyColumn('owner_id'), TaskComment::query()->select('task_comments.id')));
            });
    }

    /**
     * The files of owners that are not archived: unattached files, files of owner kinds that are never archived
     * (transaction receipts) and files whose client, project or task is active (D-21). The default Admin listing.
     *
     * @param  Builder<Document>  $query
     */
    public function scopeWithoutArchivedOwners(Builder $query): void
    {
        $query->where(function (Builder $files): void {
            $files->whereNull($this->qualifyColumn('owner_type'))
                ->orWhereNotIn($this->qualifyColumn('owner_type'), self::ARCHIVABLE_OWNER_TYPES)
                ->orWhereNot(fn (Builder $archived) => $this->constrainToArchivedOwner($archived));
        });
    }

    /**
     * Exactly the files of archived owners: the complement of {@see self::scopeWithoutArchivedOwners()}. Shown
     * under the "archived owners" filter of the central listing (D-21).
     *
     * @param  Builder<Document>  $query
     */
    public function scopeOnlyArchivedOwners(Builder $query): void
    {
        $query->where(fn (Builder $files) => $this->constrainToArchivedOwner($files));
    }

    /**
     * A file is a file of an archived owner (D-21) when its owner is a client, project, task or task comment and
     * its client or project is archived, or it is a task file of an archived task, or a comment file of a comment
     * on an archived task. Unattached files and transaction receipts never are: transactions are never archived
     * and a receipt is a finance record that must stay findable.
     *
     * The checks are plain `EXISTS` subqueries on `deleted_at`, in the style of `Project::constrainForPartner()`:
     * they do not go through the `Client` model (closed to Partners) or the SoftDeletes scopes, which would hide
     * exactly the archived rows looked for. Archiving is a soft delete that touches no document, media row or
     * stored object, so this is a pure read filter.
     *
     * @param  Builder<Document>  $query
     */
    private function constrainToArchivedOwner(Builder $query): void
    {
        $query->whereIn($this->qualifyColumn('owner_type'), self::ARCHIVABLE_OWNER_TYPES)
            ->where(function (Builder $archived): void {
                $archived
                    ->whereExists(fn ($sub) => $sub->selectRaw('1')
                        ->from('clients')
                        ->whereColumn('clients.id', $this->qualifyColumn('client_id'))
                        ->whereNotNull('clients.deleted_at'))
                    ->orWhereExists(fn ($sub) => $sub->selectRaw('1')
                        ->from('projects')
                        ->whereColumn('projects.id', $this->qualifyColumn('project_id'))
                        ->whereNotNull('projects.deleted_at'))
                    ->orWhere(fn (Builder $task) => $task
                        ->where($this->qualifyColumn('owner_type'), 'task')
                        ->whereExists(fn ($sub) => $sub->selectRaw('1')
                            ->from('tasks')
                            ->whereColumn('tasks.id', $this->qualifyColumn('owner_id'))
                            ->whereNotNull('tasks.deleted_at')))
                    ->orWhere(fn (Builder $comment) => $comment
                        ->where($this->qualifyColumn('owner_type'), 'task_comment')
                        ->whereExists(fn ($sub) => $sub->selectRaw('1')
                            ->from('task_comments')
                            ->join('tasks', 'tasks.id', '=', 'task_comments.task_id')
                            ->whereColumn('task_comments.id', $this->qualifyColumn('owner_id'))
                            ->whereNotNull('tasks.deleted_at')));
            });
    }

    /**
     * The one object of the document, on the private disk, no conversions.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::MEDIA_COLLECTION)
            ->singleFile()
            ->useDisk((string) config('media-library.disk_name'));
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo('owner');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visible_to_client' => 'boolean',
            'size_bytes' => 'integer',
        ];
    }
}
