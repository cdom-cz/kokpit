<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Actions;

use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Enums\InvoiceEmailState;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Jobs\SendInvoiceEmail;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Queues the e-mail of an issued document (IN-04; D-22, ruling R-24-1). The Admin only.
 *
 * {@see self::defaults()} gives the dialog its pre-filled values: the invoice e-mail of the client, and the subject
 * and the body of the UI-SPEC wording in the language of the document (`cs` or `en`), with the number, the amount, the
 * due date and the supplier name read from the frozen document. {@see self::handle()} validates what the Admin
 * confirmed, stores one log row `queued` and dispatches {@see SendInvoiceEmail} after the commit.
 *
 * Every send, a resend included, is a new row (one attempt group, R-24-1); nothing is looked up or reused. The
 * recipient must be a valid address without line breaks, CR and LF are removed from the subject (a header cannot be
 * injected through it), the subject is capped at 200 and the body at 5 000 characters, and control characters other
 * than a line break and a tab are dropped from both (a NUL byte would also be refused by the database). The body is
 * plain text. The work-report flag is kept only when the document still has linked time: a cancelled invoice has none.
 */
final readonly class QueueInvoiceEmail
{
    private const int RECIPIENT_MAX = 255;

    private const int SUBJECT_MAX = 200;

    private const int BODY_MAX = 5000;

    /**
     * The values the dialog is opened with. `recipient` is null when the client has no invoice e-mail; the work
     * report is ticked by default whenever the document has linked time.
     *
     * @return array{recipient: ?string, subject: string, body: string, attach_work_report: bool, has_work_report: bool}
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     */
    public function defaults(Invoice $issued): array
    {
        $context = app(PartnerContext::class);

        if (! $context->isAdmin() && ! $context->isSystem()) {
            throw new AuthorizationException('The e-mail of a document is prepared by the Admin only.');
        }

        $recipient = $issued->client?->invoice_email;
        $recipient = $recipient === null || trim($recipient) === '' ? null : trim($recipient);
        $hasReport = $issued->entries()->exists();

        return [
            'recipient' => $recipient,
            'subject' => $this->defaultSubject($issued),
            'body' => $this->defaultBody($issued),
            'attach_work_report' => $hasReport,
            'has_work_report' => $hasReport,
        ];
    }

    /**
     * @param  array<string, mixed>  $data  `recipient`, `subject`, `body`, `attach_work_report`
     *
     * @throws AuthorizationException
     * @throws ValidationException `invoice` for a draft; `recipient`, `subject` or `body` for a value that is refused
     */
    public function handle(User $actor, Invoice $issued, array $data): InvoiceEmail
    {
        Gate::forUser($actor)->authorize('update', $issued);

        return DB::transaction(function () use ($actor, $issued, $data): InvoiceEmail {
            $document = Invoice::query()->whereKey($issued->getKey())->firstOrFail();

            if (! $document->isIssued()) {
                throw ValidationException::withMessages(['invoice' => __('invoices.errors.email_not_issued')]);
            }

            $recipient = $this->cleanRecipient($data['recipient'] ?? null);
            $subject = $this->cleanSubject($data['subject'] ?? null);
            $body = $this->cleanBody($data['body'] ?? null);
            $attachReport = ($data['attach_work_report'] ?? false) === true && $document->entries()->exists();

            $row = new InvoiceEmail;
            $row->forceFill([
                'invoice_id' => $document->id,
                'recipient' => $recipient,
                'subject' => $subject,
                'body' => $body,
                'attach_work_report' => $attachReport,
                'state' => InvoiceEmailState::Queued,
                'created_by' => $actor->getKey(),
            ])->save();

            SendInvoiceEmail::dispatch($row->id)->afterCommit();

            return $row;
        });
    }

    private function defaultSubject(Invoice $issued): string
    {
        return (string) trans('invoice_mail.subject.'.$issued->kind->value, ['number' => (string) $issued->number], $issued->language->value);
    }

    private function defaultBody(Invoice $issued): string
    {
        $language = $issued->language;
        $supplier = $issued->supplier_snapshot['name'] ?? '';

        $replace = [
            'kind' => (string) trans('invoice_mail.kind.'.$issued->kind->value, [], $language->value),
            'number' => (string) $issued->number,
            'amount' => Money::ofMinor($issued->total_minor, $issued->currency)->format($language->value),
            'due' => $issued->due_date === null ? '' : $issued->due_date->format($language === InvoiceLanguage::Czech ? 'j. n. Y' : 'Y-m-d'),
            'supplier' => is_string($supplier) ? $supplier : '',
        ];

        // A credit note is not paid, so it has no due date; so does any document that carries none.
        $key = $issued->kind === InvoiceKind::CreditNote || $issued->due_date === null ? 'credit_note' : 'with_due_date';

        return (string) trans('invoice_mail.body.'.$key, $replace, $language->value);
    }

    /**
     * @throws ValidationException
     */
    private function cleanRecipient(mixed $value): string
    {
        $address = is_string($value) ? trim($value) : '';

        $valid = $address !== ''
            && preg_match('/[\x00-\x1F\x7F]/', $address) !== 1
            && mb_strlen($address) <= self::RECIPIENT_MAX
            && ! Validator::make(['recipient' => $address], ['recipient' => ['email:rfc']])->fails();

        if (! $valid) {
            throw ValidationException::withMessages(['recipient' => __('invoices.errors.email_recipient')]);
        }

        return $address;
    }

    /**
     * @throws ValidationException
     */
    private function cleanSubject(mixed $value): string
    {
        // A line break would end the header: it becomes a space, so two words never run together.
        $subject = is_string($value) ? trim($this->withoutControlCharacters(preg_replace('/\s*[\r\n]+\s*/', ' ', $value) ?? '', false)) : '';

        if ($subject === '' || mb_strlen($subject) > self::SUBJECT_MAX) {
            throw ValidationException::withMessages(['subject' => __('invoices.errors.email_subject')]);
        }

        return $subject;
    }

    /**
     * @throws ValidationException
     */
    private function cleanBody(mixed $value): string
    {
        $body = is_string($value) ? trim($this->withoutControlCharacters(str_replace(["\r\n", "\r"], "\n", $value), true)) : '';

        if ($body === '' || mb_strlen($body) > self::BODY_MAX) {
            throw ValidationException::withMessages(['body' => __('invoices.errors.email_body')]);
        }

        return $body;
    }

    /**
     * Drops the control characters (NUL, escape, the delete character and the rest of C0), keeping a tab and, when
     * the text is multi-line, the line break.
     */
    private function withoutControlCharacters(string $text, bool $keepLineBreaks): string
    {
        $pattern = $keepLineBreaks ? '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/' : '/[\x00-\x08\x0A-\x1F\x7F]/';

        return preg_replace($pattern, '', $text) ?? '';
    }
}
