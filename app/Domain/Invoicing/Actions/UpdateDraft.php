<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\InvoiceItemOrigin;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoiceItemSource;
use App\Domain\Invoicing\Support\DraftTotals;
use App\Domain\Invoicing\Support\InvoiceItemInput;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Support\PragueDay;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
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
 * - `items`: the whole list of lines, `id?`, `description`, `quantity`, `unit`, `unit_price`, `confirm_zero_price?`
 *   (see {@see InvoiceItemInput}).
 *
 * Lines are matched by id (R-11-3): a line with an id of this draft is updated and keeps its row and its source, a
 * line without one is inserted as a manual line, a stored line missing from the list is deleted with its source,
 * and positions follow the order of the list. An id that does not belong to the draft is a field error
 * `items.<n>.id`, never a write. Editing or deleting a line never changes which time entries are linked (D-03,
 * D-06). Changing the unit price of a line that was generated from time or a fixed amount marks its source as a manual
 * price and keeps its origin; the later conversion step leaves such a price alone.
 *
 * Every line total is computed here with {@see DraftTotals::lineTotal()} and stored with `price_converted` false; the
 * subtotal and the total are the sum of the stored line totals, the VAT is zero in the non-payer mode. A total or a
 * line total in the data is never read (T-10-28). The plan that converts lines of another currency re-prices the
 * non-manual ones at issue.
 */
final readonly class UpdateDraft
{
    /**
     * Where a kept line is parked while the positions are reassigned, so the unique key of (document, position)
     * is never hit by a reorder. Far above the number of lines a draft may hold.
     */
    private const int PARKED_POSITION = 1_000_000;

    private const int NOTE_MAX_LENGTH = 500;

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     * @throws DomainException when the document is no longer a draft
     */
    public function handle(User $actor, Invoice $draft, array $data): Invoice
    {
        Gate::forUser($actor)->authorize('update', $draft);

        return DB::transaction(function () use ($draft, $data): Invoice {
            $locked = Invoice::query()->whereKey($draft->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw new DomainException(__('invoices.errors.not_draft'));
            }

            $header = $this->header($data, $locked);

            if (array_key_exists('items', $data)) {
                if (! is_array($data['items'])) {
                    throw new InvalidArgumentException('The items of a draft are a list of lines.');
                }

                $this->saveItems($locked, $data['items'], $header['currency']);
            }

            $totals = $this->totals($locked, $header['currency']);

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
     * Replaces the lines of the draft by the list: update by id, insert, delete the missing, order by the list.
     *
     * @param  array<array-key, mixed>  $items
     *
     * @throws ValidationException
     */
    private function saveItems(Invoice $draft, array $items, string $currency): void
    {
        $inputs = InvoiceItemInput::list($items, $currency, $draft->kind, (int) config('kokpit.invoicing.max_items'));

        /** @var Collection<string, InvoiceItem> $existing */
        $existing = InvoiceItem::query()->where('invoice_id', $draft->id)->with('source')->get()->keyBy('id');

        $kept = $this->assertOwnLines($inputs, $existing, $currency);

        foreach ($existing as $item) {
            if (! isset($kept[$item->id])) {
                $item->source?->delete();
                $item->delete();
            }
        }

        // Park every kept line whose position changes, so no reorder ever meets the unique (document, position).
        foreach ($inputs as $index => $input) {
            $item = $input->id === null ? null : $existing->get($input->id);

            if ($item instanceof InvoiceItem && $item->position !== $index + 1) {
                $item->forceFill(['position' => self::PARKED_POSITION + $index + 1])->save();
            }
        }

        foreach ($inputs as $index => $input) {
            $this->writeLine($draft, $input, $input->id === null ? null : $existing->get($input->id), $index + 1);
        }
    }

    /**
     * Every id of the list must be a line of this draft, once. Line totals that do not fit are a field error too.
     *
     * @param  list<InvoiceItemInput>  $inputs
     * @param  Collection<string, InvoiceItem>  $existing
     * @return array<string, true> the ids kept
     *
     * @throws ValidationException
     */
    private function assertOwnLines(array $inputs, Collection $existing, string $currency): array
    {
        $kept = [];
        $errors = [];

        foreach ($inputs as $index => $input) {
            if ($input->id !== null) {
                if (! $existing->has($input->id) || isset($kept[$input->id])) {
                    $errors["items.{$index}.id"] = [__('invoices.errors.item_unknown')];
                }

                $kept[$input->id] = true;
            }

            try {
                DraftTotals::lineTotal($input->unitPrice, $input->quantity);
            } catch (OverflowException) {
                $errors["items.{$index}.unit_price"] = [__('invoices.errors.item_price')];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $kept;
    }

    private function writeLine(Invoice $draft, InvoiceItemInput $input, ?InvoiceItem $stored, int $position): void
    {
        $item = $stored ?? new InvoiceItem;
        $priceChanged = $stored instanceof InvoiceItem && $stored->unit_price_minor !== $input->unitPrice->minor;

        $item->fill(['description' => $input->description, 'unit' => $input->unit]);
        $item->forceFill([
            'invoice_id' => $draft->id,
            'position' => $position,
            'quantity' => $input->quantity->toDecimal(),
            'unit_price_minor' => $input->unitPrice->minor,
            'line_total_minor' => DraftTotals::lineTotal($input->unitPrice, $input->quantity)->minor,
            'price_converted' => false,
            'vat_rate' => null,
            'vat_minor' => 0,
        ]);
        $item->save();

        $source = $stored instanceof InvoiceItem ? $stored->source : null;

        if (! $source instanceof InvoiceItemSource) {
            $source = new InvoiceItemSource;
            $source->forceFill([
                'invoice_item_id' => $item->id,
                'invoice_id' => $draft->id,
                'origin' => InvoiceItemOrigin::Manual,
                'price_manual' => true,
            ])->save();

            return;
        }

        if ($priceChanged && ! $source->price_manual) {
            $source->forceFill(['price_manual' => true])->save();
        }
    }

    /**
     * The subtotal, VAT and total from the stored line totals, never from a unit price (D-09).
     *
     * @return array{subtotal: Money, vat: Money, total: Money}
     *
     * @throws ValidationException when the sum does not fit
     */
    private function totals(Invoice $draft, string $currency): array
    {
        /** @var list<int> $minors */
        $minors = InvoiceItem::query()->where('invoice_id', $draft->id)->pluck('line_total_minor')->map(static fn (mixed $minor): int => (int) $minor)->all();

        try {
            return DraftTotals::for(array_map(static fn (int $minor): Money => Money::ofMinor($minor, $currency), $minors), $currency);
        } catch (OverflowException) {
            throw ValidationException::withMessages(['items' => __('invoices.errors.item_price')]);
        }
    }
}
