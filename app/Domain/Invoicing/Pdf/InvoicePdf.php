<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Pdf;

use App\Domain\Invoicing\Models\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use LogicException;
use RuntimeException;

/**
 * Turns an issued document into a PDF (IN-04, IN-08; D-21, D-23, D-24): Blade HTML through Dompdf, called
 * directly, as the work report does.
 *
 * The content is the frozen snapshot only ({@see InvoicePdfData}, ruling R-15-4): number, dates, parties, bank,
 * lines, totals, language and the accent, footer, logo and signature of the appearance snapshot. A regeneration
 * after the client, the settings or the rates changed prints the same document, or fails when a snapshotted image
 * object is gone or altered.
 *
 * Dompdf runs closed: remote access, PHP and JavaScript are off, the chroot allows the PDF views and the bundled
 * fonts and nothing else, and every value reaches the template through Blade `{{ }}` escaping, so item text such as
 * `<b>x</b>` prints as text and can neither add markup nor name a file (T-10-40). The bundled Roboto fonts are
 * installed by Dompdf into `storage/framework/dompdf`, never into the vendor directory (T-10-41); the render that
 * finds them not installed runs under a cache lock so a request and a queue worker cannot race on
 * `installed-fonts.json` (ruling R-15-3). Roboto has no per-glyph fallback, so the default font stays DejaVu Sans.
 */
final class InvoicePdf
{
    /** The lock a render takes while the fonts are not installed yet. */
    public const string FONT_LOCK = 'kokpit:dompdf-fonts';

    /** Seconds the lock is held at most, and seconds a render waits for it. */
    private const int LOCK_SECONDS = 120;

    private const int LOCK_WAIT_SECONDS = 60;

    /** Memory the renderer is given at least. */
    private const string MEMORY_FLOOR = '256M';

    /** The bundled static fonts, by style, in `resources/fonts`. */
    private const array FONTS = ['normal' => 'Roboto-Regular.ttf', 'bold' => 'Roboto-Bold.ttf'];

    /**
     * The PDF bytes of the issued document, starting `%PDF-`.
     *
     * @throws LogicException for a document that is not issued
     * @throws \UnexpectedValueException when a snapshotted logo or signature object is missing or changed
     */
    public function render(Invoice $issued): string
    {
        $data = InvoicePdfData::fromInvoice($issued);
        $html = $this->renderHtml($data);

        $this->raiseMemoryLimit();

        if ($this->fontsInstalled()) {
            return $this->pdf($html, $data);
        }

        return Cache::lock(self::FONT_LOCK, self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, fn (): string => $this->pdf($html, $data));
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
            'fontRegular' => $this->fontUrl('normal'),
            'fontBold' => $this->fontUrl('bold'),
        ])->render();
    }

    private function pdf(string $html, InvoicePdfData $data): string
    {
        $dompdf = new Dompdf($this->options());
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        // The page count is known only after the layout; the decoration is a page script that receives it.
        $dompdf->getCanvas()->page_script(new InvoicePageDecoration($data->palette, $data->pageTemplate));

        return (string) $dompdf->output();
    }

    private function options(): Options
    {
        $fontDir = $this->fontDir();
        File::ensureDirectoryExists($fontDir);

        return new Options([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
            'chroot' => [resource_path('views/pdf'), resource_path('fonts')],
            'fontDir' => $fontDir,
            'fontCache' => $fontDir,
            'tempDir' => $fontDir,
        ]);
    }

    private function fontDir(): string
    {
        return storage_path('framework/dompdf');
    }

    /**
     * Whether both Roboto styles are installed: the index names them and their files exist. A half-written or
     * missing index reads as not installed.
     */
    private function fontsInstalled(): bool
    {
        $index = $this->fontDir().'/installed-fonts.json';

        if (! is_file($index)) {
            return false;
        }

        $families = json_decode((string) file_get_contents($index), true);
        $roboto = is_array($families) ? ($families['roboto'] ?? null) : null;

        if (! is_array($roboto)) {
            return false;
        }

        foreach (array_keys(self::FONTS) as $style) {
            $file = $roboto[$style] ?? null;

            if (! is_string($file) || ! is_file($file.'.ttf') || ! is_file($file.'.ufm')) {
                return false;
            }
        }

        return true;
    }

    /**
     * The `file://` URL of a bundled font, which the template names in its `@font-face` rule.
     */
    private function fontUrl(string $style): string
    {
        $path = realpath(resource_path('fonts/'.self::FONTS[$style]));

        if ($path === false) {
            throw new RuntimeException('The bundled font '.self::FONTS[$style].' is missing.');
        }

        return 'file://'.$path;
    }

    private function raiseMemoryLimit(): void
    {
        $bytes = (int) ini_parse_quantity((string) ini_get('memory_limit'));

        if ($bytes !== -1 && $bytes < ini_parse_quantity(self::MEMORY_FLOOR)) {
            ini_set('memory_limit', self::MEMORY_FLOOR);
        }
    }
}
