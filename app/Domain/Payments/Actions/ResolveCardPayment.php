<?php

declare(strict_types=1);

namespace App\Domain\Payments\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Payments\Enums\CardPaymentResolution;
use App\Domain\Payments\Enums\CardPaymentType;
use App\Domain\Payments\Models\CardPayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The Admin closes a duplicate, an unmatched payment or a refund event with a note (PA-08; D-13, ruling R11-19-2). No
 * money moves and nothing else is written: the record becomes resolved as noted, for good.
 *
 * The record is locked first. A resolved record is refused with `already_resolved` and a matched one with
 * `not_resolvable` (key `card_payment`); the note is trimmed and must be 1 to 500 characters, a field error under
 * `note` otherwise, never cut. The Admin only.
 */
final readonly class ResolveCardPayment
{
    private const int NOTE_MAX_LENGTH = 500;

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, CardPayment $record, string $note): CardPayment
    {
        Gate::forUser($actor)->authorize('update', $record);

        return DB::transaction(function () use ($actor, $record, $note): CardPayment {
            $locked = CardPayment::query()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->resolution !== CardPaymentResolution::None) {
                throw ValidationException::withMessages(['card_payment' => __('card_payments.errors.already_resolved')]);
            }

            if ($locked->type === CardPaymentType::Matched) {
                throw ValidationException::withMessages(['card_payment' => __('card_payments.errors.not_resolvable')]);
            }

            $text = trim($note);

            if ($text === '') {
                throw ValidationException::withMessages(['note' => __('card_payments.errors.note_required')]);
            }

            if (mb_strlen($text) > self::NOTE_MAX_LENGTH) {
                throw ValidationException::withMessages(['note' => __('card_payments.errors.note_length')]);
            }

            $locked->forceFill([
                'resolution' => CardPaymentResolution::Noted,
                'resolved_at' => CarbonImmutable::now(),
                'resolved_by' => $actor->getKey(),
                'resolution_note' => $text,
            ])->save();

            return $locked;
        });
    }
}
