<?php

declare(strict_types=1);

namespace App\Filament\Resources\InvoiceResource\Pages;

use App\Domain\ExchangeRates\RateFormat;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\ConvertProforma;
use App\Domain\Invoicing\Actions\RegeneratePdf;
use App\Domain\Invoicing\Actions\UpdateInternalNote;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Queries\ReceivablesReader;
use App\Domain\Invoicing\Support\InvoicePdfUrl;
use App\Domain\Invoicing\Support\InvoiceQuantity;
use App\Domain\Shared\Money\Money;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Support\InvoiceColumns;
use App\Filament\Support\InvoiceTable;
use App\Filament\Support\RecordPaymentAction;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Throwable;

/**
 * The issued document's detail (IN-03, IN-04, IN-05, IN-06, IN-07; D-12, D-13, D-16, D-18, D-21, D-23; UI-SPEC Surface
 * E): the banners that say whether the document is paid and what is next, the frozen sections (the document, both
 * parties as the snapshots froze them, the lines, the summary with the CNB snapshot, the related documents and the
 * notes), and the two writes that remain after the issue.
 *
 * Nothing frozen is editable here. The internal note goes through {@see UpdateInternalNote} and the missing PDF
 * through {@see RegeneratePdf}, both of which write only the Admin-only `invoice_admin` row (plan 10-04 R-04-6). The
 * page is Admin only and the check runs on every Livewire request: a Partner would otherwise be served an own issued
 * invoice through the Partner scope of the model. The state of the document (paid, late, converted) is derived once
 * per request by {@see ReceivablesReader}, so a banner and a badge cannot disagree.
 *
 * The header actions of the later plans (payment, conversion, storno, e-mail) are added to `getHeaderActions()`; a
 * hidden action is not rendered.
 */
final class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    /** @var array{status: InvoiceStatus, remaining: Money|null, overdue_days: int|null, paid: int}|null */
    private ?array $derived = null;

    /**
     * The Admin check of every request, before any other code of the page runs. A Partner is refused with 403 before
     * the record is looked up.
     */
    public function boot(): void
    {
        abort_unless(InvoiceResource::canAccess(), 403);
    }

    /**
     * A draft has no detail: its address goes to the editor.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        $invoice = $this->invoice();
        $invoice->loadMissing(['items', 'proforma', 'invoiceFromProforma', 'creditNotes', 'creditedInvoice']);

        if ($invoice->isDraft()) {
            $this->redirect(InvoiceResource::getUrl('edit', ['record' => $invoice]));
        }
    }

    public function getTitle(): string
    {
        $invoice = $this->invoice();

        if ($invoice->number !== null) {
            return $invoice->number;
        }

        return match ($invoice->kind) {
            InvoiceKind::Proforma => __('invoices.navigation.draft_proforma'),
            InvoiceKind::CreditNote => __('invoices.navigation.draft_credit_note'),
            InvoiceKind::Invoice => __('invoices.navigation.draft_invoice'),
        };
    }

    /**
     * The number with the kind and the state badges.
     */
    public function getHeading(): Htmlable
    {
        $invoice = $this->invoice();
        $status = $this->derived()['status'];

        $badges = Blade::render(
            '<x-filament::badge color="gray" style="vertical-align: middle;">{{ $kind }}</x-filament::badge> <x-filament::badge :color="$color" style="vertical-align: middle;">{{ $state }}</x-filament::badge>',
            ['kind' => $invoice->kind->getLabel(), 'color' => $status->getColor(), 'state' => $status->getLabel()],
        );

        return new HtmlString(e($this->getTitle()).' '.$badges);
    }

    /**
     * The client name as a link, under the heading.
     */
    public function getSubheading(): Htmlable
    {
        $invoice = $this->invoice();
        $client = $invoice->client;

        return new HtmlString('<a href="'.e(ClientResource::getUrl('view', ['record' => $invoice->client_id])).'" style="text-decoration: underline;">'.e((string) $client?->name).'</a>');
    }

    /**
     * "Stáhnout PDF" when the stored PDF exists, "Zaznamenat platbu" on a document that still owes something and
     * "Vystavit fakturu" on a paid proforma without an invoice (UI-SPEC E2); the actions of the later plans follow.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->downloadPdfAction(),
            $this->recordPaymentAction(),
            $this->convertProformaAction(),
        ];
    }

    /**
     * A payment saved or deleted in the Platby tab moves the badge, the remaining amount and the banners of this
     * page; the listener only makes the page render again from the database.
     */
    #[On('invoice-payment-saved')]
    public function refreshAfterPaymentChange(): void
    {
        $this->reloadAdminHalf();
    }

    /**
     * "Zaznamenat platbu", primary while something is still owed (an unpaid, partly paid or overdue invoice or
     * proforma). A paid document records an extra payment from the tab (D-17); a credit note, a cancelled invoice and
     * a paid proforma do not offer it here.
     */
    private function recordPaymentAction(): Action
    {
        return RecordPaymentAction::make(fn (): Invoice => $this->invoice(), fn () => $this->reloadAdminHalf(), 'primary')
            ->visible(fn (): bool => RecordPaymentAction::available($this->invoice())
                && in_array($this->derived()['status'], [InvoiceStatus::Unpaid, InvoiceStatus::PartiallyPaid, InvoiceStatus::Overdue], true));
    }

    /**
     * "Vystavit fakturu" on a paid proforma that has no invoice (IN-06; D-15, D-18; UI-SPEC E5): one confirmation, then
     * {@see ConvertProforma} and the success path of the issue (toast, the new invoice, the e-mail dialog flag of
     * ruling R-29-1). A failure leaves the proforma as it was.
     */
    private function convertProformaAction(): Action
    {
        return Action::make('convertProforma')
            ->label(__('invoices.detail.actions.issue_invoice'))
            ->icon(Heroicon::OutlinedDocumentCheck)
            ->color('primary')
            ->visible(fn (): bool => $this->invoice()->isIssued() && $this->derived()['status'] === InvoiceStatus::AwaitingInvoice)
            ->requiresConfirmation()
            ->modalHeading(fn (): string => __('invoices.conversion.heading', ['number' => (string) $this->invoice()->number]))
            ->modalDescription(__('invoices.conversion.body'))
            ->modalSubmitActionLabel(__('invoices.conversion.submit'))
            ->modalCancelActionLabel(__('invoices.conversion.cancel'))
            ->action(function (): void {
                abort_unless(InvoiceResource::canAccess(), 403);

                $actor = auth()->user();
                assert($actor instanceof User);

                try {
                    $invoice = app(ConvertProforma::class)->handle($actor, $this->invoice());
                } catch (AuthorizationException $exception) {
                    throw $exception;
                } catch (ValidationException $exception) {
                    // The proforma changed in the meantime (converted elsewhere, a payment deleted): say why and show
                    // the state as it is now.
                    $this->invoice()->refresh();
                    $this->reloadAdminHalf();

                    Notification::make()
                        ->danger()
                        ->title(__('invoices.issue.failed_title'))
                        ->body(implode(' ', array_merge(...array_values($exception->errors()))))
                        ->persistent()
                        ->send();

                    return;
                } catch (Throwable $exception) {
                    report($exception);

                    Notification::make()
                        ->danger()
                        ->title(__('invoices.issue.failed_title'))
                        ->body(__('invoices.issue.failed_body'))
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()->success()->title(__('invoices.issue.toast.invoice'))->send();

                $this->redirect(InvoiceResource::getUrl('view', ['record' => $invoice, 'send' => 1]));
            });
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            ...$this->banners(),
            $this->documentSection(),
            $this->partiesSection(),
            $this->itemsSection(),
            $this->summarySection(),
            $this->relatedSection(),
            $this->notesSection(),
        ]);
    }

    /**
     * The signed link of the stored PDF, built at click time and never rendered into the page (D-21, ruling R-38-1).
     */
    private function downloadPdfAction(): Action
    {
        return Action::make('downloadPdf')
            ->label(__('invoices.detail.actions.download'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->visible(fn (): bool => $this->invoice()->isIssued() && $this->pdfPresent())
            ->action(function (): void {
                abort_unless(InvoiceResource::canAccess(), 403);

                $user = auth()->user();

                abort_unless($user instanceof User && $this->pdfPresent(), 404);

                $this->redirect(InvoicePdfUrl::download($this->invoice(), $user));
            });
    }

    /**
     * The callouts that apply, in the order of UI-SPEC E1; the missing PDF closes the list.
     *
     * @return list<Callout>
     */
    private function banners(): array
    {
        return [
            Callout::make(fn (): string => trans_choice('invoices.detail.banners.overdue', $this->derived()['overdue_days'] ?? 0, [
                'count' => $this->derived()['overdue_days'] ?? 0,
                'amount' => ($this->derived()['remaining'] ?? Money::zero($this->invoice()->currency))->format('cs'),
            ]))
                ->danger()
                ->visible(fn (): bool => $this->derived()['status'] === InvoiceStatus::Overdue),
            Callout::make(__('invoices.detail.banners.awaiting_invoice'))
                ->danger()
                ->visible(fn (): bool => $this->derived()['status'] === InvoiceStatus::AwaitingInvoice),
            Callout::make(__('invoices.detail.banners.cancelled_paid'))
                ->warning()
                ->visible(fn (): bool => $this->invoice()->isCancelled() && $this->derived()['paid'] > 0),
            Callout::make(fn (): Htmlable => $this->creditExistsText())
                ->info()
                ->visible(fn (): bool => $this->issuedCreditNotes() !== []),
            Callout::make(__('invoices.detail.pdf_missing'))
                ->key('pdf_missing')
                ->warning()
                ->visible(fn (): bool => $this->invoice()->isIssued() && ! $this->pdfPresent())
                ->actions([$this->regeneratePdfAction()]),
        ];
    }

    /**
     * "K dokladu existuje dobropis :number." with the number as a link to each credit note.
     */
    private function creditExistsText(): Htmlable
    {
        $links = array_map(
            static fn (Invoice $note): string => '<a href="'.e(InvoiceResource::getUrl('view', ['record' => $note])).'" style="text-decoration: underline;">'.e((string) $note->number).'</a>',
            $this->issuedCreditNotes(),
        );

        return new HtmlString(str_replace(':number', implode(', ', $links), e(__('invoices.detail.banners.credit_exists', ['number' => ':number']))));
    }

    /**
     * "Vytvořit PDF znovu": the PDF is created again from the snapshots. A failure keeps the state and shows the fixed
     * copy; the cause goes to the log and never to the screen (D-23).
     */
    private function regeneratePdfAction(): Action
    {
        return Action::make('regeneratePdf')
            ->label(__('invoices.detail.pdf_regenerate'))
            ->color('gray')
            ->action(function (): void {
                abort_unless(InvoiceResource::canAccess(), 403);

                $actor = auth()->user();
                assert($actor instanceof User);

                try {
                    app(RegeneratePdf::class)->handle($actor, $this->invoice());
                } catch (AuthorizationException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    report($exception);
                    $this->reloadAdminHalf();

                    // A document that got its PDF in the meantime needs no message: the callout is gone.
                    if (! $this->pdfPresent()) {
                        Notification::make()
                            ->danger()
                            ->title(__('invoices.detail.pdf_failed_title'))
                            ->body(__('invoices.detail.pdf_failed_body'))
                            ->persistent()
                            ->send();
                    }

                    return;
                }

                $this->reloadAdminHalf();

                Notification::make()->success()->title(__('invoices.detail.pdf_created'))->send();
            });
    }

    private function documentSection(): Section
    {
        $empty = __('invoices.navigation.empty_value');

        return Section::make(__('invoices.detail.sections.document'))
            ->columns(['default' => 1, 'md' => 2])
            ->schema([
                TextEntry::make('number')
                    ->label(__('invoices.detail.fields.number'))
                    ->weight(FontWeight::SemiBold)
                    ->placeholder($empty),
                TextEntry::make('kind')
                    ->label(__('invoices.detail.fields.kind'))
                    ->badge()
                    ->color('gray'),
                TextEntry::make('issue_date')
                    ->label(__('invoices.detail.fields.issue_date'))
                    ->date('j. n. Y')
                    ->placeholder($empty),
                TextEntry::make('taxable_supply_date')
                    ->label(__('invoices.detail.fields.taxable_supply_date'))
                    ->date('j. n. Y')
                    ->visible(static fn (Invoice $record): bool => $record->taxable_supply_date !== null),
                TextEntry::make('due_date')
                    ->label(__('invoices.detail.fields.due_date'))
                    ->date('j. n. Y')
                    ->placeholder($empty),
                TextEntry::make('variable_symbol')
                    ->label(__('invoices.detail.fields.variable_symbol'))
                    ->placeholder($empty),
                TextEntry::make('language')
                    ->label(__('invoices.detail.fields.language')),
                TextEntry::make('currency')
                    ->label(__('invoices.detail.fields.currency')),
                TextEntry::make('bank_account')
                    ->label(__('invoices.detail.fields.bank_account'))
                    ->state(static fn (Invoice $record): array => InvoiceColumns::bankLines($record))
                    ->listWithLineBreaks()
                    ->placeholder($empty)
                    ->visible(static fn (Invoice $record): bool => $record->kind !== InvoiceKind::CreditNote),
                TextEntry::make('source_proforma')
                    ->label(__('invoices.detail.fields.source_proforma'))
                    ->state(static fn (Invoice $record): ?string => $record->proforma?->number)
                    ->url(static fn (Invoice $record): ?string => $record->proforma === null ? null : InvoiceResource::getUrl('view', ['record' => $record->proforma]))
                    ->visible(static fn (Invoice $record): bool => $record->proforma_id !== null),
                TextEntry::make('payment_method')
                    ->label(__('invoices.detail.fields.payment_method'))
                    ->state(static fn (): string => __('invoices.draft.payment_method_value'))
                    ->visible(static fn (Invoice $record): bool => $record->kind !== InvoiceKind::CreditNote),
            ]);
    }

    /**
     * Both parties as the snapshots froze them. Neither the client nor the supplier settings are read here, so a later
     * change never shows on an issued document.
     */
    private function partiesSection(): Section
    {
        return Section::make(__('invoices.detail.sections.parties'))
            ->schema([
                Icon::make(Heroicon::OutlinedLockClosed)
                    ->color('gray')
                    ->tooltip(__('invoices.detail.parties.frozen')),
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    TextEntry::make('supplier')
                        ->label(__('invoices.detail.parties.supplier'))
                        ->state(static fn (Invoice $record): array => InvoiceColumns::partyLines($record->supplier_snapshot))
                        ->listWithLineBreaks(),
                    TextEntry::make('customer')
                        ->label(__('invoices.detail.parties.customer'))
                        ->state(static fn (Invoice $record): array => InvoiceColumns::partyLines($record->customer_snapshot))
                        ->listWithLineBreaks(),
                ]),
            ]);
    }

    /**
     * The frozen lines, read only. The amounts of a credit note are stored negative and print negative.
     */
    private function itemsSection(): Section
    {
        return Section::make(__('invoices.detail.sections.items'))
            ->schema([
                RepeatableEntry::make('lines')
                    ->hiddenLabel()
                    ->placeholder(__('invoices.detail.no_items'))
                    ->state(fn (Invoice $record): array => $this->lines($record))
                    ->table([
                        TableColumn::make(__('invoices.draft.columns.index')),
                        TableColumn::make(__('invoices.detail.columns.description')),
                        TableColumn::make(__('invoices.detail.columns.quantity'))->alignEnd(),
                        TableColumn::make(__('invoices.detail.columns.unit_price'))->alignEnd(),
                        TableColumn::make(__('invoices.detail.columns.total'))->alignEnd(),
                    ])
                    ->schema([
                        TextEntry::make('index'),
                        TextEntry::make('description'),
                        TextEntry::make('quantity'),
                        TextEntry::make('unit_price'),
                        TextEntry::make('line_total'),
                    ]),
            ]);
    }

    /**
     * What is owed and what was paid, and for a foreign currency the frozen CNB rate with the total in CZK (IN-07).
     */
    private function summarySection(): Section
    {
        return Section::make(__('invoices.detail.sections.summary'))
            ->columns(['default' => 1, 'md' => 3])
            ->schema([
                TextEntry::make('summary_total')
                    ->label(__('invoices.detail.summary.total'))
                    ->state(static fn (Invoice $record): string => $record->total()->format('cs'))
                    ->size(TextSize::Large)
                    ->weight(FontWeight::SemiBold),
                TextEntry::make('summary_paid')
                    ->label(__('invoices.detail.summary.paid'))
                    ->state(fn (Invoice $record): string => Money::ofMinor($this->derived()['paid'], $record->currency)->format('cs'))
                    ->visible(static fn (Invoice $record): bool => $record->kind !== InvoiceKind::CreditNote),
                TextEntry::make('summary_remaining')
                    ->label(__('invoices.detail.summary.remaining'))
                    ->state(fn (Invoice $record): string => ($this->derived()['remaining'] ?? Money::zero($record->currency))->format('cs'))
                    ->visible(static fn (Invoice $record): bool => $record->kind !== InvoiceKind::CreditNote),
                TextEntry::make('summary_rate')
                    ->hiddenLabel()
                    ->state(fn (Invoice $record): ?string => $this->rateText($record))
                    ->color('gray')
                    ->columnSpanFull()
                    ->visible(fn (Invoice $record): bool => $this->rateText($record) !== null),
                TextEntry::make('summary_czk')
                    ->hiddenLabel()
                    ->state(fn (Invoice $record): ?string => $this->czkText($record))
                    ->color('gray')
                    ->columnSpanFull()
                    ->visible(fn (Invoice $record): bool => $this->czkText($record) !== null),
            ]);
    }

    /**
     * The documents that really relate to this one: the source proforma, the invoice made from this proforma, the
     * credit notes of this invoice and the invoice a credit note refers to. No row, no section.
     */
    private function relatedSection(): Section
    {
        return Section::make(__('invoices.detail.sections.related'))
            ->visible(fn (Invoice $record): bool => $this->relatedRows($record) !== [])
            ->schema([
                RepeatableEntry::make('related')
                    ->hiddenLabel()
                    ->state(fn (Invoice $record): array => $this->relatedRows($record))
                    ->table([
                        TableColumn::make(__('invoices.detail.related_columns.kind')),
                        TableColumn::make(__('invoices.detail.related_columns.number')),
                        TableColumn::make(__('invoices.detail.related_columns.status')),
                    ])
                    ->schema([
                        TextEntry::make('kind')->badge()->color('gray'),
                        TextEntry::make('link')->html(),
                        TextEntry::make('status'),
                    ]),
            ]);
    }

    /**
     * The printed note (frozen, read only) and the internal note, the one writable field of an issued document.
     */
    private function notesSection(): Section
    {
        $empty = __('invoices.navigation.empty_value');

        return Section::make(__('invoices.detail.sections.notes'))
            ->schema([
                TextEntry::make('note')
                    ->label(__('invoices.detail.note.printed'))
                    ->placeholder($empty)
                    ->prose(),
                TextEntry::make('internal_note')
                    ->label(__('invoices.detail.note.heading'))
                    ->state(static fn (Invoice $record): ?string => $record->admin?->internal_note)
                    ->placeholder($empty)
                    ->prose()
                    ->hintAction($this->editNoteAction()),
            ]);
    }

    /**
     * "Upravit poznámku": a small modal with a textarea. The write goes through {@see UpdateInternalNote}, which locks
     * the invoice, writes only the Admin-only row and checks the limit again.
     */
    private function editNoteAction(): Action
    {
        return Action::make('editNote')
            ->label(__('invoices.detail.actions.edit_note'))
            ->link()
            ->modalHeading(__('invoices.detail.note.heading'))
            ->modalSubmitActionLabel(__('invoices.detail.note.submit'))
            ->modalCancelActionLabel(__('invoices.detail.note.cancel'))
            ->fillForm(fn (): array => ['internal_note' => $this->invoice()->admin?->internal_note])
            ->schema([
                Textarea::make('internal_note')
                    ->label(__('invoices.detail.note.heading'))
                    ->rows(5)
                    ->maxLength(2000),
            ])
            ->action(function (array $data): void {
                abort_unless(InvoiceResource::canAccess(), 403);

                $actor = auth()->user();
                assert($actor instanceof User);

                try {
                    app(UpdateInternalNote::class)->handle($actor, $this->invoice(), is_string($data['internal_note'] ?? null) ? $data['internal_note'] : null);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(['mountedActions.0.data.internal_note' => $exception->errors()['internal_note'] ?? [__('invoices.errors.internal_note_length')]]);
                }

                $this->reloadAdminHalf();

                Notification::make()->success()->title(__('invoices.detail.note.toast'))->send();
            });
    }

    /**
     * @return list<array{index: string, description: string, quantity: string, unit_price: string, line_total: string}>
     */
    private function lines(Invoice $invoice): array
    {
        $lines = [];

        foreach ($invoice->items as $position => $item) {
            $lines[] = [
                'index' => (string) ($position + 1),
                'description' => $item->description,
                'quantity' => InvoiceQuantity::fromDecimal($item->quantity)->format('cs').' '.$item->unit,
                'unit_price' => Money::ofMinor($item->unit_price_minor, $invoice->currency)->format('cs'),
                'line_total' => Money::ofMinor($item->line_total_minor, $invoice->currency)->format('cs'),
            ];
        }

        return $lines;
    }

    /**
     * "Kurz ČNB ke dni vystavení :date: :rate", or null for a document in CZK or one without the snapshot.
     */
    private function rateText(Invoice $invoice): ?string
    {
        $admin = $invoice->admin;

        if (! $admin instanceof InvoiceAdmin || $invoice->currency === 'CZK' || $admin->exchange_rate === null || $admin->exchange_rate_date === null) {
            return null;
        }

        $unit = $admin->exchange_unit_amount ?? 1;

        return (string) __('invoices.detail.summary.rate', [
            'date' => $admin->exchange_rate_date->format('j. n. Y'),
            'rate' => RateFormat::display((string) $admin->exchange_rate).($unit === 1 ? '' : ' / '.$unit),
        ]);
    }

    private function czkText(Invoice $invoice): ?string
    {
        $czk = $this->rateText($invoice) === null ? null : $invoice->admin?->czkTotal();

        return $czk === null ? null : (string) __('invoices.detail.summary.czk_total', ['czk' => $czk->format('cs')]);
    }

    /**
     * @return list<array{kind: string, link: string, status: string}>
     */
    private function relatedRows(Invoice $invoice): array
    {
        $related = [];

        if ($invoice->proforma_id !== null && $invoice->proforma instanceof Invoice) {
            $related[] = $invoice->proforma;
        }

        if ($invoice->kind === InvoiceKind::Proforma && $invoice->invoiceFromProforma instanceof Invoice && $invoice->invoiceFromProforma->isIssued()) {
            $related[] = $invoice->invoiceFromProforma;
        }

        foreach ($this->issuedCreditNotes() as $note) {
            $related[] = $note;
        }

        if ($invoice->kind === InvoiceKind::CreditNote && $invoice->creditedInvoice instanceof Invoice) {
            $related[] = $invoice->creditedInvoice;
        }

        if ($related === []) {
            return [];
        }

        $states = app(ReceivablesReader::class)->statuses($related, ReceivablesReader::today());
        $rows = [];

        foreach ($related as $document) {
            $rows[] = [
                'kind' => $document->kind->getLabel(),
                'link' => '<a href="'.e(InvoiceResource::getUrl('view', ['record' => $document])).'" style="text-decoration: underline;">'.e($document->number ?? __('invoices.navigation.empty_value')).'</a>',
                'status' => $states[$document->id]['status']->getLabel(),
            ];
        }

        return $rows;
    }

    /**
     * The issued credit notes of this invoice, oldest first.
     *
     * @return list<Invoice>
     */
    private function issuedCreditNotes(): array
    {
        $invoice = $this->invoice();

        if ($invoice->kind !== InvoiceKind::Invoice) {
            return [];
        }

        return array_values($invoice->creditNotes->filter(static fn (Invoice $note): bool => $note->isIssued())->all());
    }

    /**
     * The state of the document, derived once per request.
     *
     * @return array{status: InvoiceStatus, remaining: Money|null, overdue_days: int|null, paid: int}
     */
    private function derived(): array
    {
        return $this->derived ??= InvoiceTable::statusOf($this->invoice());
    }

    private function pdfPresent(): bool
    {
        return $this->invoice()->admin?->pdfDocument !== null;
    }

    /**
     * Reads the Admin-only half again after a write, and forgets what was derived from it.
     */
    private function reloadAdminHalf(): void
    {
        $invoice = $this->invoice();
        $invoice->unsetRelation('admin');
        $invoice->load('admin.pdfDocument');
        $this->derived = null;
    }

    private function invoice(): Invoice
    {
        $record = $this->getRecord();
        assert($record instanceof Invoice);

        return $record;
    }
}
