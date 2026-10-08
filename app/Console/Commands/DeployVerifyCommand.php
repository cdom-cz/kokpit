<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\Auth\PartnerContext;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Readiness gate of a deploy (D-18): exits 0 only when the database answers,
 * no migration is pending and Redis answers.
 *
 * Zerops activates a new version only after this command succeeded, so a
 * migration that failed or never ran cannot go live and the previous version
 * keeps serving. Output names the checks and their result; connection details
 * (host, port, user, password) are never printed, and a failure is reduced to
 * the exception class.
 */
class DeployVerifyCommand extends Command
{
    protected $signature = 'kokpit:deploy:verify';

    protected $description = 'Check that the database and Redis answer and that no migration is pending (readiness gate of a deploy)';

    public function handle(): int
    {
        // Console work has no signed-in user, so the fail-closed scopes would
        // silence it: the whole run is an explicit system run.
        return app(PartnerContext::class)->runAsSystem(fn (): int => $this->verify());
    }

    private function verify(): int
    {
        $this->components->info(__('kokpit.deploy_verify.heading'));

        $results = [
            $this->check('database', function (): ?string {
                $this->pingDatabase();

                return null;
            }),
            $this->check('migrations', fn (): ?string => $this->pendingMigrations()),
            $this->check('redis', function (): ?string {
                $this->pingRedis();

                return null;
            }),
        ];

        if (in_array(false, $results, true)) {
            $this->components->error(__('kokpit.deploy_verify.failed'));

            return self::FAILURE;
        }

        $this->components->info(__('kokpit.deploy_verify.passed'));

        return self::SUCCESS;
    }

    /**
     * Runs one check and prints its line. The closure returns null when the
     * check passed or the reason it failed; a thrown exception counts as a
     * failure and is reported by its class only.
     *
     * @param  Closure(): ?string  $check
     */
    private function check(string $name, Closure $check): bool
    {
        $label = __('kokpit.deploy_verify.steps.'.$name);

        try {
            $reason = $check();
        } catch (Throwable $exception) {
            $reason = $exception::class;
        }

        if ($reason === null) {
            $this->components->info(__('kokpit.deploy_verify.step_ok', ['check' => $label]));

            return true;
        }

        $this->components->error(__('kokpit.deploy_verify.step_failed', ['check' => $label, 'reason' => $reason]));

        return false;
    }

    private function pingDatabase(): void
    {
        DB::connection()->selectOne('select 1');
    }

    /**
     * Counts the migration files the migrator knows (database/migrations plus
     * every path a package registered, the settings migrations included) that
     * are not recorded in the migrations table.
     */
    private function pendingMigrations(): ?string
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');

        $files = array_keys($migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')])));
        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];

        $pending = count(array_diff($files, $ran));

        return $pending === 0 ? null : (string) __('kokpit.deploy_verify.pending', ['count' => $pending]);
    }

    /**
     * Pings every Redis connection the queue, the cache and the default
     * connection use; the first failure ends the check.
     */
    private function pingRedis(): void
    {
        $connections = array_unique([
            'default',
            (string) config('queue.connections.redis.connection', 'default'),
            (string) config('cache.stores.redis.connection', 'default'),
        ]);

        foreach ($connections as $connection) {
            Redis::connection($connection)->ping();
        }
    }
}
