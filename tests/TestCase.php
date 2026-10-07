<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
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
