<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Clients\Models\Client;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Settings\Banking\BankAccount;
use App\Domain\Settings\Settings\BankAccountSettings;
use App\Domain\Shared\Money\Money;

/**
 * The currencies an invoice can be billed in (D-07, D-09): one list for the create-draft modal, the draft editor and
 * the open issued editor, independent of the bank account and of the payment method.
 *
 * It is the union of every currency CNB ever published a rate for, the currency of every configured bank account, the
 * currency of the client, the current currency of the document and CZK. CZK is always offered because CNB publishes
 * no CZK row (CZK needs no rate). A code Money cannot format is never offered, so a CNB currency the library does not
 * know cannot reach a document.
 *
 * The list reads {@see ExchangeRate}, which denies Partners, so it is called only from Admin pages and Admin Actions;
 * a Partner context gets the accounts, client, current currency and CZK only, which is never shown to a Partner. A
 * listed currency without a CNB list on the issue date is still the `cnb_missing` blocker of issuing, and one without a
 * bank account the refusal of the issued currency change; this class changes only what is offered.
 */
final readonly class InvoiceCurrencies
{
    /**
     * The offered codes, upper-case, de-duplicated and sorted.
     *
     * @return list<string>
     */
    public static function choices(?Client $client, ?string $current): array
    {
        /** @var list<string> $published */
        $published = ExchangeRate::query()->distinct()->pluck('currency')->all();

        $codes = [
            ...$published,
            ...array_map(static fn (BankAccount $account): string => $account->currency, app(BankAccountSettings::class)->accounts),
            'CZK',
        ];

        if ($client !== null) {
            $codes[] = $client->currency;
        }

        if ($current !== null) {
            $codes[] = $current;
        }

        $codes = array_values(array_unique(array_filter(
            array_map(static fn (string $code): string => strtoupper(trim($code)), $codes),
            static fn (string $code): bool => Money::isKnownCurrency($code),
        )));
        sort($codes);

        return $codes;
    }
}
