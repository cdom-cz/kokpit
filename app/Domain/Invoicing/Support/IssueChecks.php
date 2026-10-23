<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\ExchangeRates\ExchangeRateConverter;
use App\Domain\ExchangeRates\ExchangeRateMissing;
use App\Domain\Invoicing\Enums\DocumentPaymentMethod;
use App\Domain\Invoicing\Enums\InvoiceItemOrigin;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItemSource;
use App\Domain\Settings\Numbering\InvalidNumberPattern;
use App\Domain\Settings\Settings\InvoicingSettings;
use App\Domain\Settings\Settings\NumberingSettings;
use App\Domain\Settings\Settings\SupplierSettings;
use App\Domain\Settings\VatMode;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The pre-issue blockers of a draft (D-10, UI-SPEC Surface D): everything that must be true before a number is
 * allocated, each with its Czech message. The UI asks {@see IssueChecks::blockers()} to show the list without
 * throwing; the issue pipeline asks {@see IssueChecks::assertIssuable()} on the locked row, so a stale page cannot
 * freeze an incomplete document (T-10-32).
 *
 * A transfer document needs a bank account to print (D-05, D-06): the account the draft chose, in any currency, or for a
 * draft that chose none the first account of its currency; a chosen account that was removed from the settings is a
 * `bank_missing` blocker, not silently replaced. An online document prints no bank details and needs no account, but it
 * is paid by card: while the global setting is off, Stripe is not configured or the client's online-payment flag is off
 * it is an `online_unavailable` blocker (P14-09), so a document nobody can pay is never issued.
 *
 * A draft whose lines are priced in other currencies needs the CNB list of each of them on the issue date, a
 * `cnb_missing` blocker for every currency without one (EX-03, ruling R-17-1). While the VAT-payer calculations are not
 * built, the payer mode blocks issuing with `vat_payer_unsupported`, so no document without VAT is issued by a payer
 * (IN-08, ruling R-17-3).
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

        if (app(InvoicingSettings::class)->vat_mode !== VatMode::NonPayer) {
            $add('vat_payer_unsupported');
        }

        if (! $creditNote && $draft->payment_method !== DocumentPaymentMethod::Online && DocumentPayment::account($draft) === null) {
            $add('bank_missing');
        }

        // P14-09: an online document is paid by card, so card payment must work now. A draft converted from a proforma
        // is exempt: the carried payments pay it in full and it offers nothing by card.
        if (! $creditNote && $draft->payment_method === DocumentPaymentMethod::Online && $draft->proforma_id === null && DocumentPayment::onlineUnavailableReason($draft) !== null) {
            $add('online_unavailable');
        }

        if ($draft->currency !== 'CZK' && $exchangeSnapshot === null && ! $this->hasRate($draft->currency, $issueDate)) {
            $add('cnb_missing', ['currency' => $draft->currency, 'date' => self::czechDate($issueDate)]);
        }

        foreach ($this->lineCurrencies($draft) as $currency) {
            if ($currency !== $draft->currency && ! $this->hasRate($currency, $issueDate)) {
                $add('cnb_missing', ['currency' => $currency, 'date' => self::czechDate($issueDate)]);
            }
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
     * @throws ValidationException keyed by the key of every blocker, a key with the message of each of its blockers
     */
    public function assertIssuable(Invoice $draft, string $today, ?array $exchangeSnapshot = null): void
    {
        $blockers = $this->blockers($draft, $today, $exchangeSnapshot);

        if ($blockers === []) {
            return;
        }

        $messages = [];

        foreach ($blockers as $blocker) {
            $messages[$blocker['key']][] = $blocker['message'];
        }

        throw ValidationException::withMessages($messages);
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

    /**
     * The currencies, other than CZK, that the hourly rates of the converted time lines are in: the lines the issue
     * prices with a CNB list (generated from time, price not typed by hand, rate in another currency than the
     * document, not yet frozen).
     *
     * @return list<string>
     */
    private function lineCurrencies(Invoice $draft): array
    {
        /** @var list<string> $currencies */
        $currencies = InvoiceItemSource::query()
            ->where('invoice_id', $draft->id)
            ->where('origin', InvoiceItemOrigin::Time->value)
            ->where('price_manual', false)
            ->whereNull('conversion_snapshot')
            ->whereNotNull('source_rate_currency')
            ->where('source_rate_currency', '<>', $draft->currency)
            ->where('source_rate_currency', '<>', 'CZK')
            ->distinct()
            ->orderBy('source_rate_currency')
            ->pluck('source_rate_currency')
            ->all();

        return $currencies;
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
