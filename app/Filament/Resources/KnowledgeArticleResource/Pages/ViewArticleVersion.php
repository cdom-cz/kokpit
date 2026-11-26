<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticleResource\Pages;

use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Knowledge\Support\KnowledgeMarkdown;
use App\Filament\Resources\KnowledgeArticleResource;
use App\Filament\Resources\KnowledgeArticleResource\Concerns\ManagesVersionActions;
use App\Providers\LocalisationServiceProvider;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Livewire\Attributes\Locked;

/**
 * One stored version of an article, read-only (VER-02, D-08): the stored title and the stored body rendered by the one
 * renderer, with the callouts that apply. A redacted version shows its redacted body, because this page reads only
 * the stored row (D-12).
 *
 * The version is addressed by its number under the article. A number that is not all digits or names no version of
 * this article answers 404. A Partner is refused with 403 before the article is looked up.
 */
final class ViewArticleVersion extends Page
{
    use InteractsWithRecord;
    use ManagesVersionActions;

    /**
     * The longest number accepted: no article has a billion versions, and a bound keeps the cast safe.
     */
    private const int MAX_NUMBER_LENGTH = 9;

    protected static string $resource = KnowledgeArticleResource::class;

    protected string $view = 'filament.knowledge.version-view';

    /**
     * The number of the shown version. Locked and written only in `mount()`.
     */
    #[Locked]
    public int $versionNo;

    private ?KnowledgeArticleVersion $versionRow = null;

    public function mount(int|string $record, string $version): void
    {
        abort_unless(KnowledgeArticleResource::canAccess(), 403);

        // An archived article opens, as its own view does.
        $this->record = $this->resolveRecord($record);

        abort_unless(ctype_digit($version) && strlen($version) <= self::MAX_NUMBER_LENGTH, 404);

        $this->versionNo = (int) $version;
        $this->version();
    }

    public function getTitle(): string
    {
        return __('knowledge.versions.version_label', ['n' => $this->versionNo]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('knowledge.versions.actions.back'))
                ->color('gray')
                ->url(fn (): string => KnowledgeArticleResource::getUrl('versions', ['record' => $this->article()])),
            Action::make('compare_previous')
                ->label(__('knowledge.versions.actions.compare_previous'))
                ->color('gray')
                ->url(fn (): string => KnowledgeArticleResource::getUrl('compare', [
                    'record' => $this->article(),
                    'from' => $this->versionNo - 1,
                    'to' => $this->versionNo,
                ]))
                ->hidden(fn (): bool => $this->versionNo <= 1),
            $this->restoreVersionAction(fn (): int => $this->versionNo),
            $this->redactVersionAction(fn (): int => $this->versionNo)->outlined(),
        ];
    }

    /**
     * A redaction changed the stored row this page shows (and maybe the article): read both again, so the badge and
     * the callout of a redacted version appear in the same response.
     */
    protected function afterVersionRedacted(): void
    {
        $this->versionRow = null;
        $this->getRecord()->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $article = $this->article();
        $version = $this->version();

        return [
            'version' => $version,
            'title' => $version->title,
            'authorName' => $version->author?->name ?? '',
            'createdAt' => $version->created_at?->setTimezone('Europe/Prague')->format(LocalisationServiceProvider::DATE_TIME_FORMAT) ?? '',
            'isCurrent' => $version->version_no === $article->version_no,
            'currentNo' => $article->version_no,
            'currentUrl' => KnowledgeArticleResource::getUrl('version', ['record' => $article, 'version' => $article->version_no]),
            'redacted' => $version->redactions()->exists(),
            'archived' => $article->trashed(),
            'html' => app(KnowledgeMarkdown::class)->render($version->body_md),
        ];
    }

    private function article(): KnowledgeArticle
    {
        $article = $this->getRecord();
        assert($article instanceof KnowledgeArticle);

        return $article;
    }

    /**
     * The shown row with its body, read once per request.
     */
    private function version(): KnowledgeArticleVersion
    {
        if ($this->versionRow !== null) {
            return $this->versionRow;
        }

        $row = KnowledgeArticleVersion::query()
            ->where('article_id', $this->article()->getKey())
            ->where('version_no', $this->versionNo)
            ->with('author:id,name')
            ->first();

        abort_unless($row instanceof KnowledgeArticleVersion, 404);

        return $this->versionRow = $row;
    }
}
