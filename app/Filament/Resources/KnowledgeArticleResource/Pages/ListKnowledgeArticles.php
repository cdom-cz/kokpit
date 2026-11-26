<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticleResource\Pages;

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Actions\CreateFolder;
use App\Domain\Knowledge\Actions\DeleteFolder;
use App\Domain\Knowledge\Actions\FolderName;
use App\Domain\Knowledge\Actions\MoveFolder;
use App\Domain\Knowledge\Actions\RenameFolder;
use App\Domain\Knowledge\Exceptions\FolderMoveRefused;
use App\Domain\Knowledge\Exceptions\FolderNotEmpty;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeFolder;
use App\Domain\Knowledge\Queries\FolderTree;
use App\Domain\Shared\Models\Tag;
use App\Domain\Shared\Tags\TagType;
use App\Filament\Resources\KnowledgeArticleResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Cancel;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SpatieTagsColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    /** The option of the move form that stands for "no parent": a value no folder id can have. */
    private const string ROOT_OPTION = 'root';

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
                // The folder column needs the folder id, the tags column the tags of every row in one query.
                $query->addSelect('knowledge_articles.folder_id')->with('tags');

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
            })
            ->pushColumns([
                TextColumn::make('folder_path')
                    ->label(__('knowledge.columns.folder'))
                    ->state(fn (KnowledgeArticle $record): ?string => $record->folder_id === null ? null : $this->tree()->pathLabel((string) $record->folder_id))
                    ->color('gray')
                    ->lineClamp(1)
                    ->visibleFrom('md')
                    ->toggleable(),
                SpatieTagsColumn::make('tags')
                    ->label(__('knowledge.columns.tags'))
                    ->type(TagType::Knowledge->value)
                    ->limitList(3),
            ])
            ->pushFilters([$this->tagsFilter()])
            ->emptyStateHeading(fn (): string => match ($this->emptyStateKind()) {
                'filtered' => trans()->string('knowledge.empty.filtered_heading'),
                'folder' => trans()->string('knowledge.empty.folder_heading'),
                'unfiled' => trans()->string('knowledge.empty.unfiled_heading'),
                'archived' => trans()->string('knowledge.empty.archived_heading'),
                default => trans()->string('knowledge.empty.list_heading'),
            })
            ->emptyStateDescription(fn (): ?string => match ($this->emptyStateKind()) {
                'filtered' => trans()->string('knowledge.empty.filtered_body'),
                'folder' => trans()->string('knowledge.empty.folder_body'),
                'unfiled' => trans()->string('knowledge.empty.unfiled_body'),
                'archived' => null,
                default => trans()->string('knowledge.empty.list_description'),
            })
            ->emptyStateActions([
                $this->emptyStateCreateAction(),
                Action::make('resetFilters')
                    ->label(__('knowledge.empty.reset_filters'))
                    ->color('gray')
                    ->visible(fn (): bool => $this->emptyStateKind() === 'filtered')
                    ->action(function (): void {
                        // The folder is a property of the page, not a filter: it stays selected.
                        $this->resetTableFiltersForm();
                    }),
            ]);
    }

    /**
     * A new root folder (D-03, ORG-01).
     */
    public function createFolderAction(): Action
    {
        return Action::make('createFolder')
            ->label(__('knowledge.folders.new'))
            ->modalHeading(__('knowledge.folders.new'))
            ->modalSubmitActionLabel(__('knowledge.folders.create_submit'))
            ->successNotificationTitle(__('knowledge.folders.notifications.created'))
            ->schema([self::nameField()])
            ->action(function (array $data, Action $action): void {
                $this->forgetTree();

                $this->guarded($action, function () use ($data): void {
                    app(CreateFolder::class)->handle($this->actor(), $data['name'] ?? null, null);
                });

                $this->afterChange([]);
                $action->success();
            });
    }

    /**
     * A new folder inside the folder named by the `folder` argument.
     */
    public function createSubfolderAction(): Action
    {
        return Action::make('createSubfolder')
            ->label(__('knowledge.folders.new_subfolder'))
            ->modalHeading(__('knowledge.folders.new_subfolder'))
            ->modalSubmitActionLabel(__('knowledge.folders.create_submit'))
            ->successNotificationTitle(__('knowledge.folders.notifications.created'))
            ->schema([self::nameField()])
            ->action(function (array $data, array $arguments, Action $action): void {
                $this->forgetTree();

                $parent = $this->folderFromArguments($arguments);

                if ($parent === null) {
                    $this->folderMissing($action);
                }

                $this->guarded($action, function () use ($data, $parent): void {
                    app(CreateFolder::class)->handle($this->actor(), $data['name'] ?? null, $parent['id']);
                });

                $this->afterChange([$parent['id']]);
                $action->success();
            });
    }

    /**
     * Renames the folder named by the `folder` argument.
     */
    public function renameFolderAction(): Action
    {
        $action = Action::make('renameFolder')
            ->label(__('knowledge.folders.rename'))
            ->modalHeading(__('knowledge.folders.rename_heading'))
            ->modalSubmitActionLabel(__('knowledge.folders.rename'))
            ->successNotificationTitle(__('knowledge.folders.notifications.renamed'))
            ->schema([self::nameField()]);

        return $action
            ->fillForm(fn (): array => ['name' => $this->folderFromArguments($action->getArguments())['name'] ?? ''])
            ->action(function (array $data, array $arguments, Action $action): void {
                $this->forgetTree();

                $folder = $this->folderFromArguments($arguments);

                if ($folder === null) {
                    $this->folderMissing($action);
                }

                $this->guarded($action, function () use ($data, $folder): void {
                    app(RenameFolder::class)->handle($this->actor(), $folder['id'], $data['name'] ?? null);
                });

                $this->afterChange([]);
                $action->success();
            });
    }

    /**
     * Moves the folder named by the `folder` argument under another folder or to the root (D-03). The select disables
     * what cannot work (the folder itself, its subfolders and every target that would pass the depth cap); that is a
     * convenience, the Action decides again under the lock.
     */
    public function moveFolderAction(): Action
    {
        $action = Action::make('moveFolder')
            ->label(__('knowledge.folders.move'))
            ->modalHeading(__('knowledge.folders.move_heading'))
            ->modalSubmitActionLabel(__('knowledge.folders.move'))
            ->successNotificationTitle(__('knowledge.folders.notifications.moved'));

        return $action
            ->schema([
                Select::make('parent_id')
                    ->label(__('knowledge.folders.parent'))
                    ->searchable()
                    ->selectablePlaceholder(false)
                    ->required()
                    ->options(fn (): array => $this->moveOptions())
                    ->disableOptionWhen(fn (string $value): bool => $this->moveOptionDisabled($this->folderFromArguments($action->getArguments())['id'] ?? null, $value))
                    ->helperText(__('knowledge.folders.move_helper', ['max' => KnowledgeFolder::MAX_DEPTH])),
            ])
            ->fillForm(fn (): array => ['parent_id' => $this->folderFromArguments($action->getArguments())['parentId'] ?? self::ROOT_OPTION])
            ->action(function (array $data, array $arguments, Action $action): void {
                $this->forgetTree();

                $folder = $this->folderFromArguments($arguments);

                if ($folder === null) {
                    $this->folderMissing($action);
                }

                $target = $data['parent_id'] ?? null;
                $parentId = is_string($target) && $target !== '' && $target !== self::ROOT_OPTION ? $target : null;

                $this->guarded($action, function () use ($folder, $parentId): void {
                    app(MoveFolder::class)->handle($this->actor(), $folder['id'], $parentId);
                });

                // The selection stays; the tree opens up to the folder in its new place.
                $this->afterChange([$folder['id']]);
                $action->success();
            });
    }

    /**
     * Deletes the folder named by the `folder` argument. A folder that holds anything runs the action at once and
     * gets the refusal, so nobody confirms a delete that cannot happen (ORG-02).
     */
    public function deleteFolderAction(): Action
    {
        $action = Action::make('deleteFolder')
            ->label(__('knowledge.folders.delete'))
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('knowledge.folders.delete_description'))
            ->modalSubmitActionLabel(__('knowledge.folders.delete_submit'))
            ->successNotificationTitle(__('knowledge.folders.notifications.deleted'));

        return $action
            ->modalHeading(fn (): string => __('knowledge.folders.delete_heading', ['name' => $this->folderFromArguments($action->getArguments())['name'] ?? '']))
            ->modalHidden(fn (): bool => $this->folderHoldsAnything($action->getArguments()))
            ->action(function (array $arguments, Action $action): void {
                $this->forgetTree();

                $folder = $this->folderFromArguments($arguments);

                if ($folder === null) {
                    $this->folderMissing($action);
                }

                $this->guarded($action, function () use ($folder): void {
                    app(DeleteFolder::class)->handle($this->actor(), $folder['id']);
                });

                if ($this->folder === $folder['id']) {
                    $this->folder = null;
                    $this->resetPage(pageName: $this->getTablePaginationPageName());
                }

                $this->afterChange([]);
                $action->success();
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
                ->icon(Heroicon::OutlinedPlus)
                ->url(fn (): string => $this->createUrl()),
        ];
    }

    /**
     * The create screen, with the selected folder as the folder of the new article (the prefill is a truth of the
     * create screen itself).
     */
    private function createUrl(): string
    {
        $selected = $this->selectedFolder();

        return KnowledgeArticleResource::getUrl('create', $selected !== null && $selected !== self::NO_FOLDER ? ['folder' => $selected] : []);
    }

    /**
     * The "Nový článek" button of the empty states that have one: an empty list, an empty folder.
     */
    private function emptyStateCreateAction(): CreateAction
    {
        return CreateAction::make()
            ->label(__('knowledge.actions.create'))
            ->icon(Heroicon::OutlinedPlus)
            ->url(fn (): string => $this->createUrl())
            ->visible(fn (): bool => in_array($this->emptyStateKind(), ['list', 'folder'], true));
    }

    /**
     * Which empty state the table shows: a tag filter that matches nothing first, then the selected folder, then the
     * Phase 18 states of the archived switch and of the whole list.
     */
    private function emptyStateKind(): string
    {
        if (self::tagIds(data_get($this->getTableFilterState('tags'), 'values')) !== []) {
            return 'filtered';
        }

        $selected = $this->selectedFolder();

        if ($selected === self::NO_FOLDER) {
            return 'unfiled';
        }

        if ($selected !== null) {
            return 'folder';
        }

        return data_get($this->getTableFilterState('show_archived'), 'isActive', false) ? 'archived' : 'list';
    }

    /**
     * The knowledge tags of the filter: every tag chosen must be on the article (D-05). The options are the knowledge
     * tags only (D-04), and the query repeats the type, so a forged id of a client tag matches nothing (T-19-30).
     */
    private function tagsFilter(): SelectFilter
    {
        return SelectFilter::make('tags')
            ->label(__('knowledge.tags.label'))
            ->multiple()
            ->searchable()
            ->options(fn (): array => self::tagOptions())
            ->query(function (Builder $query, array $data): Builder {
                foreach (self::tagIds($data['values'] ?? []) as $tagId) {
                    $query->whereHas('tags', fn (Builder $tags): Builder => $tags
                        ->where('tags.id', $tagId)
                        ->where('tags.type', TagType::Knowledge->value));
                }

                return $query;
            })
            ->indicateUsing(function (array $data): array {
                $options = self::tagOptions();
                $names = [];

                foreach (self::tagIds($data['values'] ?? []) as $tagId) {
                    if (isset($options[$tagId])) {
                        $names[] = $options[$tagId];
                    }
                }

                return $names === [] ? [] : [__('knowledge.tags.filter_indicator', ['tags' => implode(', ', $names)])];
            });
    }

    /**
     * The names of the knowledge tags by id, in alphabetical order.
     *
     * @return array<string, string>
     */
    private static function tagOptions(): array
    {
        $options = [];

        foreach (Tag::getWithType(TagType::Knowledge->value)->pluck('name', 'id') as $id => $name) {
            $options[(string) $id] = (string) $name;
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }

    /**
     * The ids of a filter state that look like tag ids: the state comes from the browser, and a value that is not a
     * UUID must never reach the query.
     *
     * @return list<string>
     */
    private static function tagIds(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter($values, static fn (mixed $value): bool => is_string($value) && Str::isUuid($value))));
    }

    /**
     * The name field of the create and rename forms.
     */
    private static function nameField(): TextInput
    {
        return TextInput::make('name')
            ->label(__('knowledge.folders.name'))
            ->required()
            ->maxLength(FolderName::MAX_LENGTH)
            ->autofocus();
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function forgetTree(): void
    {
        $this->tree = null;
    }

    /**
     * The tree node of the `folder` argument of a row menu item. The id comes from the browser: anything but the id of
     * a folder that exists is null (T-19-29).
     *
     * @param  array<string, mixed>  $arguments
     * @return array{id: string, parentId: string|null, name: string, depth: int, height: int, path: list<string>, count: int, hasChildren: bool}|null
     */
    private function folderFromArguments(array $arguments): ?array
    {
        $id = $arguments['folder'] ?? null;

        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }

        return $this->tree()->node($id);
    }

    /**
     * Tells the Admin the folder is gone (another session deleted it, or the id was forged), reloads the tree on the
     * next render and ends the action.
     */
    private function folderMissing(Action $action): never
    {
        $this->forgetTree();

        Notification::make()
            ->warning()
            ->title(__('knowledge.folders.notifications.missing'))
            ->send();

        throw new Cancel;
    }

    /**
     * Runs a call of a folder Action and turns its refusals into what the Admin sees: a field error under the form
     * field (the modal stays open), a danger notification for a refused move or delete, the "gone" notice for a folder
     * that no longer exists.
     */
    private function guarded(Action $action, callable $call): void
    {
        try {
            $call();
        } catch (ValidationException $e) {
            // The pattern of ViewProject and ListApiTokens: the key of the field of the mounted action's form.
            $mapped = [];

            foreach ($e->errors() as $field => $messages) {
                $mapped["mountedActions.0.data.{$field}"] = $messages[0] ?? '';
            }

            throw ValidationException::withMessages($mapped);
        } catch (ModelNotFoundException) {
            $this->folderMissing($action);
        } catch (FolderMoveRefused $e) {
            Notification::make()
                ->danger()
                ->persistent()
                ->title(__('knowledge.folders.errors.move_title'))
                ->body(__('knowledge.folders.errors.'.$e->reason, ['max' => $e->maxDepth]))
                ->send();

            $action->halt();
        } catch (FolderNotEmpty $e) {
            Notification::make()
                ->danger()
                ->persistent()
                ->title(__('knowledge.folders.errors.delete_title'))
                ->body(__('knowledge.folders.errors.not_empty', ['contents' => self::contentsText($e->articleCount, $e->subfolderCount)]))
                ->send();

            $this->forgetTree();
            $action->cancel();
        }
    }

    /**
     * "1 článek (včetně archivovaných) a 2 podsložky": what keeps a folder from being deleted.
     */
    private static function contentsText(int $articles, int $folders): string
    {
        $parts = [];

        if ($articles > 0) {
            $parts[] = trans_choice('knowledge.folders.contents_articles', $articles, ['count' => $articles]);
        }

        if ($folders > 0) {
            $parts[] = trans_choice('knowledge.folders.contents_folders', $folders, ['count' => $folders]);
        }

        return count($parts) === 2
            ? __('knowledge.folders.contents_join', ['articles' => $parts[0], 'folders' => $parts[1]])
            : ($parts[0] ?? '');
    }

    /**
     * Whether the folder of the delete menu item holds a subfolder or an article, archived ones included: the tree
     * count leaves archived articles out, so the articles are asked again here.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function folderHoldsAnything(array $arguments): bool
    {
        $folder = $this->folderFromArguments($arguments);

        if ($folder === null) {
            return false;
        }

        return $folder['hasChildren'] || KnowledgeArticle::query()->withTrashed()->where('folder_id', $folder['id'])->exists();
    }

    /**
     * The targets of the move form: the root, then every folder as its path.
     *
     * @return array<string, string>
     */
    private function moveOptions(): array
    {
        $options = [self::ROOT_OPTION => __('knowledge.folders.root')];

        foreach ($this->tree()->nodes() as $node) {
            $options[$node['id']] = implode(' / ', $node['path']);
        }

        return $options;
    }

    /**
     * Whether a target of the move form cannot be chosen: the folder itself, a folder in its subtree or one that would
     * make the tree deeper than the cap. The root is always possible.
     */
    private function moveOptionDisabled(?string $folderId, string $value): bool
    {
        if ($folderId === null || $value === self::ROOT_OPTION) {
            return false;
        }

        return $this->tree()->moveRefusal($folderId, $value) !== null;
    }

    /**
     * After a change of the tree: reads it again for the next render and tells the nodes to open up to the given
     * folders (the new parent of a subfolder, the moved folder), so the result is visible without a click.
     *
     * @param  list<string>  $revealIds
     */
    private function afterChange(array $revealIds): void
    {
        $this->forgetTree();

        $open = [];

        foreach ($revealIds as $id) {
            for ($current = $id; $current !== null; $current = $this->tree()->node($current)['parentId'] ?? null) {
                $open[] = $current;
            }
        }

        if ($open !== []) {
            $this->dispatch('kokpit-tree-reveal', ids: array_values(array_unique($open)));
        }
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
            'maxDepth' => KnowledgeFolder::MAX_DEPTH,
        ];
    }
}
