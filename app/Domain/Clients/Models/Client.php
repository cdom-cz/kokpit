<?php

declare(strict_types=1);

namespace App\Domain\Clients\Models;

use App\Domain\Audit\LoggedAttributes;
use App\Domain\Audit\LogsAllowlistedActivity;
use App\Domain\Clients\Enums\ClientStage;
use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use App\Domain\Shared\Money\Money;
use App\Domain\Shared\Money\MoneyCast;
use ArrayAccess;
use Carbon\CarbonInterface;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Tags\HasTags;

/**
 * A client of the company. Admin-only data (D-06): a Partner reads no client
 * row through the model, a relation or a policy, so rates, payment terms and
 * billing data of a client are never exposed. A Partner account is linked to
 * its client through `users.client_id`, and the panel gate re-reads that row
 * as a system run on every request (see User::canAccessPanel()).
 *
 * Clients are archived (soft deleted), never hard deleted: every foreign key
 * that points here uses ON DELETE RESTRICT. Client tags are stored with the tag
 * type `client` and survive an archive (see detachTags()).
 *
 * Changes to the billing data and terms are written to the activity log through
 * an allowlist (D-06); the log itself is Admin-only.
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
 * @property ClientStage $stage
 * @property string $currency
 * @property Money|null $hourly_rate
 * @property int $hourly_rate_minor
 * @property string $hourly_rate_currency
 * @property int $payment_terms_days
 * @property string|null $invoice_email
 * @property InvoiceLanguage $invoice_language
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
#[LoggedAttributes([
    'name',
    'company_number',
    'tax_number',
    'country',
    'street',
    'city',
    'postal_code',
    'stage',
    'currency',
    'hourly_rate_minor',
    'hourly_rate_currency',
    'payment_terms_days',
    'invoice_email',
    'invoice_language',
    'online_payment_enabled',
])]
final class Client extends KokpitModel implements PartnerIsolated
{
    /** @use HasFactory<ClientFactory> */
    use DeniesPartners, HasFactory, LogsAllowlistedActivity, SoftDeletes;

    use HasTags {
        detachTags as private detachTagsFromTrait;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => ClientStage::class,
            'invoice_language' => InvoiceLanguage::class,
            'hourly_rate' => MoneyCast::class,
            'online_payment_enabled' => 'boolean',
            'payment_terms_days' => 'integer',
        ];
    }

    /**
     * Keeps the tags of an archived client. The tags package calls this from its
     * `deleted` listener, which also fires for a soft delete, so an archive would
     * otherwise detach every tag and a restore would not bring them back. Only a
     * force delete detaches them.
     *
     * @param  array<mixed>|ArrayAccess<int|string, mixed>  $tags
     */
    public function detachTags(array|ArrayAccess $tags, ?string $type = null): static
    {
        if ($this->trashed() && ! $this->isForceDeleting()) {
            return $this;
        }

        return $this->detachTagsFromTrait($tags, $type);
    }

    /**
     * The only way creation code sets a project's client: `client_id` is not
     * fillable on Project.
     *
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * The people of the client (CL-02). Admin-only: a Partner reads none.
     *
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * The Partner invitations issued for the client (US-02). Admin-only: a Partner
     * reads none. The rows change only through the invitation Actions.
     *
     * @return HasMany<ClientInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(ClientInvitation::class);
    }

    /**
     * The Partner accounts of the client (US-02, D-04), linked by `users.client_id`.
     * Admin-only: the accounts tab is the only place that lists them.
     *
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The one primary contact of the client, if it has any contact.
     *
     * @return HasOne<Contact, $this>
     */
    public function primaryContact(): HasOne
    {
        return $this->hasOne(Contact::class)->where('is_primary', true);
    }

    protected static function newFactory(): ClientFactory
    {
        return ClientFactory::new();
    }
}
