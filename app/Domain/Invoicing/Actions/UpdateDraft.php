<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\ExchangeRates\ExchangeRateConverter;
use App\Domain\ExchangeRates\ExchangeRateMissing;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\InvoiceItemOrigin;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoiceItemSource;
use App\Domain\Invoicing\Support\DocumentPayment;
use App\Domain\Invoicing\Support\DraftPreview;
use App\Domain\Invoicing\Support\DraftTotals;
use App\Domain\Invoicing\Support\InvoiceItemInput;
use App\Domain\Invoicing\Support\InvoiceQuantity;
use App\Domain\Invoicing\Support\IssueChecks;
use App\Domain\Invoicing\Support\ItemListWriter;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Support\PragueDay;
use App\Domain\TimeTracking\Support\TimerClock;
use DomainException;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use OverflowException;

/**
 * Saves the whole draft in one transaction under the row lock: the single write path of a draft (IN-02, IN-08, D-03,
 * D-08, D-09, D-11). The Admin only. The editor hands a plain array; a Filament relationship repeater is never the
 * write path (research Pattern 12), so every rule here is re-checked whatever the form offered.
 *
 * Data keys, each optional (an absent key keeps the stored value, a present key replaces it):
 * - `currency`: a known ISO 4217 code (R-11-2); a missing bank account for it is a blocker of issuing, not an error
 *   here. A caller that changes the currency sends the `items` in the new currency with it, as the editor always does;
 * - `language`: `cs` or `en`;
 * - `issue_date`, `due_date`: Prague `Y-m-d` or null, the due date not before the issue date. A null issue date means
 *   "today (Europe/Prague) at issue" and a null due date "the issue date plus the client's payment terms at issue";
 *   only a value the Admin typed is stored (R-11-1);
 * - `note` (at most 500 characters), printed on the document;
 * - `internal_note` (at most 2 000 characters), saved through {@see UpdateInternalNote} in the same transaction;
 * - `payment_method` (`transfer` | `online`, an invoice or a proforma draft only): how the document is paid (D-02). A
 *   new draft is `transfer`. Changing to `online` needs card payment to be available (the global setting on, Stripe
 *   configured and the client flag on) and is refused with `online_unavailable` otherwise; a draft that is already
 *   online is not refused on a save, the issue blocks it. Any other value is `payment_method_invalid`;
 * - `bank_account_id` (the UUID of a configured account, or null): the account a transfer document prints (D-05,
 *   D-06). The account may have any currency. Without the key, a change of currency preselects the first account of
 *   the new currency and a save of the same currency keeps the stored one. An id the settings do not hold is
 *   `bank_account_unknown`. A credit-note draft takes neither this key nor `payment_method`: `credit_note_no_payment`;
 * - `card_payment_enabled` (a strict boolean, an invoice or a proforma draft only): the per-document card switch of the
 *   Admin-only row (D-06, ruling R11-03-5). A new draft starts with the client's value; this key overrides it, and the
 *   issue freezes the AND of it with the global setting and a configured Stripe. `online` forces it on whatever is sent,
 *   and going back from `online` to `transfer` resets it to the client's flag unless the Admin sends a value (OA-1). A
 *   credit-note draft or a value that is not a boolean is refused with `card_payment_invalid` under
 *   `card_payment_enabled`;
 * - `items`: the whole list of lines, `id?`, `description`, `quantity`, `unit`, `unit_price`, `confirm_zero_price?`
 *   (see {@see InvoiceItemInput}).
 *
 * The lines are written by {@see ItemListWriter}, shared with the correction of an open issued document (R-10.1-04-1).
 * Lines are matched by id (R-11-3): a line with an id of this draft is updated and keeps its row and its source, a
 * line without one is inserted as a manual line, a stored line missing from the list is deleted with its source,
 * and positions follow the order of the list. An id that does not belong to the draft is a field error
 * `items.<n>.id`, never a write. Editing or deleting a line never changes which time entries are linked (D-03,
 * D-06). Changing the unit price of a line that was generated from time or a fixed amount marks its source as a manual
 * price and keeps its origin; the later conversion step leaves such a price alone.
 *
 * Every line total is computed here, never read from the data (T-10-28); the subtotal and the total are the sum of the
 * stored line totals, the VAT is zero in the non-payer mode. An ordinary line costs {@see DraftTotals::lineTotal()} and
 * is stored with `price_converted` false. A line generated from time whose hourly rate is in another currency than
 * the draft and whose price was not typed is re-previewed on every save through {@see DraftPreview::convertLine()}
 * with the CNB lists of today (UI-SPEC C1, ruling R-17-1): the printed unit price is the display figure, the line
 * amount is rounded once from the exact conversion, `price_converted` is true, so an edit of the quantity or the text
 * never turns a preview into a two-rounding figure. The price shown for such a line is not a typed price: only a
 * unit price that differs from the stored one is, and it makes the line manual and exact. When no list exists for a
 * needed currency an ordinary save keeps the stored preview of those lines (the issue blocks with `cnb_missing`
 * anyway).
 *
 * Changing the `currency` re-previews the generated lines for the new currency, with or without `items`: a rate in the
 * new currency is restored exactly, the others are converted again, a unit price typed by hand is never changed. A
 * change to a currency whose list is missing for a line that needs it is refused with `cnb_missing` under `currency`,
 * like the draft creation (R-13-4), and nothing is written. The issue converts these lines again with the lists of the
 * issue date and never reuses the preview.
 */
final readonly class UpdateDraft
{
    private const int NOTE_MAX_LENGTH = 500;

    public function __construct(
        private UpdateInternalNote $internalNote,
        private DraftPreview $preview,
        private ExchangeRateConverter $rates,
        private ItemListWriter $writer,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     * @throws DomainException when the document is no longer a draft
     */
    public function handle(User $actor, Invoice $draft, array $data): Invoice
    {
        Gate::forUser($actor)->authorize('update', $draft);

        return DB::transaction(function () use ($actor, $draft, $data): Invoice {
            $locked = Invoice::query()->whereKey($draft->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new DomainException(__('invoices.errors.not_draft'));
            }

            $header = $this->header($data, $locked);
            $payment = DocumentPayment::forDraft($locked, $data, $header['currency']);
            $currencyChanged = $header['currency'] !== $locked->currency;
            $today = TimerClock::now()->setTimezone(FilamentTimezone::get())->format('Y-m-d');

            if ($currencyChanged) {
                $this->assertListsFor($locked, $header['currency'], $today);
            }

            if (array_key_exists('internal_note', $data)) {
                $this->internalNote->handle($actor, $locked, is_string($data['internal_note']) ? $data['internal_note'] : null);
            }

            if ($payment !== null) {
                DocumentPayment::saveAdmin($locked, $payment['bank_account_id'], $payment['card_payment_enabled']);
            }

            if (array_key_exists('items', $data)) {
                if (! is_array($data['items'])) {
                    throw new InvalidArgumentException('The items of a draft are a list of lines.');
                }

                $this->saveItems($locked, $data['items'], $header['currency'], $currencyChanged, $today);
            } elseif ($currencyChanged) {
                $this->repriceGenerated($locked, $header['currency'], $today);
            }

            $totals = $this->writer->totals($locked, $header['currency']);

            $locked->fill(['note' => $header['note']]);
            $locked->forceFill([
                'currency' => $header['currency'],
                'language' => $header['language'],
                'issue_date' => $header['issue_date'],
                'due_date' => $header['due_date'],
                'subtotal_minor' => $totals['subtotal']->minor,
                'vat_minor' => $totals['vat']->minor,
                'total_minor' => $totals['total']->minor,
            ]);

            if ($payment !== null) {
                $locked->forceFill(['payment_method' => $payment['payment_method']]);
            }

            $locked->save();

            return $locked->refresh();
        });
    }

    /**
     * The checked header: the data over the stored values.
     *
     * @param  array<string, mixed>  $data
     * @return array{currency: string, language: InvoiceLanguage, issue_date: ?string, due_date: ?string, note: ?string}
     *
     * @throws ValidationException
     */
    private function header(array $data, Invoice $draft): array
    {
        $issueDate = array_key_exists('issue_date', $data) ? $this->day($data['issue_date'], 'issue_date') : $draft->issue_date?->toDateString();
        $dueDate = array_key_exists('due_date', $data) ? $this->day($data['due_date'], 'due_date') : $draft->due_date?->toDateString();

        if ($issueDate !== null && $dueDate !== null && $dueDate < $issueDate) {
            throw ValidationException::withMessages(['due_date' => __('invoices.errors.due_before_issue')]);
        }

        return [
            'currency' => array_key_exists('currency', $data) ? $this->currency($data['currency']) : $draft->currency,
            'language' => array_key_exists('language', $data) ? $this->language($data['language']) : $draft->language,
            'issue_date' => $issueDate,
            'due_date' => $dueDate,
            'note' => array_key_exists('note', $data) ? $this->note($data['note']) : $draft->note,
        ];
    }

    /**
     * @throws ValidationException
     */
    private function day(mixed $value, string $key): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return PragueDay::day(is_string($value) ? trim($value) : $value)
            ?? throw ValidationException::withMessages([$key => __('invoices.errors.date_invalid')]);
    }

    /**
     * @throws ValidationException
     */
    private function currency(mixed $value): string
    {
        $code = is_string($value) ? strtoupper(trim($value)) : '';

        if (! Money::isKnownCurrency($code)) {
            throw ValidationException::withMessages(['currency' => __('invoices.errors.currency')]);
        }

        return $code;
    }

    /**
     * @throws ValidationException
     */
    private function language(mixed $value): InvoiceLanguage
    {
        $language = $value instanceof InvoiceLanguage ? $value : (is_string($value) ? InvoiceLanguage::tryFrom($value) : null);

        return $language ?? throw ValidationException::withMessages(['language' => __('invoices.errors.language')]);
    }

    /**
     * @throws ValidationException
     */
    private function note(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = is_string($value) ? trim($value) : '';

        if (mb_strlen($text) > self::NOTE_MAX_LENGTH) {
            throw ValidationException::withMessages(['note' => __('invoices.errors.note_length')]);
        }

        return $text === '' ? null : $text;
    }

    /**
     * Replaces the lines of the draft by the list through {@see ItemListWriter}; a generated line is priced with the
     * lists of today ({@see UpdateDraft::generatedPrice()}).
     *
     * @param  array<array-key, mixed>  $items
     *
     * @throws ValidationException
     */
    private function saveItems(Invoice $draft, array $items, string $currency, bool $currencyChanged, string $today): void
    {
        $this->writer->replace(
            $draft,
            $items,
            $currency,
            $currencyChanged,
            fn (InvoiceItemSource $source, InvoiceItem $stored, InvoiceQuantity $quantity, array &$quotes): array => $this->generatedPrice($source, $stored, $quantity, $currency, $currencyChanged, $today, $quotes),
        );
    }

    /**
     * The unit price, the amount and the converted flag of a generated line for the quantity: a rate in another
     * currency than the draft is previewed with the lists of today (one rounding, ruling R-17-1) or, when the list is
     * missing, keeps the stored preview; a rate in the draft's currency stays as it is, and is restored exactly after
     * a change of currency.
     *
     * @param  array<string, array{rate: string, unit_amount: int, rate_date: string|null}>  $quotes
     * @return array{0: Money, 1: Money, 2: bool}
     *
     * @throws ValidationException when an amount does not fit
     */
    private function generatedPrice(InvoiceItemSource $source, InvoiceItem $stored, InvoiceQuantity $quantity, string $currency, bool $currencyChanged, string $today, array &$quotes): array
    {
        $rate = $source->sourceRate() ?? throw new LogicException('A generated line has its source rate.');

        try {
            if ($rate->currency !== $currency) {
                $converted = $this->preview->convertLine($rate, $quantity->toDecimal(), $currency, $today, $quotes);

                return [$converted['unit_price'], $converted['line_total'], true];
            }

            if ($currencyChanged) {
                return [$rate, DraftTotals::lineTotal($rate, $quantity), false];
            }
        } catch (ExchangeRateMissing) {
            // Keep what the draft shows; the issue refuses it with cnb_missing until the list is there.
            $unitPrice = Money::ofMinor($stored->unit_price_minor, $currency);
            $lineTotal = $stored->quantity === $quantity->toDecimal()
                ? Money::ofMinor($stored->line_total_minor, $currency)
                : DraftTotals::lineTotal($unitPrice, $quantity);

            return [$unitPrice, $lineTotal, $stored->price_converted];
        } catch (OverflowException) {
            throw ValidationException::withMessages(['items' => __('invoices.errors.item_price')]);
        }

        $unitPrice = Money::ofMinor($stored->unit_price_minor, $currency);

        return [$unitPrice, DraftTotals::lineTotal($unitPrice, $quantity), false];
    }

    /**
     * Re-previews the generated lines of a draft for a new currency when the save carries no lines; the lines typed by
     * hand, the fixed amounts and the manual lines are not touched.
     *
     * @throws ValidationException
     */
    private function repriceGenerated(Invoice $draft, string $currency, string $today): void
    {
        $quotes = [];

        /** @var Collection<int, InvoiceItem> $items */
        $items = InvoiceItem::query()->where('invoice_id', $draft->id)->with('source')->get();

        foreach ($items as $item) {
            $source = $item->source;

            if (! $source instanceof InvoiceItemSource || ! ItemListWriter::isGenerated($source)) {
                continue;
            }

            [$unitPrice, $lineTotal, $converted] = $this->generatedPrice($source, $item, InvoiceQuantity::fromDecimal($item->quantity), $currency, true, $today, $quotes);

            $item->forceFill([
                'unit_price_minor' => $unitPrice->minor,
                'line_total_minor' => $lineTotal->minor,
                'price_converted' => $converted,
            ])->save();
        }
    }

    /**
     * A change of currency needs the CNB list of every currency the generated lines are converted with: the new
     * currency and the currency of each rate that differs from it (CZK needs none). One missing list refuses the
     * change under `currency`, like the draft creation (plan 10-13 R-13-4).
     *
     * @throws ValidationException
     */
    private function assertListsFor(Invoice $draft, string $currency, string $today): void
    {
        /** @var list<string> $rateCurrencies */
        $rateCurrencies = InvoiceItemSource::query()
            ->where('invoice_id', $draft->id)
            ->where('origin', InvoiceItemOrigin::Time->value)
            ->where('price_manual', false)
            ->whereNotNull('source_rate_currency')
            ->where('source_rate_currency', '<>', $currency)
            ->distinct()
            ->orderBy('source_rate_currency')
            ->pluck('source_rate_currency')
            ->all();

        if ($rateCurrencies === []) {
            return;
        }

        foreach (array_unique([...$rateCurrencies, $currency]) as $needed) {
            if ($needed === 'CZK') {
                continue;
            }

            try {
                $this->rates->rateFor($needed, $today);
            } catch (ExchangeRateMissing) {
                throw ValidationException::withMessages(['currency' => [__('invoices.errors.cnb_missing', ['currency' => $needed, 'date' => IssueChecks::czechDate($today)])]]);
            }
        }
    }
}
