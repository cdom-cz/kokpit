<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\ExchangeRates\ExchangeRateConverter;
use App\Domain\ExchangeRates\ExchangeRateMissing;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Settings\Numbering\InvalidNumberPattern;
use App\Domain\Settings\Settings\BankAccountSettings;
use App\Domain\Settings\Settings\NumberingSettings;
use App\Domain\Settings\Settings\SupplierSettings;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The pre-issue blockers of a draft (D-10, UI-SPEC Surface D): everything that must be true before a number is
 * allocated, each with its Czech message. The UI asks {@see IssueChecks::blockers()} to show the list without
 * throwing; the issue pipeline asks {@see IssueChecks::assertIssuable()} on the locked row, so a stale page cannot
 * freeze an incomplete document (T-10-32).
 *
 * The chronology blocker here is advisory: the authoritative check runs after the allocation, under the sequence
 * lock, in the issuer (ruling R-12-1). A stored number pattern that the pattern rule of plan 10-07 refuses is the
 * `pattern_invalid` blocker and never an exception page (ruling R-12-4).
 */
final readonly class IssueChecks
{
    public function __construct(private ExchangeRateConverter $rates) {}

    /**
     * Every blocker of the draft, in the order the UI lists them, or an empty list when it may be issued.
     *
     * @param  string  $today  the current Prague calendar day, `Y-m-d`
     * @param  array{rate: string, unit_amount: int, rate_date: string}|null  $exchangeSnapshot  a CNB trio the document will copy, so no list is needed
     * @return list<array{key: string, message: string}>
     */
    public function blockers(Invoice $draft, string $today, ?array $exchangeSnapshot = null): array
    {
        $blockers = [];
        $add = static function (string $key, array $replace = []) use (&$blockers): void {
            $blockers[] = ['key' => $key, 'message' => __('invoices.errors.'.$key, $replace)];
        };

        $kind = $draft->kind;
        $creditNote = $kind === InvoiceKind::CreditNote;
        $issueDate = $draft->issue_date?->format('Y-m-d') ?? $today;

        if (! $draft->items()->exists()) {
            $add('no_items');
        }

        if ($creditNote ? $draft->total_minor >= 0 : $draft->total_minor <= 0) {
            $add('total_not_positive');
        }

        if ($this->supplierIncomplete()) {
            $add('supplier_missing');
        }

        if (! $creditNote && app(BankAccountSettings::class)->forCurrency($draft->currency) === null) {
            $add('bank_missing', ['currency' => $draft->currency]);
        }

        if ($draft->currency !== 'CZK' && $exchangeSnapshot === null && ! $this->hasRate($draft->currency, $issueDate)) {
            $add('cnb_missing', ['currency' => $draft->currency, 'date' => self::czechDate($issueDate)]);
        }

        if ($this->addressIncomplete($draft)) {
            $add('client_address_missing');
        }

        if ($issueDate > $today) {
            $add('issue_date_future');
        }

        $latest = $this->latestIssueDate($kind);

        if ($latest !== null && $issueDate < $latest) {
            $add('issue_date_before_latest', ['date' => self::czechDate($latest)]);
        }

        $dueDate = $draft->due_date?->format('Y-m-d');

        if (! $creditNote && $dueDate !== null && $dueDate < $issueDate) {
            $add('due_before_issue');
        }

        if (! $this->patternValid($kind)) {
            $add('pattern_invalid', ['kind' => mb_strtolower($kind->numberingKind()->getLabel())]);
        }

        return $blockers;
    }

    /**
     * @param  array{rate: string, unit_amount: int, rate_date: string}|null  $exchangeSnapshot
     *
     * @throws ValidationException keyed by the key of every blocker
     */
    public function assertIssuable(Invoice $draft, string $today, ?array $exchangeSnapshot = null): void
    {
        $blockers = $this->blockers($draft, $today, $exchangeSnapshot);

        if ($blockers === []) {
            return;
        }

        throw ValidationException::withMessages(array_column($blockers, 'message', 'key'));
    }

    /**
     * The issue date of the latest issued document of the kind, `Y-m-d`, or null when none was issued yet.
     */
    public function latestIssueDate(InvoiceKind $kind): ?string
    {
        $latest = Invoice::query()
            ->where('kind', $kind->value)
            ->where('state', InvoiceState::Issued->value)
            ->max('issue_date');

        return is_string($latest) ? substr($latest, 0, 10) : null;
    }

    /**
     * A calendar day the way a Czech message writes it, `14. 10. 2026`.
     */
    public static function czechDate(string $day): string
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC');

        return $date instanceof CarbonImmutable ? $date->format('j. n. Y') : $day;
    }

    private function supplierIncomplete(): bool
    {
        $supplier = app(SupplierSettings::class);

        foreach ([$supplier->company_name, $supplier->street, $supplier->city, $supplier->postal_code, $supplier->company_id] as $value) {
            if ($value === null || trim($value) === '') {
                return true;
            }
        }

        return false;
    }

    private function addressIncomplete(Invoice $draft): bool
    {
        $client = $draft->client;

        if ($client === null) {
            return true;
        }

        foreach ([$client->name, $client->street, $client->city, $client->postal_code] as $value) {
            if ($value === null || trim($value) === '') {
                return true;
            }
        }

        return false;
    }

    private function hasRate(string $currency, string $day): bool
    {
        try {
            $this->rates->rateFor($currency, $day);
        } catch (ExchangeRateMissing) {
            return false;
        }

        return true;
    }

    private function patternValid(InvoiceKind $kind): bool
    {
        try {
            app(NumberingSettings::class)->patternFor($kind->numberingKind());
        } catch (InvalidNumberPattern) {
            return false;
        }

        return true;
    }
}
