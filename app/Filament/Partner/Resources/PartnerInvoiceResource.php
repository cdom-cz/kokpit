<?php

declare(strict_types=1);

namespace App\Filament\Partner\Resources;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\AccessRules;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use App\Filament\Partner\Resources\PartnerInvoiceResource\Pages\ListPartnerInvoices;
use App\Filament\Partner\Resources\PartnerInvoiceResource\Pages\ViewPartnerInvoice;
use App\Filament\Support\InvoiceColumns;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The read-only "Faktury" list and detail of a Partner (IN-09, UI-SPEC Surface N): the issued invoices, proformas and
 * credit notes of the own client.
 *
 * The query is the scoped `Invoice` model: a draft, an unissued document and another client's document do not exist
 * for a Partner, so a guessed, foreign or draft id is a 404 and never a 403. The table and the infolist are built only
 * from the Partner-safe builders of `InvoiceColumns`; there is no create, edit or delete page, no bulk action, no
 * export, no relation manager and no global search. The slug differs from the Admin document resource.
 */
#[AccessRule(Audience::PartnerAllowed, reason: 'A Partner sees the issued documents of the own client read-only; only what the document prints and its derived state are shown.')]
final class PartnerInvoiceResource extends Resource
{
    use EnforcesResourceAccessRule;

    protected static ?string $model = Invoice::class;

    protected static ?string $slug = 'my-invoices';

    protected static ?string $recordTitleAttribute = 'number';

    protected static bool $isGloballySearchable = false;

    protected static ?int $navigationSort = 30;

    /**
     * The declaration AND the policy AND a Partner with a client. This method replaces the trait's one with a
     * stricter check, so it repeats the trait's two conditions: the Admin must not get a second "Faktury" navigation
     * entry (the Admin document resource serves the Admin).
     */
    public static function canAccess(): bool
    {
        return AccessRules::allows(self::class)
            && parent::canAccess()
            && app(PartnerContext::class)->partnerClientId() !== null;
    }

    public static function getNavigationLabel(): string
    {
        return __('invoices.partner.navigation');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedDocumentCurrencyDollar;
    }

    public static function getModelLabel(): string
    {
        return __('invoices.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('invoices.partner.navigation');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns(InvoiceColumns::partnerColumns())
            ->filters(InvoiceColumns::partnerFilters())
            ->defaultSort(static fn ($query) => $query
                ->orderByDesc('invoices.issue_date')
                ->orderByDesc('invoices.number')
                ->orderByDesc('invoices.id'))
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->recordUrl(static fn (Invoice $record): string => self::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewPartnerInvoice::downloadPdfAction(),
            ])
            ->emptyStateIcon(Heroicon::OutlinedInbox)
            ->emptyStateHeading(__('invoices.partner.list.empty_heading'))
            ->emptyStateDescription(__('invoices.partner.list.empty_body'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components(InvoiceColumns::partnerEntries());
    }

    /**
     * No relation: a Partner never reads payments, linked time, files or the e-mail log of a document.
     */
    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartnerInvoices::route('/'),
            'view' => ViewPartnerInvoice::route('/{record}'),
        ];
    }
}
