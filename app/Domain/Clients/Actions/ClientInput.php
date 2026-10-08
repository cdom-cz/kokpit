<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Enums\ClientStage;
use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Shared\Money\Money;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;

/**
 * The input rules that CreateClient and UpdateClient share, so a client is
 * validated the same way whichever Action writes it (CL-01, D-09, D-10). Every
 * error is a ValidationException keyed by the data key; the Admin form maps the
 * key to its state path. No Filament dependency.
 */
final class ClientInput
{
    /**
     * Parses the hourly rate typed in major units in the client currency. Nothing
     * is rounded: more decimals than the currency allows, a grouping character, a
     * negative sign or an amount beyond the integer range is an error.
     *
     * @throws InvalidArgumentException when the text is not an acceptable amount
     */
    public static function rate(string $text, string $currency): Money
    {
        try {
            $money = Money::fromMajor($text, $currency);
        } catch (OverflowException $e) {
            throw new InvalidArgumentException('The amount is out of range.', 0, $e);
        }

        if ($money->minor < 0) {
            throw new InvalidArgumentException('The amount must not be negative.');
        }

        return $money;
    }

    /**
     * Validates and normalises the data of a client into model attributes: text
     * trimmed (empty optional text becomes null), country and currency upper-cased,
     * the stage and the invoice language checked against their enums, the payment
     * terms within 0 to 365 days and the rate converted to a Money pair in the
     * client currency. Every problem is reported at once, keyed by the data key.
     *
     * @param  array<string, mixed>  $data  a ClientData shape, read defensively because a crafted payload may miss keys
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function attributes(array $data): array
    {
        $errors = [];

        $name = self::text($data['name'] ?? null);

        if ($name === null || mb_strlen($name) > 255) {
            $errors['name'] = __('kokpit.clients.errors.name_invalid');
        }

        $country = Str::upper((string) self::text($data['country'] ?? null));

        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            $errors['country'] = __('kokpit.clients.errors.country_invalid');
        }

        $currency = Str::upper((string) self::text($data['currency'] ?? null));

        if (! Money::isKnownCurrency($currency)) {
            $errors['currency'] = __('kokpit.clients.errors.currency_invalid');
        }

        $stage = ClientStage::tryFrom((string) ($data['stage'] ?? ''));

        if ($stage === null) {
            $errors['stage'] = __('kokpit.clients.errors.stage_invalid');
        }

        $language = InvoiceLanguage::tryFrom((string) ($data['invoice_language'] ?? ''));

        if ($language === null) {
            $errors['invoice_language'] = __('kokpit.clients.errors.language_invalid');
        }

        $terms = self::terms($data['payment_terms_days'] ?? null);

        if ($terms === null) {
            $errors['payment_terms_days'] = __('kokpit.clients.errors.terms_invalid');
        }

        $email = self::text($data['invoice_email'] ?? null);

        if ($email !== null && (mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['invoice_email'] = __('kokpit.clients.errors.email_invalid');
        }

        $rate = null;
        $rateText = self::text($data['hourly_rate'] ?? null);

        if ($rateText !== null && ! isset($errors['currency'])) {
            try {
                $rate = self::rate($rateText, $currency);
            } catch (InvalidArgumentException) {
                // Falls through to the error below.
            }
        }

        if ($rate === null) {
            $errors['hourly_rate'] = __('kokpit.clients.errors.rate_invalid');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'name' => $name,
            'company_number' => self::text($data['company_number'] ?? null),
            'tax_number' => self::text($data['tax_number'] ?? null),
            'country' => $country,
            'street' => self::text($data['street'] ?? null),
            'city' => self::text($data['city'] ?? null),
            'postal_code' => self::text($data['postal_code'] ?? null),
            'stage' => $stage,
            'currency' => $currency,
            'hourly_rate' => $rate,
            'payment_terms_days' => $terms,
            'invoice_email' => $email,
            'invoice_language' => $language,
            'online_payment_enabled' => (bool) ($data['online_payment_enabled'] ?? false),
        ];
    }

    /**
     * The payment terms as whole days from 0 to 365, or null when missing or out of range.
     */
    private static function terms(mixed $value): ?int
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        // A numeric form input may hand over a whole float such as 14.0.
        if (is_float($value) && floor($value) === $value && abs($value) < 1000) {
            $value = (int) $value;
        }

        if (is_int($value) || (is_string($value) && preg_match('/^\d{1,3}$/', $value) === 1)) {
            $days = (int) $value;

            return $days >= 0 && $days <= 365 ? $days : null;
        }

        return null;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
