<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Api\ExternalObject;
use App\Domain\Finance\CustomRate;
use App\Domain\Finance\Enums\CzkSource;
use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\TransactionCzk;
use App\Domain\Finance\TransactionInput;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Edits a transaction (FI-01, D-12, D-13, D-14): freely, by the Admin only.
 *
 * The row is read again under a row lock and the data is validated against it with
 * {@see TransactionInput::fromArray()}: a key that is absent from the data keeps the stored value, and an
 * archived category, client or project that the transaction already carries stays acceptable.
 *
 * The CZK figure follows one rule (research Pitfall 13):
 * - a typed `czk_amount` is the new figure and the source becomes manual, unless `czk_manual` is false;
 * - a manual transaction stays manual, whatever changes in the date, currency or amount, until the data says
 *   `czk_manual` false: the figure is kept and the snapshot stays empty. Switching manual on without a figure
 *   freezes the stored one;
 * - a computed transaction (identity or CNB) is recomputed through {@see TransactionCzk} only when the date,
 *   the currency or the amount changed, which also refreshes the snapshot; any other edit leaves the figure and
 *   the snapshot exactly as stored, so a list added or corrected later never moves them;
 * - switching a manual transaction back to computed always recomputes;
 * - an own rate typed in the data (`exchange_rate`) makes any transaction custom, with the transaction date as the
 *   rate date; a typed `czk_amount` makes it manual (owner decision A1 on D-06);
 * - a custom transaction (booked at an own rate) keeps that rate while the data says nothing about `czk_manual`: a
 *   changed amount in the same currency is recomputed at the stored rate and unit amount with the stored rate date,
 *   a changed currency falls back to the CNB rule of the transaction date, and a changed date or any other edit
 *   leaves the figure and the snapshot as stored;
 * - data naming `exchange_rate` with null (the API's way back to CNB, together with `czk_manual` false) recomputes
 *   a custom transaction through the CNB rule even when nothing else changed; it does not touch a manual
 *   transaction (unless `czk_manual` is false) and does not recompute a CNB one.
 * A CZK transaction needs no rate: it is identity whatever was typed or kept.
 *
 * The income that books a payment of an invoice (FI-03, D-19 as amended on 2026-10-17, ruling R-19-1) changes only in its
 * date, the CZK figure following the date by the rule above, and its category: every change of the amount, currency,
 * client, project, type, description, counterparty, document number or invoice link, and any manual or own-rate CZK
 * figure, is refused with `payment_transaction_locked` under that key, on the locked row before anything is written. A
 * form that sends the unchanged values back is not a change. The database guard of plan 10-06 refuses the same for
 * every other writer; a wrong payment is corrected by deleting it in the invoice detail (`DeletePayment`).
 *
 * The importer's `external` object (see {@see ExternalObject}) follows the API rule: an absent key keeps the stored
 * object, `null` clears it and an object replaces the stored one whole, nothing is merged. It never touches the CZK
 * figure, and it is part of what the income of a payment refuses.
 *
 * A receipt counts for the client and project of its transaction (D-11), so when either changes the receipts
 * get the new pair in the same database transaction (research Pitfall 5). That is one query update, not a model
 * event: the logged attributes of a document do not include the client or the project.
 */
final readonly class UpdateTransaction
{
    /**
     * The data keys that fix the CZK figure by hand (D-06), none of which a payment income accepts.
     */
    private const array CZK_KEYS = ['czk_amount', 'czk_manual', 'exchange_rate', 'exchange_unit_amount'];

    public function __construct(private TransactionCzk $czk) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Transaction $transaction, array $data): Transaction
    {
        Gate::forUser($actor)->authorize('update', $transaction);

        // A bad value fails before any row is locked.
        $external = array_key_exists('external', $data) ? ExternalObject::parse($data['external']) : null;

        return DB::transaction(function () use ($transaction, $data, $external): Transaction {
            $locked = Transaction::query()->whereKey($transaction->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isPaymentIncome()) {
                $this->assertOnlyDateAndCategoryChange($locked, $data, $external);
            }

            $input = TransactionInput::fromArray($data, $locked);

            $locked->fill([
                'description' => $input->description,
                'counterparty' => $input->counterparty,
                'document_number' => $input->documentNumber,
            ]);
            $locked->forceFill([
                'type' => $input->type,
                'transaction_date' => $input->transactionDate,
                'category_id' => $input->categoryId,
                'amount' => $input->amount,
                'client_id' => $input->clientId,
                'project_id' => $input->projectId,
                ...$this->figure($locked, $input),
            ]);

            if (array_key_exists('external', $data)) {
                $locked->forceFill(['external' => $external]);
            }

            $locked->save();

            if ($locked->wasChanged(['client_id', 'project_id'])) {
                $locked->documents()->update([
                    'client_id' => $locked->client_id,
                    'project_id' => $locked->project_id,
                ]);
            }

            return $locked->refresh();
        });
    }

    /**
     * Refuses a change of anything but the date and the category of the income of a payment, keyed by the changed
     * field. The type is compared as sent and the rest after the input is read against the stored row, with the type,
     * date, category and CZK keys left out, so a type or CZK refusal is not hidden behind another validation error.
     *
     * @param  array<string, mixed>  $data
     * @param  stdClass|null  $external  the checked `external` object, meaningful when the data names it
     *
     * @throws ValidationException `payment_transaction_locked` under every changed key
     */
    private function assertOnlyDateAndCategoryChange(Transaction $stored, array $data, ?stdClass $external): void
    {
        $changed = [];

        // The stored value of a payment income is always null, so any object is a change and a sent null is not.
        // A loose comparison of two objects compares their properties whatever their order.
        if (array_key_exists('external', $data) && $external != $stored->external) {
            $changed[] = 'external';
        }

        if (array_key_exists('type', $data)) {
            $type = $data['type'] instanceof TransactionType ? $data['type']->value : $data['type'];

            if ($type !== $stored->type->value) {
                $changed[] = 'type';
            }
        }

        foreach (['invoice_id', 'invoice_payment_id'] as $link) {
            if (array_key_exists($link, $data) && $data[$link] !== $stored->getAttribute($link)) {
                $changed[] = $link;
            }
        }

        foreach (self::CZK_KEYS as $key) {
            if (array_key_exists($key, $data) && $this->setsFigure($key, $data[$key])) {
                $changed[] = $key;
            }
        }

        $input = TransactionInput::fromArray(array_diff_key($data, array_flip(['type', 'transaction_date', 'category_id', ...self::CZK_KEYS])), $stored);

        $compared = [
            'currency' => $input->amount->currency !== $stored->amount_currency,
            'amount' => $input->amount->minor !== $stored->amount_minor,
            'client_id' => $input->clientId !== $stored->client_id,
            'project_id' => $input->projectId !== $stored->project_id,
            'description' => $input->description !== $stored->description,
            'counterparty' => $input->counterparty !== $stored->counterparty,
            'document_number' => $input->documentNumber !== $stored->document_number,
        ];

        $changed = [...$changed, ...array_keys(array_filter($compared))];

        if ($changed === []) {
            return;
        }

        $message = __('invoices.errors.payment_transaction_locked', ['number' => (string) $stored->invoice()->value('number')]);

        throw ValidationException::withMessages(array_fill_keys($changed, $message));
    }

    /**
     * Whether a value of a CZK key fixes the figure by hand: a typed figure, an own rate or its unit amount, or the
     * manual switch on. A null, an empty text and the manual switch off keep the figure computed, which is what a
     * form sends back unchanged.
     */
    private function setsFigure(string $key, mixed $value): bool
    {
        if ($key === 'czk_manual') {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === true;
        }

        return ! ($value === null || (is_string($value) && trim($value) === ''));
    }

    /**
     * The CZK columns to write; an empty array leaves the stored figure and snapshot untouched.
     *
     * @return array{czk_minor?: int, czk_source?: CzkSource, exchange_rate?: string|null, exchange_unit_amount?: int|null, exchange_rate_date?: string|null}
     */
    private function figure(Transaction $stored, TransactionInput $input): array
    {
        $wasManual = $stored->czk_source === CzkSource::Manual;
        $wasCustom = $stored->czk_source === CzkSource::Custom;

        $manual = $input->manualCzk();

        if (! $manual instanceof Money && $input->customRate instanceof CustomRate) {
            // A new own rate makes the transaction custom, whatever it was; its rate date is the transaction date.
            return $this->czk->resolve($input->amount, $input->transactionDate, null, $input->customRate);
        }

        if (! $manual instanceof Money && ($input->czkManual ?? $wasManual)) {
            // Manual stays manual: keep the stored figure (freezing it when manual is switched on without one).
            $manual = $stored->czk();
        }

        if ($manual instanceof Money) {
            return $this->czk->resolve($input->amount, $input->transactionDate, $manual);
        }

        if ($wasCustom && $input->czkManual === null && ! $input->customRateCleared) {
            return $this->stickyFigure($stored, $input);
        }

        $changed = $input->transactionDate !== $stored->transaction_date->toDateString()
            || ! $input->amount->equals($stored->amount);

        // A cleared own rate returns a custom transaction to the CNB rule even when nothing else changed; a CNB
        // transaction is never recomputed by it alone.
        if ($wasManual || $changed || ($wasCustom && $input->customRateCleared)) {
            return $this->czk->resolve($input->amount, $input->transactionDate, null);
        }

        return [];
    }

    /**
     * The figure of a transaction booked at an own rate when the data does not mention `czk_manual` (owner decision
     * A1 on D-06, the sticky rate): the rate is the one fixed for this transaction.
     * - a changed currency drops it for the CNB rule of the transaction date (identity for CZK);
     * - a changed amount in the same currency recomputes the figure at the stored rate and unit amount, and keeps
     *   the stored rate date, because the transaction date does not move a rate fixed for it;
     * - anything else, a changed date included, leaves the figure and the snapshot as stored.
     *
     * @return array{czk_minor?: int, czk_source?: CzkSource, exchange_rate?: string|null, exchange_unit_amount?: int|null, exchange_rate_date?: string|null}
     */
    private function stickyFigure(Transaction $stored, TransactionInput $input): array
    {
        if ($input->amount->currency !== $stored->amount_currency) {
            return $this->czk->resolve($input->amount, $input->transactionDate, null);
        }

        if ($input->amount->equals($stored->amount)) {
            return [];
        }

        $rate = new CustomRate((string) $stored->exchange_rate, (int) $stored->exchange_unit_amount, $stored->exchange_rate_date?->toDateString());

        return $this->czk->resolve($input->amount, $input->transactionDate, null, $rate);
    }
}
