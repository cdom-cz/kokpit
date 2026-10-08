<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a contact to a client (CL-02, D-12).
 *
 * The first contact of a client becomes its primary contact. The decision is
 * made in one transaction with the client row locked, so two contacts created at
 * the same moment cannot both see "no contact yet" and both claim the flag; the
 * partial unique index `contacts_one_primary_per_client` is the database
 * backstop behind it. `is_primary` is never read from the input: it is not
 * fillable and is set here with `forceFill`.
 *
 * Any number of contacts can be billing contacts (`is_billing`).
 *
 * @phpstan-type ContactData array{
 *     name?: mixed,
 *     email?: mixed,
 *     phone?: mixed,
 *     position?: mixed,
 *     is_billing?: mixed,
 * }
 */
final class CreateContact
{
    /**
     * @param  ContactData  $data
     *
     * @throws ValidationException
     */
    public function handle(Client $client, array $data): Contact
    {
        $attributes = $this->attributes($data);

        return DB::transaction(static function () use ($client, $attributes): Contact {
            // Serialises every contact write of this client; the archived client is locked too.
            Client::query()->withTrashed()->whereKey($client->getKey())->lockForUpdate()->firstOrFail();

            $contact = $client->contacts()->make($attributes);

            if (! $client->contacts()->exists()) {
                $contact->forceFill(['is_primary' => true]);
            }

            $contact->save();

            return $contact->refresh();
        });
    }

    /**
     * Validates and normalises the input: text trimmed, empty optional text
     * becomes null. Every problem is reported at once, keyed by the data key.
     *
     * @param  array<string, mixed>  $data  read defensively because a crafted payload may miss keys
     * @return array{name: string, email: string|null, phone: string|null, position: string|null, is_billing: bool}
     *
     * @throws ValidationException
     */
    private function attributes(array $data): array
    {
        $errors = [];

        $name = $this->text($data['name'] ?? null);

        if ($name === null || mb_strlen($name) > 255) {
            $errors['name'] = __('kokpit.contacts.errors.name_invalid');
        }

        $email = $this->text($data['email'] ?? null);

        if ($email !== null && (mb_strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors['email'] = __('kokpit.contacts.errors.email_invalid');
        }

        $phone = $this->text($data['phone'] ?? null);

        if ($phone !== null && mb_strlen($phone) > 50) {
            $errors['phone'] = __('kokpit.contacts.errors.phone_invalid');
        }

        $position = $this->text($data['position'] ?? null);

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

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
