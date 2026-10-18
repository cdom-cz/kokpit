<?php

declare(strict_types=1);

namespace App\Domain\Payments\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Builder;

/**
 * The tabs of the payments overview (UI-SPEC Surface H, ruling R11-11-2). Each tab is one condition over the table
 * `card_payments`, defined once here, so a tab and its count can never disagree (the counts are the very conditions
 * as `COUNT(*) FILTER`). The tabs after "Nevyřešené" and "Všechny" partition the table:
 *
 * - unresolved: a duplicate, an unmatched payment or a refund event nobody has resolved yet (the queue, the default);
 * - all: every record;
 * - matched: a payment booked to its document (also one whose payment was deleted afterwards);
 * - duplicate, unmatched, refund: the unresolved ones of that type, so what is still open is found by its kind;
 * - resolved: every record the Admin resolved, whatever its type.
 */
enum CardPaymentTab: string implements HasLabel
{
    case Unresolved = 'unresolved';
    case All = 'all';
    case Matched = 'matched';
    case Duplicate = 'duplicate';
    case Unmatched = 'unmatched';
    case Refund = 'refund';
    case Resolved = 'resolved';

    public function getLabel(): string
    {
        return __('card_payments.tabs.'.$this->value);
    }

    /**
     * The tab as SQL over the table `card_payments`.
     *
     * @return literal-string
     */
    public function condition(): string
    {
        return match ($this) {
            self::Unresolved => "card_payments.type IN ('duplicate', 'unmatched', 'refund') AND card_payments.resolution = 'none'",
            self::All => 'TRUE',
            self::Matched => "card_payments.type = 'matched'",
            self::Duplicate => "card_payments.type = 'duplicate' AND card_payments.resolution = 'none'",
            self::Unmatched => "card_payments.type = 'unmatched' AND card_payments.resolution = 'none'",
            self::Refund => "card_payments.type = 'refund' AND card_payments.resolution = 'none'",
            self::Resolved => "card_payments.resolution <> 'none'",
        };
    }

    /**
     * Narrows a query over `card_payments` to the tab.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function constrain(Builder $query): Builder
    {
        return $query->whereRaw($this->condition());
    }
}
