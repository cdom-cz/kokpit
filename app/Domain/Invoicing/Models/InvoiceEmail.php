<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\InvoiceEmailState;
use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One queued, sent or failed e-mail of a billing document (D-22). The log keeps the recipient, subject and body that
 * were sent and the state of the job, so the Admin sees what left the system and when.
 *
 * Admin-only: a Partner reads no row, not even for an e-mail about an invoice of the own client (IN-09, R-08-1).
 * The relation writes nothing to its parent: there is no `$touches`, because a locked invoice (one a successful send
 * has locked, Phase 10.1) refuses even an `updated_at` write (R-04-6). Only the successful send of the e-mail moves the
 * document from open to locked, and it does so in its own statement, not through this relation.
 *
 * Nothing is fillable: the send Action and the job set every column with `forceFill`. The model keeps no activity
 * history, the row is itself the log.
 *
 * @property string $id
 * @property string $invoice_id
 * @property string $recipient
 * @property string $subject
 * @property string $body
 * @property bool $attach_work_report
 * @property InvoiceEmailState $state
 * @property int $attempts
 * @property CarbonImmutable|null $sent_at
 * @property string|null $failure_code
 * @property string $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class InvoiceEmail extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners;

    protected $table = 'invoice_emails';

    /**
     * The one definition of the stale cutoff (ruling R-37-1 of the System page, extended to queued rows by plan 10.1-15,
     * review WR-01): a queued or sending row whose last move (`updated_at`) is at or before this instant belongs to a
     * lost job or a dead worker and counts as failed. The edit rule, the job and the Odeslani tab all read it here.
     */
    public static function staleBefore(): CarbonImmutable
    {
        return CarbonImmutable::now()->subSeconds(max(1, (int) config('kokpit.health.unsent_invoice_email_stale_after')));
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The Admin who sent the e-mail.
     *
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attach_work_report' => 'boolean',
            'state' => InvoiceEmailState::class,
            'attempts' => 'integer',
            'sent_at' => 'immutable_datetime',
        ];
    }
}
