<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Jobs;

use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Invoicing\Enums\InvoiceEmailState;
use App\Domain\Invoicing\Enums\InvoiceLockState;
use App\Domain\Invoicing\Mail\InvoiceMail;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Invoicing\Notifications\InvoiceEmailFailedNotification;
use App\Domain\Invoicing\Pdf\InvoicePdf;
use App\Domain\Invoicing\Support\InvoiceAudit;
use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
use App\Domain\Payments\Support\PayLinkBlock;
use App\Domain\Reports\WorkReport\WorkReportPdf;
use App\Domain\Reports\WorkReport\WorkReportSource;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Resources\InvoiceResource;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Sends one queued document e-mail (IN-04; D-22, Phase 8 D-10, ruling R-24-1): the PDF stored at issue and, when the
 * row asks for it and the document still has linked time, the work report built from exactly those entries.
 *
 * The unit is the log row. The job claims it with a conditional update (`queued` to `sending`, one attempt counted)
 * in a transaction that locks the invoice row first, the order of every writer, so an edit that was admitted because
 * the row is stale and the claim of that row never interleave. A second run, a double dispatch and a row that is sent,
 * failed or gone do nothing. A run that throws puts the row back to `queued` for the next try; once the queue gives up, {@see self::failed()} marks it `failed` with a fixed
 * failure class and tells the Admins in the bell, once. A missing stored PDF (no link, or no object) is final at once:
 * retrying cannot bring it back. A resend is a new row, not a retry of an old one (R-24-1); the state of a document is
 * the state of its latest row, and a row left in `sending` by a dead worker counts as failed after the stale
 * threshold of the System page (plan 10-37).
 *
 * Failure classes (R-24-2): `transport` (the mail transport refused), `attachment_missing` (the PDF object is gone),
 * `stale` (a queued row whose last move is older than the stale threshold: its job was lost or the worker was down;
 * the screens already read it as failed and offer the resend, so it is never sent late, D-10; plan 10.1-15),
 * `unexpected` (anything else). Only the class is stored; neither the log nor the bell ever holds an exception text,
 * a provider answer or the recipient (T-10-65).
 *
 * Delivery is at-least-once (T-10-64): a row that is finished, or that a dead worker left in `sending`, is never
 * sent again by a redelivery. What can send twice is a run that fails after the transport accepted the message, while
 * the row is being marked sent: the next try claims the row again. That one repeat is the stated guarantee.
 *
 * For a document issued with card payment on that still owes money, the pay-link block of {@see PayLinkBlock} follows
 * the stored body at send time (D-03); the log row keeps the confirmed text only.
 *
 * The successful send of a document e-mail is the only lock of the system (IN-11; D-01, D-02): the sent mark and the
 * lock of an issued invoice or proforma are written in one transaction that locks the invoice row first, the order of
 * every edit of the document (invoice, then children). A queued, sending or failed row, a payment, a credit note and
 * a storno lock nothing, and a document that is locked already is left exactly as it is, because a locked row refuses
 * even a timestamp-only write. No other e-mail kind may call this step: a later payment confirmation must never lock
 * the invoice it confirms.
 *
 * The work report reads the supplier and client lines live, not from the snapshot of the document (R-24-4): the
 * figures in it are hours, and a changed address in the header is an accepted drift of an attachment that carries no
 * legal weight.
 */
#[Idempotent(how: 'identified by the e-mail row id; the row is claimed by a conditional update from queued to sending, so a row that is sending, sent or failed is skipped, and a rerun of a finished row sends nothing')]
#[Timeout(120)]
final class SendInvoiceEmail extends KokpitJob
{
    public const string FAILURE_TRANSPORT = 'transport';

    public const string FAILURE_ATTACHMENT_MISSING = 'attachment_missing';

    public const string FAILURE_UNEXPECTED = 'unexpected';

    public const string FAILURE_STALE = 'stale';

    /** The message the queue and the failed-job alert see for a refused transport. */
    private const string TRANSPORT_FAILED = 'The mail transport refused the message.';

    public function __construct(public readonly string $emailId) {}

    public function handle(): void
    {
        // The id comes from the queue payload: a value that is no UUID names no row.
        if (! Str::isUuid($this->emailId)) {
            return;
        }

        if (! $this->claim()) {
            return;
        }

        $row = InvoiceEmail::query()->findOrFail($this->emailId);

        try {
            $files = $this->files($row);

            if ($files === null) {
                $this->giveUp(self::FAILURE_ATTACHMENT_MISSING);

                return;
            }

            Mail::to($row->recipient)->send(new InvoiceMail($row->subject, $this->bodyToSend($row), $files));

            $this->markSentAndLock($row);
        } catch (Throwable $e) {
            // Back to queued so the next try can claim the row again; the queue decides when it gives up.
            InvoiceEmail::query()
                ->whereKey($row->id)
                ->where('state', InvoiceEmailState::Sending->value)
                ->update(['state' => InvoiceEmailState::Queued->value, 'updated_at' => now()]);

            // The provider's answer can name the recipient, and the failed-job alert prints the first line of a
            // message into the bell (T-10-65): a transport failure leaves as a fixed message. The original stays
            // as the previous exception, which only the failed_jobs record and the log hold.
            throw $e instanceof TransportExceptionInterface ? new RuntimeException(self::TRANSPORT_FAILED, 0, $e) : $e;
        }
    }

    /**
     * Claims the row for this run, in one transaction that locks the invoice row first (the order of every writer:
     * invoice, then children; the edit holds the same lock while it asks whether an e-mail is in flight). A queued row
     * is taken only while its last move is younger than the stale threshold ({@see InvoiceEmail::staleBefore()}).
     * An older queued row is one the screens already read as failed and the Admin may have edited or resent the
     * document since, so it is given up here as failed with the class `stale` and the Admins are told once (D-10);
     * it is never sent late. A row that is sending, sent, failed or gone is left alone. True when the row was claimed.
     */
    private function claim(): bool
    {
        return DB::transaction(function (): bool {
            $invoiceId = InvoiceEmail::query()->whereKey($this->emailId)->value('invoice_id');

            if (! is_string($invoiceId)) {
                return false;
            }

            Invoice::query()->whereKey($invoiceId)->lockForUpdate()->value('id');

            $cutoff = InvoiceEmail::staleBefore();

            $claimed = InvoiceEmail::query()
                ->whereKey($this->emailId)
                ->where('state', InvoiceEmailState::Queued->value)
                ->where('updated_at', '>', $cutoff)
                ->update([
                    'state' => InvoiceEmailState::Sending->value,
                    'attempts' => DB::raw('attempts + 1'),
                    'updated_at' => now(),
                ]);

            if ($claimed === 1) {
                return true;
            }

            $gaveUp = InvoiceEmail::query()
                ->whereKey($this->emailId)
                ->where('state', InvoiceEmailState::Queued->value)
                ->where('updated_at', '<=', $cutoff)
                ->update([
                    'state' => InvoiceEmailState::Failed->value,
                    'failure_code' => self::FAILURE_STALE,
                    'updated_at' => now(),
                ]);

            if ($gaveUp === 1) {
                $this->tellAdmins($invoiceId);
            }

            return false;
        });
    }

    /**
     * Marks the row sent and locks the document, in one transaction (D-01). The invoice row is locked first; then the
     * conditional update from sending to sent; the lock follows only when that update changed the row and the document
     * is an issued invoice or proforma that is not locked yet. The lock is saved through the model, never a bulk query
     * update, and {@see InvoiceAudit::locked()} writes its one history row in the same transaction; a resend of a
     * locked document writes none. A failure rolls both back and reaches the catch of {@see self::handle()}, which puts the row back to
     * queued: the retry may send again, the at-least-once guarantee stated above.
     */
    private function markSentAndLock(InvoiceEmail $row): void
    {
        DB::transaction(function () use ($row): void {
            $invoice = Invoice::query()->whereKey($row->invoice_id)->lockForUpdate()->firstOrFail();

            $marked = InvoiceEmail::query()
                ->whereKey($row->id)
                ->where('state', InvoiceEmailState::Sending->value)
                ->update([
                    'state' => InvoiceEmailState::Sent->value,
                    'sent_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($marked === 1 && $invoice->isIssued() && $invoice->kind->carriesPayment() && ! $invoice->isLocked()) {
                $invoice->forceFill(['lock_state' => InvoiceLockState::Locked])->save();

                // D-13: the one history row of this lock, in the same transaction, with the sender as causer.
                InvoiceAudit::locked($invoice, $row->sender);
            }
        });
    }

    /**
     * The queue gave up. The row is marked failed with a fixed class and the Admins are told once; a row that is
     * already sent, failed or gone is left alone. The job middleware does not wrap this method, so the system
     * context is entered here.
     */
    public function failed(Throwable $exception): void
    {
        app(PartnerContext::class)->runAsSystem(function () use ($exception): void {
            $transport = $exception instanceof TransportExceptionInterface || $exception->getPrevious() instanceof TransportExceptionInterface;

            $this->giveUp($transport ? self::FAILURE_TRANSPORT : self::FAILURE_UNEXPECTED);
        });
    }

    /**
     * Marks a row that is still queued or sending failed and sends the one bell notice to every Admin, in one
     * transaction. The notice names the document number only.
     */
    private function giveUp(string $failureCode): void
    {
        if (! Str::isUuid($this->emailId)) {
            return;
        }

        DB::transaction(function () use ($failureCode): void {
            $changed = InvoiceEmail::query()
                ->whereKey($this->emailId)
                ->whereIn('state', [InvoiceEmailState::Queued->value, InvoiceEmailState::Sending->value])
                ->update([
                    'state' => InvoiceEmailState::Failed->value,
                    'failure_code' => $failureCode,
                    'updated_at' => now(),
                ]);

            if ($changed !== 1) {
                return;
            }

            $this->tellAdmins(InvoiceEmail::query()->whereKey($this->emailId)->value('invoice_id'));
        });
    }

    /**
     * The one bell notice of a failed send to every Admin. It names the document number only (T-10-65).
     */
    private function tellAdmins(mixed $invoiceId): void
    {
        $number = Invoice::query()->whereKey($invoiceId)->value('number');
        $notification = new InvoiceEmailFailedNotification(
            number: is_string($number) ? $number : '',
            url: InvoiceResource::getUrl('view', ['record' => $invoiceId]),
        );

        foreach (User::query()->role(RoleName::Admin->value)->get() as $admin) {
            $admin->notifyNow($notification);
        }
    }

    /**
     * The body that leaves: the text the Admin confirmed and, for a document that still owes money and was issued with
     * card payment on, the fixed pay-link block after it (D-03). The block is added here, at send time, so clearing the
     * text cannot remove it and a resend of a paid or cancelled document carries none; the log row keeps exactly what
     * the Admin confirmed.
     */
    private function bodyToSend(InvoiceEmail $row): string
    {
        $block = PayLinkBlock::forMail(Invoice::query()->findOrFail($row->invoice_id));

        return $block === null ? $row->body : rtrim($row->body).$block;
    }

    /**
     * The PDF files of the mail in the order they are attached: the stored PDF of the document and, when the row
     * asks for it and the document still has linked time, the work report. Null when the stored PDF is not there.
     *
     * @return list<array{name: string, data: string}>|null
     */
    private function files(InvoiceEmail $row): ?array
    {
        $invoice = Invoice::query()->with('admin')->findOrFail($row->invoice_id);
        $bytes = $this->storedPdf($invoice);

        if ($bytes === null) {
            return null;
        }

        $files = [['name' => app(InvoicePdf::class)->fileName($invoice), 'data' => $bytes]];

        if ($row->attach_work_report) {
            /** @var list<string> $entryIds */
            $entryIds = $invoice->entries()->pluck('time_entries.id')->values()->all();

            if ($entryIds !== []) {
                $source = WorkReportSource::fromEntryIds($entryIds, CarbonImmutable::now());

                $files[] = [
                    'name' => (string) trans('invoice_mail.work_report_file', ['number' => $this->safeNumber($invoice)], $invoice->language->value),
                    'data' => app(WorkReportPdf::class)->render($source, $invoice->language),
                ];
            }
        }

        return $files;
    }

    /**
     * The bytes of the stored PDF, or null when the document has no PDF link or its object cannot be read.
     */
    private function storedPdf(Invoice $invoice): ?string
    {
        $document = $invoice->admin?->pdfDocument;

        if (! $document instanceof Document) {
            return null;
        }

        $media = $document->getFirstMedia(Document::MEDIA_COLLECTION);

        if ($media === null) {
            return null;
        }

        $disk = Storage::disk($media->disk);
        $path = $media->getPathRelativeToRoot();

        if (! $disk->exists($path)) {
            return null;
        }

        $bytes = $disk->get($path);

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    /**
     * The number reduced to the characters of a file name (the rule of the stored PDF's name).
     */
    private function safeNumber(Invoice $invoice): string
    {
        $number = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $invoice->number) ?? '';

        return trim($number, '-');
    }
}
