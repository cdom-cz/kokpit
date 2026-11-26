<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticleResource\Pages;

use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Knowledge\Support\VersionDiff;
use App\Filament\Resources\KnowledgeArticleResource;
use App\Providers\LocalisationServiceProvider;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * The difference between any two stored versions of an article (VER-04, D-09): a unified line diff of the body with
 * the title compared apart. The two versions are chosen in the URL (`?from=n&to=m`), so the comparison is linkable and
 * survives a reload; changing a select recomputes the diff live.
 *
 * The page reads exactly two rows with their bodies, only for the request that renders it. A redacted version shows
 * its redacted body, because the stored row is what is compared (D-12). Every number is checked on mount and on every
 * Livewire update: a value that is not all digits or names no version of this article answers 404, so a forged value
 * never reaches a query with a bad cast. A Partner is refused with 403 before the article is looked up.
 */
final class CompareArticleVersions extends Page
{
    use InteractsWithRecord;

    /**
     * The longest number accepted: no article has a billion versions, and a bound keeps the cast safe.
     */
    private const int MAX_NUMBER_LENGTH = 9;

    protected static string $resource = KnowledgeArticleResource::class;

    protected string $view = 'filament.knowledge.version-compare';

    /**
     * The two chosen version numbers, as the URL and the selects carry them. Untyped on purpose: both are
     * client-controlled, so a forged array or number must reach the check below (and answer 404) instead of a type
     * error.
     */
    #[Url]
    public mixed $from = null;

    #[Url]
    public mixed $to = null;

    /**
     * The version numbers of the article with their creation time, newest first; never a body.
     *
     * @var Collection<int, KnowledgeArticleVersion>|null
     */
    private ?Collection $versionList = null;

    public function mount(int|string $record): void
    {
        abort_unless(KnowledgeArticleResource::canAccess(), 403);

        $this->record = $this->resolveRecord($record);

        $this->normalise();
    }

    public function updatedFrom(): void
    {
        $this->from = (string) $this->number($this->from);
    }

    public function updatedTo(): void
    {
        $this->to = (string) $this->number($this->to);
    }

    public function getTitle(): string
    {
        return __('knowledge.diff.title');
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('view_to')
                ->label(fn (): string => __('knowledge.diff.view_to', ['n' => $this->chosen()[1]]))
                ->color('gray')
                ->url(fn (): string => KnowledgeArticleResource::getUrl('version', ['record' => $this->article(), 'version' => $this->chosen()[1]])),
            Action::make('back')
                ->label(__('knowledge.versions.actions.back'))
                ->color('gray')
                ->url(fn (): string => KnowledgeArticleResource::getUrl('versions', ['record' => $this->article()])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        [$from, $to] = $this->chosen();

        $options = [];

        foreach ($this->versions() as $version) {
            $options[$version->version_no] = __('knowledge.diff.option', [
                'n' => $version->version_no,
                'date' => $version->created_at?->setTimezone('Europe/Prague')->format(LocalisationServiceProvider::DATE_TIME_FORMAT) ?? '',
            ]);
        }

        $data = ['options' => $options, 'fromNo' => $from, 'toNo' => $to, 'sameVersion' => $from === $to];

        if ($from === $to) {
            return $data;
        }

        // The only read of a body on this page: the two chosen rows.
        $rows = KnowledgeArticleVersion::query()
            ->where('article_id', $this->article()->getKey())
            ->whereIn('version_no', [$from, $to])
            ->get(['id', 'article_id', 'version_no', 'title', 'body_md'])
            ->keyBy('version_no');

        $fromRow = $rows->get($from);
        $toRow = $rows->get($to);

        abort_unless($fromRow instanceof KnowledgeArticleVersion && $toRow instanceof KnowledgeArticleVersion, 404);

        return [
            ...$data,
            'result' => app(VersionDiff::class)->compare($fromRow->body_md, $toRow->body_md),
            'fromTitle' => $fromRow->title,
            'toTitle' => $toRow->title,
            'caption' => __('knowledge.diff.caption', ['from' => $from, 'to' => $to]),
        ];
    }

    private function article(): KnowledgeArticle
    {
        $article = $this->getRecord();
        assert($article instanceof KnowledgeArticle);

        return $article;
    }

    /**
     * The article's versions without their bodies, keyed by number, newest first, read once per request.
     *
     * @return Collection<int, KnowledgeArticleVersion>
     */
    private function versions(): Collection
    {
        return $this->versionList ??= KnowledgeArticleVersion::query()
            ->where('article_id', $this->article()->getKey())
            ->orderByDesc('version_no')
            ->get(['id', 'article_id', 'version_no', 'created_at'])
            ->keyBy('version_no');
    }

    /**
     * Fills the missing numbers and checks both: a missing `to` is the current version, a missing `from` is the
     * version before `to` (or `to` itself when it is version 1).
     */
    private function normalise(): void
    {
        $to = $this->to === null ? $this->article()->version_no : $this->number($this->to);

        $this->to = (string) $to;
        $this->from = (string) ($this->from === null ? max(1, $to - 1) : $this->number($this->from));
    }

    /**
     * The two chosen numbers, checked again for this render.
     *
     * @return array{int, int}
     */
    private function chosen(): array
    {
        return [$this->number($this->from), $this->number($this->to)];
    }

    /**
     * One chosen number: all digits and an existing version of this article, else 404.
     */
    private function number(mixed $value): int
    {
        $value = is_int($value) ? (string) $value : $value;

        abort_unless(is_string($value) && ctype_digit($value) && strlen($value) <= self::MAX_NUMBER_LENGTH, 404);

        $number = (int) $value;

        abort_unless($this->versions()->has($number), 404);

        return $number;
    }
}
