<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticleResource\Concerns;

use App\Domain\Identity\Models\User;
use App\Domain\Knowledge\Actions\RedactionLines;
use App\Domain\Knowledge\Actions\RedactVersion;
use App\Domain\Knowledge\Actions\RestoreVersion;
use App\Domain\Knowledge\Actions\SaveOutcome;
use App\Domain\Knowledge\Enums\RedactionScope;
use App\Domain\Knowledge\Exceptions\ArticleWriteFailed;
use App\Domain\Knowledge\Exceptions\SecretGuardRefused;
use App\Domain\Knowledge\Exceptions\StaleArticle;
use App\Domain\Knowledge\Guard\SecretFinding;
use App\Domain\Knowledge\Guard\Severity;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Models\KnowledgeArticleVersion;
use App\Domain\Knowledge\Support\MarkdownNormaliser;
use App\Domain\Knowledge\Support\VersionDiff;
use App\Domain\Knowledge\Support\VersionDiffResult;
use App\Filament\Resources\KnowledgeArticleResource;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * The restore and the redaction of a stored version as modal actions of the history screens (VER-03, VER-05, D-11 to
 * D-13), shared by the versions table and the version view.
 *
 * Restore: the modal shows the diff of the current text against the chosen version. The version number and the
 * redaction number of the article are taken in `mountUsing()` from a fresh read, never from the browser, and handed to
 * {@see RestoreVersion}, so an article that moved on (or whose current version was redacted) after the modal opened
 * is refused as stale. Every refusal ends the call with `halt()`: Filament then keeps the modal mounted. A refused
 * restore stores the rule and the line of each finding (never any text) and, only for an ambiguous-only refusal, the
 * sha256 of the normalised body that was refused. The acknowledgment of the next call is recomputed from the stored
 * version read now, so it holds for that very text only and for this one restore.
 *
 * Redaction: the modal reads the stored version, shows it with line numbers as escaped plain text and hands the scope,
 * the line list and the reason to {@see RedactVersion}. The reason is validated there, its length is stored and its
 * text is dropped; this class never copies it into a property, a notification or a message. A field error comes back
 * keyed `mountedActions.0.data.<field>` so it lands under its field with the modal still open.
 *
 * `$restoreVersionNo` names the version of whichever of the two modals is open; both set it in `mountUsing()`.
 * The five properties are locked: only the server writes them.
 */
trait ManagesVersionActions
{
    /**
     * Lines of the stored version shown at most in the redaction preview.
     */
    private const int REDACTION_PREVIEW_MAX_LINES = 2000;

    /**
     * The version the open restore or redaction modal is about. Written only in `mountUsing()`.
     */
    #[Locked]
    public ?int $restoreVersionNo = null;

    /**
     * The version number of the article when the restore modal was opened.
     */
    #[Locked]
    public ?int $restoreExpectedVersion = null;

    /**
     * The redaction number of the article when the restore modal was opened.
     */
    #[Locked]
    public ?int $restoreExpectedRedactionNo = null;

    /**
     * The findings of the last refused restore: rule key, line and severity key; never any matched text.
     *
     * @var list<array{rule: string, line: int, severity: string}>
     */
    #[Locked]
    public array $restoreFindings = [];

    /**
     * The sha256 of the normalised body of an acknowledgeable refusal, or null.
     */
    #[Locked]
    public ?string $restoreAcknowledgeableHash = null;

    /**
     * The article read in this request (never persisted by Livewire).
     */
    protected ?KnowledgeArticle $versionActionArticleCache = null;

    abstract public function getRecord(): Model;

    /**
     * Runs after a redaction succeeded, so a page that shows a stored version can read it again.
     */
    protected function afterVersionRedacted(): void {}

    /**
     * "Obnovit tuto verzi": the diff of the current text against the chosen version, then a new version.
     *
     * @param  Closure(KnowledgeArticleVersion): int  $versionNo  the chosen version number, from the table record or the page
     */
    protected function restoreVersionAction(Closure $versionNo): Action
    {
        return Action::make('restoreVersion')
            ->label(trans()->string('knowledge.restore.action'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->hidden(function (Action $action) use ($versionNo): bool {
                $article = $this->versionActionArticle();

                return $article->trashed() || $this->chosenVersionNo($action, $versionNo) === $article->version_no;
            })
            ->modalWidth(Width::FiveExtraLarge)
            ->modalIcon(Heroicon::OutlinedArrowUturnLeft)
            ->modalHeading(fn (Action $action): string => trans()->string('knowledge.restore.heading', ['from' => $this->chosenVersionNo($action, $versionNo)]))
            ->modalDescription(fn (Action $action): string => trans()->string('knowledge.restore.description', [
                'from' => $this->chosenVersionNo($action, $versionNo),
                'next' => $this->versionActionArticle()->version_no + 1,
            ]))
            ->modalSubmitActionLabel(fn (): string => trans()->string($this->restoreAcknowledgeableHash !== null ? 'knowledge.restore.submit_anyway' : 'knowledge.restore.submit'))
            ->modalCancelActionLabel(trans()->string('knowledge.restore.cancel'))
            // Nothing to submit when the text is identical or an unambiguous secret refuses the restore (the modal's own
            // submit action arrives as $action).
            ->modalSubmitAction(fn (Action $action): Action|false => $this->restoreIsIdentical() || $this->restoreHasFindings(Severity::Blocking) ? false : $action)
            ->mountUsing(function (Action $action) use ($versionNo): void {
                $article = $this->versionActionArticle(fresh: true);

                $this->restoreVersionNo = $this->chosenVersionNo($action, $versionNo);
                $this->restoreExpectedVersion = $article->version_no;
                $this->restoreExpectedRedactionNo = $article->redaction_no;
                $this->clearRestoreRefusal();
            })
            ->schema([
                Callout::make(trans()->string('knowledge.restore.blocking_heading'))
                    ->description(trans()->string('knowledge.restore.blocking_body'))
                    ->color('danger')
                    ->icon(Heroicon::OutlinedShieldExclamation)
                    ->extraAttributes(['role' => 'alert', 'data-kokpit-guard' => '', 'tabindex' => '-1'])
                    ->footer([
                        View::make('filament.knowledge.guard-findings')
                            ->viewData(fn (): array => ['lines' => $this->restoreFindingLines(Severity::Blocking)]),
                        Action::make('redactInstead')
                            ->label(trans()->string('knowledge.restore.redact_link'))
                            ->link()
                            ->color('danger')
                            ->action(fn () => $this->replaceMountedAction('redactVersion', [], $this->mountedActions[0]['context'] ?? [])),
                    ])
                    ->visible(fn (): bool => $this->restoreHasFindings(Severity::Blocking)),
                Callout::make(trans()->string('knowledge.restore.ambiguous_heading'))
                    ->description(trans()->string('knowledge.restore.ambiguous_body'))
                    ->color('warning')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->extraAttributes(['role' => 'alert', 'data-kokpit-guard' => '', 'tabindex' => '-1'])
                    ->footer([
                        View::make('filament.knowledge.guard-findings')
                            ->viewData(fn (): array => ['lines' => $this->restoreFindingLines(Severity::Ambiguous)]),
                    ])
                    ->visible(fn (): bool => ! $this->restoreHasFindings(Severity::Blocking) && $this->restoreHasFindings(Severity::Ambiguous)),
                Text::make(trans()->string('knowledge.restore.identical'))
                    ->visible(fn (): bool => $this->restoreIsIdentical()),
                View::make('filament.knowledge.version-diff')
                    ->viewData(fn (): array => $this->restoreDiffData())
                    // An unambiguous secret in the chosen version: the refusal names rule and line only, so the diff, which
                    // would print the stored line, is not shown until the version is redacted.
                    ->visible(fn (): bool => ! $this->restoreIsIdentical() && ! $this->restoreHasFindings(Severity::Blocking)),
            ])
            ->action(function (Action $action) use ($versionNo): void {
                $this->runRestore($action, $this->chosenVersionNo($action, $versionNo));
            });
    }

    /**
     * "Redigovat verzi": the irreversible rewrite of a stored version (any version, the current one and archived
     * articles included).
     *
     * @param  Closure(KnowledgeArticleVersion): int  $versionNo  the chosen version number, from the table record or the page
     */
    protected function redactVersionAction(Closure $versionNo): Action
    {
        return Action::make('redactVersion')
            ->label(trans()->string('knowledge.redact.action'))
            ->icon(Heroicon::OutlinedShieldExclamation)
            ->color('danger')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalIcon(Heroicon::OutlinedShieldExclamation)
            ->modalIconColor('danger')
            ->modalHeading(fn (Action $action): string => trans()->string('knowledge.redact.heading', ['n' => $this->chosenVersionNo($action, $versionNo)]))
            ->modalSubmitActionLabel(trans()->string('knowledge.redact.submit'))
            ->modalCancelActionLabel(trans()->string('knowledge.redact.cancel'))
            ->mountUsing(function (Action $action, ?Schema $schema) use ($versionNo): void {
                $this->restoreVersionNo = $this->chosenVersionNo($action, $versionNo);
                $this->clearRestoreRefusal();

                $schema?->fill(['scope' => RedactionScope::Lines->value, 'lines' => '', 'reason' => '']);
            })
            ->schema([
                Callout::make(trans()->string('knowledge.redact.irreversible_heading'))
                    ->description(trans()->string('knowledge.redact.irreversible_body'))
                    ->color('danger')
                    ->icon(Heroicon::OutlinedShieldExclamation),
                Callout::make(trans()->string('knowledge.redact.rotate_heading'))
                    ->description(trans()->string('knowledge.redact.rotate_body'))
                    ->color('warning')
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    // A redaction never cascades: later versions holding the same text keep it (D-12, owner decision OD-3).
                    ->footer([Text::make(trans()->string('knowledge.redact.later_versions_keep'))]),
                Callout::make()
                    ->description(trans()->string('knowledge.redact.current_body'))
                    ->color('info')
                    ->icon(Heroicon::OutlinedInformationCircle)
                    ->visible(fn (): bool => $this->restoreVersionNo === $this->versionActionArticle()->version_no),
                View::make('filament.knowledge.redaction-preview')
                    ->viewData(fn (): array => $this->redactionPreviewData()),
                Radio::make('scope')
                    ->label(trans()->string('knowledge.redact.scope'))
                    ->options([
                        RedactionScope::Lines->value => trans()->string('knowledge.redact.scope_lines'),
                        RedactionScope::Whole->value => trans()->string('knowledge.redact.scope_whole'),
                    ])
                    ->default(RedactionScope::Lines->value)
                    ->helperText(trans()->string('knowledge.redact.scope_helper'))
                    ->live()
                    ->required(),
                TextInput::make('lines')
                    ->label(trans()->string('knowledge.redact.lines'))
                    ->helperText(trans()->string('knowledge.redact.lines_helper'))
                    ->maxLength(RedactionLines::MAX_INPUT_LENGTH)
                    ->visible(static fn (Get $get): bool => $get('scope') !== RedactionScope::Whole->value)
                    ->required(static fn (Get $get): bool => $get('scope') !== RedactionScope::Whole->value)
                    ->validationMessages(['required' => trans()->string('knowledge.redact.errors.lines_required')]),
                Textarea::make('reason')
                    ->label(trans()->string('knowledge.redact.reason'))
                    ->helperText(trans()->string('knowledge.redact.reason_helper'))
                    ->rows(3)
                    ->required()
                    ->maxLength(RedactVersion::MAX_REASON_LENGTH)
                    ->validationMessages([
                        'required' => trans()->string('knowledge.redact.errors.reason_required'),
                        'max' => trans()->string('knowledge.redact.errors.reason_too_long', ['max' => RedactVersion::MAX_REASON_LENGTH]),
                    ]),
            ])
            ->action(function (array $data, Action $action) use ($versionNo): void {
                $this->runRedaction($action, $this->chosenVersionNo($action, $versionNo), $data);
            });
    }

    /**
     * The restore call: acknowledgment decided from the stored refusal, then the one restore path.
     */
    private function runRestore(Action $action, int $number): void
    {
        $actor = auth()->user();
        assert($actor instanceof User);

        $article = $this->versionActionArticle(fresh: true);
        $expectedVersion = $this->restoreExpectedVersion;
        $expectedRedactionNo = $this->restoreExpectedRedactionNo;

        // The tokens are written by the mount of this modal only; a call without a mount is a stale one.
        if ($expectedVersion === null || $expectedRedactionNo === null || $this->restoreVersionNo !== $number) {
            $this->sendStaleRestoreNotice(false);
            $action->halt();

            return;
        }

        $acknowledge = $this->restoreAcknowledgement($article, $number);
        $this->clearRestoreRefusal();

        try {
            $result = app(RestoreVersion::class)->handle($actor, $article, $number, $expectedVersion, $expectedRedactionNo, $acknowledge);
        } catch (SecretGuardRefused $refused) {
            $this->restoreFindings = array_map(
                static fn (SecretFinding $finding): array => [...$finding->toArray(), 'severity' => $finding->severity()->value],
                $refused->findings,
            );
            $this->restoreAcknowledgeableHash = $refused->isAcknowledgeable() ? $refused->normalisedBodyHash : null;

            $this->js("setTimeout(() => { const callout = document.querySelector('[data-kokpit-guard]'); if (callout) { callout.scrollIntoView({ block: 'center' }); callout.focus({ preventScroll: true }) } }, 50)");

            $action->halt();

            return;
        } catch (StaleArticle $stale) {
            $this->sendStaleRestoreNotice($stale->redactionChanged);
            $action->halt();

            return;
        } catch (ModelNotFoundException $missing) {
            // Archived meanwhile (the scoped read of the restore finds no article): nothing was written.
            if (! $this->versionActionArticle(fresh: true)->trashed()) {
                throw $missing;
            }

            Notification::make()
                ->warning()
                ->persistent()
                ->title(trans()->string('knowledge.versions.callouts.archived'))
                ->send();

            $action->cancel();

            return;
        } catch (ArticleWriteFailed $failed) {
            report($failed);

            Notification::make()
                ->danger()
                ->persistent()
                ->title(trans()->string('knowledge.notifications.write_failed_title'))
                ->body(trans()->string('knowledge.notifications.write_failed_body'))
                ->send();

            $action->halt();

            return;
        }

        if ($result->outcome === SaveOutcome::Unchanged) {
            Notification::make()->info()->title(trans()->string('knowledge.notifications.unchanged'))->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(trans()->string(
                $result->markedSensitive ? 'knowledge.restore.notifications.restored_sensitive' : 'knowledge.restore.notifications.restored',
                ['from' => $number, 'next' => $result->article->version_no],
            ))
            ->send();

        $this->redirect(KnowledgeArticleResource::getUrl('version', ['record' => $result->article, 'version' => $result->article->version_no]));
    }

    /**
     * The redaction call. The reason goes to RedactVersion and nowhere else.
     *
     * @param  array<string, mixed>  $data  the form state of the modal
     */
    private function runRedaction(Action $action, int $number, array $data): void
    {
        $actor = auth()->user();
        assert($actor instanceof User);

        $article = $this->versionActionArticle(fresh: true);
        $scope = RedactionScope::tryFrom(is_string($data['scope'] ?? null) ? $data['scope'] : '') ?? RedactionScope::Lines;
        $lines = is_string($data['lines'] ?? null) ? $data['lines'] : '';

        try {
            $result = app(RedactVersion::class)->handle($actor, $article, $number, $scope, $lines, $data['reason'] ?? null);
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
                ->title(trans()->string('knowledge.redact.notifications.failed_title'))
                ->body(trans()->string('knowledge.redact.notifications.failed_body'))
                ->send();

            $action->halt();

            return;
        }

        $range = $result->keyRanges[0] ?? null;

        Notification::make()
            ->success()
            ->title(trans()->string('knowledge.redact.notifications.redacted', ['n' => $result->versionNo]))
            ->body($range === null ? null : trans()->string('knowledge.redact.notifications.key_extended', ['from' => $range['from'], 'to' => $range['to']]))
            ->send();

        if ($result->remainingFindings !== []) {
            Notification::make()
                ->warning()
                ->persistent()
                ->title(trans()->string('knowledge.redact.notifications.remaining_title'))
                ->body(trans()->string('knowledge.redact.notifications.remaining_body', [
                    'findings' => implode(', ', array_map(
                        fn (SecretFinding $finding): string => $this->versionFindingLine($finding->rule->value, $finding->line),
                        $result->remainingFindings,
                    )),
                ]))
                ->send();
        }

        // The modal ends here and its form state with it; the page reads the redacted version again.
        $this->versionActionArticleCache = null;
        $this->afterVersionRedacted();
    }

    /**
     * The article read from the database in this request: fresh on demand, archived ones included.
     */
    protected function versionActionArticle(bool $fresh = false): KnowledgeArticle
    {
        if ($fresh || $this->versionActionArticleCache === null) {
            $record = $this->getRecord();

            $this->versionActionArticleCache = KnowledgeArticle::query()->withTrashed()->whereKey($record->getKey())->firstOrFail();
        }

        return $this->versionActionArticleCache;
    }

    private function chosenVersionNo(Action $action, Closure $versionNo): int
    {
        return (int) $action->evaluate($versionNo);
    }

    /**
     * The stored version the open modal is about, with its body.
     */
    private function versionActionRow(): ?KnowledgeArticleVersion
    {
        if ($this->restoreVersionNo === null) {
            return null;
        }

        return KnowledgeArticleVersion::query()
            ->where('article_id', $this->versionActionArticle()->getKey())
            ->where('version_no', $this->restoreVersionNo)
            ->first();
    }

    private function clearRestoreRefusal(): void
    {
        $this->restoreFindings = [];
        $this->restoreAcknowledgeableHash = null;
    }

    /**
     * Whether the stored refusal may be acknowledged now: the hash binds it to the exact text that was refused, read
     * again from the stored version, and to the same version number.
     */
    private function restoreAcknowledgement(KnowledgeArticle $article, int $number): bool
    {
        if ($this->restoreAcknowledgeableHash === null || $this->restoreVersionNo !== $number) {
            return false;
        }

        $body = KnowledgeArticleVersion::query()
            ->where('article_id', $article->getKey())
            ->where('version_no', $number)
            ->value('body_md');

        if (! is_string($body) || mb_strlen($body) > KnowledgeArticle::MAX_BODY_LENGTH) {
            return false;
        }

        return hash_equals($this->restoreAcknowledgeableHash, hash('sha256', app(MarkdownNormaliser::class)->normalise($body)));
    }

    private function restoreHasFindings(Severity $severity): bool
    {
        foreach ($this->restoreFindings as $finding) {
            if ($finding['severity'] === $severity->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function restoreFindingLines(Severity $severity): array
    {
        $lines = [];

        foreach ($this->restoreFindings as $finding) {
            if ($finding['severity'] === $severity->value) {
                $lines[] = $this->versionFindingLine($finding['rule'], $finding['line']);
            }
        }

        return $lines;
    }

    /**
     * One finding as the Admin reads it: the rule label and the line number, never the matched text.
     */
    private function versionFindingLine(string $rule, int $line): string
    {
        $label = trans()->string('knowledge.guard.rules.'.$rule);

        return $line > 0
            ? trans()->string('knowledge.guard.finding', ['rule' => $label, 'line' => $line])
            : trans()->string('knowledge.guard.finding_without_line', ['rule' => $label]);
    }

    /**
     * Whether restoring would change nothing: the chosen version holds the title and the body of the article now.
     */
    private function restoreIsIdentical(): bool
    {
        $version = $this->versionActionRow();
        $article = $this->versionActionArticle();

        return $version instanceof KnowledgeArticleVersion
            && $version->title === $article->title
            && $version->body_md === $article->body_md;
    }

    /**
     * The data of the diff view: the current text against the chosen version (plain escaped rows only).
     *
     * @return array<string, mixed>
     */
    private function restoreDiffData(): array
    {
        $article = $this->versionActionArticle();
        $version = $this->versionActionRow();

        return [
            'result' => $version instanceof KnowledgeArticleVersion
                ? app(VersionDiff::class)->compare($article->body_md, $version->body_md)
                : VersionDiffResult::identical(),
            'fromNo' => $article->version_no,
            'toNo' => $this->restoreVersionNo ?? 0,
            'fromTitle' => $article->title,
            'toTitle' => $version->title ?? $article->title,
            'caption' => trans()->string('knowledge.restore.diff_caption', ['current' => $article->version_no]),
            'maxHeight' => '60vh',
        ];
    }

    /**
     * The data of the redaction preview: the stored lines, numbered as the secret guard numbers them.
     *
     * @return array<string, mixed>
     */
    private function redactionPreviewData(): array
    {
        $version = $this->versionActionRow();

        return [
            'lines' => VersionDiff::lines($version instanceof KnowledgeArticleVersion ? $version->body_md : ''),
            'maxLines' => self::REDACTION_PREVIEW_MAX_LINES,
        ];
    }

    private function sendStaleRestoreNotice(bool $redactionChanged): void
    {
        Notification::make()
            ->danger()
            ->persistent()
            ->title(trans()->string($redactionChanged ? 'knowledge.notifications.stale_redacted_title' : 'knowledge.restore.notifications.stale_title'))
            ->body(trans()->string($redactionChanged ? 'knowledge.notifications.stale_redacted_body' : 'knowledge.restore.notifications.stale_body'))
            ->send();
    }
}
