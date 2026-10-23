<?php

declare(strict_types=1);

namespace App\Filament\Resources\InvoiceResource\RelationManagers;

use App\Domain\Invoicing\Enums\InvoiceEmailState;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use App\Filament\Support\SendInvoiceEmailAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * The "Odeslání" tab of an issued document (IN-04; D-22, ruling R-24-1; UI-SPEC Surface G): one row per send, a resend
 * included. Created, recipient, the attachments ("PDF" or "PDF a výkaz"), the state as a badge and a note that is one
 * fixed Czech sentence for a failed send. Admin only: the log holds recipients and bodies of e-mails and a Partner
 * reads none of it.
 *
 * The note is never the stored failure class and never an exception or provider text (T-10-65): the query does not
 * even select it. A row still `queued` or `sending` after the stale threshold of the System page belongs to a lost job
 * or a worker that died and reads as failed (ruling R-37-1, queued rows by plan 10.1-15), so the Admin can send again;
 * the job never sends such a row. "Odeslat znovu" is offered on failed rows only and opens
 * the shared dialog pre-filled with the values of that row; the send is a new row.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The send log holds recipients and bodies of e-mails with documents: a Partner never sees it, not even for the own invoice (IN-09).')]
final class InvoiceEmailsRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule {
        canViewForRecord as private canViewForAdmin;
    }

    protected static string $relationship = 'emails';

    /**
     * A draft has no log, so the tab is offered for an issued document only.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::canViewForAdmin($ownerRecord, $pageClass)
            && $ownerRecord instanceof Invoice
            && $ownerRecord->isIssued();
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('invoices.email.log.title');
    }

    /**
     * A send queued from the page or from this tab changes what the tab lists; the listener only makes it render again.
     */
    #[On('invoice-email-state-changed')]
    public function refreshEmails(): void {}

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query->select([
                'invoice_emails.id',
                'invoice_emails.invoice_id',
                'invoice_emails.recipient',
                'invoice_emails.attach_work_report',
                'invoice_emails.state',
                'invoice_emails.created_at',
                'invoice_emails.updated_at',
            ]))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('invoices.email.log.columns.created'))
                    ->dateTime('j. n. Y H:i'),
                TextColumn::make('recipient')
                    ->label(__('invoices.email.log.columns.recipient')),
                TextColumn::make('attachments')
                    ->label(__('invoices.email.log.columns.attachments'))
                    ->state(static fn (InvoiceEmail $record): string => $record->attach_work_report
                        ? __('invoices.email.log.attachments_pdf_report')
                        : __('invoices.email.log.attachments_pdf')),
                TextColumn::make('state')
                    ->label(__('invoices.email.log.columns.state'))
                    ->state(fn (InvoiceEmail $record): InvoiceEmailState => $this->effectiveState($record))
                    ->badge()
                    ->formatStateUsing(static fn (InvoiceEmailState $state): string => $state->getLabel())
                    ->color(static fn (InvoiceEmailState $state): string => match ($state) {
                        InvoiceEmailState::Sent => 'success',
                        InvoiceEmailState::Failed => 'danger',
                        InvoiceEmailState::Queued, InvoiceEmailState::Sending => 'info',
                    }),
                TextColumn::make('note')
                    ->label(__('invoices.email.log.columns.note'))
                    ->state(fn (InvoiceEmail $record): string => $this->effectiveState($record) === InvoiceEmailState::Failed
                        ? __('invoices.email.log.failure_note')
                        : __('invoices.navigation.empty_value'))
                    ->wrap(),
            ])
            ->defaultSort(static fn (Builder $query): Builder => $query->orderByDesc('invoice_emails.created_at')->orderByDesc('invoice_emails.id'))
            ->paginated(false)
            ->recordActions([
                SendInvoiceEmailAction::forLogRow(fn (): Invoice => $this->invoice())
                    ->visible(fn (InvoiceEmail $record): bool => $this->effectiveState($record) === InvoiceEmailState::Failed),
            ])
            ->emptyStateIcon(Heroicon::OutlinedEnvelope)
            ->emptyStateHeading(__('invoices.email.log.empty'));
    }

    /**
     * The state of a row as the Admin should read it: a send that is still queued or sending past the stale threshold
     * belongs to a lost job or a dead worker and counts as failed (ruling R-37-1, extended to queued rows by plan
     * 10.1-15; the job never sends such a row).
     */
    private function effectiveState(InvoiceEmail $email): InvoiceEmailState
    {
        if ($email->state !== InvoiceEmailState::Queued && $email->state !== InvoiceEmailState::Sending) {
            return $email->state;
        }

        return $email->updated_at !== null && $email->updated_at->lessThanOrEqualTo(InvoiceEmail::staleBefore())
            ? InvoiceEmailState::Failed
            : $email->state;
    }

    private function invoice(): Invoice
    {
        $owner = $this->getOwnerRecord();
        assert($owner instanceof Invoice);

        return $owner;
    }
}
