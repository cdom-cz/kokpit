<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Invoicing\Actions\UpdateIssuedDocument;
use App\Domain\Settings\Settings\SupplierSettings;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Checks the customer and supplier lines an open issued document is corrected with ({@see UpdateIssuedDocument}, D-06,
 * assumption A9, R-10.1-07-3). Both are free fields of the snapshots of this one document: the stored snapshot is the
 * starting point, a field absent from the input keeps its stored value, an unknown key is ignored, and the values that
 * are given are trimmed (an empty value becomes null).
 *
 * The limits are the ones the sources of the data already use, so a refill from the client card or from the settings
 * always fits: the customer follows the columns of the client ({@see ClientInput}), the supplier follows
 * {@see SupplierSettings::rules()}. Only a field whose value differs from the stored one is checked, so a stored value
 * that today's rules would refuse never locks the document against a correction of something else.
 *
 * Every problem is a {@see ValidationException} keyed `customer.<field>` or `supplier.<field>`.
 */
final class SnapshotInput
{
    /** The rules of the customer lines: the width of the client columns, the country as the client form takes it. */
    private const array CUSTOMER_RULES = [
        'name' => ['required', 'string', 'max:255'],
        'street' => ['string', 'max:255'],
        'city' => ['string', 'max:255'],
        'postal_code' => ['string', 'max:20'],
        'country' => ['required', 'regex:/^[A-Z]{2}$/'],
        'company_id' => ['string', 'max:32'],
        'vat_id' => ['string', 'max:32'],
    ];

    /**
     * The customer lines of a document: `name`, `street`, `city`, `postal_code`, `country`, `company_id`, `vat_id`.
     *
     * @param  array<array-key, mixed>  $input
     * @param  array<array-key, mixed>  $stored  the customer snapshot of the document
     * @return array<array-key, mixed>
     *
     * @throws ValidationException
     */
    public static function customer(array $input, array $stored): array
    {
        return self::party('customer', self::CUSTOMER_RULES, $input, $stored);
    }

    /**
     * The supplier lines of a document: `name` (`company_name` is accepted for it), `street`, `city`, `postal_code`,
     * `country`, `company_id`, `vat_id`, `email`, `phone`, `website`, `registration_note`. The limits are
     * {@see SupplierSettings::rules()}. The VAT payer flag is not a line of the form: it is copied from the stored
     * snapshot whatever the input carries (A9).
     *
     * @param  array<array-key, mixed>  $input
     * @param  array<array-key, mixed>  $stored  the supplier snapshot of the document
     * @return array<array-key, mixed>
     *
     * @throws ValidationException
     */
    public static function supplier(array $input, array $stored): array
    {
        if (! array_key_exists('name', $input) && array_key_exists('company_name', $input)) {
            $input['name'] = $input['company_name'];
        }

        $rules = SupplierSettings::rules();
        $supplier = self::party('supplier', [
            'name' => $rules['company_name'],
            'street' => $rules['street'],
            'city' => $rules['city'],
            'postal_code' => $rules['postal_code'],
            'country' => $rules['country'],
            'company_id' => $rules['company_id'],
            'vat_id' => $rules['vat_id'],
            'email' => $rules['email'],
            'phone' => $rules['phone'],
            'website' => $rules['website'],
            'registration_note' => $rules['registration_note'],
        ], $input, $stored);

        $supplier['vat_payer'] = $stored['vat_payer'] ?? false;

        return $supplier;
    }

    /**
     * Applies the input over the stored snapshot of one party.
     *
     * @param  array<string, list<string>>  $rules  the rules by field
     * @param  array<array-key, mixed>  $input
     * @param  array<array-key, mixed>  $stored
     * @return array<array-key, mixed>
     *
     * @throws ValidationException
     */
    private static function party(string $party, array $rules, array $input, array $stored): array
    {
        $result = $stored;
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            if (! array_key_exists($field, $input)) {
                continue;
            }

            $value = self::value($input[$field], $field === 'country');

            if ($value === false) {
                $errors["{$party}.{$field}"] = __('invoices.errors.party_format');

                continue;
            }

            if ($value === self::value($stored[$field] ?? null, $field === 'country')) {
                continue;
            }

            $problem = self::problem($field, $fieldRules, $value);

            if ($problem !== null) {
                $errors["{$party}.{$field}"] = $problem;

                continue;
            }

            $result[$field] = $value;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $result;
    }

    /**
     * The copy of the first rule the value breaks, or null when it passes.
     *
     * @param  list<string>  $rules
     */
    private static function problem(string $field, array $rules, ?string $value): ?string
    {
        if ($value === null && ! in_array('required', $rules, true)) {
            return null;
        }

        $validator = Validator::make([$field => $value], [$field => $rules]);

        if (! $validator->fails()) {
            return null;
        }

        $failed = $validator->failed()[$field] ?? [];
        $rule = array_key_first($failed);
        $parameters = $rule === null ? [] : $failed[$rule];

        return match (true) {
            $field === 'country' => __('invoices.errors.party_country'),
            $rule === 'Required' => __('invoices.errors.party_required'),
            $rule === 'Max' => __('invoices.errors.party_max', ['max' => $parameters[0] ?? '']),
            default => __('invoices.errors.party_format'),
        };
    }

    /**
     * The value as it is stored: trimmed, empty as null, the country in capitals. False for a value that is not text.
     */
    private static function value(mixed $raw, bool $country): string|false|null
    {
        if ($raw === null) {
            return null;
        }

        if (! is_string($raw) && ! is_int($raw) && ! is_float($raw)) {
            return false;
        }

        $text = trim((string) $raw);

        if ($text === '') {
            return null;
        }

        return $country ? mb_strtoupper($text) : $text;
    }
}
