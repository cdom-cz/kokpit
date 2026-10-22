<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Actions\DeactivatePaymentLink;
use App\Domain\Payments\Actions\RegeneratePaymentLink;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Queries\PaymentLinkStatus;
use App\Filament\Resources\InvoiceResource;
use Closure;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;

/**
 * The "Platba kartou" section of the issued document detail, the two link actions and the two link banners (PA-01,
 * PA-03, PA-04; D-05 to D-09, UI-SPEC Surface E, ruling R11-12-3). Admin only: the host page refuses a Partner before
 * anything here runs, and every action checks the Admin again.
 *
 * Nothing Stripe-made is rendered. The state, the amount, the date and the stable Kokpit pay URL are shown; the Stripe
 * URL, the link id and the failure class never are, and a failed link reads the fixed reason line, never the text the
 * provider sent (T-11-42). The section polls every 5 seconds while the link is being prepared and stops as soon as
 * the state changes.
 *
 * The three link readers of the page share one `$status` closure (the page memoises it per request); without one the
 * section reads the status itself.
 *
 * @phpstan-import-type LinkStatusRow from PaymentLinkStatus
 */
final class PaymentLinkSection
{
    public const string KEY = 'payment_link';

    /**
     * @param  Closure(): Invoice  $invoice  the document the page shows
     * @param  Closure(): void  $reload  forgets what the page derived, after a write
     * @param  (Closure(): LinkStatusRow)|null  $status
     */
    public static function section(Closure $invoice, Closure $reload, ?Closure $status = null): Section
    {
        $status = self::reader($invoice, $status);
        $empty = __('invoices.navigation.empty_value');

        return Section::make(__('payment_links.section.heading'))
            ->key(self::KEY)
            ->columns(['default' => 1, 'md' => 2])
            ->visible(static fn (): bool => self::applies($invoice()))
            ->extraAttributes(static fn (): array => self::state($status) === PaymentLinkState::Pending ? ['wire:poll.5s' => '$refresh'] : [])
            ->headerActions([
                self::regenerateAction($invoice, $reload, $status),
                self::deactivateAction($invoice, $reload, $status),
            ])
            ->schema([
                TextEntry::make('payment_link_state')
                    ->label(__('payment_links.section.state'))
                    ->badge()
                    ->state(static fn (): string => self::stateLabel($status))
                    ->color(static fn (): string => self::stateColor($status))
                    ->icon(static fn (): Heroicon => self::stateIcon($status)),
                TextEntry::make('payment_link_amount')
                    ->label(__('payment_links.section.amount'))
                    ->state(static fn (): ?string => self::row($status)['offered']?->format('cs'))
                    ->placeholder($empty),
                TextEntry::make('payment_link_created')
                    ->label(__('payment_links.section.created'))
                    ->state(static fn (): mixed => self::row($status)['created_at'])
                    ->dateTime('j. n. Y H:i')
                    ->placeholder($empty),
                TextEntry::make('payment_link_url')
                    ->label(__('payment_links.section.url'))
                    ->state(static fn (): ?string => self::row($status)['url'])
                    ->copyable()
                    ->copyMessage(__('payment_links.section.copied'))
                    ->suffixAction(
                        Action::make('openPayPage')
                            ->label(__('payment_links.actions.open_pay_page'))
                            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                            ->url(static fn (): ?string => self::row($status)['url'])
                            ->openUrlInNewTab(),
                    )
                    ->visible(static fn (): bool => self::row($status)['url'] !== null)
                    ->columnSpanFull(),
                TextEntry::make('payment_link_reason')
                    ->label(__('payment_links.section.reason'))
                    ->state(static fn (): ?string => self::reason($status))
                    ->color('gray')
                    ->visible(static fn (): bool => self::reason($status) !== null)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * The failure banner (danger) and the inactive banner (warning, with the remaining amount), each with the
     * regenerate action inside. They sit after the overdue banner of the page.
     *
     * @param  Closure(): Invoice  $invoice
     * @param  Closure(): void  $reload
     * @param  (Closure(): LinkStatusRow)|null  $status
     * @return list<Callout>
     */
    public static function banners(Closure $invoice, Closure $reload, ?Closure $status = null): array
    {
        $status = self::reader($invoice, $status);

        return [
            Callout::make(__('payment_links.banners.failed'))
                ->key('payment_link_failed')
                ->danger()
                ->visible(static fn (): bool => self::applies($invoice()) && self::row($status)['failed'] === true && self::row($status)['regenerable'] === true)
                ->actions([self::regenerateAction($invoice, $reload, $status)]),
            Callout::make(static fn (): string => __('payment_links.banners.inactive', [
                'amount' => self::row($status)['remaining']?->format('cs') ?? __('invoices.navigation.empty_value'),
            ]))
                ->key('payment_link_inactive')
                ->warning()
                ->visible(static fn (): bool => self::applies($invoice())
                    && self::row($status)['regenerable'] === true
                    && self::row($status)['failed'] === false
                    && self::state($status) !== PaymentLinkState::Active)
                ->actions([self::regenerateAction($invoice, $reload, $status)]),
        ];
    }

    /**
     * Whether the document has a card-payment section at all: an issued invoice or proforma. A credit note, a draft
     * and a Partner page have none (D-05, D-06).
     */
    public static function applies(Invoice $invoice): bool
    {
        return $invoice->isIssued() && $invoice->kind->carriesPayment();
    }

    /**
     * "Vytvořit odkaz znovu": hidden unless a new link can be created. The write goes through
     * {@see RegeneratePaymentLink}, which judges the locked document again; a refusal becomes a danger toast.
     *
     * @param  Closure(): Invoice  $invoice
     * @param  Closure(): void  $reload
     * @param  Closure(): LinkStatusRow  $status
     */
    private static function regenerateAction(Closure $invoice, Closure $reload, Closure $status): Action
    {
        return Action::make('regeneratePaymentLink')
            ->label(__('payment_links.actions.regenerate.label'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(static fn (): bool => self::row($status)['regenerable'] === true)
            ->requiresConfirmation()
            ->modalIcon(null)
            ->modalHeading(__('payment_links.actions.regenerate.heading'))
            ->modalDescription(static fn (): string => __('payment_links.actions.regenerate.body', [
                'amount' => self::row($status)['remaining']?->format('cs') ?? __('invoices.navigation.empty_value'),
            ]))
            ->modalSubmitActionLabel(__('payment_links.actions.regenerate.submit'))
            ->modalCancelActionLabel(__('payment_links.actions.regenerate.cancel'))
            ->modalSubmitAction(static fn (Action $action): Action => $action->color('primary'))
            ->action(static function () use ($invoice, $reload): void {
                abort_unless(InvoiceResource::canAccess(), 403);

                $actor = auth()->user();
                assert($actor instanceof User);

                try {
                    app(RegeneratePaymentLink::class)->handle($actor, self::fresh($invoice));
                } catch (ValidationException $exception) {
                    $reload();
                    self::refused($exception);

                    return;
                }

                $reload();

                Notification::make()->info()->title(__('payment_links.actions.regenerate.toast'))->send();
            });
    }

    /**
     * "Deaktivovat odkaz": hidden unless the link is active. Warning, not destructive: the document is untouched and
     * the link can be recreated.
     *
     * @param  Closure(): Invoice  $invoice
     * @param  Closure(): void  $reload
     * @param  Closure(): LinkStatusRow  $status
     */
    private static function deactivateAction(Closure $invoice, Closure $reload, Closure $status): Action
    {
        return Action::make('deactivatePaymentLink')
            ->label(__('payment_links.actions.deactivate.label'))
            ->icon(Heroicon::OutlinedLinkSlash)
            ->color('warning')
            ->visible(static fn (): bool => self::state($status) === PaymentLinkState::Active)
            ->requiresConfirmation()
            ->modalIcon(null)
            ->modalHeading(__('payment_links.actions.deactivate.heading'))
            ->modalDescription(__('payment_links.actions.deactivate.body'))
            ->modalSubmitActionLabel(__('payment_links.actions.deactivate.submit'))
            ->modalCancelActionLabel(__('payment_links.actions.deactivate.cancel'))
            ->action(static function () use ($invoice, $reload): void {
                abort_unless(InvoiceResource::canAccess(), 403);

                $actor = auth()->user();
                assert($actor instanceof User);

                try {
                    app(DeactivatePaymentLink::class)->handle($actor, self::fresh($invoice));
                } catch (ValidationException $exception) {
                    $reload();
                    self::refused($exception);

                    return;
                }

                $reload();

                Notification::make()->success()->title(__('payment_links.actions.deactivate.toast'))->send();
            });
    }

    /**
     * The document again through its own query, so a forged record id reaches nothing else.
     *
     * @param  Closure(): Invoice  $invoice
     */
    private static function fresh(Closure $invoice): Invoice
    {
        return Invoice::query()->whereKey($invoice()->getKey())->firstOrFail();
    }

    private static function refused(ValidationException $exception): void
    {
        Notification::make()
            ->danger()
            ->title(__('payment_links.actions.refused_title'))
            ->body(implode(' ', array_merge(...array_values($exception->errors()))))
            ->persistent()
            ->send();
    }

    /**
     * @param  Closure(): Invoice  $invoice
     * @param  (Closure(): LinkStatusRow)|null  $status
     * @return Closure(): LinkStatusRow
     */
    private static function reader(Closure $invoice, ?Closure $status): Closure
    {
        return $status ?? static fn (): array => PaymentLinkStatus::for($invoice());
    }

    /**
     * @param  Closure(): LinkStatusRow  $status
     * @return LinkStatusRow
     */
    private static function row(Closure $status): array
    {
        return $status();
    }

    /**
     * @param  Closure(): LinkStatusRow  $status
     */
    private static function state(Closure $status): ?PaymentLinkState
    {
        return self::row($status)['state'];
    }

    /**
     * "Vypnuto" when the switch was off at issue, otherwise the label of the newest link, the dash without one.
     *
     * @param  Closure(): LinkStatusRow  $status
     */
    private static function stateLabel(Closure $status): string
    {
        $row = self::row($status);

        if (! $row['switch']) {
            return __('payment_links.section.off');
        }

        return $row['state']?->getLabel() ?? __('invoices.navigation.empty_value');
    }

    /**
     * @param  Closure(): LinkStatusRow  $status
     */
    private static function stateColor(Closure $status): string
    {
        $row = self::row($status);

        if (! $row['switch']) {
            return 'gray';
        }

        return match ($row['state']) {
            PaymentLinkState::Active => 'success',
            PaymentLinkState::Pending => 'info',
            PaymentLinkState::Failed => 'danger',
            PaymentLinkState::Deactivated, null => 'gray',
        };
    }

    /**
     * @param  Closure(): LinkStatusRow  $status
     */
    private static function stateIcon(Closure $status): Heroicon
    {
        $row = self::row($status);

        if (! $row['switch']) {
            return Heroicon::OutlinedCreditCard;
        }

        return match ($row['state']) {
            PaymentLinkState::Active => Heroicon::OutlinedLink,
            PaymentLinkState::Pending => Heroicon::OutlinedClock,
            PaymentLinkState::Failed => Heroicon::OutlinedExclamationCircle,
            PaymentLinkState::Deactivated, null => Heroicon::OutlinedLinkSlash,
        };
    }

    /**
     * The reason line: fixed copy for a switch that was off and for a failed link, the reason of a deactivated one;
     * never the text of the provider (D-09).
     *
     * @param  Closure(): LinkStatusRow  $status
     */
    private static function reason(Closure $status): ?string
    {
        $row = self::row($status);

        if (! $row['switch']) {
            return __('payment_links.section.reasons.off');
        }

        if ($row['failed']) {
            return __('payment_links.section.reasons.failed');
        }

        return $row['reason']?->getLabel();
    }
}
