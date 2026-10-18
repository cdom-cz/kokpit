<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Clients\Models\Client;
use App\Domain\Finance\Actions\RecordTransaction;
use App\Domain\Identity\Models\User;
use App\Domain\Payments\Enums\CardPaymentResolution;
use App\Domain\Payments\Enums\CardPaymentType;
use App\Domain\Payments\Models\CardPayment;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;

/**
 * The Admin records that the money of a duplicate or unmatched payment was returned (PA-08; D-13, Phase 10 D-16,
 * UI-SPEC U-12, ruling R11-19-3). Kokpit returns nothing: there is no Stripe call and no refund automation. The return
 * is a manually entered expense through the one finance write path ({@see RecordTransaction}), in the currency of the
 * payment and for at most its amount, linked from the record, which becomes resolved as returned, for good.
 *
 * Data keys: `amount` (a positive decimal, a comma or a dot), `returned_on` (the day of the expense), `category_id`
 * (an expense category), `note` (optional, at most 500 characters, kept as the note of the resolution). The expense
 * carries the client of the document of the record when there is one and the client still exists.
 *
 * The record is locked first. A resolved record is refused with `already_resolved`, a matched or refund record with
 * `not_returnable` (key `card_payment`). The Admin only.
 */
final readonly class RecordPaymentReturn
{
    private const int NOTE_MAX_LENGTH = 500;

    public function __construct(private RecordTransaction $transactions) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handle(User $actor, CardPayment $record, array $data): CardPayment
    {
        Gate::forUser($actor)->authorize('update', $record);

        return DB::transaction(function () use ($actor, $record, $data): CardPayment {
            $locked = CardPayment::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->resolution !== CardPaymentResolution::None) {
                throw ValidationException::withMessages(['card_payment' => __('card_payments.errors.already_resolved')]);
            }

            if (! in_array($locked->type, [CardPaymentType::Duplicate, CardPaymentType::Unmatched], true)) {
                throw ValidationException::withMessages(['card_payment' => __('card_payments.errors.not_returnable')]);
            }

            $amount = $this->amount($data['amount'] ?? null, $locked);
            $note = $this->note($data['note'] ?? null);

            $expense = $this->transactions->handle($actor, [
                'type' => 'expense',
                'transaction_date' => $data['returned_on'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'amount' => $amount->toMajor(),
                'currency' => $locked->currency,
                'description' => __('card_payments.return.description'),
                'client_id' => $this->clientId($locked),
            ]);

            $locked->forceFill([
                'resolution' => CardPaymentResolution::Returned,
                'resolved_at' => CarbonImmutable::now(),
                'resolved_by' => $actor->getKey(),
                'resolution_note' => $note,
                'return_transaction_id' => $expense->getKey(),
            ])->save();

            return $locked;
        });
    }

    /**
     * @throws ValidationException
     */
    private function amount(mixed $value, CardPayment $record): Money
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            throw ValidationException::withMessages(['amount' => __('card_payments.errors.return_amount_invalid')]);
        }

        try {
            $amount = Money::fromMajor((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $value), $record->currency);
        } catch (InvalidArgumentException|OverflowException) {
            throw ValidationException::withMessages(['amount' => __('card_payments.errors.return_amount_invalid')]);
        }

        if (! $amount->isPositive()) {
            throw ValidationException::withMessages(['amount' => __('card_payments.errors.return_amount_invalid')]);
        }

        if ($amount->minor > $record->amount_minor) {
            throw ValidationException::withMessages(['amount' => __('card_payments.errors.return_above_payment')]);
        }

        return $amount;
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

        if (! is_string($value) || mb_strlen($text) > self::NOTE_MAX_LENGTH) {
            throw ValidationException::withMessages(['note' => __('card_payments.errors.note_length')]);
        }

        return $text === '' ? null : $text;
    }

    /**
     * The client of the document the payment was meant for, when there is one and it is not archived (an archived
     * client cannot take a new transaction, and the return must still be recordable).
     */
    private function clientId(CardPayment $record): ?string
    {
        $clientId = $record->invoice?->client_id;

        if ($clientId === null) {
            return null;
        }

        return Client::query()->whereKey($clientId)->exists() ? $clientId : null;
    }
}
