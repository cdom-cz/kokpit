<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Clients\Models\Contact;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The JSON of one contact on the birthday list.
 *
 * Every key is listed explicitly and none of the model is passed through, so a column added to the table later never
 * reaches the API by accident. A contact is personal data of a client: the full contact is exposed only to tokens
 * with the `contacts:read` ability, and only to the Admin. `client_name` comes from the loaded client. `age` is the
 * age turned in the year of the day asked for, or null when the birth year is unknown. Every key is always present;
 * a missing value is null. Instants are ISO 8601 in UTC.
 *
 * @property-read Contact $resource
 */
final class ContactResource extends JsonResource
{
    public function __construct(Contact $resource, private readonly CarbonInterface $day)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $contact = $this->resource;

        return [
            'id' => $contact->id,
            'client_id' => $contact->client_id,
            'client_name' => $contact->client?->name,
            'name' => $contact->name,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'position' => $contact->position,
            'is_primary' => $contact->is_primary,
            'is_billing' => $contact->is_billing,
            'birth_day' => $contact->birth_day,
            'birth_month' => $contact->birth_month,
            'birth_year' => $contact->birth_year,
            'age' => $contact->ageOn($this->day),
            'created_at' => self::instant($contact->created_at),
            'updated_at' => self::instant($contact->updated_at),
        ];
    }

    private static function instant(?CarbonInterface $instant): ?string
    {
        return $instant?->utc()->toIso8601String();
    }
}
