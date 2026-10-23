<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Invoicing\Enums\DocumentPaymentMethod;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Payments\Support\CardPaymentSwitch;
use App\Domain\Settings\Banking\BankAccount;
use App\Domain\Settings\Settings\BankAccountSettings;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
 * - D-05, D-06: the account is chosen by id and independently of the currency. {@see DocumentPayment::preselect()} only
 *   suggests the first account of a currency in list order; an explicit account of any currency always wins and is
 *   never replaced. A stored account that was removed from the settings is not silently re-picked: the document then
 *   has no account ({@see DocumentPayment::account()}).
 *
 * Resolution of {@see DocumentPayment::account()}: the stored id, found in the settings, is the account; a stored id the
 * settings no longer know is no account; a document with no stored id (a draft created before the phase, or one created
 * when no account had its currency) falls back to the first account of its currency.
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

        $cardPayment = match (true) {
            $method === DocumentPaymentMethod::Online => true,
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
     * Writes the chosen account and the card switch on the Admin-only row of the draft, under a row lock, creating
     * the row when the draft has none. A save that changes nothing writes nothing. Returns the row.
     */
    public static function saveAdmin(Invoice $locked, ?string $bankAccountId, bool $cardPayment): InvoiceAdmin
    {
        $admin = InvoiceAdmin::query()->where('invoice_id', $locked->id)->lockForUpdate()->first();

        if (! $admin instanceof InvoiceAdmin) {
            $admin = new InvoiceAdmin;
            $admin->forceFill(['invoice_id' => $locked->id, 'card_payment_enabled' => $cardPayment, 'bank_account_id' => $bankAccountId])->save();

            return $admin;
        }

        if ($admin->card_payment_enabled === $cardPayment && $admin->bank_account_id === $bankAccountId) {
            return $admin;
        }

        $admin->forceFill(['card_payment_enabled' => $cardPayment, 'bank_account_id' => $bankAccountId])->save();

        return $admin;
    }

    private static function adminRow(Invoice $document): ?InvoiceAdmin
    {
        return InvoiceAdmin::query()->where('invoice_id', $document->id)->first();
    }
}
