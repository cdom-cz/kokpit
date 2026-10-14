<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ApiExamples;
use Tests\Support\ApiDocs;
use Tests\Support\ApiFixtures;
use Tests\Support\TimeEntryContract;

/*
 * D-09, D-10: the time entry wire contract the owner confirmed (07-02 Task 2, amended). The keys, their
 * order and their JSON types are pinned against the real resource, the documented examples and the
 * generated spec, so a rename or a type change fails here before any n8n flow breaks.
 *
 * `project_key` and `task_reference` are output-only. Every key that can be absent in the domain
 * (project, task, description, end, duration, billing time) is nullable in the response.
 */

it('returns exactly the confirmed keys, order and types for a finished entry with project and task', function (): void {
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $project = ApiFixtures::project($client, 'DEMO');
    $task = ApiFixtures::task($project);
    $entry = ApiFixtures::entry($admin, [
        'client_id' => $client->id,
        'project_id' => $project->id,
        'task_id' => $task->id,
        'description' => 'Example work',
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:30:00',
    ]);

    $data = $this->withToken(ApiFixtures::token($admin, ['time:read']))
        ->getJson('/api/v1/time-entries/'.$entry->id)
        ->assertOk()
        ->json('data');

    TimeEntryContract::assertShape($data);
    expect($data['project_key'])->toBe('DEMO')
        ->and($data['task_reference'])->toBe($task->reference)
        ->and($data['duration_seconds'])->toBe(5400);
});

it('returns the same keys with null for every optional value on a client-only running entry', function (): void {
    $admin = ApiFixtures::admin();
    $entry = ApiFixtures::running($admin, ApiFixtures::client());

    $data = $this->withToken(ApiFixtures::token($admin, ['time:read']))
        ->getJson('/api/v1/time-entries/'.$entry->id)
        ->assertOk()
        ->json('data');

    TimeEntryContract::assertShape($data);
    expect($data['ended_at'])->toBeNull()
        ->and($data['duration_seconds'])->toBeNull()
        ->and($data['project_id'])->toBeNull()
        ->and($data['project_key'])->toBeNull()
        ->and($data['task_id'])->toBeNull()
        ->and($data['task_reference'])->toBeNull();
});

it('documents examples with the same keys and types as the resource', function (): void {
    TimeEntryContract::assertShape(ApiExamples::TIME_ENTRY['data']);
    TimeEntryContract::assertShape(ApiExamples::TIME_ENTRY_RUNNING['data']);

    expect(ApiExamples::TIME_ENTRY_RUNNING['data']['ended_at'])->toBeNull()
        ->and(ApiExamples::TIME_ENTRY_RUNNING['data']['duration_seconds'])->toBeNull();
});

it('marks every optional key of the response as nullable in the generated spec and no other key', function (): void {
    $schema = ApiDocs::spec()['paths']['/api/v1/time-entries/{timeEntry}']['get']['responses'][200]['content']['application/json']['schema'];

    // A finished and a running example are documented, so the 200 response is a oneOf of both.
    expect($schema['oneOf'])->toHaveCount(2);

    foreach ($schema['oneOf'] as $variant) {
        $properties = $variant['properties']['data']['properties'];

        expect(array_keys($properties))->toBe(array_keys(TimeEntryContract::KEYS))
            ->and($variant['properties']['data']['required'])->toBe(array_keys(TimeEntryContract::KEYS));

        foreach (TimeEntryContract::KEYS as $key => $type) {
            expect(in_array('null', (array) $properties[$key]['type'], true))
                ->toBe(str_starts_with($type, '?'), "nullable flag of $key in the spec");
        }
    }
});

it('tells readers in the endpoint description that a running entry has no end and no duration', function (): void {
    $description = ApiDocs::spec()['paths']['/api/v1/time-entries/{timeEntry}']['get']['description'];

    expect($description)->toContain('running')
        ->and($description)->toContain('ended_at')
        ->and($description)->toContain('duration_seconds');
});
