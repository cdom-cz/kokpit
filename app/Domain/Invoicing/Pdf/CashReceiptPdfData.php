<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Pdf;

use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Invoicing\Models\CashReceipt;
use App\Domain\Settings\DocumentImage;
use Carbon\CarbonInterface;
use Filament\Support\Facades\FilamentTimezone;
use LogicException;
use UnexpectedValueException;

/**
 * Everything the cash receipt template prints, read from the receipt row and nothing else (D-11, ruling R-15-09-1):
 * its columns (number, dates, amount, currency, purpose, language, the frozen number of the paid document) and its
 * three frozen snapshots. It never reads the `invoices` row, the client, the supplier settings or any other table, so a
 * later change of any of them cannot alter a regenerated PDF.
 *
 * The content is that of an accounting document (Act 563/1991 Coll., Sec. 11): the title and number, the issue date
 * and the date the cash was received, who paid and who received it, the amount with its currency, the purpose and the
 * paid document, and the issuer line with the supplier's signature image as the signature record. It carries no VAT
 * breakdown, variable symbol, QR code, bank data, pay button, payer signature box or amount in words (D-02 spirit, AS-5,
 * AS-6). A cancelled receipt keeps all of it and gains the cancelled date for the band (D-08).
 *
 * The logo and the signature come from {@see DocumentImage::dataUri()} with the path and SHA-256 of the appearance
 * snapshot, which throws when the stored object is missing or changed, so a regeneration is either equivalent to the
 * issued document or fails. The receipt prints neither the supplier's e-mail, phone and website nor the document footer
 * of the appearance snapshot, though both stay in the snapshots (user decision 2026-10-28). All words are resolved here in the language of the receipt and Blade escapes each value.
 */
final readonly class CashReceiptPdfData
{
    private const int LOGO_MAX_WIDTH = 120;

    private const int LOGO_MAX_HEIGHT = 30;

    private const int SIGNATURE_MAX_WIDTH = 200;

    private const int SIGNATURE_MAX_HEIGHT = 80;

    /**
     * @param  array{heading: string, numberLabel: string, number: string}  $header
     * @param  array{label: string, name: string, lines: list<string>, ids: list<array{key: string, value: string}>, notes: list<string>, vatNote: string|null}  $receivedFrom
     * @param  array{label: string, name: string, lines: list<string>, ids: list<array{key: string, value: string}>, notes: list<string>, vatNote: string|null}  $receivedBy
     * @param  list<array{label: string, value: string}>  $dates
     * @param  array{label: string, amount: string}  $summary
     * @param  array{label: string, value: string}  $purpose
     * @param  array{label: string, value: string}  $document
     * @param  array{uri: string, width: float, height: float}|null  $logo
     * @param  array{uri: string, width: float, height: float}|null  $signature
     */
    private function __construct(
        public InvoiceLanguage $language,
        public array $header,
        public DocumentPalette $palette,
        public ?array $logo,
        public array $receivedFrom,
        public array $receivedBy,
        public array $dates,
        public array $summary,
        public array $purpose,
        public array $document,
        public string $documentNumber,
        public string $issuer,
        public ?array $signature,
        public ?string $cancelled,
        public string $pageTemplate,
        public string $title,
    ) {}

    /**
     * @throws LogicException when the receipt lacks a snapshot
     * @throws UnexpectedValueException when a snapshotted image is missing or no longer matches its hash
     */
    public static function fromReceipt(CashReceipt $receipt): self
    {
        $language = $receipt->language;
        $locale = $language->value;
        $supplier = self::snapshot($receipt->supplier_snapshot, 'supplier');
        $customer = self::snapshot($receipt->customer_snapshot, 'customer');
        $appearance = self::snapshot($receipt->appearance_snapshot, 'appearance');

        $t = static fn (string $key, array $replace = []): string => trans('cash_receipt_pdf.'.$key, $replace, $locale);
        $date = static fn (CarbonInterface $day): string => $day->format($language === InvoiceLanguage::Czech ? 'j. n. Y' : 'Y-m-d');

        $images = app(DocumentImage::class);
        $supplierName = PdfParts::text($supplier['name'] ?? null) ?? '';
        $title = $t('title');

        return new self(
            language: $language,
            header: ['heading' => $title, 'numberLabel' => $t('number'), 'number' => $receipt->number],
            palette: DocumentPalette::fromAccent(PdfParts::text($appearance['accent'] ?? null)),
            logo: PdfParts::image($images, $appearance['logo'] ?? null, self::LOGO_MAX_WIDTH, self::LOGO_MAX_HEIGHT),
            receivedFrom: PdfParts::party($t('received_from'), $customer, $t, null),
            receivedBy: PdfParts::party($t('received_by'), $supplier, $t, $t('not_vat_payer')),
            dates: [
                ['label' => $t('issued_on'), 'value' => $date($receipt->issued_on)],
                ['label' => $t('received_on'), 'value' => $date($receipt->received_on)],
            ],
            summary: ['label' => $t('amount'), 'amount' => $receipt->amount()->format($locale)],
            purpose: ['label' => $t('purpose_label'), 'value' => $receipt->purpose],
            document: ['label' => $t('document'), 'value' => $receipt->document_number],
            documentNumber: $receipt->document_number,
            issuer: $t('issuer', ['name' => $supplierName]),
            signature: PdfParts::image($images, $appearance['signature'] ?? null, self::SIGNATURE_MAX_WIDTH, self::SIGNATURE_MAX_HEIGHT),
            cancelled: $receipt->cancelled_at === null
                ? null
                : $t('cancelled', ['date' => $date($receipt->cancelled_at->setTimezone(FilamentTimezone::get()))]),
            pageTemplate: $t('page', ['n' => '{n}', 'total' => '{total}']),
            title: $title.' '.$receipt->number,
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     *
     * @throws LogicException for a snapshot that holds nothing
     */
    private static function snapshot(array $snapshot, string $name): array
    {
        return $snapshot !== [] ? $snapshot : throw new LogicException('A cash receipt has a '.$name.' snapshot.');
    }
}
