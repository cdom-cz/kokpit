<?php

declare(strict_types=1);

use Tests\Support\ApiFixtures;

/*
 * AP-02, D-11: the read-back list of time entries. Newest first, cursor paginated, filtered by the
 * documented filters. Every value is fictional.
 */

it('lists the entries newest first and narrows them to one client', function (): void {
    $admin = ApiFixtures::admin();
    $first = ApiFixtures::client();
    $second = ApiFixtures::client();

    $oldest = ApiFixtures::entry($admin, ['client_id' => $first->id, 'started_at' => '2026-10-10 08:00:00', 'ended_at' => '2026-10-10 09:00:00']);
    $middle = ApiFixtures::entry($admin, ['client_id' => $second->id, 'started_at' => '2026-10-11 08:00:00', 'ended_at' => '2026-10-11 09:00:00']);
    $newest = ApiFixtures::entry($admin, ['client_id' => $first->id, 'started_at' => '2026-10-12 08:00:00', 'ended_at' => '2026-10-12 09:00:00']);
    $token = ApiFixtures::token($admin, ['time:read']);

    $all = $this->withToken($token)->getJson('/api/v1/time-entries')->assertOk();

    expect(array_column($all->json('data'), 'id'))->toBe([$newest->id, $middle->id, $oldest->id])
        ->and($all->json())->toHaveKeys(['data', 'links', 'meta'])
        ->and($all->json('meta'))->toHaveKey('next_cursor')
        ->and($all->json('meta.next_cursor'))->toBeNull();

    $narrowed = $this->withToken($token)->getJson('/api/v1/time-entries?filter[client]='.$first->id)->assertOk();

    expect(array_column($narrowed->json('data'), 'id'))->toBe([$newest->id, $oldest->id]);
});

it('follows the cursor of a page to the next entry', function (): void {
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $older = ApiFixtures::entry($admin, ['client_id' => $client->id, 'started_at' => '2026-10-10 08:00:00', 'ended_at' => '2026-10-10 09:00:00']);
    $newer = ApiFixtures::entry($admin, ['client_id' => $client->id, 'started_at' => '2026-10-12 08:00:00', 'ended_at' => '2026-10-12 09:00:00']);
    $token = ApiFixtures::token($admin, ['time:read']);

    $page = $this->withToken($token)->getJson('/api/v1/time-entries?per_page=1')->assertOk();
    $cursor = $page->json('meta.next_cursor');

    expect(array_column($page->json('data'), 'id'))->toBe([$newer->id])
        ->and($cursor)->toBeString()->not->toBe('');

    $next = $this->withToken($token)->getJson('/api/v1/time-entries?per_page=1&cursor='.urlencode($cursor))->assertOk();

    expect(array_column($next->json('data'), 'id'))->toBe([$older->id])
        ->and($next->json('meta.next_cursor'))->toBeNull();
});

it('refuses a token without the read ability', function (): void {
    $admin = ApiFixtures::admin();

    $this->withToken(ApiFixtures::token($admin, ['time:write']))
        ->getJson('/api/v1/time-entries')
        ->assertForbidden();
});

it('answers 422, not 400, for a filter key the API does not know', function (): void {
    $token = ApiFixtures::token(ApiFixtures::admin(), ['time:read']);

    $this->withToken($token)->getJson('/api/v1/time-entries?filter[unknown]=1')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['filter']);

    $this->withToken($token)->getJson('/api/v1/time-entries?filter[client]=abc')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['filter.client']);
});
