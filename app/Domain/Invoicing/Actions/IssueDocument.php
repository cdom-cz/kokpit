<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Issuing\DocumentIssuer;
use App\Domain\Invoicing\Issuing\IssueContext;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Settings\Numbering\DocumentNumbering;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Issues a draft invoice or proforma (IN-01, IN-03, D-10, D-13): one transaction that locks the draft, runs the issue
 * pipeline and commits a numbered, frozen document, or rolls back and leaves the draft, the counter and every other
 * row exactly as they were. The Admin only.
 *
 * The number is allocated here and nowhere earlier. What the Admin sees before confirming comes from
 * {@see IssueDocument::expectedNumber()}, which only previews the counter and may differ from the final number when
 * another document is issued in between.
 */
final readonly class IssueDocument
{
    public function __construct(
        private DocumentIssuer $issuer,
        private DocumentNumbering $numbering,
    ) {}

    /**
     * @throws ValidationException with the blockers keyed by their key
     * @throws \DomainException when the document is no longer a draft
     */
    public function handle(User $actor, Invoice $draft): Invoice
    {
        Gate::forUser($actor)->authorize('update', $draft);

        return DB::transaction(function () use ($actor, $draft): Invoice {
            $locked = Invoice::query()->whereKey($draft->getKey())->lockForUpdate()->firstOrFail();

            return $this->issuer->issue($locked, IssueContext::now($actor));
        });
    }

    /**
     * The number the confirmation modal shows (UI-SPEC F-14): a preview of the counter of the draft's series for
     * the day it would be issued on, never an allocation.
     */
    public function expectedNumber(Invoice $draft): string
    {
        $day = $draft->issue_date?->format('Y-m-d') ?? IssueContext::today();
        $noon = CarbonImmutable::createFromFormat('!Y-m-d H:i', $day.' 12:00', FilamentTimezone::get()) ?? CarbonImmutable::now();

        return $this->numbering->preview($draft->kind->numberingKind(), null, $noon);
    }
}
