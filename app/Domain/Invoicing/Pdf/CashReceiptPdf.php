<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Pdf;

use App\Domain\Invoicing\Models\CashReceipt;
use LogicException;
use UnexpectedValueException;

/**
 * Turns a cash receipt into a PDF (Phase 15; D-11, ruling R-15-09-2): Blade HTML through the closed
 * {@see ClosedDompdf} engine the invoice PDF runs on, with the same page decoration.
 *
 * The content is the receipt row only ({@see CashReceiptPdfData}); every value reaches the template through Blade
 * `{{ }}` escaping, so a purpose such as `<b>x</b>` prints as text and can neither add markup nor name a file (T-15-19).
 */
final class CashReceiptPdf
{
    public function __construct(private readonly ClosedDompdf $engine) {}

    /**
     * The PDF bytes of the receipt, starting `%PDF-`.
     *
     * @throws LogicException when the receipt lacks a snapshot
     * @throws UnexpectedValueException when a snapshotted logo or signature object is missing or changed
     */
    public function render(CashReceipt $receipt): string
    {
        $data = CashReceiptPdfData::fromReceipt($receipt);

        return $this->engine->render($this->renderHtml($data), new InvoicePageDecoration($data->palette, $data->pageTemplate));
    }

    /**
     * The standalone HTML of the receipt, exactly what Dompdf is given.
     *
     * @throws LogicException when the receipt lacks a snapshot
     * @throws UnexpectedValueException when a snapshotted logo or signature object is missing or changed
     */
    public function html(CashReceipt $receipt): string
    {
        return $this->renderHtml(CashReceiptPdfData::fromReceipt($receipt));
    }

    /**
     * The generated file name: `prijmovy-doklad-:number.pdf` in Czech, `cash-receipt-:number.pdf` in English. Only the
     * number, reduced to safe characters, reaches the name.
     */
    public function fileName(CashReceipt $receipt): string
    {
        $number = preg_replace('/[^A-Za-z0-9._-]+/', '-', $receipt->number) ?? '';

        return trans('cash_receipt_pdf.file_name', ['number' => trim($number, '-')], $receipt->language->value);
    }

    private function renderHtml(CashReceiptPdfData $data): string
    {
        return view('pdf.cash-receipt', [
            'data' => $data,
            'fontRegular' => $this->engine->fontUrl('normal'),
            'fontBold' => $this->engine->fontUrl('bold'),
        ])->render();
    }
}
