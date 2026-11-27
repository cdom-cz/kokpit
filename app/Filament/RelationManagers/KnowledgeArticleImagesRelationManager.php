<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Actions\DeleteImageForGood;
use App\Domain\Knowledge\Exceptions\ArticleWriteFailed;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleImage;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesRelationManagerAccessRule;
use App\Filament\Support\DocumentColumns;
use App\Http\Controllers\KnowledgeAttachmentController;
use App\Providers\LocalisationServiceProvider;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Callout;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * The "Obrázky" tab of the article view screen (IMG-02, IMG-03, D-10, D-19): the images bound to the article, newest
 * first, each with a 48 x 48 thumbnail, its facts and where it is still used, and the one row action "Smazat navždy"
 * for the leaked-secret case. Admin only: a Partner never sees the tab or the action.
 *
 * The thumbnail state is the absolute application route of the image, so the column never asks a disk for a URL and
 * no cell can hold a bucket host, a presigned query or a storage path. Pending images are not listed: they belong to
 * no article yet.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The images of an article and their deletion are the Admin\'s alone; a Partner never sees one.')]
final class KnowledgeArticleImagesRelationManager extends RelationManager
{
    use EnforcesRelationManagerAccessRule;

    protected static string $relationship = 'images';

    /**
     * A relation manager on a view page is read-only by default, which would hide the delete action.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('knowledge.images.manager.heading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $this->withUsage($query))
            ->columns([
                ImageColumn::make('preview')
                    ->label(__('knowledge.images.manager.columns.preview'))
                    ->state(static fn (KnowledgeArticleImage $record): string => route(KnowledgeAttachmentController::ROUTE, ['attachment' => $record->getKey()]))
                    ->imageWidth(48)
                    ->imageHeight(48)
                    ->alt('')
                    ->extraImgAttributes(['style' => 'object-fit: cover; width: 48px; height: 48px;']),
                TextColumn::make('file_name')
                    ->label(__('knowledge.images.manager.columns.file'))
                    ->wrap(),
                TextColumn::make('dimensions')
                    ->label(__('knowledge.images.manager.columns.dimensions'))
                    ->state(static fn (KnowledgeArticleImage $record): string => __('knowledge.images.manager.dimensions', ['width' => $record->width, 'height' => $record->height])),
                TextColumn::make('size_bytes')
                    ->label(__('knowledge.images.manager.columns.size'))
                    ->formatStateUsing(static fn (int $state): string => DocumentColumns::humanSize($state)),
                TextColumn::make('created_at')
                    ->label(__('knowledge.images.manager.columns.uploaded_at'))
                    ->dateTime(LocalisationServiceProvider::DATE_TIME_FORMAT, 'Europe/Prague'),
                TextColumn::make('usage')
                    ->label(__('knowledge.images.manager.columns.usage'))
                    ->badge()
                    ->state(static fn (KnowledgeArticleImage $record): string => self::usage($record))
                    ->formatStateUsing(static fn (string $state): string => __('knowledge.images.manager.usage.'.$state))
                    ->color(static fn (string $state): string => match ($state) {
                        'current' => 'success',
                        'older' => 'gray',
                        default => 'warning',
                    }),
            ])
            ->defaultSort(static fn (Builder $query): Builder => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->headerActions([])
            ->toolbarActions([])
            ->recordActions([$this->deleteForGoodAction()])
            ->emptyStateHeading(__('knowledge.images.manager.empty_heading'))
            ->emptyStateDescription(__('knowledge.images.manager.empty_body'));
    }

    /**
     * Adds the two usage flags: "referenced by the current version" (a reference whose version has the number the
     * article is at) and "referenced by any version".
     *
     * @param  Builder<KnowledgeArticleImage>  $query
     * @return Builder<KnowledgeArticleImage>
     */
    private function withUsage(Builder $query): Builder
    {
        $owner = $this->getOwnerRecord();
        $versionNo = $owner instanceof KnowledgeArticle ? $owner->version_no : 0;

        return $query->withExists([
            'references as used_by_current' => static fn (Builder $references): Builder => $references
                ->whereHas('version', static fn (Builder $version): Builder => $version->where('version_no', $versionNo)),
            'references as used_by_any',
        ]);
    }

    /**
     * The key of the usage badge: current, older or unused.
     */
    private static function usage(KnowledgeArticleImage $record): string
    {
        if ((bool) $record->getAttribute('used_by_current')) {
            return 'current';
        }

        return (bool) $record->getAttribute('used_by_any') ? 'older' : 'unused';
    }

    /**
     * "Smazat navždy": the irreversible removal of one image with a required reason (D-19). The reason goes to the
     * Action and nowhere else; the only trace is the fact that one was recorded.
     */
    private function deleteForGoodAction(): Action
    {
        return Action::make('deleteForGood')
            ->label(trans()->string('knowledge.images.delete.action'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalIcon(Heroicon::OutlinedTrash)
            ->modalIconColor('danger')
            ->modalHeading(trans()->string('knowledge.images.delete.heading'))
            ->modalSubmitActionLabel(trans()->string('knowledge.images.delete.submit'))
            ->modalCancelActionLabel(trans()->string('knowledge.images.delete.cancel'))
            ->schema([
                Callout::make()
                    ->description(trans()->string('knowledge.images.delete.irreversible'))
                    ->color('danger')
                    ->icon(Heroicon::OutlinedShieldExclamation),
                Callout::make()
                    ->description(trans()->string('knowledge.images.delete.rotate'))
                    ->color('warning')
                    ->icon(Heroicon::OutlinedExclamationTriangle),
                Textarea::make('reason')
                    ->label(trans()->string('knowledge.images.delete.reason'))
                    ->helperText(trans()->string('knowledge.images.delete.reason_helper'))
                    ->rows(3)
                    ->required()
                    ->maxLength(DeleteImageForGood::MAX_REASON_LENGTH)
                    ->autofocus()
                    ->validationMessages([
                        'required' => trans()->string('knowledge.images.delete.errors.reason_required'),
                        'max' => trans()->string('knowledge.images.delete.errors.reason_too_long', ['max' => DeleteImageForGood::MAX_REASON_LENGTH]),
                    ]),
            ])
            ->action(function (array $data, Action $action, Model $record): void {
                assert($record instanceof KnowledgeArticleImage);

                $actor = auth()->user();
                assert($actor instanceof User);

                try {
                    app(DeleteImageForGood::class)->handle($actor, $record, $data['reason'] ?? null);
                } catch (ValidationException $invalid) {
                    $messages = [];

                    foreach ($invalid->errors() as $field => $errors) {
                        $messages['mountedActions.0.data.'.$field] = $errors;
                    }

                    throw ValidationException::withMessages($messages);
                } catch (ArticleWriteFailed $failed) {
                    report($failed);

                    Notification::make()
                        ->danger()
                        ->persistent()
                        ->title(trans()->string('knowledge.images.delete.notifications.failed'))
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(trans()->string('knowledge.images.delete.notifications.deleted'))
                    ->send();
            });
    }
}
