<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Jobs\RemoveDocumentObjects;
use App\Domain\Documents\Models\Document;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Media;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;

/*
 * The queued removal of the objects of deleted documents (WR-01, DO-03, D-23): it removes what the disk refused
 * right after the commit, retries while the disk refuses, and only ever touches the object of a deleted document.
 * Every name and uuid is fictional or generated at run time.
 */

/**
 * Runs the job like a worker does: through its own middleware, so the system context is entered.
 */
function removalRun(RemoveDocumentObjects $job): void
{
    (new Pipeline(app()))
        ->send($job)
        ->through($job->middleware())
        ->then(static fn (RemoveDocumentObjects $job): mixed => app()->call($job->handle(...)));
}

/**
 * Attaches a file to a fresh project and returns the document and the path of its object.
 *
 * @return array{0: Document, 1: string}
 */
function removalAttached(string $name): array
{
    $project = app(CreateProject::class)->handle(Client::factory()->create(), [
        'name' => 'Example removal project',
        'key' => 'REM',
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]);

    assert($project instanceof Project);

    $document = app(AttachDocuments::class)
        ->handle(test()->admin, $project, [DocumentFixtures::upload($name)])
        ->firstOrFail();

    $media = app(PartnerContext::class)->runAsSystem(
        static fn (): array => Media::query()->where('model_id', $document->id)->get()->all(),
    );

    expect($media)->toHaveCount(1);

    return [$document, $media[0]->getPathRelativeToRoot()];
}

/**
 * A deleted document whose object is still on the disk: the media row and the documents row are gone.
 */
function removalOrphan(string $name): string
{
    [$document, $path] = removalAttached($name);

    app(PartnerContext::class)->runAsSystem(static function () use ($document): void {
        Media::query()->where('model_id', $document->id)->delete();
        Document::query()->whereKey($document->id)->delete();
    });

    Storage::disk('local')->assertExists($path);

    return $path;
}

/**
 * Replaces the faked private disk by one that fails to delete the object at the path: it throws, or it answers
 * false without removing anything.
 */
function removalRefuseDelete(string $path, bool $throw): void
{
    $real = Storage::disk('local');
    $failing = Mockery::mock($real)->makePartial();

    $failing->shouldReceive('delete')->andReturnUsing(static function (mixed $target) use ($real, $path, $throw): bool {
        if ($target === $path) {
            if ($throw) {
                throw new RuntimeException('Fictional disk failure.');
            }

            return false;
        }

        return $real->delete($target);
    });

    Storage::set('local', $failing);
}

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();

    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('removes the object of a deleted document and a second run changes nothing (WR-01)', function (): void {
    $path = removalOrphan('orphan-example.txt');
    $job = new RemoveDocumentObjects([['disk' => 'local', 'path' => $path]]);

    Exceptions::fake();

    removalRun($job);

    expect(Storage::disk('local')->exists($path))->toBeFalse();

    removalRun($job);

    expect(Storage::disk('local')->exists($path))->toBeFalse();

    Exceptions::assertNothingReported();
});

it('throws without a path and leaves the object while the disk answers false, so the queue retries (WR-01)', function (): void {
    $path = removalOrphan('stuck-orphan-example.txt');

    removalRefuseDelete($path, throw: false);

    $thrown = null;

    try {
        removalRun(new RemoveDocumentObjects([['disk' => 'local', 'path' => $path]]));
    } catch (RuntimeException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown?->getMessage())->toContain('could not be removed')
        ->and($thrown?->getMessage())->not->toContain($path)
        ->and(Storage::disk('local')->exists($path))->toBeTrue();
});

it('throws without a path and leaves the object while the disk throws, then removes it once the disk recovered (WR-01)', function (): void {
    $path = removalOrphan('throwing-orphan-example.txt');
    $real = Storage::disk('local');

    removalRefuseDelete($path, throw: true);

    $thrown = null;

    try {
        removalRun(new RemoveDocumentObjects([['disk' => 'local', 'path' => $path]]));
    } catch (RuntimeException $e) {
        $thrown = $e;
    }

    expect($thrown?->getMessage())->toContain('could not be removed')
        ->and($thrown?->getMessage())->not->toContain($path)
        ->and($real->exists($path))->toBeTrue();

    Storage::set('local', $real);

    removalRun(new RemoveDocumentObjects([['disk' => 'local', 'path' => $path]]));

    expect($real->exists($path))->toBeFalse();
});

it('never removes the object of a live document, skipping and reporting it (WR-01)', function (): void {
    [, $path] = removalAttached('live-example.txt');

    Exceptions::fake();

    removalRun(new RemoveDocumentObjects([['disk' => 'local', 'path' => $path]]));

    expect(Storage::disk('local')->exists($path))->toBeTrue();

    Exceptions::assertReported(static fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'was skipped') && ! str_contains($e->getMessage(), $path));
});

it('skips and reports a path that is not a document object path and touches nothing (WR-01)', function (string $shape): void {
    $path = match ($shape) {
        'archive' => 'document-archives/'.Str::uuid7()->toString().'.zip',
        'traversal' => '../'.Str::uuid7()->toString().'/'.Str::uuid7()->toString().'.txt',
        'foreign file name' => Str::uuid7()->toString().'/notes.txt',
        'upper case' => strtoupper(Str::uuid7()->toString()).'/'.Str::uuid7()->toString().'.txt',
        'long extension' => Str::uuid7()->toString().'/'.Str::uuid7()->toString().'.abcdefghijk',
        'empty' => '',
    };

    $safe = ! in_array($shape, ['traversal', 'empty'], true);

    if ($safe) {
        Storage::disk('local')->put($path, 'Fictional content.');
    }

    Exceptions::fake();

    removalRun(new RemoveDocumentObjects([['disk' => 'local', 'path' => $path]]));

    if ($safe) {
        expect(Storage::disk('local')->exists($path))->toBeTrue();
    }

    Exceptions::assertReported(static fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'was skipped'));
    Exceptions::assertNotReported(static fn (Throwable $e): bool => $path !== '' && str_contains($e->getMessage(), $path));
})->with(['archive', 'traversal', 'foreign file name', 'upper case', 'long extension', 'empty']);

it('skips and reports an entry on another disk than the media disk and leaves the object (WR-01)', function (): void {
    $path = removalOrphan('foreign-disk-example.txt');

    Exceptions::fake();

    removalRun(new RemoveDocumentObjects([['disk' => 's3', 'path' => $path]]));

    expect(Storage::disk('local')->exists($path))->toBeTrue();

    Exceptions::assertReported(static fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'was skipped'));
});

it('skips a malformed entry and still removes the valid one next to it (WR-01)', function (): void {
    $path = removalOrphan('mixed-example.txt');

    Exceptions::fake();

    removalRun(new RemoveDocumentObjects([['disk' => 'local'], 'not an entry', ['disk' => 'local', 'path' => $path]]));

    expect(Storage::disk('local')->exists($path))->toBeFalse();

    Exceptions::assertReportedCount(2);
});
