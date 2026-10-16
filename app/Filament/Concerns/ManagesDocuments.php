<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\Support\DocumentActions;
use App\Filament\Support\DocumentColumns;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The shared behaviour of the Admin's "Soubory" tab (D-02): the list of files with upload, download and
 * preview. A relation manager that uses it declares `$relationship = 'documents'` and says whether the
 * list needs an owner column (only the client tab does, because it lists the files of everything that
 * belongs to the client).
 *
 * `#[AccessRule]` is not inherited by a subclass, so the behaviour is a trait and every host is its own
 * final class with its own attribute and `EnforcesRelationManagerAccessRule` (D-03).
 */
trait ManagesDocuments
{
    /**
     * Whether the list shows what each file hangs on.
     */
    abstract protected function showsOwnerColumn(): bool;

    /**
     * The tab sits on a read-only detail page, but the Admin uploads there, so Filament's read-only
     * default for view pages does not apply.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('documents.relation_title');
    }

    public function table(Table $table): Table
    {
        $showOwner = $this->showsOwnerColumn();

        return $table
            ->recordTitleAttribute('name')
            ->columns(DocumentColumns::admin(showOwner: $showOwner))
            // One query per owner kind instead of one per row (research Pitfall 7).
            ->modifyQueryUsing(static fn (Builder $query): Builder => $showOwner ? DocumentColumns::withOwners($query) : $query)
            ->defaultSort(static fn (Builder $query): Builder => $query->orderByDesc($query->qualifyColumn('created_at'))->orderBy($query->qualifyColumn('id')))
            ->headerActions([
                DocumentActions::upload(),
            ])
            ->recordActions([
                DocumentActions::preview(),
                DocumentActions::download(),
                DocumentActions::rename(),
                DocumentActions::toggleVisibility(),
                DocumentActions::delete(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DocumentActions::bulkDelete(),
                ]),
            ])
            ->emptyStateHeading(__('documents.empty.heading'))
            ->emptyStateDescription(__('documents.empty.description'));
    }
}
