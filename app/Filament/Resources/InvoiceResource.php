<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Support\InvoiceQuantity;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Money\Money;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use App\Filament\Resources\InvoiceResource\Pages\EditInvoice;
use App\Filament\Resources\InvoiceResource\Pages\ListInvoices;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Resources\InvoiceResource\RelationManagers\InvoiceEntriesRelationManager;
use App\Filament\Support\InvoiceTable;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
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
     * The linked time: "Záznamy času" with the add and unlink actions on a draft, "Navázaný čas" read only on an
     * issued document (ruling R-25-2). The later plans add the payments, the files, the e-mail log and the history.
     */
    public static function getRelations(): array
    {
        return [
            InvoiceEntriesRelationManager::class,
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

    /**
     * The first document page (ruling R-21-1): the number, kind, state and client and the frozen lines, read only.
     * The draft editor is the `edit` page (plan 10-25); the later plans add the rest of Surface E.
     */
    public static function infolist(Schema $schema): Schema
    {
        $empty = __('invoices.navigation.empty_value');

        return $schema->components([
            Section::make(__('invoices.detail.sections.document'))
                ->columns(2)
                ->schema([
                    TextEntry::make('number')
                        ->label(__('invoices.list.columns.number'))
                        ->placeholder($empty),
                    TextEntry::make('kind')
                        ->label(__('invoices.list.columns.kind'))
                        ->badge()
                        ->color('gray'),
                    TextEntry::make('status')
                        ->label(__('invoices.list.columns.status'))
                        ->state(static fn (Invoice $record) => InvoiceTable::statusOf($record)['status'])
                        ->badge(),
                    TextEntry::make('client.name')
                        ->label(__('invoices.list.columns.client'))
                        ->url(static fn (Invoice $record): string => ClientResource::getUrl('view', ['record' => $record->client_id])),
                    TextEntry::make('currency')
                        ->label(__('invoices.list.columns.currency')),
                    TextEntry::make('total')
                        ->label(__('invoices.list.columns.total'))
                        ->state(static fn (Invoice $record): string => $record->total()->format('cs')),
                ]),
            Section::make(__('invoices.detail.sections.items'))
                ->schema([
                    RepeatableEntry::make('lines')
                        ->hiddenLabel()
                        ->placeholder(__('invoices.detail.no_items'))
                        ->state(static fn (Invoice $record): array => self::lines($record))
                        ->table([
                            TableColumn::make(__('invoices.detail.columns.description')),
                            TableColumn::make(__('invoices.detail.columns.quantity'))->alignEnd(),
                            TableColumn::make(__('invoices.detail.columns.unit_price'))->alignEnd(),
                            TableColumn::make(__('invoices.detail.columns.total'))->alignEnd(),
                        ])
                        ->schema([
                            TextEntry::make('description'),
                            TextEntry::make('quantity'),
                            TextEntry::make('unit_price'),
                            TextEntry::make('line_total'),
                        ]),
                ]),
        ]);
    }

    /**
     * The frozen lines of the document as plain text rows, in order. The text is formatted here once, with the
     * currency of the document, so the entry renders no query per line.
     *
     * @return list<array{description: string, quantity: string, unit_price: string, line_total: string}>
     */
    private static function lines(Invoice $invoice): array
    {
        $lines = [];

        foreach ($invoice->items as $item) {
            $lines[] = [
                'description' => $item->description,
                'quantity' => InvoiceQuantity::fromDecimal($item->quantity)->format('cs').' '.$item->unit,
                'unit_price' => Money::ofMinor($item->unit_price_minor, $invoice->currency)->format('cs'),
                'line_total' => Money::ofMinor($item->line_total_minor, $invoice->currency)->format('cs'),
            ];
        }

        return $lines;
    }
}
