<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Mail;

use App\Domain\Notifications\Mail\PlainTextHtml;
use App\Domain\Payments\Support\PayLinkBlock;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The e-mail of a billing document (IN-04, D-22; Phase 13 D-01, D-02): an HTML message on the shared mail layout with
 * the supplier header and footer, and a plain-text alternative. The subject and the body are the texts stored on the log
 * row, exactly as the Admin confirmed them. The HTML view escapes the body into paragraph blocks and never parses it as
 * Markdown or HTML ({@see PlainTextHtml}); the plain-text alternative is the body itself, followed by the pay-link text
 * block when the document offers card payment, byte for byte what was sent before the layout existed. The attachments
 * are built from bytes under generated names (`faktura-<number>.pdf`, `pracovni-vykaz-<number>.pdf` and their English
 * counterparts), never from user text. The layout strings and the button follow the language of the document.
 *
 * Not queued: the send job is the queued unit, and it calls `Mail::send()` so a transport failure surfaces in the job.
 */
final class InvoiceMail extends Mailable
{
    /** The plain-text alternative: the confirmed body and, when card payment is offered, the pay-link block. */
    public readonly string $plainText;

    /**
     * @param  array{lead: string, url: string, label: string}|null  $payLink  the result of {@see PayLinkBlock::forHtml()}
     * @param  list<array{name: string, data: string}>  $files  PDF files, in the order they are attached
     */
    public function __construct(
        public readonly string $mailSubject,
        public readonly string $body,
        public readonly ?array $payLink,
        public readonly array $files,
        string $language,
    ) {
        $this->plainText = $payLink === null ? $body : rtrim($body).PayLinkBlock::textBlock($payLink);

        $this->locale($language);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invoice', text: 'mail.invoice-text');
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            static fn (array $file): Attachment => Attachment::fromData(static fn (): string => $file['data'], $file['name'])->withMime('application/pdf'),
            $this->files,
        );
    }
}
