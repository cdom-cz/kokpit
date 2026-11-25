<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticleResource\Concerns;

use App\Domain\Knowledge\Exceptions\SecretGuardRefused;
use App\Domain\Knowledge\Guard\SecretFinding;
use App\Domain\Knowledge\Guard\Severity;
use App\Domain\Knowledge\Models\KnowledgeArticle;
use App\Domain\Knowledge\Support\MarkdownNormaliser;
use Closure;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Exceptions\Halt;
use Livewire\Attributes\Locked;

/**
 * The screen side of the secret guard for a page of the article form (SEC-01 to SEC-03, D-01, D-03, D-04).
 *
 * A refused save leaves the form untouched and stores what the callouts need: the rule and the line of every finding
 * (never the matched text) and, only when an acknowledgment could pass the save, the sha256 of the normalised body that
 * was refused. Both live in locked Livewire properties, so a browser cannot set them. `saveAnyway()` sets the one-save
 * acknowledgment only when the body in the form still hashes to the refused one; any other body is scanned again by
 * SaveArticle, which also rejects every Blocking finding whatever the flag says.
 *
 * The page using it implements {@see SecretGuardForm}, has the Livewire form state in `$data`, and submits the form
 * through `submitGuardedForm()`, which must route the save through `runGuarded()`.
 */
trait GuardsSecrets
{
    /**
     * The findings of the last refused save: rule key, line and severity key; never any matched text.
     *
     * @var list<array{rule: string, line: int, severity: string}>
     */
    #[Locked]
    public array $guardFindings = [];

    /**
     * The sha256 of the normalised body of an acknowledgeable refusal, or null.
     */
    #[Locked]
    public ?string $refusedBodyHash = null;

    /**
     * True only while `saveAnyway()` submits the form; never a Livewire property, so it never travels to the browser.
     */
    protected bool $acknowledgeThisSave = false;

    /**
     * Submits the page form after the acknowledgment decision of `saveAnyway()` (the create screen calls `create()`).
     */
    abstract protected function submitGuardedForm(): void;

    /**
     * Runs a save and turns a refusal of the guard into the callouts: the findings are stored, the callout is brought
     * into view and nothing changes in the form (`Halt` ends the Filament submit without a notification or redirect).
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $save
     * @return TReturn
     */
    protected function runGuarded(Closure $save): mixed
    {
        $this->clearGuardFindings();

        try {
            return $save();
        } catch (SecretGuardRefused $refused) {
            $this->rememberRefusal($refused);

            $this->js("setTimeout(() => { const callout = document.querySelector('[data-kokpit-guard]'); if (callout) { callout.scrollIntoView({ block: 'center' }); callout.focus({ preventScroll: true }) } }, 50)");

            throw new Halt;
        }
    }

    private function rememberRefusal(SecretGuardRefused $refused): void
    {
        $this->guardFindings = array_map(
            static fn (SecretFinding $finding): array => [...$finding->toArray(), 'severity' => $finding->severity()->value],
            $refused->findings,
        );

        $this->refusedBodyHash = $refused->isAcknowledgeable() ? $refused->normalisedBodyHash : null;
    }

    #[Unexposed]
    public function hasGuardFindings(Severity $severity): bool
    {
        foreach ($this->guardFindings as $finding) {
            if ($finding['severity'] === $severity->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    #[Unexposed]
    public function guardFindingLines(Severity $severity): array
    {
        $lines = [];

        foreach ($this->guardFindings as $finding) {
            if ($finding['severity'] !== $severity->value) {
                continue;
            }

            $rule = trans()->string('knowledge.guard.rules.'.$finding['rule']);

            $lines[] = $finding['line'] > 0
                ? trans()->string('knowledge.guard.finding', ['rule' => $rule, 'line' => $finding['line']])
                : trans()->string('knowledge.guard.finding_without_line', ['rule' => $rule]);
        }

        return $lines;
    }

    #[Unexposed]
    public function canSaveAnyway(): bool
    {
        return $this->refusedBodyHash !== null && ! $this->hasGuardFindings(Severity::Blocking);
    }

    #[Unexposed]
    public function clearGuardFindings(): void
    {
        $this->guardFindings = [];
        $this->refusedBodyHash = null;
    }

    /**
     * Saves the form with the acknowledgment of the ambiguous findings, for exactly the body that was refused. The
     * acknowledgment holds for this one call only. The raw length is compared with the body limit before the body is
     * normalised for the hash, so a public call with an oversized body never reaches the parser of the normaliser; such
     * a body fails the length rule of the editor in the submit (T-18-52).
     */
    public function saveAnyway(): void
    {
        $body = is_array($this->data) && is_string($this->data['body'] ?? null) ? $this->data['body'] : '';

        $this->acknowledgeThisSave = $this->refusedBodyHash !== null
            && mb_strlen($body) <= KnowledgeArticle::MAX_BODY_LENGTH
            && hash_equals($this->refusedBodyHash, hash('sha256', app(MarkdownNormaliser::class)->normalise($body)));

        try {
            $this->submitGuardedForm();
        } finally {
            $this->acknowledgeThisSave = false;
        }
    }
}
