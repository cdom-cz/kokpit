<?php

declare(strict_types=1);

namespace App\Filament\Resources\CardPaymentResource\Pages;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Queries\InvoiceBalances;
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
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

/**
 * The record of one Stripe payment or refund event (UI-SPEC Surface H; D-11, D-12, D-14): the amount as the heading
 * with the type and the state, the one banner that says what happened and what it did or did not change, and the
 * sections with the Stripe facts, the matching and, after a resolution, the resolution. Nothing is editable here; the
 * resolutions are header actions added by a later plan.
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
