<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticleResource\Pages;

use App\Domain\Knowledge\Queries\FolderTree;
use App\Filament\Resources\KnowledgeArticleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

/**
 * The list of the general knowledge articles. The header button opens the create screen (KB-01); the empty state of
 * the table offers the same action.
 *
 * Phase 19 turns it into a browsable library (D-01): the folder tree panel sits left of the table and the selection
 * lives in the URL (`?folder=<uuid>` or `?folder=none`). The folder, the tags and the archived switch combine.
 */
final class ListKnowledgeArticles extends ListRecords
{
    /** The value of `?folder=` for the articles without a folder (D-02). */
    private const string NO_FOLDER = 'none';

    protected static string $resource = KnowledgeArticleResource::class;

    /**
     * The selected folder: a folder id, 'none' or null for all articles. Never read directly, always through
     * selectedFolder(): the value comes from the URL and may be anything. The #[Url] binding keeps history, so the
     * back button walks the selections.
     */
    #[Url(history: true)]
    public ?string $folder = null;

    /**
     * The tree of this request, read once (two queries) and shared by the panel, the column, the filter and the actions.
     */
    private ?FolderTree $tree = null;

    /**
     * A Partner is refused with 403 before anything is read: the deny-all scope of the article would answer 404 or
     * an empty list first.
     */
    public function mount(): void
    {
        abort_unless(KnowledgeArticleResource::canAccess(), 403);

        parent::mount();
    }

    public function getTitle(): string
    {
        return __('knowledge.articles.navigation_label');
    }

    /**
     * A new selection starts the list on its first page.
     */
    public function updatedFolder(): void
    {
        $this->resetPage(pageName: $this->getTablePaginationPageName());
    }

    /**
     * The tree panel left of the list and the table with the folder chip above it (R-19-08-2): the two render hooks
     * and the table are those of ListRecords::content(), in their default order.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getTabsContentComponent(),
            Flex::make([
                View::make('filament.knowledge.folder-tree')
                    ->viewData(fn (): array => $this->treeViewData('panel'))
                    ->grow(false),
                Group::make([
                    View::make('filament.knowledge.folder-tree')
                        ->viewData(fn (): array => $this->treeViewData('chip')),
                    RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                    EmbeddedTable::make(),
                    RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
                ])->extraAttributes(['class' => 'kokpit-tree-main']),
            ])->from('lg'),
        ]);
    }

    /**
     * The resource table with the folder constraint of the selection (D-01).
     */
    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(function (Builder $query, bool $isResolvingRecord): Builder {
                $query->addSelect('knowledge_articles.folder_id');

                // A row action resolves its record by key and must find it whatever is selected.
                if ($isResolvingRecord) {
                    return $query;
                }

                $selected = $this->selectedFolder();

                if ($selected === self::NO_FOLDER) {
                    return $query->whereNull('knowledge_articles.folder_id');
                }

                // The folder and every folder below it (D-01); the ids come from the tree, never from the URL.
                return $selected === null
                    ? $query
                    : $query->whereIn('knowledge_articles.folder_id', $this->tree()->subtreeIds($selected));
            });
    }

    /**
     * @return array<CreateAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('knowledge.actions.create'))
                ->icon(Heroicon::OutlinedPlus),
        ];
    }

    /**
     * The folder tree of this request. Two queries, the first time it is asked for.
     */
    private function tree(): FolderTree
    {
        return $this->tree ??= FolderTree::load();
    }

    /**
     * What the URL selects, made safe: 'none' for the literal, the folder id when it is a UUID of a folder that
     * exists, else null (all articles). A malformed value never reaches a query (T-19-28).
     */
    private function selectedFolder(): ?string
    {
        if ($this->folder === self::NO_FOLDER) {
            return self::NO_FOLDER;
        }

        if ($this->folder !== null && Str::isUuid($this->folder) && $this->tree()->has($this->folder)) {
            return $this->folder;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function treeViewData(string $part): array
    {
        $tree = $this->tree();
        $selected = $this->selectedFolder();

        $children = [];

        foreach ($tree->nodes() as $node) {
            $children[$node['parentId'] ?? ''][] = $node;
        }

        // The folders above the selection stay expanded (UI-SPEC section 1).
        $ancestors = [];
        $parentId = $selected !== null && $selected !== self::NO_FOLDER ? $tree->node($selected)['parentId'] ?? null : null;

        while ($parentId !== null) {
            $ancestors[$parentId] = true;
            $parentId = $tree->node($parentId)['parentId'] ?? null;
        }

        $real = $selected !== null && $selected !== self::NO_FOLDER ? $selected : null;

        return [
            'part' => $part,
            'children' => $children,
            'ancestors' => $ancestors,
            'selected' => $selected,
            'selectedName' => match (true) {
                $selected === null => __('knowledge.folders.all'),
                $selected === self::NO_FOLDER => __('knowledge.folders.none'),
                default => (string) ($tree->node($selected)['name'] ?? ''),
            },
            'chipPath' => $real === null ? null : $tree->pathLabel($real),
        ];
    }
}
