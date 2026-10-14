<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Actions\CreateTimeEntry;
use App\Domain\TimeTracking\Actions\StartTimer;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonInterface;
use Closure;
use Database\Factories\ProjectFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Fixtures of the REST API tests.
 *
 * Pest helper functions are global for the whole suite, so the helpers of the
 * API tests live here as static methods instead. Every value is fictional and
 * assembled at runtime.
 */
final class ApiFixtures
{
    /**
     * An active Admin with a fictional address.
     */
    public static function admin(): User
    {
        return Canary::admin();
    }

    /**
     * A real Sanctum token of the user; returns the plain text `<uuid>|<secret>`.
     *
     * @param  list<string>  $abilities
     */
    public static function token(User $user, array $abilities, ?CarbonInterface $expiresAt = null): string
    {
        return $user->createToken('example '.bin2hex(random_bytes(3)), $abilities, $expiresAt)->plainTextToken;
    }

    public static function client(): Client
    {
        return app(PartnerContext::class)->runAsSystem(static fn (): Client => Client::factory()->create());
    }

    public static function project(Client $client, ?string $key = null): Project
    {
        return app(CreateProject::class)->handle($client, [
            'name' => 'Example project '.bin2hex(random_bytes(3)),
            'key' => $key ?? ProjectFactory::randomKey(),
            'billing_type' => 'hourly',
            'hourly_rate' => '900',
        ]);
    }

    public static function task(Project $project): Task
    {
        $admin = self::admin();

        return self::signedIn($admin, static fn (): Task => app(CreateTask::class)
            ->handle($admin, $project, ['title' => 'Example task '.bin2hex(random_bytes(3))])
            ->refresh());
    }

    /**
     * A finished entry of the actor, written through the domain Action with the actor signed in.
     *
     * @param  array<string, mixed>  $data
     */
    public static function entry(User $actor, array $data): TimeEntry
    {
        return self::signedIn($actor, static fn (): TimeEntry => app(CreateTimeEntry::class)->handle($actor, $data));
    }

    /**
     * Bills the entry through raw SQL, the way the billing action will, and returns the stored row.
     */
    public static function billed(TimeEntry $entry): TimeEntry
    {
        DB::table('time_entries')->where('id', $entry->id)->update([
            'billing_state' => 'billed',
            'billed_at' => '2026-10-13 09:00:00',
        ]);

        return app(PartnerContext::class)->runAsSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($entry->id));
    }

    /**
     * A running entry (a started timer) of the actor for the client.
     */
    public static function running(User $actor, Client $client): TimeEntry
    {
        return self::signedIn($actor, static fn (): TimeEntry => app(StartTimer::class)
            ->handle($actor, ['client_id' => $client->id])['entry']);
    }

    /**
     * Runs the callback with the actor signed in on the web guard, then forgets the guards again,
     * so a fixture never leaves a session behind that could mask a token check of the test.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private static function signedIn(User $actor, Closure $callback): mixed
    {
        Auth::shouldUse('web');
        Auth::guard('web')->setUser($actor);

        try {
            return $callback();
        } finally {
            Auth::forgetGuards();
        }
    }
}
