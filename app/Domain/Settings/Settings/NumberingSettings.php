<?php

declare(strict_types=1);

namespace App\Domain\Settings\Settings;

use App\Domain\Settings\Numbering\DocumentKind;
use App\Domain\Settings\Numbering\InvalidNumberPattern;
use App\Domain\Settings\Numbering\NumberPattern;
use App\Domain\Settings\Rules\NumberPatternRule;

/**
 * The stored number patterns (group `numbering`, D-05): one per document kind
 * that has a configurable pattern, plus the fixed task pattern.
 *
 * A pattern only decides how numbers allocated from now on are written;
 * numbers already handed out never change.
 */
class NumberingSettings extends ValidatedSettings
{
    public string $invoice_pattern;

    public string $proforma_pattern;

    public string $credit_note_pattern;

    public string $task_pattern;

    public static function group(): string
    {
        return 'numbering';
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'invoice_pattern' => ['bail', 'required', 'string', new NumberPatternRule(DocumentKind::Invoice)],
            'proforma_pattern' => ['bail', 'required', 'string', new NumberPatternRule(DocumentKind::Proforma)],
            'credit_note_pattern' => ['bail', 'required', 'string', new NumberPatternRule(DocumentKind::CreditNote)],
            'task_pattern' => ['bail', 'required', 'string', new NumberPatternRule(DocumentKind::Task)],
        ];
    }

    /**
     * The parsed pattern of a kind, as stored.
     *
     * @throws InvalidNumberPattern
     */
    public function patternFor(DocumentKind $kind): NumberPattern
    {
        return NumberPattern::parse(match ($kind) {
            DocumentKind::Invoice => $this->invoice_pattern,
            DocumentKind::Proforma => $this->proforma_pattern,
            DocumentKind::CreditNote => $this->credit_note_pattern,
            DocumentKind::Task => $this->task_pattern,
        }, $kind);
    }
}
