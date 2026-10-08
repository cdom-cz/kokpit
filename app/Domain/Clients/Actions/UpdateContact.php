<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Models\Contact;
use Illuminate\Validation\ValidationException;

/**
 * Edits a contact: name, e-mail, phone, position and the billing flag (CL-02, D-12).
 *
 * It never touches the primary flag or the client, even when the input carries
 * `is_primary` or `client_id`: those columns are not fillable and the input is
 * reduced to the known fields first. The primary flag moves only through
 * SetPrimaryContact. The client's invoice e-mail is a separate field and is
 * never read or written here.
 */
final class UpdateContact
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handle(Contact $contact, array $data): Contact
    {
        $contact->fill(ContactInput::attributes($data))->save();

        return $contact->refresh();
    }
}
