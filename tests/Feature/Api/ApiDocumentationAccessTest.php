<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\ApiDocs;
use Tests\Support\ApiFixtures;

/*
 * AP-05, D-17, OD-6: the generated page at /docs and the spec at /docs.openapi are public, carry no
 * session, are marked noindex, answer 404 (never 500) before the first generation and are limited per
 * IP address. The API itself stays behind its token.
 */

it('serves the page to a guest without a cookie and marked noindex', function (): void {
    ApiDocs::ensureGenerated();

    $response = $this->get('/docs');

    $response->assertOk();
    expect($response->headers->get('X-Robots-Tag'))->toContain('noindex')
        ->and($response->headers->has('Set-Cookie'))->toBeFalse()
        ->and($response->headers->getCookies())->toBe([]);
});

it('serves the spec to a guest as YAML without a cookie and marked noindex', function (): void {
    ApiDocs::ensureGenerated();

    $response = $this->get('/docs.openapi');

    $response->assertOk();
    expect($response->headers->get('X-Robots-Tag'))->toContain('noindex')
        ->and($response->headers->has('Set-Cookie'))->toBeFalse();

    $spec = Yaml::parse(File::get(ApiDocs::specPath()));
    expect($spec)->toBeArray()->and($spec['openapi'])->toBe('3.1.0');
});

it('answers 404, not 500, before the first generation', function (): void {
    $empty = sys_get_temp_dir().'/kokpit-docs-absent-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($empty);
    Storage::fake('local');
    View::getFinder()->setPaths([$empty]);
    View::getFinder()->flush();

    try {
        foreach (['/docs', '/docs.openapi', '/docs.postman'] as $path) {
            $response = $this->get($path);

            $response->assertNotFound();
            expect($response->headers->get('X-Robots-Tag'))->toContain('noindex');
        }
    } finally {
        File::deleteDirectory($empty);
    }
});

it('answers 404 for the Postman collection while it is disabled, even after a generation', function (): void {
    ApiDocs::ensureGenerated();

    $this->get('/docs.postman')->assertNotFound();
});

it('limits the docs routes per IP address and answers 429 over the limit', function (): void {
    ApiDocs::ensureGenerated();
    config(['kokpit.api.docs_rate_limit' => 2]);

    $this->get('/docs')->assertOk();
    $this->get('/docs.openapi')->assertOk();
    $this->get('/docs')->assertStatus(429);
});

it('keeps the API itself behind its token while the docs are public', function (): void {
    ApiDocs::ensureGenerated();
    $entry = ApiFixtures::entry(ApiFixtures::admin(), [
        'client_id' => ApiFixtures::client()->id,
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:30:00',
    ]);

    $this->get('/docs')->assertOk();
    $this->getJson('/api/v1/time-entries/'.$entry->id)->assertUnauthorized();
});
