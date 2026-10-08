<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\RoleName;
use App\Domain\Shared\Auth\NotPartnerScoped;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * The application user: the single Admin or a client account (Partner).
 *
 * `client_id` links a Partner to its client (D-01) and stays null for the
 * Admin. It is deliberately not fillable: only trusted code assigns it, so a
 * user can never re-point themselves at another client through request input.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $client_id
 * @property CarbonInterface|null $deactivated_at
 * @property string|null $app_authentication_secret
 * @property array<int, string>|null $app_authentication_recovery_codes
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
#[NotPartnerScoped(reason: 'authentication and the scope itself load users')]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The panel gate, called by Filament at login and on every authenticated
     * request. Access needs all three of:
     * - the Admin or the Partner role;
     * - an account that is not deactivated (`deactivated_at` is null);
     * - for a Partner, a client that exists and is not archived. The Admin is
     *   not tied to a client row.
     *
     * Nothing is memoised: the client is looked up again on every call (one
     * primary-key query), so an archive committed by the Admin refuses the very
     * next request, even in a long-lived process or on a reused user object, and
     * the deactivation is read from the user the request just loaded. The client
     * lookup is an explicit system run because Client is closed to Partners (it
     * would otherwise always look missing); the soft-delete scope makes an
     * archived client not found. A failed check at login yields the same generic
     * credential error as a wrong password.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->hasAnyRole([RoleName::Admin->value, RoleName::Partner->value])) {
            return false;
        }

        if ($this->deactivated_at !== null) {
            return false;
        }

        if ($this->hasRole(RoleName::Admin->value)) {
            return true;
        }

        $clientId = $this->client_id;

        return is_string($clientId)
            && app(PartnerContext::class)->runAsSystem(
                static fn (): bool => Client::query()->whereKey($clientId)->exists(),
            );
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
