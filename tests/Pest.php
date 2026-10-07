<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * Unit tests are plain PHPUnit test cases without the application.
 * Feature tests boot the application on the guarded kokpit_test database.
 * Later plans add their own suites (Arch, Concurrency, Isolation).
 */
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
