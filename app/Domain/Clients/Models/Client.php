<?php

declare(strict_types=1);

namespace App\Domain\Clients\Models;

use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Money\MoneyCast;
use Carbon\CarbonInterface;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A client of the company. Admin-only data (D-06): a Partner reads no client
 * row through the model, a relation or a policy, so rates, payment terms and
 * billing data of a client are never exposed. A Partner account is linked to
 * its client through `users.client_id`, and the panel gate re-reads that row
 * as a system run on every request (see User::canAccessPanel()).
 *
 * Clients are archived (soft deleted), never hard deleted: every foreign key
 * that points here uses ON DELETE RESTRICT.
 *
 * The hourly rate is the virtual `hourly_rate` Money attribute over the
 * `hourly_rate_minor` and `hourly_rate_currency` column pair.
 *
 * @property string $id
 * @property string $name
 * @property string|null $company_number
 * @property string|null $tax_number
 * @property string $country
 * @property string|null $street
 * @property string|null $city
 * @property string|null $postal_code
 * @property string $stage
 * @property string $currency
 * @property Money|null $hourly_rate
 * @property int $hourly_rate_minor
 * @property string $hourly_rate_currency
 * @property int $payment_terms_days
 * @property string|null $invoice_email
 * @property string $invoice_language
 * @property bool $online_payment_enabled
 * @property CarbonInterface|null $deleted_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable([
    'name',
    'company_number',
    'tax_number',
    'country',
    'street',
    'city',
    'postal_code',
    'stage',
    'currency',
    'hourly_rate',
    'payment_terms_days',
    'invoice_email',
    'invoice_language',
    'online_payment_enabled',
])]
final class Client extends KokpitModel implements PartnerIsolated
{
    /** @use HasFactory<ClientFactory> */
    use DeniesPartners, HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'hourly_rate' => MoneyCast::class,
            'online_payment_enabled' => 'boolean',
            'payment_terms_days' => 'integer',
        ];
    }

    protected static function newFactory(): ClientFactory
    {
        return ClientFactory::new();
    }
}
