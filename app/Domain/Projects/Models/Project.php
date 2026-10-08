<?php

declare(strict_types=1);

namespace App\Domain\Projects\Models;

use App\Domain\Audit\LoggedAttributes;
use App\Domain\Audit\LogsAllowlistedActivity;
use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Enums\ProjectPriority;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Shared\Auth\IsolatesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use App\Domain\Tasks\Models\Task;
use ArrayAccess;
use Carbon\CarbonInterface;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Tags\HasTags;

/**
 * A project of a client. The only Partner-readable model of Phase 4: a Partner
 * sees a project only when it belongs to the own client, is flagged
 * client-visible, is not archived and its client is not archived (D-07, D-11).
 * Every other state, including a missing flag, is invisible.
 *
 * The table holds Partner-safe columns only. Rates, prices, estimates, billing
 * type and internal notes live in the Admin-only project_billing table (D-05),
 * reached through `billing()`; that relation is null for a Partner because
 * ProjectBilling is closed to Partners.
 *
 * `client_id` is not fillable (a project must not be created under or moved to
 * another client by mass assignment): creation code sets it through
 * `$client->projects()` or `forceFill`. `$project->client` is null for a Partner
 * because Client is closed to Partners (D-06).
 *
 * Changes to the identity, status and dates are written to the activity log
 * through an allowlist (D-06). The description is free text and stays out.
 *
 * @property string $id
 * @property string $client_id
 * @property string $name
 * @property string $key
 * @property string|null $description
 * @property ProjectStatus $status
 * @property ProjectPriority $priority
 * @property CarbonInterface|null $start_date
 * @property CarbonInterface|null $end_date
 * @property bool $client_visible
 * @property CarbonInterface|null $deleted_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'name',
    'key',
    'description',
    'status',
    'priority',
    'start_date',
    'end_date',
    'client_visible',
])]
#[LoggedAttributes([
    'client_id',
    'name',
    'key',
    'status',
    'priority',
    'start_date',
    'end_date',
    'client_visible',
])]
final class Project extends KokpitModel implements PartnerIsolated
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, IsolatesPartners, LogsAllowlistedActivity, SoftDeletes;

    use HasTags {
        detachTags as private detachTagsFromTrait;
    }

    /**
     * Own client AND client-visible AND the client is not archived. The client
     * row is checked with a plain EXISTS subquery (not through the Client model,
     * which is closed to Partners), so an archived client hides its projects
     * even for a session that is still open.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function constrainForPartner(Builder $query, string $clientId): void
    {
        $table = $this->getTable();

        $query
            ->where("{$table}.client_id", $clientId)
            ->where("{$table}.client_visible", true)
            ->whereExists(static fn ($sub) => $sub->selectRaw('1')
                ->from('clients')
                ->whereColumn('clients.id', "{$table}.client_id")
                ->whereNull('clients.deleted_at'));
    }

    /**
     * Projects a picker may offer: not archived and belonging to a client that is
     * not archived (D-11). Every project picker from Phase 5 on (tasks, time
     * entries, invoices) uses this scope instead of repeating the rule.
     *
     * The client row is checked with a plain EXISTS subquery, not through the
     * Client model, which is closed to Partners. The Partner scope still applies
     * on top of this one.
     *
     * @param  Builder<Project>  $query
     */
    public function scopeSelectable(Builder $query): void
    {
        $table = $this->getTable();

        $query
            ->whereNull("{$table}.deleted_at")
            ->whereExists(static fn ($sub) => $sub->selectRaw('1')
                ->from('clients')
                ->whereColumn('clients.id', "{$table}.client_id")
                ->whereNull('clients.deleted_at'));
    }

    /**
     * Keeps the tags of an archived project. The tags package calls this from
     * its `deleted` listener, which also fires for a soft delete, so an archive
     * would otherwise detach every tag and a restore would not bring them back.
     * Only a force delete detaches them.
     *
     * @param  array<mixed>|ArrayAccess<int|string, mixed>  $tags
     */
    public function detachTags(array|ArrayAccess $tags, ?string $type = null): static
    {
        if ($this->trashed() && ! $this->isForceDeleting()) {
            return $this;
        }

        return $this->detachTagsFromTrait($tags, $type);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * The Admin-only billing terms; every project has exactly one row.
     *
     * @return HasOne<ProjectBilling, $this>
     */
    public function billing(): HasOne
    {
        return $this->hasOne(ProjectBilling::class);
    }

    /**
     * The tasks of the project. Archived ones are reached through `withTrashed()`;
     * they still keep the project key frozen.
     *
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'priority' => ProjectPriority::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'client_visible' => 'boolean',
        ];
    }

    protected static function newFactory(): ProjectFactory
    {
        return ProjectFactory::new();
    }
}
