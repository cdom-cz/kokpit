<?php

declare(strict_types=1);

namespace App\Domain\Reports\Models;

use App\Domain\Reports\Enums\ReportExportFormat;
use App\Domain\Reports\Enums\ReportExportStatus;
use App\Domain\Shared\Auth\OwnedByUser;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use App\Domain\TimeTracking\Support\TimerClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One queued report export of an Admin (RE-03, D-09): the request, its status and the finished file.
 *
 * Owner-scoped (OwnedByUser): the Admin who made the export reads it, a second Admin reads nothing of it and a
 * Partner reads nothing at all. The file lives on the default (private) disk under a server-generated path and
 * is downloaded through the application with a permission check, never through a public URL.
 *
 * `summary` is the server-built "Zadání" text; `filters` holds validated ids and Prague days only. Neither
 * user text nor a client name reaches `path` or the download name.
 *
 * @property string $id
 * @property string $user_id
 * @property ReportExportFormat $format
 * @property ReportExportStatus $status
 * @property string $summary
 * @property array<string, mixed> $filters
 * @property string|null $path
 * @property int|null $row_count
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['format', 'summary', 'filters'])]
final class ReportExport extends KokpitModel implements PartnerIsolated
{
    use OwnedByUser;

    /** The retention in days when the configuration carries no usable value. */
    private const int DEFAULT_TTL_DAYS = 7;

    protected $table = 'report_exports';

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
            'format' => ReportExportFormat::class,
            'status' => ReportExportStatus::class,
            'filters' => 'array',
            'row_count' => 'integer',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * How many days a record is kept (D-09), at least one: a zero or negative setting must never make a
     * finished export expire before it can be downloaded.
     */
    public static function ttlDays(): int
    {
        return max(1, (int) config('kokpit.exports.ttl_days', self::DEFAULT_TTL_DAYS));
    }

    /**
     * Where the finished file lives on the default (private) disk: built from the export id and the format
     * only, the exact shape the `report_exports_path_check` constraint pins. No user text can reach it.
     */
    public function storagePath(): string
    {
        return 'report-exports/'.$this->getKey().'.'.$this->format->extension();
    }

    /**
     * The name the file is offered under: `report-casu-YYYY-MM-DD_YYYY-MM-DD.csv` (or `.xlsx`), built from the
     * stored period only (UI-SPEC C3). A client or project name never appears in it, and a period that is
     * not a pair of real days (a record written by other means) gives the plain `report-casu.<ext>`, so
     * stored filter text can never reach a download name.
     */
    public function downloadFileName(): string
    {
        $extension = $this->format->extension();
        $from = self::day($this->filters['from'] ?? null);
        $to = self::day($this->filters['to'] ?? null);

        if ($from === null || $to === null) {
            return 'report-casu.'.$extension;
        }

        return 'report-casu-'.$from.'_'.$to.'.'.$extension;
    }

    /**
     * Whether the file may be offered for download at this instant: finished, with a stored path and not yet
     * expired (the expiry instant itself is already expired).
     */
    public function isDownloadable(CarbonInterface $now): bool
    {
        return $this->status === ReportExportStatus::Ready
            && $this->path !== null
            && $this->expires_at->greaterThan($now);
    }

    /**
     * The export is finished: the file sits at `$path`, holds `$rowCount` entry rows, and is kept for the
     * retention from now on. One of the two lifecycle writes (with markFailed()); the database refuses a path
     * that is not the server-generated shape.
     */
    public function markReady(string $path, int $rowCount, CarbonInterface $now): void
    {
        $completedAt = TimerClock::truncate($now);

        $this->forceFill([
            'status' => ReportExportStatus::Ready,
            'path' => $path,
            'row_count' => $rowCount,
            'completed_at' => $completedAt,
            'expires_at' => $completedAt->addDays(self::ttlDays()),
        ])->save();
    }

    /**
     * The export could not be built. The expiry of its creation stays, so the prune removes the record on
     * schedule. One of the two lifecycle writes.
     */
    public function markFailed(): void
    {
        $this->forceFill(['status' => ReportExportStatus::Failed])->save();
    }

    /**
     * A stored day as `YYYY-MM-DD`, or null when the value is not a real calendar day in that exact form.
     */
    private static function day(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) !== 1) {
            return null;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]) ? $value : null;
    }
}
