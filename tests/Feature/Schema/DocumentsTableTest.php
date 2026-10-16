<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Canary;
use Tests\Support\RawSql;

/*
 * The documents table and the media key of a document (DO-01, D-01, D-03), proven by raw SQL so no model, cast or
 * Action stands between a value and its constraint. Every value is invented.
 */

/**
 * A client-visible project of a fresh client, written through the domain Action.
 */
function documentsSchemaProject(): Project
{
    return app(CreateProject::class)->handle(Client::factory()->create(), [
        'name' => 'Example schema project',
        'key' => 'SCH',
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]);
}

/**
 * Inserts one document row by raw SQL with valid defaults (an unattached, hidden text file uploaded by a fresh Admin);
 * overrides replace single columns (the column names come from the tests only).
 *
 * @param  array<string, mixed>  $overrides
 */
function documentsRawInsert(array $overrides = []): string
{
    $row = [
        'name' => 'Example file',
        'uploaded_by_id' => (string) Canary::admin()->getKey(),
        'extension' => 'txt',
        'mime_type' => 'text/plain',
        'size_bytes' => 12,
        'created_at' => '2026-10-01 10:00:00+00',
        'updated_at' => '2026-10-01 10:00:00+00',
        ...$overrides,
    ];

    $columns = array_keys($row);

    /** @var object{id: string} $inserted */
    $inserted = DB::selectOne(
        sprintf(
            'insert into documents (%s) values (%s) returning id',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        ),
        array_values($row),
    );

    return $inserted->id;
}

/**
 * Inserts one media row for the given model type and id by raw SQL.
 */
function documentsRawMedia(string $modelType, string $modelId): void
{
    DB::insert(
        'insert into media (model_type, model_id, collection_name, name, file_name, mime_type, disk, size, manipulations, custom_properties, generated_conversions, responsive_images, created_at, updated_at) '
        .'values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$modelType, $modelId, 'file', 'example', 'example.txt', 'text/plain', 'local', 12, '[]', '[]', '[]', '[]', '2026-10-01 10:00:00+00', '2026-10-01 10:00:00+00'],
    );
}

it('gives a row inserted without an id a database-generated UUID version 7 and the hidden default', function (): void {
    $id = documentsRawInsert();
    $row = DB::selectOne('select id, visible_to_client from documents where id = ?', [$id]);

    expect($row->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($row->visible_to_client)->toBeFalse();
});

describe('checks (23514)', function (): void {
    it('refuses an owner type without an id and an owner id without a type', function (): void {
        RawSql::expectSqlState('23514', fn () => documentsRawInsert(['owner_type' => 'project']));
        RawSql::expectSqlState('23514', fn () => documentsRawInsert(['owner_id' => (string) Str::uuid7()]));
    });

    it('refuses a visible file whose owner is a client, a transaction or nothing', function (): void {
        RawSql::expectSqlState('23514', fn () => documentsRawInsert([
            'visible_to_client' => true,
            'owner_type' => 'client',
            'owner_id' => (string) Str::uuid7(),
        ]));
        RawSql::expectSqlState('23514', fn () => documentsRawInsert([
            'visible_to_client' => true,
            'owner_type' => 'transaction',
            'owner_id' => (string) Str::uuid7(),
        ]));
        RawSql::expectSqlState('23514', fn () => documentsRawInsert(['visible_to_client' => true]));
    });

    it('accepts a visible file whose owner is a project, a task or a task comment', function (): void {
        foreach (['project', 'task', 'task_comment'] as $owner) {
            RawSql::expectAllowed(fn () => documentsRawInsert([
                'visible_to_client' => true,
                'owner_type' => $owner,
                'owner_id' => (string) Str::uuid7(),
            ]));
        }
    });

    it('accepts a hidden file of a client or a transaction', function (): void {
        foreach (['client', 'transaction'] as $owner) {
            RawSql::expectAllowed(fn () => documentsRawInsert([
                'owner_type' => $owner,
                'owner_id' => (string) Str::uuid7(),
            ]));
        }
    });

    it('refuses a project without a client', function (): void {
        $project = documentsSchemaProject();

        RawSql::expectSqlState('23514', fn () => documentsRawInsert(['project_id' => (string) $project->getKey()]));
    });

    it('refuses a blank name', function (): void {
        RawSql::expectSqlState('23514', fn () => documentsRawInsert(['name' => '']));
        RawSql::expectSqlState('23514', fn () => documentsRawInsert(['name' => "  \t "]));
    });

    it('refuses a malformed extension and accepts a clean one or none', function (): void {
        RawSql::expectSqlState('23514', fn () => documentsRawInsert(['extension' => 'PDF']));
        RawSql::expectSqlState('23514', fn () => documentsRawInsert(['extension' => '../x']));
        RawSql::expectSqlState('23514', fn () => documentsRawInsert(['extension' => '']));
        RawSql::expectSqlState('23514', fn () => documentsRawInsert(['extension' => 'abcdefghijk']));
        RawSql::expectAllowed(fn () => documentsRawInsert(['extension' => 'pdf']));
        RawSql::expectAllowed(fn () => documentsRawInsert(['extension' => null]));
    });

    it('refuses a negative size and accepts zero', function (): void {
        RawSql::expectSqlState('23514', fn () => documentsRawInsert(['size_bytes' => -1]));
        RawSql::expectAllowed(fn () => documentsRawInsert(['size_bytes' => 0]));
    });
});

describe('foreign keys (23503)', function (): void {
    it('refuses a project paired with the client of another project', function (): void {
        $project = documentsSchemaProject();
        $other = Client::factory()->create();

        RawSql::expectSqlState('23503', fn () => documentsRawInsert([
            'client_id' => (string) $other->getKey(),
            'project_id' => (string) $project->getKey(),
        ]));
    });

    it('accepts a project paired with its own client', function (): void {
        $project = documentsSchemaProject();

        RawSql::expectAllowed(fn () => documentsRawInsert([
            'client_id' => (string) $project->client_id,
            'project_id' => (string) $project->getKey(),
        ]));
    });

    it('refuses an unknown client and an unknown uploader', function (): void {
        RawSql::expectSqlState('23503', fn () => documentsRawInsert(['client_id' => (string) Str::uuid7()]));
        RawSql::expectSqlState('23503', fn () => documentsRawInsert(['uploaded_by_id' => (string) Str::uuid7()]));
    });
});

describe('media key (23505)', function (): void {
    it('refuses a second media row for one document', function (): void {
        $id = documentsRawInsert();
        documentsRawMedia('document', $id);

        RawSql::expectSqlState('23505', fn () => documentsRawMedia('document', $id));
    });

    it('accepts one media row for each of two documents', function (): void {
        documentsRawMedia('document', documentsRawInsert());

        RawSql::expectAllowed(fn () => documentsRawMedia('document', documentsRawInsert()));
    });

    it('does not restrict the media of other models', function (): void {
        $id = (string) Str::uuid7();
        documentsRawMedia('project', $id);

        RawSql::expectAllowed(fn () => documentsRawMedia('project', $id));
    });
});
