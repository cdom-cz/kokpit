<?php

declare(strict_types=1);

namespace App\Domain\Documents\Models;

use App\Domain\Documents\Enums\DocumentArchiveStatus;
use App\Domain\Shared\Auth\OwnedByUser;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use App\Domain\TimeTracking\Support\TimerClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One queued ZIP archive of documents of an Admin (DO-02, D-08): the request, its status and the finished file.
 *
 * Owner-scoped (OwnedByUser): the Admin who asked reads the archive, a second Admin reads nothing of it and a
 * Partner reads nothing at all. The ZIP lives on the default (private) disk under a server-generated path and is
 * downloaded through the application with a permission check, never through a public URL. The twin of
 * ReportExport.
 *
 * `summary` is the server-built text the list shows; `document_ids` holds validated document ids only. Neither
 * user text nor a file or client name reaches `path` or the download name. The request and the build job come
 * with plan 09-17, the list, the route and the prune with plan 09-18.
 *
 * @property string $id
 * @property string $user_id
 * @property DocumentArchiveStatus $status
 * @property string $summary
 * @property list<string> $document_ids
 * @property int $document_count
 * @property int $total_bytes
 * @property string|null $path
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['summary', 'document_ids', 'document_count', 'total_bytes'])]
final class DocumentArchive extends KokpitModel implements PartnerIsolated
{
    use OwnedByUser;

    /** The retention in days when the configuration carries no usable value. */
    private const int DEFAULT_TTL_DAYS = 7;

    /** The day of the download name is the Prague day, like every day the user sees. */
    private const string DISPLAY_TIMEZONE = 'Europe/Prague';

    protected $table = 'document_archives';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    protected static function booted(): void
    {
        // A new record expires the retention after its creation; markReady() moves the expiry to completion
        // plus the retention, so the prune also removes a pending or failed record.
        self::creating(static function (Model $model): void {
            if ($model instanceof self && $model->getAttribute('expires_at') === null) {
                $model->setAttribute('expires_at', TimerClock::now()->addDays(self::ttlDays()));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DocumentArchiveStatus::class,
            'document_ids' => 'array',
            'document_count' => 'integer',
            'total_bytes' => 'integer',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * How many days a record is kept (D-08), at least one: a zero or negative setting must never make a
     * finished archive expire before it can be downloaded.
     */
    public static function ttlDays(): int
    {
        return max(1, (int) config('kokpit.documents.archive.ttl_days', self::DEFAULT_TTL_DAYS));
    }

    /**
     * Where the finished ZIP lives on the default (private) disk: built from the archive id only, the exact
     * shape the `document_archives_path_check` constraint pins. No user text can reach it.
     */
    public function storagePath(): string
    {
        return 'document-archives/'.$this->getKey().'.zip';
    }

    /**
     * The name the ZIP is offered under: `soubory-YYYY-MM-DD.zip` with the Prague day of the creation. A file,
     * client or project name never appears in it.
     */
    public function downloadFileName(): string
    {
        $created = $this->created_at ?? CarbonImmutable::now();

        return 'soubory-'.$created->setTimezone(self::DISPLAY_TIMEZONE)->format('Y-m-d').'.zip';
    }

    /**
     * Whether the ZIP may be offered for download at this instant: finished, with a stored path and not yet
     * expired (the expiry instant itself is already expired).
     */
    public function isDownloadable(CarbonInterface $now): bool
    {
        return $this->status === DocumentArchiveStatus::Ready
            && $this->path !== null
            && $this->expires_at->greaterThan($now);
    }

    /**
     * The archive is finished: the ZIP sits at `$path` and is kept for the retention from now on. One of the two
     * lifecycle writes (with markFailed()); the database refuses a path that is not the server-generated shape.
     */
    public function markReady(string $path, CarbonInterface $now): void
    {
        $completedAt = TimerClock::truncate($now);

        $this->forceFill([
            'status' => DocumentArchiveStatus::Ready,
            'path' => $path,
            'completed_at' => $completedAt,
            'expires_at' => $completedAt->addDays(self::ttlDays()),
        ])->save();
    }

    /**
     * The archive could not be built. The expiry of its creation stays, so the prune removes the record on
     * schedule. One of the two lifecycle writes.
     */
    public function markFailed(): void
    {
        $this->forceFill(['status' => DocumentArchiveStatus::Failed])->save();
    }
}
