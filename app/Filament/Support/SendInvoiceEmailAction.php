<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\QueueInvoiceEmail;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Payments\Support\PayLinkBlock;
use App\Filament\Resources\InvoiceResource;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The "Odeslat :kind e-mailem" dialog (IN-04; D-22, ruling R-24-1; UI-SPEC Surface G), shared by the header of the
 * document detail, the row of the document list and the row of the Odeslání tab.
 *
 * The dialog asks for the recipient (pre-filled with the invoice e-mail of the client, empty with a helper when the
 * client has none), the subject and the plain text body (both pre-filled in the language of the document), and, only
 * for a document with linked time, whether the work report is attached (default off). A gray line names the
 * attachments, and a second gray line announces the pay-link block that the mailer adds by itself when it applies (D-03,
 * {@see PayLinkBlock::applies()}); the block is never put into the text area. The pre-filled values come from {@see QueueInvoiceEmail::defaults()}, or from the earlier row when the
 * dialog is opened to send again.
 *
 * The write is {@see QueueInvoiceEmail::handle()}, which validates every value again and stores one new log row; this
 * class only maps its field errors back under the modal fields. A send is never a retry of an old row, so a resend
 * simply opens the same dialog with the values of the previous one. The document the host shows is read again through
 * the scoped query on every call, and a previous row is looked up through that document, so a forged record id reaches
 * nothing else.
 */
final class SendInvoiceEmailAction
{
    /**
     * "Odeslat e-mailem" in the header of the document detail.
     *
     * @param  Closure(): Invoice  $invoice  the document the page shows
     */
    public static function make(Closure $invoice): Action
    {
        return self::build(
            Action::make('sendEmail')
                ->label(__('invoices.email.actions.send'))
                ->color('gray'),
            static fn (): array => [self::fresh($invoice()), null],
        )->visible(static fn (): bool => self::fresh($invoice())->isIssued());
    }

    /**
     * "Odeslat znovu" on a row of the document list (the record is the document).
     */
    public static function forListRow(): Action
    {
        return self::build(
            Action::make('resendEmail')
                ->label(__('invoices.email.actions.resend'))
                ->color('gray'),
            static function (Action $action): array {
                $record = $action->getRecord();
                assert($record instanceof Invoice);

                return [self::fresh($record), null];
            },
        );
    }

    /**
     * "Odeslat znovu" on a row of the Odeslání tab (the record is an earlier send of the document).
     *
     * @param  Closure(): Invoice  $invoice  the document the tab belongs to
     */
    public static function forLogRow(Closure $invoice): Action
    {
        return self::build(
            Action::make('resendEmail')
                ->label(__('invoices.email.actions.resend'))
                ->color('gray'),
            static function (Action $action) use ($invoice): array {
                $document = self::fresh($invoice());
                $record = $action->getRecord();
                $previous = $record instanceof InvoiceEmail ? $document->emails()->whereKey($record->getKey())->first() : null;

                return [$document, $previous];
            },
        );
    }

    /**
     * @param  Closure(Action): array{0: Invoice, 1: InvoiceEmail|null}  $resolve  the document and the earlier send, read again
     */
    private static function build(Action $action, Closure $resolve): Action
    {
        return $action
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->modalHeading(static fn (Action $action): string => __('invoices.email.dialog.heading', ['kind' => self::kind($resolve($action)[0])]))
            ->modalWidth('2xl')
            ->stickyModalFooter()
            ->modalSubmitActionLabel(__('invoices.email.dialog.submit'))
            ->modalSubmitAction(static fn (Action $action): Action => $action->icon(Heroicon::OutlinedPaperAirplane))
            ->modalCancelActionLabel(__('invoices.email.dialog.cancel'))
            ->fillForm(static function (Action $action) use ($resolve): array {
                [$document, $previous] = $resolve($action);
                $defaults = app(QueueInvoiceEmail::class)->defaults($document);

                if ($previous instanceof InvoiceEmail) {
                    return [
                        'recipient' => $previous->recipient,
                        'subject' => $previous->subject,
                        'body' => $previous->body,
                        'attach_work_report' => $previous->attach_work_report && $defaults['has_work_report'],
                    ];
                }

                return [
                    'recipient' => $defaults['recipient'],
                    'subject' => $defaults['subject'],
                    'body' => $defaults['body'],
                    'attach_work_report' => $defaults['attach_work_report'],
                ];
            })
            ->schema(static fn (Action $action): array => self::fields(static fn (): array => $resolve($action)))
            ->action(static function (array $data, Action $action, Component $livewire) use ($resolve): void {
                abort_unless(InvoiceResource::canAccess(), 403);

                $actor = auth()->user();
                assert($actor instanceof User);

                [$document] = $resolve($action);

                try {
                    app(QueueInvoiceEmail::class)->handle($actor, $document, $data);
                } catch (ValidationException $exception) {
                    throw self::underModal($exception);
                }

                $livewire->dispatch('invoice-email-state-changed');

                Notification::make()->info()->title(__('invoices.email.dialog.queued'))->send();
            });
    }

    /**
     * @param  Closure(): array{0: Invoice, 1: InvoiceEmail|null}  $resolve
     * @return list<TextInput|Textarea|Checkbox|Text>
     */
    private static function fields(Closure $resolve): array
    {
        $hasReport = static fn (): bool => app(QueueInvoiceEmail::class)->defaults($resolve()[0])['has_work_report'];

        return [
            TextInput::make('recipient')
                ->label(__('invoices.email.dialog.fields.recipient'))
                ->required()
                ->email()
                ->maxLength(255)
                ->helperText(static fn (): ?string => app(QueueInvoiceEmail::class)->defaults($resolve()[0])['recipient'] === null
                    ? (string) __('invoices.email.dialog.missing_address')
                    : null),
            TextInput::make('subject')
                ->label(__('invoices.email.dialog.fields.subject'))
                ->required()
                ->maxLength(200),
            Textarea::make('body')
                ->label(__('invoices.email.dialog.fields.body'))
                ->required()
                ->rows(10)
                ->maxLength(5000),
            Checkbox::make('attach_work_report')
                ->label(__('invoices.email.dialog.fields.attach_report'))
                ->helperText(__('invoices.email.dialog.report_helper'))
                ->live()
                ->visible($hasReport),
            Text::make(static fn (Get $get): string => __('invoices.email.dialog.attachments', [
                'files' => self::attachments($resolve()[0], $hasReport() && $get('attach_work_report') === true),
            ]))->color('gray'),
            // D-03: the pay link is appended at send time, never part of the editable text; the line only announces it.
            Text::make(static fn (): string => (string) __('invoices.email.dialog.pay_link'))
                ->color('gray')
                ->visible(static fn (): bool => PayLinkBlock::applies($resolve()[0])),
        ];
    }

    /**
     * The names of the files the e-mail carries: the document PDF and, when ticked, the work report.
     */
    private static function attachments(Invoice $document, bool $withReport): string
    {
        $files = [$document->number.'.pdf'];

        if ($withReport) {
            $files[] = __('invoices.email.dialog.report_file');
        }

        return implode(', ', $files);
    }

    /**
     * "faktura", "zálohová faktura" or "dobropis" in the accusative, for the heading.
     */
    private static function kind(Invoice $document): string
    {
        return (string) __('invoices.issue.kind_accusative.'.$document->kind->value);
    }

    private static function fresh(Invoice $host): Invoice
    {
        return Invoice::query()->whereKey($host->getKey())->firstOrFail();
    }

    /**
     * The Action reports plain data keys; the modal form lives under the action state path. An error that belongs to
     * no field of the modal (a draft, which cannot be sent) lands on the recipient.
     */
    private static function underModal(ValidationException $exception): ValidationException
    {
        $mapped = [];

        foreach ($exception->errors() as $key => $messages) {
            $field = in_array($key, ['recipient', 'subject', 'body'], true) ? $key : 'recipient';
            $path = 'mountedActions.0.data.'.$field;
            $mapped[$path] = [...($mapped[$path] ?? []), ...$messages];
        }

        return ValidationException::withMessages($mapped);
    }
}
