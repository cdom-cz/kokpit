<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Clients\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The JSON of one client lookup (research A5): the id and the name, nothing else.
 *
 * The client model carries the hourly rate, payment terms, tax and company numbers, the invoice e-mail and the
 * address. None of it leaves through a lookup (D-10, T-07-36), so the keys are listed explicitly and no
 * attribute is passed through.
 *
 * @property-read Client $resource
 */
final class ClientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
        ];
    }
}
