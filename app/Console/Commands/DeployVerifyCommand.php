<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Database\CzechCollation;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Readiness gate of a deploy (D-18): exits 0 only when the database answers,
 * no migration is pending, Redis answers and the database server provides the
 * Czech collation (a server without ICU would sort Czech names wrongly, so it
 * never goes live; there is no fallback to the default collation).
 *
 * It runs as an init command of every container, right after the execOnce
 * migration. Zerops ends the deploy at the first failing init command, so a
 * migration that failed or never ran cannot go live and the previous version
 * keeps serving. The checks include the session connection (the Redis session
 * connection for the redis driver, the database session connection for the
 * database driver) and treat a falsy Redis ping as a failure. Output names the
 * checks and their result; connection details (host, port, user, password) are
 * never printed, and a failure is reduced to the exception class or a fixed
 * translated reason.
 */
class DeployVerifyCommand extends Command
{
    protected $signature = 'kokpit:deploy:verify';

    protected $description = 'Check that the database and Redis answer, that no migration is pending and that the Czech collation exists (readiness gate of a deploy)';

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
            $this->check('redis', fn (): ?string => $this->pingRedis()),
            $this->check('collation', fn (): ?string => $this->czechCollation()),
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

    /**
     * Pings the default connection and, for the database session driver, the
     * session connection (null means the default connection).
     */
    private function pingDatabase(): void
    {
        DB::connection()->selectOne('select 1');

        if (config('session.driver') === 'database') {
            $connection = config('session.connection');

            DB::connection(is_string($connection) && $connection !== '' ? $connection : null)->selectOne('select 1');
        }
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
     * Fails when the database server has no Czech ICU collation. The reason names the
     * collation, a fixed constant, and no connection value.
     */
    private function czechCollation(): ?string
    {
        return CzechCollation::isAvailable() ? null : (string) __('kokpit.deploy_verify.collation_missing', ['collation' => CzechCollation::NAME]);
    }

    /**
     * Pings every Redis connection the queue, the cache and the default
     * connection use, plus the session connection for the redis session driver
     * (null means the default connection). The first failure ends the check; a
     * falsy ping result counts as a failure.
     */
    private function pingRedis(): ?string
    {
        $connections = [
            'default',
            (string) config('queue.connections.redis.connection', 'default'),
            (string) config('cache.stores.redis.connection', 'default'),
        ];

        if (config('session.driver') === 'redis') {
            $session = config('session.connection');
            $connections[] = is_string($session) && $session !== '' ? $session : 'default';
        }

        foreach (array_unique($connections) as $connection) {
            if (! Redis::connection($connection)->ping()) {
                return (string) __('kokpit.deploy_verify.no_answer');
            }
        }

        return null;
    }
}
