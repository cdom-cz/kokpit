<?php

declare(strict_types=1);

namespace App\Domain\Operations\Health\Indicators;

use App\Domain\Invoicing\Enums\InvoiceEmailState;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Operations\Health\HealthIndicator;
use App\Domain\Operations\Health\HealthResult;
use App\Domain\Operations\Health\HealthSlot;
use App\Domain\Operations\Health\HealthStatus;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\InvoiceResource;
use Carbon\CarbonImmutable;

/**
 * Document e-mails that did not leave the system (IN-04, D-22, FND-10).
 *
 * Only the latest e-mail row of each document counts (R-24-1): a resend is a new row, so a failed row followed by a
 * sent row is a document that was delivered. Any latest row in `failed` turns the slot Error, and the Admin resends
 * from the invoice list, which the link opens filtered by the e-mail state. A row in `sending` for longer than
 * kokpit.health.unsent_invoice_email_stale_after belongs to a worker that died, so it counts as failed (R-37-1).
 * A row that waits, queued or sending, for longer than kokpit.health.unsent_invoice_email_warning_after turns the slot
 * Warning; a failure outranks a wait, and the value is the count of the grade shown.
 *
 * A latest row in `skipped` (the client's switch "send documents by e-mail" kept it back, Phase 16, D-08) is neither
 * failed nor waiting, so it matches neither count and the slot stays Ok. The documents of clients with the switch off
 * are left out altogether: a failed or waiting send from before the switch was turned off could never be cleared by
 * a resend, because the resend is not offered to such a client (owner answer A3). An archived client with the switch
 * on still counts.
 *
 * The indicator reads states and timestamps only. It never selects a recipient, a subject, a body or a failure
 * code, so none of them can reach the System page (T-10-89); the detail is fixed Czech copy with a count.
 */
final readonly class UnsentInvoiceEmailsIndicator implements HealthIndicator
{
    /** The latest row of each document: no later row of the same invoice exists (created_at, then id as the tie-break). */
    private const string LATEST_ROW_SQL = 'NOT EXISTS (SELECT 1 FROM invoice_emails AS later WHERE later.invoice_id = invoice_emails.invoice_id AND (later.created_at, later.id) > (invoice_emails.created_at, invoice_emails.id))';

    /** Leaves out the documents of clients whose document e-mails are switched off (Phase 16, owner answer A3). */
    private const string HAND_SERVED_SQL = 'NOT EXISTS (SELECT 1 FROM invoices AS doc JOIN clients AS doc_client ON doc_client.id = doc.client_id WHERE doc.id = invoice_emails.invoice_id AND doc_client.document_emails_enabled = false)';

    public function slot(): HealthSlot
    {
        return HealthSlot::UnsentInvoiceEmails;
    }

    public function check(): HealthResult
    {
        $now = CarbonImmutable::now();
        $warningAfter = max(1, (int) config('kokpit.health.unsent_invoice_email_warning_after'));
        // The stale threshold never sits below the warning one, so a row cannot count as failed before it is a wait.
        $staleAfter = max($warningAfter, (int) config('kokpit.health.unsent_invoice_email_stale_after'));
        $warnBefore = $now->subSeconds($warningAfter);
        $staleBefore = $now->subSeconds($staleAfter);

        // The model is Admin-only and the registry may also run outside a request, so the read is explicit.
        // The age of a row runs from its last move: a queued row is not touched until a worker claims it, and the claim
        // stamps a sending row, so a sending row older than the threshold belongs to a worker that died.
        $counts = app(PartnerContext::class)->runAsSystem(
            static fn (): object => (object) InvoiceEmail::query()
                ->whereRaw(self::LATEST_ROW_SQL)
                ->whereRaw(self::HAND_SERVED_SQL)
                ->selectRaw(
                    'COUNT(*) FILTER (WHERE state = ? OR (state = ? AND updated_at <= ?)) AS failed, '
                    .'COUNT(*) FILTER (WHERE state IN (?, ?) AND updated_at <= ? AND NOT (state = ? AND updated_at <= ?)) AS waiting',
                    [
                        InvoiceEmailState::Failed->value, InvoiceEmailState::Sending->value, $staleBefore,
                        InvoiceEmailState::Queued->value, InvoiceEmailState::Sending->value, $warnBefore,
                        InvoiceEmailState::Sending->value, $staleBefore,
                    ],
                )
                ->toBase()
                ->first(),
        );

        $failed = (int) $counts->failed;
        $waiting = (int) $counts->waiting;

        if ($failed > 0) {
            return new HealthResult(
                HealthStatus::Error,
                (string) $failed,
                __('invoices.system.error', ['count' => $failed]),
                self::listUrl(InvoiceEmailState::Failed),
                __('invoices.system.link'),
            );
        }

        if ($waiting > 0) {
            return new HealthResult(
                HealthStatus::Warning,
                (string) $waiting,
                __('invoices.system.warning', ['count' => $waiting]),
                self::listUrl(InvoiceEmailState::Queued),
                __('invoices.system.link'),
            );
        }

        return new HealthResult(HealthStatus::Ok, '0', __('invoices.system.ok'));
    }

    /**
     * The invoice list narrowed by the e-mail filter: `failed` for the failed rows, `queued` for those still waiting.
     */
    private static function listUrl(InvoiceEmailState $state): string
    {
        $value = $state === InvoiceEmailState::Failed ? 'failed' : 'queued';

        return InvoiceResource::getUrl('index', ['tableFilters' => ['email' => ['value' => $value]]]);
    }
}
