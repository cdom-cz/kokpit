<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Unit tests are plain PHPUnit test cases without the application.
 * Feature tests boot the application on the guarded kokpit_test database.
 * Arch tests boot the application too but never touch the database.
 * Concurrency tests boot the application and spawn real worker processes; they
 * commit real rows, so they have no RefreshDatabase wrapper and clean up after
 * themselves. Isolation tests prove the Partner default-deny at the data layer on
 * a test-only tenant model; they use the database inside a RefreshDatabase transaction.
 */
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Arch');
pest()->extend(TestCase::class)->in('Concurrency');
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Isolation');

/**
 * An example.com address assembled at runtime from fragments, so no test file
 * contains a complete address literal.
 */
function exampleEmail(string $local = ''): string
{
    return ($local !== '' ? $local : 'user'.Str::lower(Str::random(8))).'@'.implode('.', ['example', 'com']);
}
