<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use Illuminate\Validation\ValidationException;

/**
 * Validation and normalisation of the contact fields shared by CreateContact and
 * UpdateContact (CL-02, D-12). It knows the text fields and the billing flag and
 * nothing else: the primary flag and the client are never read from input.
 *
 * @phpstan-type ContactData array{
 *     name?: mixed,
 *     email?: mixed,
 *     phone?: mixed,
 *     position?: mixed,
 *     is_billing?: mixed,
 * }
 * @phpstan-type ContactAttributes array{name: string, email: string|null, phone: string|null, position: string|null, is_billing: bool}
 */
final class ContactInput
{
    /**
     * Validates and normalises the input: text trimmed, empty optional text
     * becomes null. Every problem is reported at once, keyed by the data key.
     *
     * @param  array<string, mixed>  $data  read defensively because a crafted payload may miss keys
     * @return ContactAttributes
     *
     * @throws ValidationException
     */
    public static function attributes(array $data): array
    {
        $errors = [];

        $name = self::text($data['name'] ?? null);

        if ($name === null || mb_strlen($name) > 255) {
            $errors['name'] = __('kokpit.contacts.errors.name_invalid');
        }

        $email = self::text($data['email'] ?? null);

        if ($email !== null && (mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['email'] = __('kokpit.contacts.errors.email_invalid');
        }

        $phone = self::text($data['phone'] ?? null);

        if ($phone !== null && mb_strlen($phone) > 50) {
            $errors['phone'] = __('kokpit.contacts.errors.phone_invalid');
        }

        $position = self::text($data['position'] ?? null);

        if ($position !== null && mb_strlen($position) > 255) {
            $errors['position'] = __('kokpit.contacts.errors.position_invalid');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'name' => (string) $name,
            'email' => $email,
            'phone' => $phone,
            'position' => $position,
            'is_billing' => (bool) ($data['is_billing'] ?? false),
        ];
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
