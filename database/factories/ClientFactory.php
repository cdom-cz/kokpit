<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Clients\Models\Client;
use App\Domain\Shared\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Fictional clients only: the name is assembled at runtime, there is no
 * company number and no real address.
 *
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    /**
     * @var class-string<Client>
     */
    protected $model = Client::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Example client '.Str::lower(Str::random(8)),
            'country' => 'CZ',
            'stage' => 'active',
            'currency' => 'CZK',
            'hourly_rate' => Money::ofMinor(0, 'CZK'),
            'payment_terms_days' => 14,
            'invoice_language' => 'cs',
            'online_payment_enabled' => false,
        ];
    }
}
