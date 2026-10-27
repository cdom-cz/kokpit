<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Pdf;

use App\Domain\Invoicing\Models\Invoice;
use LogicException;

/**
 * Turns an issued document into a PDF (IN-04, IN-08; D-21, D-23, D-24): Blade HTML through Dompdf, called
 * directly, as the work report does.
 *
 * The content is the frozen snapshot only ({@see InvoicePdfData}, ruling R-15-4): number, dates, parties, bank,
 * lines, totals, language and the accent, footer, logo and signature of the appearance snapshot. A regeneration
 * after the client, the settings or the rates changed prints the same document, or fails when a snapshotted image
 * object is gone or altered.
 *
 * Dompdf runs closed through {@see ClosedDompdf} (ruling R-15-03-1): remote access, PHP and JavaScript are off, the
 * chroot allows the PDF views and the bundled fonts and nothing else, and every value reaches the template through
 * Blade `{{ }}` escaping, so item text such as `<b>x</b>` prints as text and can neither add markup nor name a file
 * (T-10-40). The engine installs the bundled fonts into `storage/framework/dompdf` under a cache lock (T-10-41,
 * ruling R-15-3); this class keeps the invoice's own page decoration.
 */
final class InvoicePdf
{
    /** The lock a render takes while the fonts are not installed yet. */
    public const string FONT_LOCK = ClosedDompdf::FONT_LOCK;

    public function __construct(private readonly ClosedDompdf $engine) {}

    /**
     * The PDF bytes of the issued document, starting `%PDF-`.
     *
     * @throws LogicException for a document that is not issued
     * @throws \UnexpectedValueException when a snapshotted logo or signature object is missing or changed
     */
    public function render(Invoice $issued): string
    {
        $data = InvoicePdfData::fromInvoice($issued);

        return $this->engine->render($this->renderHtml($data), new InvoicePageDecoration($data->palette, $data->pageTemplate));
    }

    /**
     * The standalone HTML of the document, exactly what Dompdf is given.
     *
     * @throws LogicException for a document that is not issued
     * @throws \UnexpectedValueException when a snapshotted logo or signature object is missing or changed
     */
    public function html(Invoice $issued): string
    {
        return $this->renderHtml(InvoicePdfData::fromInvoice($issued));
    }

    /**
     * The generated file name of the document: `faktura-:number.pdf`, `zalohova-faktura-:number.pdf`,
     * `dobropis-:number.pdf` in Czech and the English counterparts. Only the number, reduced to safe characters,
     * reaches the name.
     */
    public function fileName(Invoice $issued): string
    {
        $number = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $issued->number) ?? '';

        return trans('invoice_pdf.file_name.'.$issued->kind->value, ['number' => trim($number, '-')], $issued->language->value);
    }

    private function renderHtml(InvoicePdfData $data): string
    {
        return view('pdf.invoice', [
            'data' => $data,
            'fontRegular' => $this->engine->fontUrl('normal'),
            'fontBold' => $this->engine->fontUrl('bold'),
        ])->render();
    }
}
