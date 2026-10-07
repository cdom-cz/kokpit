<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleSeeder;
use Filament\Auth\Pages\Login;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Tests\Support\Uuids;

/**
 * Runs a callback with KOKPIT_ADMIN_PASSWORD set (or removed) and restores it afterwards.
 */
function withAdminPasswordEnv(?string $value, Closure $callback): void
{
    $name = 'KOKPIT_ADMIN_PASSWORD';
    $previous = getenv($name);

    $value === null ? putenv($name) : putenv($name.'='.$value);

    try {
        $callback();
    } finally {
        $previous === false ? putenv($name) : putenv($name.'='.$previous);
    }
}

function generatedPassword(): string
{
    return Str::password(20);
}

it('creates both roles idempotently through the seeder', function (): void {
    $this->seed(RoleSeeder::class);
    $this->seed(RoleSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(Role::query()->pluck('name')->sort()->values()->all())->toBe(['admin', 'partner'])
        ->and(Role::query()->first()->id)->toMatch(Uuids::V7_PATTERN);
});

it('labels both roles in Czech', function (): void {
    expect(RoleName::Admin->getLabel())->toBe('Administrátor')
        ->and(RoleName::Partner->getLabel())->toBe('Partner');
});

it('creates exactly one Admin from the interactive prompts', function (): void {
    $password = generatedPassword();
    $email = exampleEmail();

    $this->artisan('kokpit:install')
        ->expectsQuestion(__('kokpit.install.prompt_name'), 'Jane Example')
        ->expectsQuestion(__('kokpit.install.prompt_email'), $email)
        ->expectsQuestion(__('kokpit.install.prompt_password'), $password)
        ->expectsQuestion(__('kokpit.install.prompt_password_confirmation'), $password)
        ->expectsOutputToContain(__('kokpit.install.created'))
        ->doesntExpectOutputToContain($password)
        ->assertExitCode(0);

    $user = User::query()->sole();

    expect($user->email)->toBe($email)
        ->and($user->hasRole(RoleName::Admin->value))->toBeTrue()
        ->and($user->password)->not->toBe($password)
        ->and(Hash::check($password, $user->password))->toBeTrue()
        ->and($user->app_authentication_secret)->toBeNull()
        ->and($user->client_id)->toBeNull()
        ->and($user->id)->toMatch(Uuids::V7_PATTERN)
        ->and(Role::query()->count())->toBe(2);
});

it('refuses an interactive run whose password confirmation differs', function (): void {
    $this->artisan('kokpit:install', ['--name' => 'Jane Example', '--email' => exampleEmail()])
        ->expectsQuestion(__('kokpit.install.prompt_password'), generatedPassword())
        ->expectsQuestion(__('kokpit.install.prompt_password_confirmation'), generatedPassword())
        ->expectsOutputToContain(__('kokpit.install.password_mismatch'))
        ->assertExitCode(1);

    expect(User::query()->count())->toBe(0);
});

it('reads the password from the environment variable without interaction', function (): void {
    $password = generatedPassword();
    $email = exampleEmail();

    withAdminPasswordEnv($password, function () use ($email, $password): void {
        $this->artisan('kokpit:install', ['--name' => 'Jane Example', '--email' => $email, '--no-interaction' => true])
            ->doesntExpectOutputToContain($password)
            ->assertExitCode(0);
    });

    $user = User::query()->sole();

    expect($user->email)->toBe($email)
        ->and($user->hasRole(RoleName::Admin->value))->toBeTrue()
        ->and(Hash::check($password, $user->password))->toBeTrue();
});

it('fails without creating anything when the environment variable is empty', function (): void {
    withAdminPasswordEnv('', function (): void {
        $this->artisan('kokpit:install', ['--name' => 'Jane Example', '--email' => exampleEmail(), '--no-interaction' => true])
            ->expectsOutputToContain(__('kokpit.install.password_env_missing'))
            ->assertExitCode(1);
    });

    withAdminPasswordEnv(null, function (): void {
        $this->artisan('kokpit:install', ['--name' => 'Jane Example', '--email' => exampleEmail(), '--no-interaction' => true])
            ->assertExitCode(1);
    });

    expect(User::query()->count())->toBe(0);
});

it('requires name and e-mail without interaction', function (): void {
    withAdminPasswordEnv(generatedPassword(), function (): void {
        $this->artisan('kokpit:install', ['--no-interaction' => true])
            ->expectsOutputToContain(__('kokpit.install.name_email_required'))
            ->assertExitCode(1);
    });

    expect(User::query()->count())->toBe(0);
});

it('has no password option or argument', function (): void {
    expect(fn () => Artisan::call('kokpit:install', ['--password' => generatedPassword(), '--no-interaction' => true]))
        ->toThrow(InvalidOptionException::class);

    Artisan::call('kokpit:install', ['--help' => true]);
    $help = Artisan::output();

    expect($help)->toContain('--name')
        ->and($help)->toContain('--email')
        ->and($help)->not->toContain('--password')
        ->and(User::query()->count())->toBe(0);
});

it('refuses a second run and creates nothing', function (): void {
    $this->artisan('kokpit:install', ['--name' => 'Jane Example', '--email' => exampleEmail()])
        ->expectsQuestion(__('kokpit.install.prompt_password'), $password = generatedPassword())
        ->expectsQuestion(__('kokpit.install.prompt_password_confirmation'), $password)
        ->assertExitCode(0);

    withAdminPasswordEnv(generatedPassword(), function (): void {
        $this->artisan('kokpit:install', ['--name' => 'John Example', '--email' => exampleEmail(), '--no-interaction' => true])
            ->expectsOutputToContain(__('kokpit.install.admin_exists'))
            ->assertExitCode(1);
    });

    expect(User::query()->count())->toBe(1)
        ->and(User::role(RoleName::Admin->value)->count())->toBe(1);
});

it('rejects a password shorter than 12 characters', function (): void {
    withAdminPasswordEnv(Str::random(11), function (): void {
        $this->artisan('kokpit:install', ['--name' => 'Jane Example', '--email' => exampleEmail(), '--no-interaction' => true])
            ->assertExitCode(1);
    });

    expect(User::query()->count())->toBe(0);
});

it('accepts a password of exactly 12 characters and 72 bytes', function (): void {
    foreach ([12, 72] as $length) {
        withAdminPasswordEnv(Str::random($length), function (): void {
            $this->artisan('kokpit:install', ['--name' => 'Jane Example', '--email' => exampleEmail(), '--no-interaction' => true])
                ->assertExitCode(0);
        });

        expect(User::query()->count())->toBe(1);
        User::query()->delete();
    }
});

it('rejects a password longer than 72 bytes because bcrypt would ignore the rest', function (): void {
    withAdminPasswordEnv(Str::random(73), function (): void {
        $this->artisan('kokpit:install', ['--name' => 'Jane Example', '--email' => exampleEmail(), '--no-interaction' => true])
            ->expectsOutputToContain(__('kokpit.install.password_too_long'))
            ->assertExitCode(1);
    });

    expect(User::query()->count())->toBe(0);
});

it('counts password length in bytes, not characters', function (): void {
    // 40 two-byte characters are 80 bytes but only 40 characters.
    withAdminPasswordEnv(str_repeat("\u{00E1}", 40), function (): void {
        $this->artisan('kokpit:install', ['--name' => 'Jane Example', '--email' => exampleEmail(), '--no-interaction' => true])
            ->assertExitCode(1);
    });

    expect(User::query()->count())->toBe(0);
});

it('rejects an invalid e-mail address', function (): void {
    withAdminPasswordEnv(generatedPassword(), function (): void {
        $this->artisan('kokpit:install', ['--name' => 'Jane Example', '--email' => 'not-an-address', '--no-interaction' => true])
            ->assertExitCode(1);
    });

    expect(User::query()->count())->toBe(0);
});

it('rejects an e-mail address that is already registered', function (): void {
    $email = exampleEmail();
    User::factory()->create(['email' => $email]);

    withAdminPasswordEnv(generatedPassword(), function () use ($email): void {
        $this->artisan('kokpit:install', ['--name' => 'Jane Example', '--email' => $email, '--no-interaction' => true])
            ->assertExitCode(1);
    });

    // The role scope throws on a database without roles, so count the pivot rows directly.
    expect(User::query()->count())->toBe(1)
        ->and(DB::table('model_has_roles')->count())->toBe(0);
});

it('lets the created Admin sign in to the panel', function (): void {
    $password = generatedPassword();
    $email = exampleEmail();

    withAdminPasswordEnv($password, function () use ($email): void {
        $this->artisan('kokpit:install', ['--name' => 'Jane Example', '--email' => $email, '--no-interaction' => true])
            ->assertExitCode(0);
    });

    Livewire::test(Login::class)
        ->fillForm(['email' => $email, 'password' => $password])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $this->assertAuthenticatedAs(User::query()->sole());

    $this->get('/admin')->assertOk();
});

it('turns a user without a role away from the panel', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin')->assertForbidden();
});
