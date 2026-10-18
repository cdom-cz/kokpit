<?php

declare(strict_types=1);

namespace App\Filament\Resources\CardPaymentResource\Pages;

use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Payments\Actions\AssignCardPayment;
use App\Domain\Payments\Enums\CardPaymentReason;
use App\Domain\Payments\Enums\CardPaymentResolution;
use App\Domain\Payments\Enums\CardPaymentStatus;
use App\Domain\Payments\Enums\CardPaymentType;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Shared\Money\Money;
use App\Filament\Resources\CardPaymentResource;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\TransactionResource;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The record of one Stripe payment or refund event (UI-SPEC Surface H; D-11, D-12, D-14): the amount as the heading
 * with the type and the state, the one banner that says what happened and what it did or did not change, and the
 * sections with the Stripe facts, the matching and, after a resolution, the resolution. Nothing is editable here; an
 * open record offers the resolutions of D-13 as header actions (PA-08; R11-19-4), each one final.
 *
 * Every action re-checks the Admin, calls its Action, then shows the toast, dispatches `card-payment-resolved` for the
 * list and the dashboard card and refreshes the record. A record that was resolved in the meantime (another tab, a
 * stale page) is refused by the Action with "Platba už byla vyřešena", and the page refreshes to the resolved state.
 *
 * The page is Admin only and the check runs on every Livewire request: a Partner would otherwise be served a record
 * of an own document through the Partner scope of the model.
 */
final class ViewCardPayment extends ViewRecord
{
    protected static string $resource = CardPaymentResource::class;

    /** @var array{paid: int, credited: int, remaining: int|null}|null */
    private ?array $balance = null;

    /**
     * The documents the assign modal offers, read once per request. Not persisted by Livewire.
     *
     * @var array<string, string>|null
     */
    private ?array $documentOptions = null;

    /**
     * The Admin check of every request, before any other code of the page runs. A Partner is refused with 403 before
     * the record is looked up.
     */
    public function boot(): void
    {
        abort_unless(CardPaymentResource::canAccess(), 403);
    }

    public function getTitle(): string
    {
        return $this->payment()->amount()->format('cs');
    }

    /**
     * The amount with the type and state badges.
     */
    public function getHeading(): Htmlable
    {
        $payment = $this->payment();
        $status = $payment->status();

        $badges = Blade::render(
            '<x-filament::badge :color="$typeColor" :icon="$typeIcon" style="vertical-align: middle;">{{ $type }}</x-filament::badge> <x-filament::badge :color="$statusColor" :icon="$statusIcon" style="vertical-align: middle;">{{ $state }}</x-filament::badge>',
            [
                'typeColor' => CardPaymentResource::typeColor($payment->type),
                'typeIcon' => CardPaymentResource::typeIcon($payment->type),
                'type' => $payment->type->getLabel(),
                'statusColor' => CardPaymentResource::statusColor($status),
                'statusIcon' => CardPaymentResource::statusIcon($status),
                'state' => $status->getLabel(),
            ],
        );

        return new HtmlString(e($this->getTitle()).' '.$badges);
    }

    /**
     * The received instant, under the heading.
     */
    public function getSubheading(): string
    {
        return $this->payment()->received_at->setTimezone(FilamentTimezone::get())->format('j. n. Y H:i');
    }

    /**
     * The resolutions of an open record (D-13): hidden ones are not rendered.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->assignAction(),
        ];
    }

    /**
     * "Přiřadit k dokladu" (D-13): the payment is booked to an issued, not cancelled invoice or proforma of the payment
     * currency through the rules of a manual payment. Accent on an unmatched payment, gray on a duplicate.
     */
    private function assignAction(): Action
    {
        return Action::make('assignCardPayment')
            ->label(__('card_payments.actions.assign'))
            ->icon(Heroicon::OutlinedLink)
            ->color(fn (): string => $this->payment()->type === CardPaymentType::Unmatched ? 'primary' : 'gray')
            ->visible(fn (): bool => $this->offers([CardPaymentType::Unmatched, CardPaymentType::Duplicate]))
            ->modalHeading(__('card_payments.assign.heading'))
            ->modalWidth('lg')
            ->modalSubmitActionLabel(__('card_payments.assign.submit'))
            ->modalCancelActionLabel(__('card_payments.assign.cancel'))
            ->modalSubmitAction(fn (Action $action): Action => $this->documentOptions() === [] ? $action->disabled() : $action)
            ->fillForm(function (): array {
                $payment = $this->payment();
                $day = $payment->received_at->setTimezone(FilamentTimezone::get())->format('Y-m-d');

                return [
                    'invoice_id' => array_key_exists((string) $payment->invoice_id, $this->documentOptions()) ? $payment->invoice_id : null,
                    'paid_on' => $day,
                    'income_date' => $day,
                    'category_id' => TransactionCategory::invoicePayments()->getKey(),
                    'note' => null,
                ];
            })
            ->schema(fn (): array => $this->assignFields())
            ->action(function (array $data): void {
                $this->resolveWith(
                    static fn (User $actor, CardPayment $record): CardPayment => app(AssignCardPayment::class)->handle($actor, $record, $data),
                    static fn (CardPayment $done): string => __('card_payments.assign.toast', ['number' => (string) $done->assignedInvoice?->number]),
                    ['invoice_id', 'paid_on', 'income_date', 'category_id', 'note'],
                    'invoice_id',
                );
            });
    }

    /**
     * @return list<Callout|Text|Select|DatePicker|Textarea>
     */
    private function assignFields(): array
    {
        $proforma = fn (Get $get): bool => $this->chosen($get('invoice_id'))?->kind === InvoiceKind::Proforma;

        return [
            Callout::make(fn (Get $get): string => $this->assignWarning($get('invoice_id')))
                ->warning()
                ->visible(fn (Get $get): bool => $this->assignWarning($get('invoice_id')) !== ''),
            Select::make('invoice_id')
                ->label(__('card_payments.assign.fields.invoice'))
                ->required()
                ->searchable()
                ->live()
                ->options(fn (): array => $this->documentOptions())
                ->noOptionsMessage(__('card_payments.assign.empty'))
                ->placeholder(fn (): ?string => $this->documentOptions() === [] ? (string) __('card_payments.assign.empty') : null),
            DatePicker::make('paid_on')
                ->label(__('card_payments.assign.fields.paid_on'))
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
            DatePicker::make('income_date')
                ->label(__('card_payments.assign.fields.income_date'))
                ->native(false)
                ->displayFormat('j. n. Y')
                ->hidden($proforma),
            Select::make('category_id')
                ->label(__('card_payments.assign.fields.category'))
                ->options(fn (): array => $this->categoryOptions(TransactionType::Income))
                ->searchable()
                ->hidden($proforma),
            Textarea::make('note')
                ->label(__('card_payments.assign.fields.note'))
                ->rows(2)
                ->rule('max:500')
                ->live(debounce: 400)
                ->hint(fn (Get $get): string => mb_strlen((string) $get('note')).' / 500'),
            Text::make(__('card_payments.assign.final'))->color('gray'),
        ];
    }

    /**
     * The live warning of the chosen document: nothing is owed on it any more, or the payment differs from what is.
     * Never a block (D-17).
     */
    private function assignWarning(mixed $id): string
    {
        $document = $this->chosen($id);
        $payment = $this->payment();

        if (! $document instanceof Invoice || $document->currency !== $payment->currency) {
            return '';
        }

        $remaining = app(InvoiceBalances::class)->for([$document->id])[$document->id]['remaining'] ?? null;

        if ($remaining === null) {
            return '';
        }

        if ($remaining <= 0) {
            return (string) __('card_payments.assign.already_paid');
        }

        return $payment->amount_minor === $remaining
            ? ''
            : (string) __('card_payments.assign.differs', [
                'paid' => $payment->amount()->format('cs'),
                'remaining' => Money::ofMinor($remaining, $document->currency)->format('cs'),
            ]);
    }

    /**
     * The documents the payment can be assigned to, "number, client, zbývá remaining": issued, not cancelled invoices and
     * proformas in the payment currency that still owe something, and the document of the record itself even when it is
     * paid (a duplicate is the payment of a paid document). Another currency is never offered.
     *
     * @return array<string, string>
     */
    private function documentOptions(): array
    {
        if ($this->documentOptions !== null) {
            return $this->documentOptions;
        }

        $payment = $this->payment();

        $documents = Invoice::query()
            ->select('invoices.*')
            ->selectRaw(InvoiceBalances::REMAINING_SQL.' AS remaining')
            ->where('state', InvoiceState::Issued->value)
            ->whereIn('kind', [InvoiceKind::Invoice->value, InvoiceKind::Proforma->value])
            ->where('currency', $payment->currency)
            ->notCancelled()
            ->where(function (Builder $query) use ($payment): void {
                $query->whereRaw(InvoiceBalances::REMAINING_SQL.' > 0');

                if ($payment->invoice_id !== null) {
                    $query->orWhere('invoices.id', $payment->invoice_id);
                }
            })
            ->orderByDesc('issue_date')
            ->orderByDesc('number')
            ->limit(200)
            ->get();

        $options = [];

        foreach ($documents as $document) {
            $customer = $document->customer_snapshot['name'] ?? null;

            $options[$document->id] = (string) __('card_payments.assign.option', [
                'number' => (string) $document->number,
                'client' => is_string($customer) ? $customer : (string) __('card_payments.navigation.empty_value'),
                'remaining' => Money::ofMinor((int) $document->getAttribute('remaining'), $document->currency)->format('cs'),
            ]);
        }

        return $this->documentOptions = $options;
    }

    /**
     * The document of the id the modal holds, or null. Read through the scoped query, so a forged id reaches nothing
     * the Admin may not read.
     */
    private function chosen(mixed $id): ?Invoice
    {
        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        return Invoice::query()->whereKey(strtolower($id))->first();
    }

    /**
     * The categories of one transaction type, "Parent › Child".
     *
     * @return array<string, string>
     */
    private function categoryOptions(TransactionType $type): array
    {
        $options = [];

        foreach (TransactionCategory::query()->selectable($type)->with('parent')->get() as $category) {
            $options[$category->id] = TransactionResource::categoryLabel($category);
        }

        asort($options);

        return $options;
    }

    /**
     * Whether an action of the given record types is offered: the record waits for the Admin and is of one of the
     * types. A matched payment is never offered a resolution (D-14), a resolved record none at all (U-11).
     *
     * @param  list<CardPaymentType>  $types
     */
    private function offers(array $types): bool
    {
        $payment = $this->payment();

        return CardPaymentResource::canAccess() && $payment->isUnresolved() && in_array($payment->type, $types, true);
    }

    /**
     * The one path of every resolution: the Admin check of this request, the Action, then the toast, the event and the
     * fresh record. A refusal of the record itself (resolved in the meantime) is the danger toast and a refresh to the
     * resolved state; any other field error goes back under the modal field it belongs to.
     *
     * @param  Closure(User, CardPayment): CardPayment  $write
     * @param  Closure(CardPayment): string  $toast
     * @param  list<string>  $fields  the modal fields; any other error key lands on `$fallback`
     */
    private function resolveWith(Closure $write, Closure $toast, array $fields, string $fallback): void
    {
        abort_unless(CardPaymentResource::canAccess(), 403);

        $actor = auth()->user();
        assert($actor instanceof User);

        try {
            $done = $write($actor, $this->payment());
        } catch (ValidationException $exception) {
            $refusal = $exception->errors()['card_payment'][0] ?? null;

            if ($refusal === null) {
                throw $this->underModal($exception, $fields, $fallback);
            }

            Notification::make()->danger()->title($refusal)->send();
            $this->refreshPayment();

            return;
        }

        Notification::make()->success()->title($toast($done))->send();

        $this->dispatch('card-payment-resolved');
        $this->refreshPayment();
    }

    /**
     * The Action reports plain data keys; the modal form lives under the action state path.
     *
     * @param  list<string>  $fields
     */
    private function underModal(ValidationException $exception, array $fields, string $fallback): ValidationException
    {
        $mapped = [];

        foreach ($exception->errors() as $key => $messages) {
            $path = 'mountedActions.0.data.'.(in_array($key, $fields, true) ? $key : $fallback);
            $mapped[$path] = [...($mapped[$path] ?? []), ...$messages];
        }

        return ValidationException::withMessages($mapped);
    }

    /**
     * The record as the database holds it now, with what was derived from it forgotten.
     */
    private function refreshPayment(): void
    {
        $this->payment()->refresh();
        $this->balance = null;
        $this->documentOptions = null;
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            $this->banner(),
            $this->paymentSection(),
            $this->matchingSection(),
            $this->resolutionSection(),
        ]);
    }

    /**
     * The one banner that applies, in this order: resolved, matched and later deleted, matched, refund, duplicate, and
     * an unmatched payment by its reason (R11-11-5). The copy is the Copywriting Contract of the overview.
     */
    private function banner(): Callout
    {
        $payment = $this->payment();
        $document = CardPaymentResource::documentOf($payment);
        $dash = (string) __('card_payments.navigation.empty_value');
        $number = $document === null ? $dash : (string) $document->number;

        if ($payment->resolution !== CardPaymentResolution::None) {
            return Callout::make(__('card_payments.banners.resolved', [
                'date' => $payment->resolved_at?->setTimezone(FilamentTimezone::get())->format('j. n. Y') ?? $dash,
                'resolution' => (string) CardPaymentResource::resolutionLine($payment),
            ]))->info();
        }

        if ($payment->status() === CardPaymentStatus::PaymentDeleted) {
            return Callout::make(__('card_payments.banners.matched_deleted'))->info();
        }

        return match ($payment->type) {
            CardPaymentType::Matched => Callout::make(__('card_payments.banners.matched', ['number' => $number]))->info(),
            CardPaymentType::Refund => Callout::make(__('card_payments.banners.refund', ['amount' => $payment->amount()->format('cs')]))->warning(),
            CardPaymentType::Duplicate => Callout::make(__('card_payments.banners.duplicate', ['number' => $number]))->danger(),
            CardPaymentType::Unmatched => match ($payment->reason) {
                CardPaymentReason::Amount => Callout::make(__('card_payments.banners.amount', [
                    'paid' => $payment->amount()->format('cs'),
                    'offered' => $payment->offered()?->format('cs') ?? $dash,
                ]))->danger(),
                CardPaymentReason::Currency => Callout::make(__('card_payments.banners.currency', [
                    'currency' => $payment->currency,
                    'offered_currency' => $payment->offered_currency ?? $dash,
                ]))->danger(),
                CardPaymentReason::Cancelled => Callout::make(__('card_payments.banners.cancelled', ['number' => $number]))->danger(),
                default => Callout::make(__('card_payments.banners.undetermined'))->danger(),
            },
        };
    }

    private function paymentSection(): Section
    {
        $empty = __('card_payments.navigation.empty_value');

        return Section::make(__('card_payments.sections.payment'))
            ->columns(['default' => 1, 'md' => 2])
            ->schema([
                TextEntry::make('amount')
                    ->label(__('card_payments.fields.amount'))
                    ->state(fn (): string => $this->payment()->amount()->format('cs'))
                    ->weight(FontWeight::SemiBold),
                TextEntry::make('currency')
                    ->label(__('card_payments.fields.currency')),
                TextEntry::make('received_at')
                    ->label(__('card_payments.fields.received'))
                    ->dateTime('j. n. Y H:i'),
                TextEntry::make('stripe_payment_intent_id')
                    ->label(__('card_payments.fields.payment'))
                    ->copyable()
                    ->placeholder($empty),
                TextEntry::make('stripe_event_id')
                    ->label(__('card_payments.fields.event'))
                    ->copyable(),
                TextEntry::make('stripe_link_id')
                    ->label(__('card_payments.fields.link'))
                    ->copyable()
                    ->placeholder($empty),
            ]);
    }

    private function matchingSection(): Section
    {
        $empty = __('card_payments.navigation.empty_value');

        return Section::make(__('card_payments.sections.matching'))
            ->columns(['default' => 1, 'md' => 2])
            ->schema([
                TextEntry::make('document')
                    ->label(__('card_payments.fields.invoice'))
                    ->state(fn (): ?string => $this->document()?->number)
                    ->url(fn (): ?string => ($document = $this->document()) === null ? null : InvoiceResource::getUrl('view', ['record' => $document]))
                    ->placeholder($empty),
                TextEntry::make('client')
                    ->label(__('card_payments.fields.client'))
                    ->state(fn (): ?string => $this->document()?->client?->name)
                    ->url(fn (): ?string => ($document = $this->document()) === null ? null : ClientResource::getUrl('view', ['record' => $document->client_id]))
                    ->placeholder($empty),
                TextEntry::make('offered')
                    ->label(__('card_payments.fields.offered'))
                    ->state(fn (): ?string => $this->payment()->offered()?->format('cs'))
                    ->placeholder($empty),
                TextEntry::make('remaining')
                    ->label(__('card_payments.fields.remaining'))
                    ->state(fn (): ?string => $this->remaining()?->format('cs'))
                    ->placeholder($empty),
                TextEntry::make('reason')
                    ->label(__('card_payments.fields.reason'))
                    ->state(fn (): ?string => $this->payment()->reason?->getLabel())
                    ->placeholder($empty),
            ]);
    }

    /**
     * Only after a resolution: how it was settled, when, the note and the expense of a recorded return.
     */
    private function resolutionSection(): Section
    {
        $empty = __('card_payments.navigation.empty_value');

        return Section::make(__('card_payments.sections.resolution'))
            ->columns(['default' => 1, 'md' => 2])
            ->visible(fn (): bool => $this->payment()->resolution !== CardPaymentResolution::None)
            ->schema([
                TextEntry::make('resolution_status')
                    ->label(__('card_payments.fields.status'))
                    ->state(fn (): string => $this->payment()->status()->getLabel()),
                TextEntry::make('resolved_at')
                    ->label(__('card_payments.fields.resolved_at'))
                    ->dateTime('j. n. Y H:i'),
                TextEntry::make('resolution_method')
                    ->label(__('card_payments.fields.method'))
                    ->state(fn (): ?string => CardPaymentResource::resolutionLine($this->payment())),
                TextEntry::make('resolution_note')
                    ->label(__('card_payments.fields.note'))
                    ->placeholder($empty),
                TextEntry::make('return_transaction')
                    ->label(__('card_payments.fields.expense'))
                    ->state(fn (): ?string => $this->payment()->returnTransaction?->amount->format('cs'))
                    ->url(fn (): ?string => ($expense = $this->payment()->returnTransaction) === null ? null : TransactionResource::getUrl('edit', ['record' => $expense]))
                    ->visible(fn (): bool => $this->payment()->resolution === CardPaymentResolution::Returned && $this->payment()->return_transaction_id !== null),
            ]);
    }

    /**
     * What is still owed on the document, from the one balance reader; null without a document.
     */
    private function remaining(): ?Money
    {
        $document = $this->document();

        if ($document === null) {
            return null;
        }

        $this->balance ??= app(InvoiceBalances::class)->for([$document->id])[$document->id] ?? ['paid' => 0, 'credited' => 0, 'remaining' => null];

        return $this->balance['remaining'] === null ? null : Money::ofMinor($this->balance['remaining'], $document->currency);
    }

    private function document(): ?Invoice
    {
        return CardPaymentResource::documentOf($this->payment());
    }

    private function payment(): CardPayment
    {
        $record = $this->getRecord();
        assert($record instanceof CardPayment);

        return $record;
    }
}
