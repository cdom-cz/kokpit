<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Invoicing\Enums\DocumentPaymentMethod;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Payments\Support\CardPaymentSwitch;
use App\Domain\Payments\Support\PayToken;
use App\Domain\Settings\Banking\BankAccount;
use App\Domain\Settings\Settings\BankAccountSettings;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The one rule of how a document is paid (Phase 14; D-01, D-02, D-03, D-05, D-06, owner amendment OA-1): the payment
 * method, the chosen bank account and the card switch of a draft, read and written in one place so the draft Actions,
 * the blockers and the issue pipeline never disagree.
 *
 * - D-01, D-02: a new draft is `transfer`; `online` prints no bank details and is the Admin's deliberate choice. A
 *   credit note has neither a method nor an account.
 * - D-03: a `transfer` draft keeps the card switch, which defaults to the client's online-payment flag and stays the
 *   Admin's choice. `online` forces the switch on and needs card payment to be available (the global setting on,
 *   Stripe configured and the client flag on). Going back from `online` to `transfer` resets the switch to the client
 *   flag unless the Admin sends a value (OA-1).
 * - Phase 15 D-03, D-05: a `cash` draft keeps the switch off (an explicit request to turn it on is refused) and needs
 *   no bank account, whatever its currency; a stored account id is harmless and never printed.
 * - D-05, D-06: the account is chosen by id and independently of the currency. {@see DocumentPayment::preselect()} only
 *   suggests the first account of a currency in list order; an explicit account of any currency always wins and is
 *   never replaced. A stored account that was removed from the settings is not silently re-picked: the document then
 *   has no account ({@see DocumentPayment::account()}).
 *
 * Resolution of {@see DocumentPayment::account()}: the stored id, found in the settings, is the account; a stored id the
 * settings no longer know is no account; a document with no stored id (a draft created before the phase, or one created
 * when no account had its currency) falls back to the first account of its currency.
 *
 * - D-09, D-10, OA-1, OA-3, OA-7: an open issued document keeps the same choices editable until the lock
 *   ({@see DocumentPayment::forIssued()}). An issued `online` document carries no bank snapshot, an issued `transfer`
 *   document must carry one, and the pay token is set once and never replaced.
 *
 * Settings are read through `app(...)` at call time, never held, so a long-lived worker sees the current values. The
 * Admin-only row is read here, so every caller runs as the Admin or as system.
 */
final class DocumentPayment
{
    /** Why `online` cannot be offered although card payment works: the client has online payment switched off. */
    public const string CLIENT_OFF = 'client_off';

    /**
     * The method of the data value.
     *
     * @throws ValidationException `payment_method`
     */
    public static function method(mixed $value): DocumentPaymentMethod
    {
        $method = $value instanceof DocumentPaymentMethod
            ? $value
            : (is_string($value) ? DocumentPaymentMethod::tryFrom($value) : null);

        return $method ?? throw ValidationException::withMessages(['payment_method' => [__('invoices.errors.payment_method_invalid')]]);
    }

    /**
     * The id of a configured account, or null for no account. The account itself never travels, only the id of an
     * account the settings hold (T-14-12).
     *
     * @throws ValidationException `bank_account_id`
     */
    public static function accountId(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (is_string($value) && Str::isUuid($value) && app(BankAccountSettings::class)->find($value) instanceof BankAccount) {
            return $value;
        }

        throw ValidationException::withMessages(['bank_account_id' => [__('invoices.errors.bank_account_unknown')]]);
    }

    /**
     * The id of the first account of the currency in list order, or null when no account has it (D-05). The only
     * application caller of {@see BankAccountSettings::firstForCurrency()}, apart from the legacy fallback of
     * {@see DocumentPayment::account()}.
     */
    public static function preselect(string $currency): ?string
    {
        return app(BankAccountSettings::class)->firstForCurrency($currency)?->id;
    }

    /**
     * The account the document prints (or would print): the stored one when it is still configured, nothing when it
     * is stored but gone, and the first account of the currency for a document that stores none.
     */
    public static function account(Invoice $document): ?BankAccount
    {
        $settings = app(BankAccountSettings::class);
        $id = self::adminRow($document)?->bank_account_id;

        if ($id === null) {
            return $settings->firstForCurrency($document->currency);
        }

        return $settings->find($id);
    }

    /**
     * Why the document cannot be paid online now: `settings_off`, `stripe_missing`, {@see DocumentPayment::CLIENT_OFF},
     * or null when it can (D-03).
     */
    public static function onlineUnavailableReason(Invoice $document): ?string
    {
        $reason = app(CardPaymentSwitch::class)->unavailableReason();

        if ($reason !== null) {
            return $reason;
        }

        return $document->client?->online_payment_enabled === true ? null : self::CLIENT_OFF;
    }

    /**
     * The checked method, account and card switch of a draft save, or null for a credit-note draft that sends none of
     * them. Nothing is written here; the Action writes the answer.
     *
     * @param  array<string, mixed>  $data
     * @return array{payment_method: DocumentPaymentMethod, bank_account_id: ?string, card_payment_enabled: bool}|null
     *
     * @throws ValidationException `payment_method`, `bank_account_id`, `card_payment_enabled`
     */
    public static function forDraft(Invoice $lockedDraft, array $data, string $currency): ?array
    {
        $hasCard = array_key_exists('card_payment_enabled', $data);

        if ($lockedDraft->kind === InvoiceKind::CreditNote) {
            $errors = [];

            foreach (['payment_method', 'bank_account_id'] as $key) {
                if (array_key_exists($key, $data)) {
                    $errors[$key] = [__('invoices.errors.credit_note_no_payment')];
                }
            }

            if ($hasCard) {
                $errors['card_payment_enabled'] = [__('invoices.errors.card_payment_invalid')];
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            return null;
        }

        $admin = self::adminRow($lockedDraft);
        $stored = $lockedDraft->payment_method ?? DocumentPaymentMethod::Transfer;
        $method = array_key_exists('payment_method', $data) ? self::method($data['payment_method']) : $stored;

        if ($method === DocumentPaymentMethod::Online && $stored !== DocumentPaymentMethod::Online && self::onlineUnavailableReason($lockedDraft) !== null) {
            throw ValidationException::withMessages(['payment_method' => [__('invoices.errors.online_unavailable')]]);
        }

        if (array_key_exists('bank_account_id', $data)) {
            $accountId = self::accountId($data['bank_account_id']);
        } elseif ($currency !== $lockedDraft->currency) {
            $accountId = self::preselect($currency);
        } else {
            $accountId = $admin?->bank_account_id;
        }

        $explicit = null;

        if ($hasCard) {
            $explicit = $data['card_payment_enabled'];

            if (! is_bool($explicit)) {
                throw ValidationException::withMessages(['card_payment_enabled' => [__('invoices.errors.card_payment_invalid')]]);
            }
        }

        // D-03: cash excludes card payment. The switch stays off, and an explicit request to turn it on is refused.
        if ($method === DocumentPaymentMethod::Cash && $explicit === true) {
            throw ValidationException::withMessages(['card_payment_enabled' => [__('invoices.errors.card_payment_cash')]]);
        }

        $cardPayment = match (true) {
            $method === DocumentPaymentMethod::Cash => false,
            $method->forcesCardPayment() => true,
            $explicit !== null => $explicit,
            $stored === DocumentPaymentMethod::Online => $lockedDraft->kind->carriesPayment() && $lockedDraft->client?->online_payment_enabled === true,
            default => $admin instanceof InvoiceAdmin && $admin->card_payment_enabled,
        };

        return [
            'payment_method' => $method,
            'bank_account_id' => $accountId,
            'card_payment_enabled' => $cardPayment,
        ];
    }

    /**
     * The checked method, account, card switch, token and bank snapshot of an open issued invoice or proforma after an
     * Admin correction (Phase 14; D-01, D-03, D-05, D-09, D-10, OA-1, OA-3, OA-7). A credit note never reaches it,
     * because {@see IssuedDocumentRules::assertEditable()} refuses it first. Nothing is written here; the Action
     * writes the answer and decides what happens to the Stripe link from `method_changed` and `card_changed`.
     *
     * In this order: the method (a change to online needs card payment to be available); the account (the data, else
     * the first account of the new currency when the currency changed, else the stored one); the switch (for online it
     * stays on, and turns on only while card payment is available to the document; for transfer the Admin's boolean,
     * else the client flag when the document was online, else the stored one; a value that turns the switch on, or
     * keeps it on after a change from online, needs card payment to be available: a derived value falls back to off,
     * an explicit request is refused, WR-01); the snapshot (none for online; for transfer taken again
     * from the settings when the document was online, the account or the currency changed, or the refill was asked
     * for, otherwise the issued snapshot stays); the token (generated once when the switch is on and none exists).
     *
     * @param  array<string, mixed>  $data
     * @return array{payment_method: DocumentPaymentMethod, bank_account_id: ?string, card_payment_enabled: bool, pay_token: ?string, bank_snapshot?: array<string, string|null>|null, method_changed: bool, card_changed: bool, changed: bool}
     *
     * @throws ValidationException `payment_method`, `bank_account_id`, `card_payment_enabled`
     * @throws InvalidArgumentException for a refill flag that is not a boolean
     */
    public static function forIssued(Invoice $locked, array $data, string $currency, bool $currencyChanged): array
    {
        $admin = self::adminRow($locked);
        $storedMethod = $locked->payment_method ?? DocumentPaymentMethod::Transfer;
        $storedAccount = $admin?->bank_account_id;
        $storedSwitch = $admin instanceof InvoiceAdmin && $admin->card_payment_enabled;
        $storedToken = $admin?->pay_token;

        $reload = false;

        if (array_key_exists('reload_bank', $data)) {
            if (! is_bool($data['reload_bank'])) {
                throw new InvalidArgumentException('The bank refill flag of a document is a boolean.');
            }

            $reload = $data['reload_bank'];
        }

        $method = array_key_exists('payment_method', $data) ? self::method($data['payment_method']) : $storedMethod;
        $online = $method === DocumentPaymentMethod::Online;

        if ($online && $storedMethod !== DocumentPaymentMethod::Online && self::onlineUnavailableReason($locked) !== null) {
            throw ValidationException::withMessages(['payment_method' => [__('invoices.errors.online_unavailable')]]);
        }

        $accountSent = array_key_exists('bank_account_id', $data);

        if ($accountSent) {
            $accountId = self::accountId($data['bank_account_id']);
        } elseif ($currencyChanged) {
            $accountId = self::preselect($currency);
        } else {
            $accountId = $storedAccount;
        }

        $switch = self::issuedSwitch($locked, $data, $method, $storedMethod, $storedSwitch);

        $result = [
            'payment_method' => $method,
            'bank_account_id' => $accountId,
            'card_payment_enabled' => $switch,
            'pay_token' => $storedToken,
            'method_changed' => $method !== $storedMethod,
            'card_changed' => $switch !== $storedSwitch,
            'changed' => false,
        ];

        if ($online) {
            if ($locked->bank_snapshot !== null) {
                $result['bank_snapshot'] = null;
            }
        } else {
            $retake = $storedMethod === DocumentPaymentMethod::Online || $accountId !== $storedAccount || $currencyChanged || $reload;
            $taken = self::issuedSnapshot($currency, $accountSent, $accountId, $retake);

            if ($taken !== null) {
                $result['bank_account_id'] = $taken['account'];

                if ($taken['snapshot'] != $locked->bank_snapshot) {
                    $result['bank_snapshot'] = $taken['snapshot'];
                }
            }
        }

        if ($switch && $storedToken === null) {
            $result['pay_token'] = PayToken::generate();
        }

        $result['changed'] = $result['method_changed']
            || $result['card_changed']
            || $result['bank_account_id'] !== $storedAccount
            || $result['pay_token'] !== $storedToken
            || array_key_exists('bank_snapshot', $result);

        return $result;
    }

    /**
     * The card switch of an open issued document after the correction (D-03, OA-1, WR-01). Every result that turns the
     * switch on, or keeps it on after a change from online, needs card payment to be available: online keeps a stored
     * switch and turns a stored-off one on only while {@see DocumentPayment::onlineUnavailableReason()} is null;
     * transfer degrades a derived value to off and refuses an explicit `true`.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException `card_payment_enabled`
     */
    private static function issuedSwitch(Invoice $locked, array $data, DocumentPaymentMethod $method, DocumentPaymentMethod $storedMethod, bool $storedSwitch): bool
    {
        $explicit = null;

        if (array_key_exists('card_payment_enabled', $data)) {
            $explicit = $data['card_payment_enabled'];

            if (! is_bool($explicit)) {
                throw ValidationException::withMessages(['card_payment_enabled' => [__('invoices.errors.card_payment_invalid')]]);
            }
        }

        // Online keeps a switch that is on, and turns a stored-off one on only while card payment is available to the
        // document (WR-01, P14-09); a change from transfer to online is refused earlier while it is not.
        if ($method === DocumentPaymentMethod::Online) {
            return $storedSwitch || self::onlineUnavailableReason($locked) === null;
        }

        $switch = match (true) {
            $explicit !== null => $explicit,
            $storedMethod === DocumentPaymentMethod::Online => $locked->kind->carriesPayment() && $locked->client?->online_payment_enabled === true,
            default => $storedSwitch,
        };

        // A switch that turns on, or stays on after a change from online, needs card payment to be available (WR-01).
        if ($switch && (! $storedSwitch || $storedMethod === DocumentPaymentMethod::Online) && ! app(CardPaymentSwitch::class)->available()) {
            if ($explicit === true) {
                throw ValidationException::withMessages(['card_payment_enabled' => [__('invoices.errors.card_payment_unavailable')]]);
            }

            return false;
        }

        return $switch;
    }

    /**
     * The bank snapshot of an open issued transfer document: null when the issued one stays, else the account to
     * record and the snapshot copied from the settings (never from the request, T-10.1-28). The account is the
     * configured account of the id; when the data sent no id and the id is empty or no longer configured, the first
     * account of the currency (D-05). No account at all is `bank_account_required`.
     *
     * @return array{account: string, snapshot: array<string, string|null>}|null null when the issued snapshot stays
     *
     * @throws ValidationException `bank_account_id`
     */
    private static function issuedSnapshot(string $currency, bool $accountSent, ?string $accountId, bool $retake): ?array
    {
        $settings = app(BankAccountSettings::class);

        // An account sent as empty on a transfer document is refused even when nothing else asks for a new snapshot.
        if (! $retake && ! ($accountSent && $accountId === null)) {
            return null;
        }

        $account = $accountId === null ? null : $settings->find($accountId);

        if (! $account instanceof BankAccount && ! $accountSent) {
            $account = $settings->firstForCurrency($currency);
        }

        if (! $account instanceof BankAccount || $account->id === null) {
            throw ValidationException::withMessages(['bank_account_id' => [__('invoices.errors.bank_account_required')]]);
        }

        return ['account' => $account->id, 'snapshot' => app(InvoiceSnapshot::class)->bankAccount($account)];
    }

    /**
     * Writes the chosen account and the card switch on the Admin-only row of the document, under a row lock, creating
     * the row when the document has none. The pay token is written only when the stored one is empty: it is set once
     * and never replaced (D-10, T-14-23). A save that changes nothing writes nothing. Returns the row.
     */
    public static function saveAdmin(Invoice $locked, ?string $bankAccountId, bool $cardPayment, ?string $payToken = null): InvoiceAdmin
    {
        $admin = InvoiceAdmin::query()->where('invoice_id', $locked->id)->lockForUpdate()->first();

        if (! $admin instanceof InvoiceAdmin) {
            $admin = new InvoiceAdmin;
            $admin->forceFill(['invoice_id' => $locked->id, 'card_payment_enabled' => $cardPayment, 'bank_account_id' => $bankAccountId, 'pay_token' => $payToken])->save();

            return $admin;
        }

        $writeToken = $payToken !== null && $admin->pay_token === null;

        if ($admin->card_payment_enabled === $cardPayment && $admin->bank_account_id === $bankAccountId && ! $writeToken) {
            return $admin;
        }

        $values = ['card_payment_enabled' => $cardPayment, 'bank_account_id' => $bankAccountId];

        if ($writeToken) {
            $values['pay_token'] = $payToken;
        }

        $admin->forceFill($values)->save();

        return $admin;
    }

    private static function adminRow(Invoice $document): ?InvoiceAdmin
    {
        return InvoiceAdmin::query()->where('invoice_id', $document->id)->first();
    }
}
