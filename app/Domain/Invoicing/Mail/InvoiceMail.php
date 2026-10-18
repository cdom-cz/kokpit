<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The e-mail of a billing document (IN-04, D-22): plain text only, never HTML (UI-SPEC U-11). The subject and the body
 * are the texts stored on the log row, exactly as the Admin confirmed them; the view prints the body as it is, so
 * nothing in it is interpreted as markup. The attachments are built from bytes under generated names
 * (`faktura-<number>.pdf`, `pracovni-vykaz-<number>.pdf` and their English counterparts), never from user text.
 *
 * Not queued: the send job is the queued unit, and it calls `Mail::send()` so a transport failure surfaces in the job.
 */
final class InvoiceMail extends Mailable
{
    /**
     * @param  list<array{name: string, data: string}>  $files  PDF files, in the order they are attached
     */
    public function __construct(
        public readonly string $mailSubject,
        public readonly string $plainText,
        public readonly array $files,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.invoice-text');
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
