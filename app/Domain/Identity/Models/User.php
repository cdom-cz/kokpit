<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\RoleName;
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
 * @property string|null $app_authentication_secret
 * @property array<int, string>|null $app_authentication_recovery_codes
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
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
            'password' => 'hashed',
        ];
    }

    /**
     * Only the Admin and Partner roles may enter the panel; a user without
     * either role is turned away even with a valid password.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasAnyRole([RoleName::Admin->value, RoleName::Partner->value]);
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
