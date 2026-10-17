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

/**
 * Document e-mails that did not leave the system (IN-04, D-22, FND-10).
 *
 * Only the latest e-mail row of each document counts (R-24-1): a resend is a new row, so a failed row followed by a
 * sent row is a document that was delivered. Any latest row in `failed` turns the slot Error, and the Admin resends
 * from the invoice list, which the link opens filtered by the e-mail state.
 *
 * The indicator reads states and timestamps only. It never selects a recipient, a subject, a body or a failure
 * code, so none of them can reach the System page (T-10-89); the detail is fixed Czech copy with a count.
 */
final readonly class UnsentInvoiceEmailsIndicator implements HealthIndicator
{
    /** The latest row of each document: no later row of the same invoice exists (created_at, then id as the tie-break). */
    private const string LATEST_ROW_SQL = 'NOT EXISTS (SELECT 1 FROM invoice_emails AS later WHERE later.invoice_id = invoice_emails.invoice_id AND (later.created_at, later.id) > (invoice_emails.created_at, invoice_emails.id))';

    public function slot(): HealthSlot
    {
        return HealthSlot::UnsentInvoiceEmails;
    }

    public function check(): HealthResult
    {
        // The model is Admin-only and the registry may also run outside a request, so the read is explicit.
        $failed = app(PartnerContext::class)->runAsSystem(
            static fn (): int => InvoiceEmail::query()
                ->whereRaw(self::LATEST_ROW_SQL)
                ->where('state', InvoiceEmailState::Failed->value)
                ->count(),
        );

        if ($failed > 0) {
            return new HealthResult(
                HealthStatus::Error,
                (string) $failed,
                __('invoices.system.error', ['count' => $failed]),
                self::listUrl(InvoiceEmailState::Failed),
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
