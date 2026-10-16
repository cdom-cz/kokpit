<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Support\DocumentUrl;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;

/*
 * The one route through which a file reaches a browser (DO-03, D-10): signed, short-lived, bound to the
 * signed-in user and streamed by the application. Every name and content is fictional.
 */

/**
 * A client-visible project of a fresh client, written through the domain Action.
 */
function fileRouteProject(?Client $client = null, string $key = 'FIL'): Project
{
    return app(CreateProject::class)->handle($client ?? Client::factory()->create(), [
        'name' => 'Example file route project',
        'key' => $key,
        'billing_type' => 'hourly',
        'client_visible' => true,
    ]);
}

/**
 * Attaches one fictional upload to the owner as the Admin and returns the stored document.
 */
function fileRouteDocument(object $owner, string $name, string $content): Document
{
    return app(AttachDocuments::class)
        ->handle(test()->admin, $owner, [DocumentFixtures::upload($name, $content)])
        ->firstOrFail();
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();

    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->project = fileRouteProject();
});

it('streams the stored bytes of a document to the signed-in Admin through a signed app link', function (): void {
    $content = implode(' ', ['Fictional', 'file', 'bytes', Str::random(16)]);
    $document = fileRouteDocument($this->project, 'example-note.txt', $content);

    $url = DocumentUrl::download($document, $this->admin);

    expect(parse_url($url, PHP_URL_PATH))->toStartWith('/admin/files/documents/'.$document->getKey());

    $response = $this->get($url)->assertOk();

    expect($response->streamedContent())->toBe($content)
        ->and((string) $response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and((string) $response->headers->get('Content-Disposition'))->toContain('example-note.txt')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store')
        ->and((string) $response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('Content-Length'))->toBe((string) strlen($content));
});
