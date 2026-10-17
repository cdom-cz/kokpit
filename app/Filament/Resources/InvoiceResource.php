<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use App\Filament\RelationManagers\InvoiceDocumentsRelationManager;
use App\Filament\RelationManagers\InvoiceHistoryRelationManager;
use App\Filament\Resources\InvoiceResource\Pages\EditInvoice;
use App\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Resources\InvoiceResource\RelationManagers\InvoiceEmailsRelationManager;
use App\Filament\Resources\InvoiceResource\RelationManagers\InvoiceEntriesRelationManager;
use App\Filament\Resources\InvoiceResource\RelationManagers\InvoicePaymentsRelationManager;
use App\Filament\Support\InvoiceTable;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Admin's "Faktury" screen (IN-01, IN-02, IN-05, IN-06): the one list of invoices, proformas and credit notes,
 * drafts included, and the document page.
 *
 * Nothing here writes a row: the header action and the row actions hand their data to the domain Actions
 * (`CreateDraft`, `DeleteDraft`), which re-validate every key. A document carries money, so the resource is Admin
 * only and is not globally searchable: a Partner could otherwise probe numbers (UI-SPEC U-15). The Partner has its
 * own read-only resource in a later plan.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Billing documents carry rates, payments and drafts: a Partner never opens the Admin document screen (IN-09).')]
final class InvoiceResource extends Resource
{
    use EnforcesResourceAccessRule;

    protected static ?string $model = Invoice::class;

    protected static ?string $slug = 'invoices';

    protected static ?int $navigationSort = 50;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationLabel(): string
    {
        return __('invoices.navigation.label');
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
        return __('invoices.navigation.plural');
    }

    /**
     * The rows of the list with everything a row shows loaded for the page at once: the client (archived ones
     * included), the Admin half with the stored PDF, the cancelled marker and the e-mail log without the bodies, so
     * the number of queries does not depend on the number of rows (UI-SPEC N1).
     */
    public static function getEloquentQuery(): Builder
    {
        return self::withListRelations(parent::getEloquentQuery());
    }

    /**
     * The eager loads and the cancelled marker every document list needs, shared by the Admin list and the client tab.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function withListRelations(Builder $query): Builder
    {
        return $query
            ->with([
                'client',
                'admin.pdfDocument',
                'emails' => static fn ($emails) => $emails->select(['invoice_emails.id', 'invoice_emails.invoice_id', 'invoice_emails.state', 'invoice_emails.sent_at', 'invoice_emails.created_at']),
            ])
            ->withExists('stornoCreditNote');
    }

    /**
     * The tabs of a document, in the order of UI-SPEC E4: the payments, the linked time ("Záznamy času" with the add and
     * unlink actions on a draft, "Navázaný čas" read only on an issued document, ruling R-25-2), the files, the e-mail
     * log ("Odeslání") and the history. Each tab says itself on which documents it is offered.
     */
    public static function getRelations(): array
    {
        return [
            InvoicePaymentsRelationManager::class,
            InvoiceEntriesRelationManager::class,
            InvoiceDocumentsRelationManager::class,
            InvoiceEmailsRelationManager::class,
            InvoiceHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'edit' => EditInvoice::route('/{record}/edit'),
            'view' => ViewInvoice::route('/{record}'),
        ];
    }

    public static function table(Table $table): Table
    {
        return InvoiceTable::configure($table);
    }
}
