<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Support\DocumentUrl;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Media;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\URL;
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

/**
 * A signed link for any document id, as the application would sign it for the given user.
 */
function fileRouteLink(string $documentId, string $userId, string $mode = 'download'): string
{
    return URL::temporarySignedRoute(DocumentUrl::ROUTE, now()->addSeconds((int) config('kokpit.documents.signed_url_ttl_seconds')), [
        'document' => $documentId,
        'mode' => $mode,
        'u' => $userId,
    ]);
}

/**
 * Fictional file bytes of the types the inline decision cares about, assembled from fragments at runtime.
 *
 * @return array<string, string>
 */
function fileRouteBytes(): array
{
    return [
        'png' => (string) base64_decode(implode('', ['iVBORw0KGgoAAAANSUhEUgAAAAEAAAAB', 'CAYAAAAfFcSJAAAADUlEQVR42mNk', 'YPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='])),
        'pdf' => implode("\n", ['%PDF-1.4', '%fictional', '1 0 obj', '<< >>', 'endobj', 'trailer', '<< >>', '%%EOF', '']),
        'svg' => implode('', ['<?xml version="1.0"?>', '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1">', '<script>/* fictional */</script>', '</svg>']),
        'html' => implode('', ['<!DOCTYPE html>', '<html><body>', 'fictional', '</body></html>']),
        'txt' => implode(' ', ['Fictional', 'plain', 'text']),
    ];
}

it('refuses an unsigned, tampered or expired link and a link signed for another user with 403 and no referrer', function (): void {
    $document = fileRouteDocument($this->project, 'example-note.txt', 'Fictional bytes');
    $otherAdmin = Canary::admin();

    $unsigned = route(DocumentUrl::ROUTE, ['document' => $document->getKey(), 'mode' => 'download', 'u' => $this->admin->getKey()]);
    $signed = DocumentUrl::download($document, $this->admin);
    $tampered = substr($signed, 0, -1).(str_ends_with($signed, 'a') ? 'b' : 'a');
    $foreign = DocumentUrl::download($document, $otherAdmin);

    foreach ([$unsigned, $tampered, $foreign] as $link) {
        $response = $this->get($link);

        expect($response->getStatusCode())->toBe(403, $link)
            ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer');
    }

    $this->travel((int) config('kokpit.documents.signed_url_ttl_seconds') + 1)->seconds();

    $expired = $this->get($signed);

    expect($expired->getStatusCode())->toBe(403)
        ->and($expired->headers->get('Referrer-Policy'))->toBe('no-referrer');
});

it('sends a guest who opens a valid link to the panel login', function (): void {
    $document = fileRouteDocument($this->project, 'example-note.txt', 'Fictional bytes');
    $link = DocumentUrl::download($document, $this->admin);

    auth()->forgetGuards();

    $this->get($link)->assertRedirect(Filament::getLoginUrl());
});

it('answers 404 for a malformed and for an unknown document id', function (): void {
    foreach (['not-a-uuid', (string) Str::uuid7()] as $id) {
        $this->get(fileRouteLink($id, $this->admin->getKey()))->assertNotFound();
    }
});

it('answers a Partner of another client 404 and a Partner of the own client 403 until the read grant exists', function (): void {
    $document = fileRouteDocument($this->project, 'example-note.txt', 'Fictional bytes');

    $own = Canary::partnerFor($this->project->client_id);
    $this->actingAs($own);
    $this->get(DocumentUrl::download($document, $own))->assertForbidden();

    $other = Canary::partnerFor(Client::factory()->create()->getKey());
    $this->actingAs($other);
    $this->get(DocumentUrl::download($document, $other))->assertNotFound();
});

it('answers 404 for a file the Partner may not see and 403 for a link signed for somebody else', function (): void {
    $hidden = app(AttachDocuments::class)
        ->handle($this->admin, $this->project, [DocumentFixtures::upload('hidden-note.txt', 'Fictional bytes')], visibleToClient: false)
        ->firstOrFail();

    $own = Canary::partnerFor($this->project->client_id);
    $this->actingAs($own);

    $this->get(DocumentUrl::download($hidden, $own))->assertNotFound();
    $this->get(DocumentUrl::download($hidden, $this->admin))->assertForbidden();
});

it('shows only a preview of the five allowlisted types inline and sends everything else as an octet-stream attachment', function (string $kind, string $name, ?string $mime, bool $inline): void {
    $document = fileRouteDocument($this->project, $name, fileRouteBytes()[$kind]);

    if ($mime !== null) {
        expect($document->mime_type)->toBe($mime);
    }

    $response = $this->get(DocumentUrl::preview($document, $this->admin))->assertOk();
    $disposition = (string) $response->headers->get('Content-Disposition');

    if ($inline) {
        expect($disposition)->toStartWith('inline')
            ->and($response->headers->get('Content-Type'))->toBe($document->mime_type);
    } else {
        expect($disposition)->toStartWith('attachment')
            ->and($response->headers->get('Content-Type'))->toBe('application/octet-stream');
    }

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
})->with([
    'png' => ['png', 'example-image.png', 'image/png', true],
    'pdf' => ['pdf', 'example-report.pdf', 'application/pdf', true],
    'svg' => ['svg', 'example-drawing.svg', 'image/svg+xml', false],
    'html' => ['html', 'example-page.html', 'text/html', false],
    'text' => ['txt', 'example-note.txt', 'text/plain', false],
]);

it('sends an allowlisted PDF as an attachment when a download was asked for', function (): void {
    $document = fileRouteDocument($this->project, 'example-report.pdf', fileRouteBytes()['pdf']);

    $response = $this->get(DocumentUrl::download($document, $this->admin))->assertOk();

    expect((string) $response->headers->get('Content-Disposition'))->toStartWith('attachment')
        ->and($response->headers->get('Content-Type'))->toBe('application/octet-stream');
});

it('encodes a Czech name per RFC 6266 with an ASCII fallback', function (): void {
    $name = implode(' ', ['Příliš', 'žluťoučký', 'kůň']).'.txt';
    $document = fileRouteDocument($this->project, $name, 'Fictional bytes');

    $disposition = (string) $this->get(DocumentUrl::download($document, $this->admin))->assertOk()->headers->get('Content-Disposition');

    expect($disposition)->toContain("filename*=utf-8''")
        ->and($disposition)->toContain('Prilis zlutoucky kun.txt')
        ->and($disposition)->toContain(rawurlencode('Příliš'));
});

it('downloads a document renamed without its extension as name plus extension', function (): void {
    $document = fileRouteDocument($this->project, 'example-note.txt', 'Fictional bytes');
    $document->forceFill(['name' => 'Renamed note'])->save();

    $disposition = (string) $this->get(DocumentUrl::download($document, $this->admin))->assertOk()->headers->get('Content-Disposition');

    expect($disposition)->toContain('Renamed note.txt');
});

it('keeps the bucket host, signing parameters and storage path out of every header and the body', function (): void {
    $document = fileRouteDocument($this->project, 'example-note.txt', 'Fictional bytes');
    $media = app(PartnerContext::class)->runAsSystem(static fn () => Media::query()->where('model_id', $document->getKey())->firstOrFail());

    $response = $this->get(DocumentUrl::download($document, $this->admin))->assertOk();
    $everything = $response->headers->__toString().$response->streamedContent();
    $host = parse_url((string) config('filesystems.disks.s3.endpoint'), PHP_URL_HOST);

    expect(stripos($everything, 'X-Amz'))->toBeFalse()
        ->and(str_contains($everything, $media->getPathRelativeToRoot()))->toBeFalse()
        ->and(str_contains($everything, $media->file_name))->toBeFalse()
        ->and(is_string($host) && $host !== '' ? str_contains($everything, $host) : false)->toBeFalse();
});

it('answers 429 with no referrer once the per-minute limit of a user is used up', function (): void {
    config(['kokpit.documents.download_rate_limit_per_minute' => 3]);
    $document = fileRouteDocument($this->project, 'example-note.txt', 'Fictional bytes');
    $link = DocumentUrl::download($document, $this->admin);

    for ($request = 0; $request < 3; $request++) {
        $this->get($link)->assertOk();
    }

    expect($this->get($link)->assertStatus(429)->headers->get('Referrer-Policy'))->toBe('no-referrer');
});

it('counts the default limit per minute from the one config value', function (): void {
    expect(config('kokpit.documents.download_rate_limit_per_minute'))->toBe(120);
});
