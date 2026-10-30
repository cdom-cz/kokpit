<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Mail;

use App\Domain\Notifications\Mail\MailText;
use App\Domain\Shared\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The confirmation to the client that a payment was received (Phase 13, D-05, D-06): a Markdown message on the shared
 * mail layout with the supplier header and footer. It names the document number, the amount paid, the payment date and,
 * for a partial payment, the amount still owed; the payment that leaves nothing owed reads "paid in full". It has no
 * pay button (A-13-5). The only attachment it can carry is the receipt of a cash payment (Phase 15, D-12): the stored
 * PDF, named by a generated file name, and one sentence naming the receipt number; a payment by another method, and a
 * receipt cancelled before the send, have no attachment and no sentence. The plain-text alternative is rendered from the same view by the text
 * components of the layout, so both parts state the same facts.
 *
 * Every value is formatted for the language of the client by {@see self::forPayment()}; the view makes each one inert
 * with {@see MailText::escapeMarkdown()} before it enters the sentence, so a document number with an asterisk or an
 * underscore reads literally.
 *
 * Not queued: the send job is the queued unit, and it calls `Mail::send()` so a transport failure surfaces in the job.
 */
final class PaymentConfirmationMail extends Mailable
{
    /**
     * @param  string|null  $remaining  the formatted amount still owed, null when nothing is owed any more
     */
    public function __construct(
        public readonly string $number,
        public readonly string $amount,
        public readonly string $paidOn,
        public readonly ?string $remaining,
        private readonly string $language,
        public readonly ?string $receiptNumber = null,
        private readonly ?string $receiptName = null,
        private readonly ?string $receiptData = null,
    ) {
        $this->locale($language);
    }

    /**
     * Builds the e-mail from the facts of a payment, formatted for the language of the client: the amount with the
     * grouping of the language, the date as `j. n. Y` in Czech and `Y-m-d` in English (the formats of the document
     * e-mail) and the remaining amount only while something is still owed.
     *
     * @param  array{name: string, data: string, number: string}|null  $receipt  the receipt of a cash payment: the generated
     *                                                                           file name, the PDF bytes and the receipt number
     */
    public static function forPayment(string $number, Money $amount, CarbonImmutable $paidOn, int $remainingMinor, string $language, ?array $receipt = null): self
    {
        return new self(
            number: $number,
            amount: $amount->format($language),
            paidOn: $paidOn->format($language === 'cs' ? 'j. n. Y' : 'Y-m-d'),
            remaining: $remainingMinor > 0 ? Money::ofMinor($remainingMinor, $amount->currency)->format($language) : null,
            language: $language,
            receiptNumber: $receipt['number'] ?? null,
            receiptName: $receipt['name'] ?? null,
            receiptData: $receipt['data'] ?? null,
        );
    }

    public function envelope(): Envelope
    {
        $key = $this->remaining === null ? 'full' : 'partial';

        return new Envelope(from: SupplierSender::address(), subject: (string) trans('payment_mail.subject.'.$key, ['number' => $this->number], $this->language));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.payment-confirmation');
    }

    /**
     * The receipt PDF of a cash payment as the only attachment (D-12), none otherwise (D-06).
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $data = $this->receiptData;

        if ($data === null || $this->receiptName === null) {
            return [];
        }

        return [Attachment::fromData(static fn (): string => $data, $this->receiptName)->withMime('application/pdf')];
    }
}
