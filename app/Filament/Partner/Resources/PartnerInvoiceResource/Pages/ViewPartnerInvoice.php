<?php

declare(strict_types=1);

namespace App\Filament\Partner\Resources\PartnerInvoiceResource\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Support\InvoicePdfUrl;
use App\Domain\Payments\Queries\PartnerPayButton;
use App\Filament\Partner\Resources\PartnerInvoiceResource;
use App\Filament\Support\InvoiceColumns;
use App\Filament\Support\PayByCardAction;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Livewire\Component;

/**
 * The read-only detail of an issued document of the own client (IN-09). The record comes from the Partner-scoped
 * query, so a foreign, draft, unknown or malformed id is a 404 before anything renders.
 */
final class ViewPartnerInvoice extends ViewRecord
{
    protected static string $resource = PartnerInvoiceResource::class;

    public function getTitle(): string
    {
        $invoice = $this->getRecord();
        assert($invoice instanceof Invoice);

        return (string) $invoice->number;
    }

    /**
     * The kind and the state of the document next to its number, as the client reads them.
     */
    public function getSubheading(): Htmlable
    {
        $invoice = $this->getRecord();
        assert($invoice instanceof Invoice);

        $status = InvoiceColumns::partnerState($invoice)['status'];

        return new HtmlString(Blade::render(
            '<div class="flex flex-wrap items-center gap-2"><x-filament::badge color="gray">{{ $kind }}</x-filament::badge><x-filament::badge :color="$color">{{ $state }}</x-filament::badge></div>',
            ['kind' => $invoice->kind->getLabel(), 'state' => $status->partnerLabel(), 'color' => InvoiceColumns::partnerColor($status)],
        ));
    }

    /**
     * The header holds "Zaplatit kartou" in front of the PDF (D-04, UI-SPEC Surfaces F and M): the one accent action,
     * shown exactly while the document is not paid, not cancelled and has an active link, opening the stable pay URL
     * in a new tab. Otherwise the header holds the PDF only, with no placeholder. What the button reads is decided by
     * {@see PartnerPayButton}, which returns the URL or nothing; `canAccess()` is checked again before it is asked.
     *
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        $record = function (): Invoice {
            $invoice = $this->getRecord();
            assert($invoice instanceof Invoice);

            return $invoice;
        };
        $url = static fn (Invoice $invoice): ?string => PartnerInvoiceResource::canAccess() ? PartnerPayButton::urlFor($invoice) : null;

        return [
            PayByCardAction::make(
                $record,
                static fn (): bool => $url($record()) !== null,
                'primary',
                static fn (): ?string => $url($record()),
            ),
            self::downloadPdfAction(),
        ];
    }

    /**
     * "Stáhnout PDF": the signed, short-lived link of the invoice PDF route (plan 10-38), built for the signed-in
     * user at click time and followed by a redirect, never rendered into the page. The list row uses the same action.
     */
    public static function downloadPdfAction(): Action
    {
        return Action::make('downloadPdf')
            ->label(__('invoices.partner.list.download'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('gray')
            ->action(static function (Invoice $record, Component $livewire): void {
                abort_unless(PartnerInvoiceResource::canAccess(), 403);

                $user = auth()->user();

                abort_unless($user instanceof User, 403);

                $livewire->redirect(InvoicePdfUrl::download($record, $user));
            });
    }
}
