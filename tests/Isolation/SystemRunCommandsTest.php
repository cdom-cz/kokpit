<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Canary;

/**
 * Collects the system flag at the moment each statement matching the pattern
 * runs, so a test can prove the work happened inside the system context.
 *
 * @return Closure(): list<bool>
 */
function recordSystemFlagAt(string $pattern): Closure
{
    $flags = [];

    DB::listen(function (QueryExecuted $query) use (&$flags, $pattern): void {
        if (preg_match($pattern, $query->sql) === 1) {
            $flags[] = app(PartnerContext::class)->isSystem();
        }
    });

    return static function () use (&$flags): array {
        return $flags;
    };
}

it('creates the Admin inside the system context', function (): void {
    $flags = recordSystemFlagAt('/^insert into "users"/');

    $password = Str::password(20);
    putenv('KOKPIT_ADMIN_PASSWORD='.$password);

    try {
        $this->artisan('kokpit:install', ['--name' => 'Jane Example', '--email' => exampleEmail(), '--no-interaction' => true])
            ->assertExitCode(0);
    } finally {
        putenv('KOKPIT_ADMIN_PASSWORD');
    }

    expect($flags())->toBe([true])
        ->and(app(PartnerContext::class)->isSystem())->toBeFalse();
});

it('reads the migrations table of the deploy check inside the system context', function (): void {
    $flags = recordSystemFlagAt('/from "migrations"/');

    $this->artisan('kokpit:deploy:verify')->assertExitCode(0);

    $seen = $flags();

    expect($seen)->not->toBe([])
        ->and(array_unique($seen))->toBe([true])
        ->and(app(PartnerContext::class)->isSystem())->toBeFalse();
});

it('resets two-factor authentication inside the system context', function (): void {
    $user = Canary::admin();
    $flags = recordSystemFlagAt('/^(select .* from "users"|update "users")/');

    $this->artisan('kokpit:admin:reset-2fa', ['email' => $user->email, '--force' => true, '--no-interaction' => true])
        ->assertExitCode(0);

    $seen = $flags();

    expect($seen)->not->toBe([])
        ->and(array_unique($seen))->toBe([true])
        ->and(app(PartnerContext::class)->isSystem())->toBeFalse()
        ->and(User::query()->whereKey($user->id)->exists())->toBeTrue();
});
