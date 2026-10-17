<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Jobs;

use App\Domain\Documents\Models\Document;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Invoicing\Enums\InvoiceEmailState;
use App\Domain\Invoicing\Mail\InvoiceMail;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceEmail;
use App\Domain\Invoicing\Notifications\InvoiceEmailFailedNotification;
use App\Domain\Invoicing\Pdf\InvoicePdf;
use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
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
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Sends one queued document e-mail (IN-04; D-22, Phase 8 D-10, ruling R-24-1): the PDF stored at issue and, when the
 * row asks for it and the document still has linked time, the work report built from exactly those entries.
 *
 * The unit is the log row. The job claims it with a conditional update (`queued` to `sending`, one attempt counted),
 * so a second run, a double dispatch and a row that is sent, failed or gone do nothing. A run that throws puts the row
 * back to `queued` for the next try; once the queue gives up, {@see self::failed()} marks it `failed` with a fixed
 * failure class and tells the Admins in the bell, once. A missing stored PDF (no link, or no object) is final at once:
 * retrying cannot bring it back. A resend is a new row, not a retry of an old one (R-24-1); the state of a document is
 * the state of its latest row, and a row left in `sending` by a dead worker counts as failed after the stale
 * threshold of the System page (plan 10-37).
 *
 * Failure classes (R-24-2): `transport` (the mail transport refused), `attachment_missing` (the PDF object is gone),
 * `unexpected` (anything else). Only the class is stored; neither the log nor the bell ever holds an exception text,
 * a provider answer or the recipient (T-10-65).
 *
 * Delivery is at-least-once (T-10-64): a row that is finished, or that a dead worker left in `sending`, is never
 * sent again by a redelivery. What can send twice is a run that fails after the transport accepted the message, while
 * the row is being marked sent: the next try claims the row again. That one repeat is the stated guarantee.
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

    public function __construct(public readonly string $emailId) {}

    public function handle(): void
    {
        // The id comes from the queue payload: a value that is no UUID names no row.
        if (! Str::isUuid($this->emailId)) {
            return;
        }

        $claimed = InvoiceEmail::query()
            ->whereKey($this->emailId)
            ->where('state', InvoiceEmailState::Queued->value)
            ->update([
                'state' => InvoiceEmailState::Sending->value,
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return;
        }

        $row = InvoiceEmail::query()->findOrFail($this->emailId);

        try {
            $files = $this->files($row);

            if ($files === null) {
                $this->giveUp(self::FAILURE_ATTACHMENT_MISSING);

                return;
            }

            Mail::to($row->recipient)->send(new InvoiceMail($row->subject, $row->body, $files));

            InvoiceEmail::query()
                ->whereKey($row->id)
                ->where('state', InvoiceEmailState::Sending->value)
                ->update([
                    'state' => InvoiceEmailState::Sent->value,
                    'sent_at' => now(),
                    'updated_at' => now(),
                ]);
        } catch (Throwable $e) {
            // Back to queued so the next try can claim the row again; the queue decides when it gives up.
            InvoiceEmail::query()
                ->whereKey($row->id)
                ->where('state', InvoiceEmailState::Sending->value)
                ->update(['state' => InvoiceEmailState::Queued->value, 'updated_at' => now()]);

            throw $e;
        }
    }

    /**
     * The queue gave up. The row is marked failed with a fixed class and the Admins are told once; a row that is
     * already sent, failed or gone is left alone. The job middleware does not wrap this method, so the system
     * context is entered here.
     */
    public function failed(Throwable $exception): void
    {
        app(PartnerContext::class)->runAsSystem(function () use ($exception): void {
            $this->giveUp($exception instanceof TransportExceptionInterface ? self::FAILURE_TRANSPORT : self::FAILURE_UNEXPECTED);
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

            $invoiceId = InvoiceEmail::query()->whereKey($this->emailId)->value('invoice_id');
            $number = Invoice::query()->whereKey($invoiceId)->value('number');
            $notification = new InvoiceEmailFailedNotification(
                number: is_string($number) ? $number : '',
                url: InvoiceResource::getUrl('view', ['record' => $invoiceId]),
            );

            foreach (User::query()->role(RoleName::Admin->value)->get() as $admin) {
                $admin->notifyNow($notification);
            }
        });
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
