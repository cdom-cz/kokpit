<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * No test may reach a real HTTP service: a request without a fake fails the test
     * (the ARES lookup must never call the live registry from a test).
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /**
     * Create the application, then refuse to continue unless the default
     * connection is a PostgreSQL database dedicated to tests.
     *
     * RefreshDatabase runs migrate:fresh on whatever database resolves, so
     * this check has to run before any trait can touch the database.
     */
    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if ($connection !== 'pgsql' || ! str_ends_with($database, '_test')) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests against database "%s" on connection "%s": tests need a pgsql database whose name ends in _test',
                $database,
                $connection,
            ));
        }

        return $app;
    }
}
