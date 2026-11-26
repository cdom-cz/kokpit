<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Actions\ArchiveArticle;
use App\Domain\Knowledge\Actions\ArticleClassification;
use App\Domain\Knowledge\Actions\RestoreArticle;
use App\Domain\Knowledge\Enums\ArticleKind;
use App\Domain\Knowledge\Guard\Severity;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Queries\FolderTree;
use App\Domain\Knowledge\Support\KnowledgeMarkdown;
use App\Domain\Knowledge\Support\MarkdownNormaliser;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Database\CzechCollation;
use App\Domain\Shared\Models\Tag;
use App\Domain\Shared\Tags\TagType;
use App\Filament\Concerns\EnforcesResourceAccessRule;
use App\Filament\Concerns\InKnowledgeGroup;
use App\Filament\RelationManagers\KnowledgeArticleHistoryRelationManager;
use App\Filament\Resources\KnowledgeArticleResource\Concerns\SecretGuardForm;
use App\Filament\Resources\KnowledgeArticleResource\Pages\CompareArticleVersions;
use App\Filament\Resources\KnowledgeArticleResource\Pages\CreateKnowledgeArticle;
use App\Filament\Resources\KnowledgeArticleResource\Pages\EditKnowledgeArticle;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ListArticleVersions;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ListKnowledgeArticles;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ViewArticleVersion;
use App\Filament\Resources\KnowledgeArticleResource\Pages\ViewKnowledgeArticle;
use App\Providers\LocalisationServiceProvider;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use Livewire\Component as LivewireComponent;

/**
 * The Admin screens of the general knowledge articles: the list with the archived switch and the view screen that
 * renders the body through the one renderer (KB-02, KB-03, D-06, D-13).
 *
 * This resource lists only articles of the kind General; incidents and retrospectives get their own resources in a
 * later phase. Archiving is a soft delete, so an archived article stays reachable on its view URL. There is no
 * force-delete, no bulk action and no table search here.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Knowledge articles are internal notes of the Admin; client sharing arrives in Phase 23.')]
final class KnowledgeArticleResource extends Resource
{
    use EnforcesResourceAccessRule;
    use InKnowledgeGroup;

    protected static ?string $model = KnowledgeArticle::class;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('knowledge.articles.navigation_label');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedBookOpen;
    }

    public static function getModelLabel(): string
    {
        return __('knowledge.articles.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('knowledge.articles.plural_model_label');
    }

    /**
     * Archived articles stay reachable: the archived switch decides what the list shows. Only the general kind
     * belongs to this resource.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->where('kind', ArticleKind::General->value);
    }

    /**
     * An archived article opens by its URL.
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class])
            ->where('kind', ArticleKind::General->value);
    }

    /**
     * The form of the create screen and of the edit screen: the title, the tabs Psaní and Náhled and the
     * sensitive toggle, in one column (KB-01, D-06, D-07).
     *
     * The body is the only field the preview reads. It is not live: typing sends no request and the text travels
     * with the next call, which is the tab switch, a save or the save-anyway button.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                // The secret-guard feedback of a refused save, danger first (D-01, D-03). Each callout shows only the
                // rule and the line of its findings, never the matched text.
                self::guardCallout(Severity::Blocking, 'danger', Heroicon::OutlinedShieldExclamation, 'blocking'),
                self::guardCallout(Severity::Ambiguous, 'warning', Heroicon::OutlinedExclamationTriangle, 'ambiguous'),
                TextInput::make('title')
                    ->label(__('knowledge.fields.title'))
                    ->required()
                    ->maxLength(KnowledgeArticle::MAX_TITLE_LENGTH)
                    ->validationMessages(['required' => __('knowledge.errors.title_required')])
                    ->autofocus(static fn (LivewireComponent $livewire): bool => $livewire instanceof CreateKnowledgeArticle),
                Tabs::make()
                    ->key('editor')
                    ->livewireProperty('activeTab')
                    ->tabs([
                        'write' => Tab::make(__('knowledge.tabs.write'))
                            ->schema([
                                MarkdownEditor::make('body')
                                    ->label(__('knowledge.fields.body'))
                                    // The toolbar of D-07. The task-list button is added by resources/js/knowledge-editor.js
                                    // after the ordered-list button: Filament's toolbar has no such name.
                                    ->toolbarButtons([
                                        ['bold', 'italic', 'link'],
                                        ['heading'],
                                        ['blockquote', 'codeBlock', 'bulletList', 'orderedList'],
                                        ['table'],
                                        ['undo', 'redo'],
                                    ])
                                    ->fileAttachments(false)
                                    // A changed body drops the refusal: the next save scans the new text again (SEC-03).
                                    ->afterStateUpdated(static function (LivewireComponent $livewire): void {
                                        if ($livewire instanceof SecretGuardForm) {
                                            $livewire->clearGuardFindings();
                                        }
                                    })
                                    ->maxLength(KnowledgeArticle::MAX_BODY_LENGTH)
                                    ->minHeight('24rem')
                                    ->extraAttributes(['data-kokpit-task-list' => __('knowledge.editor.task_list')]),
                            ]),
                        'preview' => Tab::make(__('knowledge.tabs.preview'))
                            ->schema([
                                Html::make(static fn (Get $get): HtmlString => self::previewHtml($get('body'))),
                            ]),
                    ]),
                // Shown while the tab roundtrip runs: an inactive tab renders nothing, so the indicator sits outside it.
                Html::make(static fn (): HtmlString => self::previewLoadingIndicator()),
                self::classificationSection(),
                Toggle::make('sensitive')
                    ->label(__('knowledge.fields.sensitive'))
                    ->helperText(__('knowledge.fields.sensitive_helper')),
            ]);
    }

    /**
     * The Zařazení section: the folder, the knowledge tags and the client and project links of the article (ORG-03,
     * ORG-04, D-04, D-06). The tags are the plain Filament tags input bound to the form state, not the Spatie plugin
     * input: the plugin writes tags on its own, outside the save transaction, while SaveArticle must write them
     * together with the content. Only the client select is live (it clears the project); every other field of the
     * section, like the editor, sends no request while the Admin types.
     */
    private static function classificationSection(): Section
    {
        return Section::make(__('knowledge.links.section'))
            ->columns(['md' => 2])
            ->schema([
                Select::make('folder_id')
                    ->label(__('knowledge.links.folder'))
                    ->placeholder(__('knowledge.links.folder_placeholder'))
                    ->options(static function (): array {
                        $tree = FolderTree::load();
                        $options = [];

                        foreach ($tree->nodes() as $node) {
                            $options[$node['id']] = (string) $tree->pathLabel($node['id']);
                        }

                        return $options;
                    })
                    ->searchable()
                    ->native(false),
                TagsInput::make('tags')
                    ->label(__('knowledge.tags.label'))
                    ->helperText(__('knowledge.tags.helper'))
                    // Only the knowledge vocabulary is suggested; a client, project or task tag never shows up here.
                    ->suggestions(static fn (): array => Tag::getWithType(TagType::Knowledge->value)
                        ->pluck('name')
                        ->map(static fn (mixed $name): string => (string) $name)
                        ->values()
                        ->all())
                    ->splitKeys(['Enter', ','])
                    ->nestedRecursiveRules(['max:'.ArticleClassification::MAX_TAG_LENGTH]),
                Select::make('client_id')
                    ->label(__('knowledge.links.client'))
                    ->placeholder(__('knowledge.links.client_placeholder'))
                    ->options(static fn (?KnowledgeArticle $record, Get $get): array => self::classificationClientOptions($record, $get('client_id')))
                    ->searchable()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(static function (Get $get, Set $set): void {
                        self::classificationClientChanged($get, $set);
                    }),
                Select::make('project_id')
                    ->label(__('knowledge.links.project'))
                    ->placeholder(__('knowledge.links.project_placeholder'))
                    ->disabled(static fn (Get $get): bool => self::classificationId($get('client_id')) === null)
                    ->helperText(static fn (Get $get): ?string => self::classificationId($get('client_id')) === null
                        ? trans()->string('knowledge.links.project_needs_client')
                        : null)
                    ->options(static fn (?KnowledgeArticle $record, Get $get): array => self::classificationProjectOptions(
                        $record,
                        self::classificationId($get('client_id')),
                        $get('project_id'),
                    ))
                    ->searchable()
                    ->native(false),
            ]);
    }

    /**
     * A project of another client is cleared when the client changes or is cleared (D-06).
     */
    private static function classificationClientChanged(Get $get, Set $set): void
    {
        $projectId = self::classificationId($get('project_id'));

        if ($projectId === null) {
            return;
        }

        $clientId = self::classificationId($get('client_id'));

        if ($clientId === null || ! Project::query()->withTrashed()->whereKey($projectId)->where('client_id', $clientId)->exists()) {
            $set('project_id', null);
        }
    }

    /**
     * A non-empty string is an id; anything else is "nothing chosen".
     */
    private static function classificationId(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The clients an article may be linked to: not archived, in Czech order. The stored client stays offered while it
     * is the chosen one, even when it was archived since.
     *
     * @return array<string, string>
     */
    private static function classificationClientOptions(?KnowledgeArticle $record, mixed $current): array
    {
        $options = [];

        foreach (CzechCollation::orderBy(Client::query(), 'clients.name')->orderBy('clients.id')->get(['clients.id', 'clients.name']) as $client) {
            $options[(string) $client->id] = (string) $client->name;
        }

        $stored = $record?->client_id;

        if ($stored !== null && $current === $stored && ! array_key_exists($stored, $options)) {
            $name = Client::query()->withTrashed()->whereKey($stored)->value('name');

            if (is_string($name)) {
                $options[$stored] = $name;
            }
        }

        return $options;
    }

    /**
     * The selectable projects of the chosen client as "KEY · name"; none without a client. The stored project stays
     * offered while it is the chosen one.
     *
     * @return array<string, string>
     */
    private static function classificationProjectOptions(?KnowledgeArticle $record, ?string $clientId, mixed $current): array
    {
        $options = [];

        if ($clientId === null) {
            return $options;
        }

        $query = Project::query()->selectable()->where('projects.client_id', $clientId);

        foreach (CzechCollation::orderBy($query, 'projects.name')->orderBy('projects.id')->get(['projects.id', 'projects.key', 'projects.name']) as $project) {
            $options[(string) $project->id] = $project->key.' · '.$project->name;
        }

        $stored = $record?->project_id;

        if ($stored !== null && $current === $stored && ! array_key_exists($stored, $options)) {
            $project = Project::query()->withTrashed()->whereKey($stored)->where('client_id', $clientId)->first(['id', 'key', 'name']);

            if ($project instanceof Project) {
                $options[$stored] = $project->key.' · '.$project->name;
            }
        }

        return $options;
    }

    /**
     * One callout of the secret guard: visible while the page reports findings of its severity, announced to assistive
     * technology and focusable so the page can bring it into view. The finding lines come from the page.
     *
     * @param  'blocking'|'ambiguous'  $kind
     */
    private static function guardCallout(Severity $severity, string $color, Heroicon $icon, string $kind): Callout
    {
        return Callout::make(trans()->string('knowledge.guard.'.$kind.'_heading'))
            ->description(trans()->string('knowledge.guard.'.$kind.'_body'))
            ->color($color)
            ->icon($icon)
            ->extraAttributes(['role' => 'alert', 'data-kokpit-guard' => '', 'tabindex' => '-1'])
            ->footer([
                View::make('filament.knowledge.guard-findings')
                    ->viewData(static fn (LivewireComponent $livewire): array => [
                        'lines' => $livewire instanceof SecretGuardForm ? $livewire->guardFindingLines($severity) : [],
                    ]),
            ])
            ->visible(static fn (LivewireComponent $livewire): bool => $livewire instanceof SecretGuardForm && $livewire->hasGuardFindings($severity));
    }

    /**
     * The preview of an unsaved body: the normalised text, as SaveArticle will store it, rendered by the one renderer
     * inside the prose view of the view screen, so the preview and the saved article are identical (D-06). It never
     * scans for secrets and never writes.
     */
    public static function previewHtml(mixed $body): HtmlString
    {
        $text = is_string($body) ? $body : '';
        $markdown = app(KnowledgeMarkdown::class);

        // The input is bounded before anything parses it: the normaliser runs a full CommonMark parse itself, so a text
        // over the renderer's limit goes to the renderer unnormalised, whose plain branch shows it escaped without
        // parsing. Such a text can never be stored (ArticleInput refuses it), so parity with the view screen is intact.
        $html = mb_strlen($text) > KnowledgeMarkdown::MAX_INPUT_LENGTH
            ? $markdown->render($text)
            : $markdown->render(app(MarkdownNormaliser::class)->normalise($text));

        return new HtmlString(view('filament.knowledge.prose', [
            'html' => $html,
            'emptyText' => __('knowledge.empty.preview'),
            'emptyActionUrl' => null,
            'emptyActionLabel' => null,
        ])->render());
    }

    /**
     * The Filament loading indicator that shows while a tab switch is on its way to the server. Livewire hides it
     * until the request starts; inline styles only, because the panel theme does not compile utility classes.
     */
    private static function previewLoadingIndicator(): HtmlString
    {
        return new HtmlString(
            '<div wire:loading.flex wire:target="activeTab" role="status" style="justify-content:center;padding:0.5rem 0;">'
            .Blade::render('<x-filament::loading-indicator style="width:1.5rem;height:1.5rem;" />')
            .'</div>',
        );
    }

    public static function table(Table $table): Table
    {
        return $table
            // The body is large and private: the list never loads it. The archived switch is applied here and not in
            // the filter, because a filter callback runs only while the filter is on, and the default is the other way.
            ->modifyQueryUsing(static function (Builder $query, HasTable $livewire, bool $isResolvingRecord): Builder {
                $query->select(['id', 'kind', 'title', 'version_no', 'edited_at', 'sensitive', 'deleted_at']);

                $showArchived = (bool) data_get($livewire->getTableFilterState('show_archived'), 'isActive', false);

                return $showArchived || $isResolvingRecord ? $query : $query->whereNull('deleted_at');
            })
            ->columns([
                TextColumn::make('title')
                    ->label(__('knowledge.columns.title'))
                    ->weight(FontWeight::SemiBold)
                    ->lineClamp(1)
                    ->tooltip(static fn (string $state): string => $state)
                    ->color(static fn (KnowledgeArticle $record): ?string => $record->trashed() ? 'gray' : null),
                TextColumn::make('archived')
                    ->label('')
                    ->state(static fn (KnowledgeArticle $record): ?string => $record->trashed() ? trans()->string('knowledge.badges.archived') : null)
                    ->badge()
                    ->color('gray'),
                TextColumn::make('version_no')
                    ->label(__('knowledge.columns.version'))
                    ->alignEnd(),
                TextColumn::make('edited_at')
                    ->label(__('knowledge.columns.edited_at'))
                    ->dateTime()
                    ->sortable(),
                IconColumn::make('sensitive')
                    ->label(__('knowledge.columns.sensitive'))
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedLockClosed)
                    ->trueColor('warning')
                    ->falseIcon(false)
                    ->tooltip(static fn (bool $state): ?string => $state ? trans()->string('knowledge.columns.sensitive_tooltip') : null),
            ])
            ->filters([
                Filter::make('show_archived')
                    ->label(__('knowledge.filters.show_archived'))
                    ->toggle(),
            ])
            ->recordActions([
                self::editAction(EditAction::make())->iconButton(),
                self::archiveAction()->iconButton(),
                self::restoreAction()->iconButton(),
            ])
            ->recordUrl(static fn (KnowledgeArticle $record): string => self::getUrl('view', ['record' => $record]))
            ->defaultSort('edited_at', 'desc')
            ->emptyStateHeading(static fn (HasTable $livewire): string => data_get($livewire->getTableFilterState('show_archived'), 'isActive', false)
                ? trans()->string('knowledge.empty.archived_heading')
                : trans()->string('knowledge.empty.list_heading'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('knowledge.actions.create'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(static fn (HasTable $livewire): bool => ! (bool) data_get($livewire->getTableFilterState('show_archived'), 'isActive', false)),
            ])
            ->emptyStateDescription(static fn (HasTable $livewire): ?string => data_get($livewire->getTableFilterState('show_archived'), 'isActive', false)
                ? null
                : trans()->string('knowledge.empty.list_description'));
    }

    /**
     * The read-only screen of one article: the meta line, then the body rendered by the one renderer.
     */
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.knowledge.article-meta')
                ->columnSpanFull()
                ->viewData(static fn (KnowledgeArticle $record): array => [
                    'version' => $record->version_no,
                    'editedAt' => $record->edited_at->setTimezone('Europe/Prague')->format(LocalisationServiceProvider::DATE_TIME_FORMAT),
                    'sensitive' => $record->sensitive,
                    'archived' => $record->trashed(),
                    ...self::classificationMeta($record),
                ]),
            Section::make()
                ->columnSpanFull()
                ->schema([
                    View::make('filament.knowledge.prose')
                        ->viewData(static fn (KnowledgeArticle $record): array => [
                            'html' => app(KnowledgeMarkdown::class)->render((string) $record->body_md),
                            'emptyText' => __('knowledge.empty.body'),
                            // An empty body invites the Admin to write one; an archived article has no edit screen.
                            'emptyActionUrl' => $record->trashed() ? null : self::getUrl('edit', ['record' => $record]),
                            'emptyActionLabel' => __('knowledge.actions.edit'),
                        ]),
                ]),
        ]);
    }

    /**
     * What the second meta row of the view screen shows: the folder path with its list link, the client and project
     * with their links, and the knowledge tag names. An archived client or project stays shown, as it stays linked.
     * A value that is not set is null (the tags: empty).
     *
     * @return array{folderPath: string|null, folderUrl: string|null, clientName: string|null, clientUrl: string|null, projectLabel: string|null, projectUrl: string|null, tags: list<string>}
     */
    private static function classificationMeta(KnowledgeArticle $record): array
    {
        $record->loadMissing([
            'client' => static fn ($query) => $query->withTrashed(),
            'project' => static fn ($query) => $query->withTrashed(),
            'tags',
        ]);

        $folderPath = $record->folder_id === null ? null : FolderTree::load()->pathLabel($record->folder_id);
        $client = $record->client;
        $project = $record->project;

        return [
            'folderPath' => $folderPath,
            'folderUrl' => $folderPath === null ? null : self::getUrl('index', ['folder' => $record->folder_id]),
            'clientName' => $client?->name,
            'clientUrl' => $client === null ? null : ClientResource::getUrl('view', ['record' => $client]),
            'projectLabel' => $project === null ? null : $project->key.' · '.$project->name,
            'projectUrl' => $project === null ? null : ProjectResource::getUrl('view', ['record' => $project]),
            'tags' => $record->tags
                ->filter(static fn (Tag $tag): bool => $tag->type === TagType::Knowledge->value)
                ->map(static fn (Tag $tag): string => (string) $tag->name)
                ->values()
                ->all(),
        ];
    }

    /**
     * The edit action of a row and of the view header (KB-02): a link to the own edit screen, never a modal, so the
     * only write path stays SaveArticle::update(). An archived article has no edit screen (restore first), so
     * the action is hidden for it. The caller adds `iconButton()` for a row; the header shows the label.
     */
    public static function editAction(EditAction $action): EditAction
    {
        return $action
            ->label(__('knowledge.actions.edit'))
            ->tooltip(__('knowledge.actions.edit'))
            ->url(static fn (Model $record): string => self::getUrl('edit', ['record' => $record]))
            ->hidden(static fn (Model $record): bool => $record instanceof KnowledgeArticle && $record->trashed());
    }

    /**
     * The archive action of a row and of the view header (KB-07, D-13): a confirmation first, then the one write path
     * of the archive. The step is reversible, so the confirmation is neutral and not a danger prompt. The caller
     * adds `iconButton()` for a row; the header shows the label.
     */
    public static function archiveAction(): Action
    {
        return Action::make('archive')
            ->label(__('knowledge.actions.archive'))
            ->tooltip(__('knowledge.actions.archive'))
            ->icon(Heroicon::OutlinedArchiveBox)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('knowledge.actions.archive_heading'))
            ->modalDescription(__('knowledge.actions.archive_description'))
            ->modalSubmitActionLabel(__('knowledge.actions.archive'))
            ->modalIcon(Heroicon::OutlinedArchiveBox)
            ->successNotificationTitle(__('knowledge.notifications.archived'))
            ->visible(static fn (Model $record): bool => $record instanceof KnowledgeArticle && ! $record->trashed())
            ->action(static function (Model $record, Action $action): void {
                assert($record instanceof KnowledgeArticle);

                app(ArchiveArticle::class)->handle(self::actor(), $record);

                $action->success();
            });
    }

    /**
     * The restore action of a row and of the view header (KB-07, D-13): no confirmation, because nothing is lost. It is
     * offered for an archived article only. The caller adds `iconButton()` for a row; the header shows the label.
     */
    public static function restoreAction(): Action
    {
        return Action::make('restore')
            ->label(__('knowledge.actions.restore'))
            ->tooltip(__('knowledge.actions.restore'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->successNotificationTitle(__('knowledge.notifications.restored'))
            ->visible(static fn (Model $record): bool => $record instanceof KnowledgeArticle && $record->trashed())
            ->action(static function (Model $record, Action $action): void {
                assert($record instanceof KnowledgeArticle);

                app(RestoreArticle::class)->handle(self::actor(), $record);

                $action->success();
            });
    }

    /**
     * The signed-in user behind a screen action; the Action authorises again inside itself.
     */
    private static function actor(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new InvalidArgumentException('The knowledge screens need a signed-in user.');
        }

        return $user;
    }

    public static function getRelations(): array
    {
        return [
            KnowledgeArticleHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeArticles::route('/'),
            'create' => CreateKnowledgeArticle::route('/create'),
            'view' => ViewKnowledgeArticle::route('/{record}'),
            'edit' => EditKnowledgeArticle::route('/{record}/edit'),
            // The static compare path is registered before the parameter path, and ViewArticleVersion answers 404 for
            // a version that is not all digits, so the order is not the only guard (R-19-06-1).
            'versions' => ListArticleVersions::route('/{record}/versions'),
            'compare' => CompareArticleVersions::route('/{record}/versions/compare'),
            'version' => ViewArticleVersion::route('/{record}/versions/{version}'),
        ];
    }

    /**
     * The sub-navigation of every screen of one article: Zobrazit, Upravit and Verze (VER-02, D-08). An archived
     * article has no edit screen (its URL answers 404), so Upravit is left out for it.
     *
     * @return array<NavigationItem|NavigationGroup>
     */
    public static function getRecordSubNavigation(Page $page): array
    {
        $record = $page->getSubNavigationParameters()['record'] ?? null;
        $archived = $record instanceof KnowledgeArticle && $record->trashed();

        return $page->generateNavigationItems(array_values(array_filter([
            ViewKnowledgeArticle::class,
            $archived ? null : EditKnowledgeArticle::class,
            ListArticleVersions::class,
        ])));
    }
}
