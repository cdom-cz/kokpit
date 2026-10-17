<?php

declare(strict_types=1);

use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Documents\Actions\DeleteDocument;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Support\DocumentUrl;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Support\InvoicePdfUrl;
use App\Domain\Shared\Auth\PartnerContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The one route through which the PDF of an issued document reaches its reader (IN-09, IN-04; D-21; ruling R-38-1):
 * signed, short-lived, bound to the signed-in user, authorised through the Partner-scoped invoice and the policy,
 * and streamed by the application. The stored PDF is reached through the Admin-only link as system. Every name,
 * number and amount is fictional.
 */

/**
 * The stored PDF document of an issued invoice, read as system.
 */
function pdfRouteDocument(Invoice $invoice): Document
{
    return app(PartnerContext::class)->runAsSystem(static function () use ($invoice): Document {
        $id = DB::table('invoice_admin')->where('invoice_id', $invoice->id)->value('pdf_document_id');

        return Document::query()->whereKey($id)->firstOrFail();
    });
}

/**
 * The bytes stored for the PDF of an issued invoice.
 */
function pdfRouteStoredBytes(Invoice $invoice): string
{
    $document = pdfRouteDocument($invoice);

    $media = app(PartnerContext::class)->runAsSystem(static fn () => $document->getFirstMedia(Document::MEDIA_COLLECTION));

    return (string) Storage::disk($media->disk)->get($media->getPathRelativeToRoot());
}

/**
 * A signed link for any invoice id, as the application would sign it for the given user.
 */
function pdfRouteLink(string $invoiceId, string $userId): string
{
    return URL::temporarySignedRoute(InvoicePdfUrl::ROUTE, now()->addSeconds((int) config('kokpit.documents.signed_url_ttl_seconds')), [
        'invoice' => $invoiceId,
        'u' => $userId,
    ]);
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();

    $this->client = InvoiceFixtures::client();
    $this->partner = Canary::partnerFor($this->client->id);
    $this->admin = Canary::admin();
});

describe('the download', function (): void {
    it('streams the stored PDF of an issued invoice to the Partner of its client as an attachment', function (): void {
        $issued = InvoiceFixtures::issued($this->client);
        $bytes = pdfRouteStoredBytes($issued);

        $this->actingAs($this->partner);

        $url = InvoicePdfUrl::download($issued, $this->partner);

        expect(parse_url($url, PHP_URL_PATH))->toStartWith('/admin/files/invoices/'.$issued->id);

        $response = $this->get($url)->assertOk();

        expect($bytes)->not->toBe('')
            ->and($response->streamedContent())->toBe($bytes)
            ->and($response->headers->get('Content-Type'))->toBe('application/pdf')
            ->and((string) $response->headers->get('Content-Disposition'))->toStartWith('attachment')
            ->and((string) $response->headers->get('Content-Disposition'))->toContain((string) pdfRouteDocument($issued)->name)
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and((string) $response->headers->get('Cache-Control'))->toContain('private')
            ->and((string) $response->headers->get('Cache-Control'))->toContain('no-store')
            ->and($response->headers->get('Content-Length'))->toBe((string) strlen($bytes))
            ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer');
    });

    it('streams the same PDF to the Admin through a link built for the Admin', function (): void {
        $issued = InvoiceFixtures::issued($this->client);
        $bytes = pdfRouteStoredBytes($issued);

        $this->actingAs($this->admin);

        $response = $this->get(InvoicePdfUrl::download($issued, $this->admin))->assertOk();

        expect($response->streamedContent())->toBe($bytes)
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    });
});

describe('the refusals', function (): void {
    it('refuses an unsigned, tampered or expired link and a link signed for another user with 403', function (): void {
        $issued = InvoiceFixtures::issued($this->client);
        $otherPartner = Canary::partnerFor($this->client->id);

        $this->actingAs($this->partner);

        $unsigned = route(InvoicePdfUrl::ROUTE, ['invoice' => $issued->id, 'u' => $this->partner->getKey()]);
        $signed = InvoicePdfUrl::download($issued, $this->partner);
        $tampered = substr($signed, 0, -1).(str_ends_with($signed, 'a') ? 'b' : 'a');
        $foreign = InvoicePdfUrl::download($issued, $otherPartner);

        foreach ([$unsigned, $tampered, $foreign] as $link) {
            $response = $this->get($link);

            expect($response->getStatusCode())->toBe(403, $link)
                ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer');
        }

        $this->travel((int) config('kokpit.documents.signed_url_ttl_seconds') + 1)->seconds();

        expect($this->get($signed)->getStatusCode())->toBe(403);
    });

    it('answers 404 for a malformed and for an unknown invoice id, to the Partner and the Admin', function (): void {
        foreach ([$this->partner, $this->admin] as $user) {
            $this->actingAs($user);

            foreach (['not-a-uuid', (string) Str::uuid7()] as $id) {
                $this->get(pdfRouteLink($id, $user->getKey()))->assertNotFound();
            }
        }
    });

    it('answers a Partner 404, never 403, for the issued invoice of another client and for the own draft', function (): void {
        $foreign = InvoiceFixtures::issued(InvoiceFixtures::client());
        $draft = InvoiceFixtures::draft($this->client);

        $this->actingAs($this->partner);

        foreach ([$foreign, $draft] as $invoice) {
            $response = $this->get(InvoicePdfUrl::download($invoice, $this->partner))->assertNotFound();

            expect(str_contains((string) $response->getContent(), (string) $invoice->id))->toBeFalse();
        }
    });

    it('answers the Admin 404 for a draft', function (): void {
        $draft = InvoiceFixtures::draft($this->client);

        $this->actingAs($this->admin);

        $this->get(InvoicePdfUrl::download($draft, $this->admin))->assertNotFound();
    });

    it('answers a Partner without a client 403 or 404, since the panel itself refuses such an account', function (): void {
        $issued = InvoiceFixtures::issued($this->client);
        $loose = Canary::partnerFor(null);

        $this->actingAs($loose);

        expect($this->get(InvoicePdfUrl::download($issued, $loose))->getStatusCode())->toBeIn([403, 404]);
    });

    it('sends a guest who opens a valid link to the panel login', function (): void {
        $issued = InvoiceFixtures::issued($this->client);
        $link = InvoicePdfUrl::download($issued, $this->partner);

        auth()->forgetGuards();

        $this->get($link)->assertRedirect(Filament::getLoginUrl());
    });
});

describe('a PDF that is not there or not the invoice\'s own', function (): void {
    it('answers 404 to the Partner and the Admin when the PDF was deleted', function (): void {
        $issued = InvoiceFixtures::issued($this->client);
        $pdf = pdfRouteDocument($issued);

        // The sanctioned path: deleting the file clears the link in the database.
        app(DeleteDocument::class)->handle($this->admin, $pdf, true);

        foreach ([$this->partner, $this->admin] as $user) {
            $this->actingAs($user);

            $response = $this->get(InvoicePdfUrl::download($issued, $user))->assertNotFound();

            expect(str_contains((string) $response->getContent(), (string) $pdf->id))->toBeFalse();
        }
    });

    it('answers 404 when the stored object is gone', function (): void {
        $issued = InvoiceFixtures::issued($this->client);
        $document = pdfRouteDocument($issued);
        $media = app(PartnerContext::class)->runAsSystem(static fn () => $document->getFirstMedia(Document::MEDIA_COLLECTION));

        Storage::disk($media->disk)->delete($media->getPathRelativeToRoot());

        $this->actingAs($this->partner);

        $this->get(InvoicePdfUrl::download($issued, $this->partner))->assertNotFound();
    });

    it('answers 404 to the Partner and the Admin for a link repointed to a document of another owner, without relying on the database guard', function (): void {
        $issued = InvoiceFixtures::issued($this->client);
        $stray = app(AttachDocuments::class)
            ->handle($this->admin, null, [DocumentFixtures::upload('example-stray.pdf', 'Example content.')], false)
            ->firstOrFail();

        // A file of no invoice, to which the link is forced.
        // Mutation style: the guard that refuses the repoint is dropped for this test only (it is rolled back).
        DB::unprepared('DROP TRIGGER invoice_admin_pdf_link_guard ON invoice_admin');
        DB::table('invoice_admin')->where('invoice_id', $issued->id)->update(['pdf_document_id' => $stray->id]);

        foreach ([$this->partner, $this->admin] as $user) {
            $this->actingAs($user);

            $response = $this->get(InvoicePdfUrl::download($issued, $user))->assertNotFound();

            expect(str_contains((string) $response->getContent(), (string) $stray->id))->toBeFalse();
        }
    });
});

it('keeps the Document scope closed: the Partner finds no document row of the invoice file, only the route hands out the bytes', function (): void {
    $issued = InvoiceFixtures::issued($this->client);
    $pdf = pdfRouteDocument($issued);

    $this->actingAs($this->partner);

    expect(Document::query()->whereKey($pdf->id)->exists())->toBeFalse();

    $this->get(DocumentUrl::download($pdf, $this->partner))->assertNotFound();
    $this->get(InvoicePdfUrl::download($issued, $this->partner))->assertOk();
});
