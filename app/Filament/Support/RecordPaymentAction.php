<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Queries\ReceivablesReader;
use App\Domain\Shared\Money\Money;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\TransactionResource;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Component;
use OverflowException;

/**
 * The "Zaznamenat platbu" modal (IN-05, FI-03; D-17, D-18, D-19, ruling R-16-1; UI-SPEC Surface F), shared by the
 * header of the document detail and the header of its Platby tab.
 *
 * The modal asks for the amount (default the remaining amount, the currency as a suffix), the payment date (default
 * today), the way it was paid and, on an invoice only, the income date (default the payment date) and the income
 * category (default the system one). A proforma books no income until it is converted, so those two fields are
 * hidden and a gray callout says why. The callouts for a document that is already paid or an amount above the
 * remaining amount come live from {@see RecordPayment::warnings()}; they are a confirmation, never a block, and the
 * submit then reads "Přesto uložit platbu" (D-17).
 *
 * The write is {@see RecordPayment::handle()}, which re-validates every key and decides on the locked row; this class
 * only maps its field errors back under the modal fields. The invoice the host shows is read again through the
 * scoped query on every call, so a forged record id reaches nothing else.
 */
final class RecordPaymentAction
{
    /**
     * @param  Closure(): Invoice  $invoice  the invoice the host (page or relation manager) shows
     * @param  Closure(): void|null  $afterSave  refreshes what the host derived from the payments
     * @param  string  $color  `primary` in the header of the detail, `gray` in the Platby tab
     */
    public static function make(Closure $invoice, ?Closure $afterSave = null, string $color = 'gray'): Action
    {
        $fresh = static function () use ($invoice): Invoice {
            $host = $invoice();

            return Invoice::query()->whereKey($host->getKey())->firstOrFail();
        };

        return Action::make('recordPayment')
            ->label(__('invoices.detail.actions.record_payment'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->color($color)
            ->modalHeading(__('invoices.payments.record.heading'))
            ->modalWidth('lg')
            ->modalSubmitActionLabel(static fn (Action $action): string => self::warningsFor($fresh(), $action->getRawData()) === []
                ? __('invoices.payments.record.submit')
                : __('invoices.payments.record.submit_anyway'))
            ->modalCancelActionLabel(__('invoices.payments.record.cancel'))
            ->fillForm(static function () use ($fresh): array {
                $document = $fresh();
                $remaining = self::remaining($document);
                $today = ReceivablesReader::today();

                return [
                    'amount' => $remaining->isPositive() ? $remaining->toMajor() : null,
                    'paid_on' => $today,
                    'method' => PaymentMethod::Transfer->value,
                    'income_date' => $today,
                    'category_id' => $document->kind === InvoiceKind::Invoice ? TransactionCategory::invoicePayments()->getKey() : null,
                    'note' => null,
                ];
            })
            ->schema(static fn (): array => self::fields($fresh))
            ->action(static function (array $data, Component $livewire) use ($fresh, $afterSave): void {
                abort_unless(InvoiceResource::canAccess(), 403);

                $actor = auth()->user();
                assert($actor instanceof User);

                $document = $fresh();
                $proforma = $document->kind === InvoiceKind::Proforma;

                try {
                    app(RecordPayment::class)->handle($actor, $document, self::input($data));
                } catch (ValidationException $exception) {
                    throw self::underModal($exception);
                }

                if ($afterSave instanceof Closure) {
                    $afterSave();
                }

                $livewire->dispatch('invoice-payment-saved');

                Notification::make()
                    ->success()
                    ->title(__($proforma ? 'invoices.payments.record.toast_proforma' : 'invoices.payments.record.toast_invoice'))
                    ->send();
            });
    }

    /**
     * Whether a payment can be recorded on the document at all: an issued invoice or proforma that is not cancelled
     * and, for a proforma, not yet converted (a payment on a converted proforma would never book its income). The
     * Action refuses a credit note and a cancelled invoice by itself; the screens simply do not offer it.
     */
    public static function available(Invoice $invoice): bool
    {
        if (! $invoice->isIssued() || ! $invoice->kind->carriesPayment() || $invoice->isCancelled()) {
            return false;
        }

        return InvoiceTable::statusOf($invoice)['status'] !== InvoiceStatus::Converted;
    }

    /**
     * @param  Closure(): Invoice  $fresh
     * @return list<Callout|Text|TextInput|DatePicker|ToggleButtons|Select|Textarea>
     */
    private static function fields(Closure $fresh): array
    {
        $isProforma = static fn (): bool => $fresh()->kind === InvoiceKind::Proforma;

        return [
            Callout::make(__('invoices.payments.record.proforma_callout'))
                ->info()
                ->visible($isProforma),
            Callout::make(static fn (Get $get): string => self::warningText($fresh(), self::warningsFor($fresh(), ['amount' => $get('amount')])))
                ->warning()
                ->visible(static fn (Get $get): bool => self::warningsFor($fresh(), ['amount' => $get('amount')]) !== []),
            TextInput::make('amount')
                ->label(__('invoices.payments.record.fields.amount'))
                ->required()
                ->inputMode('decimal')
                ->suffix(static fn (): string => $fresh()->currency)
                ->live(onBlur: true),
            DatePicker::make('paid_on')
                ->label(__('invoices.payments.record.fields.paid_on'))
                ->required()
                ->native(false)
                ->displayFormat('j. n. Y')
                ->live()
                ->afterStateUpdated(static function (mixed $state, mixed $old, Get $get, Set $set): void {
                    // The income date follows the payment date until the Admin chose a different day for it.
                    $income = $get('income_date');

                    if ($income === null || $income === '' || $income === $old) {
                        $set('income_date', $state);
                    }
                }),
            ToggleButtons::make('method')
                ->label(__('invoices.payments.record.fields.method'))
                ->enum(PaymentMethod::class)
                ->options(collect(PaymentMethod::manual())->mapWithKeys(static fn (PaymentMethod $method): array => [$method->value => $method->getLabel()])->all())
                ->default(PaymentMethod::Transfer->value)
                ->inline()
                ->required(),
            DatePicker::make('income_date')
                ->label(__('invoices.payments.record.fields.income_date'))
                ->native(false)
                ->displayFormat('j. n. Y')
                ->hidden($isProforma),
            Select::make('category_id')
                ->label(__('invoices.payments.record.fields.category'))
                ->options(static fn (): array => self::categoryOptions())
                ->searchable()
                ->hidden($isProforma),
            Textarea::make('note')
                ->label(__('invoices.payments.record.fields.note'))
                ->rows(2)
                ->maxLength(500),
            Text::make(__('invoices.payments.record.not_editable'))->color('gray'),
        ];
    }

    /**
     * The income categories a payment may be booked under, "Parent › Child".
     *
     * @return array<string, string>
     */
    private static function categoryOptions(): array
    {
        $options = [];

        foreach (TransactionCategory::query()->selectable(TransactionType::Income)->with('parent')->get() as $category) {
            $options[$category->id] = TransactionResource::categoryLabel($category);
        }

        asort($options);

        return $options;
    }

    /**
     * The warnings of the amount typed so far. A document that is already paid warns whatever the amount is, so an
     * empty or unreadable amount is asked as one minor unit; the above-the-remaining warning needs a real amount.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function warningsFor(Invoice $invoice, array $data): array
    {
        $amount = self::parseAmount($data['amount'] ?? null, $invoice->currency) ?? Money::ofMinor(1, $invoice->currency);

        return app(RecordPayment::class)->warnings($invoice, $amount);
    }

    /**
     * @param  list<string>  $warnings
     */
    private static function warningText(Invoice $invoice, array $warnings): string
    {
        if (in_array('already_paid', $warnings, true)) {
            return __('invoices.payments.record.already_paid');
        }

        if (in_array('above_remaining', $warnings, true)) {
            return __('invoices.payments.record.over_amount', ['remaining' => self::remaining($invoice)->format('cs')]);
        }

        return '';
    }

    /**
     * The amount as a person types it (a comma or a dot, spaces of any kind), or null when it is not a positive
     * amount. The Action reads the same text again with its own error messages.
     */
    private static function parseAmount(mixed $value, string $currency): ?Money
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        try {
            $amount = Money::fromMajor((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $value), $currency);
        } catch (InvalidArgumentException|OverflowException) {
            return null;
        }

        return $amount->isPositive() ? $amount : null;
    }

    private static function remaining(Invoice $invoice): Money
    {
        return app(ReceivablesReader::class)->statuses([$invoice], ReceivablesReader::today())[$invoice->id]['remaining'] ?? Money::zero($invoice->currency);
    }

    /**
     * The data the Action reads. The system income category is sent as "no category", which means the same.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function input(array $data): array
    {
        $category = $data['category_id'] ?? null;

        if (is_string($category) && $category === TransactionCategory::invoicePayments()->getKey()) {
            $data['category_id'] = null;
        }

        return $data;
    }

    /**
     * The Action reports plain data keys; the modal form lives under the action state path. An error that belongs to
     * no field of the modal (a document cancelled in the meantime) lands on the amount.
     */
    private static function underModal(ValidationException $exception): ValidationException
    {
        $fields = ['amount', 'paid_on', 'method', 'income_date', 'category_id', 'note'];
        $mapped = [];

        foreach ($exception->errors() as $key => $messages) {
            $field = in_array($key, $fields, true) ? $key : 'amount';
            $path = 'mountedActions.0.data.'.$field;
            $mapped[$path] = [...($mapped[$path] ?? []), ...$messages];
        }

        return ValidationException::withMessages($mapped);
    }
}
