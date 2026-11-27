<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Knowledge\Support\InternalImageSrcSanitizer;
use App\Filament\Resources\KnowledgeArticleResource;
use App\Http\Controllers\KnowledgeAttachmentController;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;

/*
 * The one route through which a stored knowledge image reaches a browser (IMG-02, D-10 to D-15): inside the panel's
 * authentication, no signature, streamed by the application with its own header set. Every name is fictional.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Storage::fake('local');

    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

it('streams the stored bytes of a bound image to the Admin with the header set of D-12', function (): void {
    $article = KnowledgeFixtures::article();
    $image = KnowledgeFixtures::image($article, $this->admin);
    $bytes = (string) Storage::disk('local')->get($image->storagePath());

    $response = $this->get(KnowledgeAttachmentController::path($image->getKey()))->assertOk();
    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($response->streamedContent())->toBe($bytes)
        ->and($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Content-Length'))->toBe((string) strlen($bytes))
        ->and($cacheControl)->toContain('private')
        ->and($cacheControl)->toContain('max-age=300')
        ->and($response->headers->get('ETag'))->toBe('"'.$image->sha256.'"')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; sandbox")
        ->and($response->headers->get('Content-Disposition'))->toBe('inline')
        ->and((string) $response->headers->get('Vary'))->toContain('Cookie')
        ->and($response->headers->get('Location'))->toBeNull();
});

it('keeps the file name, the bucket and the storage path out of every header', function (): void {
    $image = KnowledgeFixtures::image(KnowledgeFixtures::article(), $this->admin);

    $headers = $this->get(KnowledgeAttachmentController::path($image->getKey()))->assertOk()->headers->__toString();

    expect(str_contains($headers, $image->file_name))->toBeFalse()
        ->and(str_contains($headers, $image->storagePath()))->toBeFalse()
        ->and(stripos($headers, 'X-Amz'))->toBeFalse();
});

it('builds a path that matches the one address the renderer accepts and carries no signature', function (): void {
    $id = (string) Str::uuid7();
    $path = KnowledgeAttachmentController::path($id);

    expect($path)->toBe('/admin/knowledge/attachments/'.$id)
        ->and(preg_match(InternalImageSrcSanitizer::PATTERN, $path))->toBe(1)
        ->and(str_contains($path, 'signature'))->toBeFalse();
});

it('registers the route without a signature middleware and with its own limiter', function (): void {
    $route = Route::getRoutes()->getByName(KnowledgeAttachmentController::ROUTE);

    expect($route)->not->toBeNull();

    $middleware = array_map(strval(...), $route->gatherMiddleware());

    expect($middleware)->toContain('throttle:knowledge-attachments');

    foreach ($middleware as $name) {
        expect(stripos($name, 'signed'))->toBeFalse($name)
            ->and(stripos($name, 'ValidateSignature'))->toBeFalse($name);
    }
});

it('sends a guest to the panel login and never a byte of the image', function (): void {
    $image = KnowledgeFixtures::image(KnowledgeFixtures::article(), $this->admin);
    $url = KnowledgeAttachmentController::path($image->getKey());

    auth()->forgetGuards();

    $this->get($url)->assertRedirect(Filament::getLoginUrl());
});

it('refuses the same link after the Admin logged out', function (): void {
    $image = KnowledgeFixtures::image(KnowledgeFixtures::article(), $this->admin);
    $url = KnowledgeAttachmentController::path($image->getKey());

    $this->get($url)->assertOk();

    $this->post(Filament::getLogoutUrl());
    auth()->forgetGuards();

    $this->get($url)->assertRedirect(Filament::getLoginUrl());
});

it('answers a Partner 403 for any image, for an unknown id and for a conditional request, and a guest never gets 304', function (): void {
    $image = KnowledgeFixtures::image(KnowledgeFixtures::article(), $this->admin);
    $url = KnowledgeAttachmentController::path($image->getKey());
    $etag = '"'.$image->sha256.'"';

    $this->actingAs(Canary::partnerFor(Client::factory()->create()->getKey()));

    $this->get($url)->assertForbidden();
    $this->get(KnowledgeAttachmentController::path((string) Str::uuid7()))->assertForbidden();
    $this->get('/admin/knowledge/attachments/not-a-uuid')->assertForbidden();
    $this->get($url, ['If-None-Match' => $etag])->assertForbidden();

    auth()->forgetGuards();

    $this->get($url, ['If-None-Match' => $etag])->assertRedirect(Filament::getLoginUrl());
});

it('answers 404 for an unknown id and for a malformed id', function (): void {
    $this->get(KnowledgeAttachmentController::path((string) Str::uuid7()))->assertNotFound();
    $this->get('/admin/knowledge/attachments/not-a-uuid')->assertNotFound();
});

it('answers 404 for a row whose object is gone from the disk', function (): void {
    $image = KnowledgeFixtures::image(KnowledgeFixtures::article(), $this->admin);

    Storage::disk('local')->delete($image->storagePath());

    $this->get(KnowledgeAttachmentController::path($image->getKey()))->assertNotFound();
});

it('serves the own pending image to its uploader and answers 404 for the pending image of another Admin', function (): void {
    $own = KnowledgeFixtures::image(null, $this->admin);
    $foreign = KnowledgeFixtures::image(null, Canary::admin());

    $this->get(KnowledgeAttachmentController::path($own->getKey()))->assertOk();
    $this->get(KnowledgeAttachmentController::path($foreign->getKey()))->assertNotFound();
});

it('serves an image of an archived article, because authorisation follows the article', function (): void {
    $image = KnowledgeFixtures::image(KnowledgeFixtures::archived(), $this->admin);

    $this->get(KnowledgeAttachmentController::path($image->getKey()))->assertOk();
});

it('answers 304 with an empty body to the matching ETag and 200 to another one', function (): void {
    $image = KnowledgeFixtures::image(KnowledgeFixtures::article(), $this->admin);
    $url = KnowledgeAttachmentController::path($image->getKey());
    $etag = '"'.$image->sha256.'"';

    $notModified = $this->get($url, ['If-None-Match' => $etag]);

    expect($notModified->getStatusCode())->toBe(304)
        ->and((string) $notModified->getContent())->toBe('')
        ->and($notModified->headers->get('ETag'))->toBe($etag)
        ->and((string) $notModified->headers->get('Cache-Control'))->toContain('max-age=300');

    $other = $this->get($url, ['If-None-Match' => '"'.str_repeat('a', 64).'"']);

    expect($other->getStatusCode())->toBe(200);
});

it('answers 304 only after the authorisation, so a pending image of another Admin is 404 even with the right ETag', function (): void {
    $foreign = KnowledgeFixtures::image(null, Canary::admin());

    $this->get(KnowledgeAttachmentController::path($foreign->getKey()), ['If-None-Match' => '"'.$foreign->sha256.'"'])
        ->assertNotFound();
});

it('adds no Content-Security-Policy header to the article screen', function (): void {
    $article = KnowledgeFixtures::article();

    $response = $this->get(KnowledgeArticleResource::getUrl('view', ['record' => $article->getKey()]))->assertOk();

    expect($response->headers->has('Content-Security-Policy'))->toBeFalse();
});

it('answers 429 once the per-minute limit of the Admin is used up', function (): void {
    config(['kokpit.knowledge.image_rate_limit_per_minute' => 3]);

    $image = KnowledgeFixtures::image(KnowledgeFixtures::article(), $this->admin);
    $url = KnowledgeAttachmentController::path($image->getKey());

    for ($request = 0; $request < 3; $request++) {
        $this->get($url)->assertOk();
    }

    expect($this->get($url)->assertStatus(429)->headers->get('Referrer-Policy'))->toBe('no-referrer');
});

it('counts the default limit per minute from the one config value', function (): void {
    expect(config('kokpit.knowledge.image_rate_limit_per_minute'))->toBe(600);
});
