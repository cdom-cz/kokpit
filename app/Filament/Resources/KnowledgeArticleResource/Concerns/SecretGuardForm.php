<?php

declare(strict_types=1);

namespace App\Filament\Resources\KnowledgeArticleResource\Concerns;

use App\Domain\Knowledge\Guard\Severity;

/**
 * What the shared article form asks of a page that shows the secret-guard feedback (D-01, D-03): which callouts to
 * show, their finding lines, whether the save-anyway button is offered, and how a changed body drops the refusal. The
 * create screen and the edit screen implement it through {@see GuardsSecrets}.
 */
interface SecretGuardForm
{
    /**
     * Whether the last refused save had a finding of this severity.
     */
    public function hasGuardFindings(Severity $severity): bool;

    /**
     * The lines of the callout of this severity: the rule label and the line number, never the matched text.
     *
     * @return list<string>
     */
    public function guardFindingLines(Severity $severity): array;

    /**
     * Whether the Ambiguous-only refusal can be saved anyway for the exact body that was refused.
     */
    public function canSaveAnyway(): bool;

    /**
     * Forgets the findings and the refused body hash; a changed body is scanned again by the next save.
     */
    public function clearGuardFindings(): void;
}
