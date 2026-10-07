<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('renders the Filament login page', function () {
    $response = $this->get('/admin/login');

    $response->assertOk();
    $response->assertSee('wire:model="data.email"', false);
});

it('redirects the root URL to the admin panel', function () {
    $this->get('/')->assertRedirect('/admin');
});

it('runs on PostgreSQL', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

it('runs on the dedicated test database', function () {
    expect(DB::selectOne('select current_database() as d')->d)->toBe('kokpit_test');
});

it('keeps the PostgreSQL session time zone at UTC', function () {
    expect(DB::selectOne('show time zone')->TimeZone)->toBe('UTC');
});
