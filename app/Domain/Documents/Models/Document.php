<?php

declare(strict_types=1);

namespace App\Domain\Documents\Models;

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
final class Document extends KokpitModel implements HasMedia, PartnerIsolated
{
    use InteractsWithMedia, IsolatesPartners;

    public const string MEDIA_COLLECTION = 'file';

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
