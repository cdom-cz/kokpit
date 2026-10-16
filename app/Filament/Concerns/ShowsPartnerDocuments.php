<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\Support\DocumentActions;
use App\Filament\Support\DocumentColumns;
use Filament\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The shared behaviour of the Partner's read-only "Soubory" tab on a project and a task page (DO-01, D-04): the
 * files the owner has, shown with the pinned Partner columns and the download and preview actions.
 *
 * The relation goes through the Partner-scoped Document model, so a hidden file, an internal-comment file, a
 * client-owned file and anything of another client or an archived owner is not part of the query: there is no
 * row, no count and no empty-state hint that could reveal one. The tab never offers an upload, rename or
 * visibility switch; a host may add per-file actions through extraRecordActions() (the task tab: delete of
 * an own file, plan 09-10). `#[AccessRule]` is not inherited, so
 * every host is its own final class with its own attribute and `EnforcesRelationManagerAccessRule`.
 */
trait ShowsPartnerDocuments
{
    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('documents.relation_title');
    }

    /**
     * Further per-file actions of a host (the task tab offers the Partner's delete, D-05). None by default, so
     * the project tab stays preview and download only; a host class that defines this method replaces it.
     *
     * @return list<Action>
     */
    protected function extraRecordActions(): array
    {
        return [];
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns(DocumentColumns::partner())
            ->defaultSort(static fn (Builder $query): Builder => $query->orderByDesc($query->qualifyColumn('created_at'))->orderBy($query->qualifyColumn('id')))
            ->recordActions([
                DocumentActions::preview(),
                DocumentActions::download(),
                ...$this->extraRecordActions(),
            ])
            ->emptyStateHeading(__('documents.partner_empty.heading'))
            ->emptyStateDescription(__('documents.partner_empty.description'));
    }
}
