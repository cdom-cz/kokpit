<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * The closed Dompdf engine every issued-document PDF runs through (IN-04, IN-08; D-21, D-23, D-24; ruling
 * R-15-03-1): the invoice PDF and the cash receipt PDF share it, so the hardening exists once.
 *
 * Dompdf runs closed: remote access, PHP and JavaScript are off, the chroot allows the PDF views and the bundled
 * fonts and nothing else, and every value reaches the template through Blade `{{ }}` escaping, so item text such as
 * `<b>x</b>` prints as text and can neither add markup nor name a file (T-10-40, T-15-42). The bundled Roboto fonts
 * are installed by Dompdf into `storage/framework/dompdf`, never into the vendor directory (T-10-41); the render that
 * finds them not installed runs under a cache lock so a request and a queue worker cannot race on
 * `installed-fonts.json` (ruling R-15-3). Roboto has no per-glyph fallback, so the default font stays DejaVu Sans.
 */
final class ClosedDompdf
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
     * The PDF bytes of a standalone HTML page, starting `%PDF-`, on A4 portrait.
     *
     * The page script runs after the layout, when the page count is known, so a page decoration (header, footer,
     * "page n of m") can be drawn on every page.
     *
     * @param  (callable(int, int, mixed, mixed): mixed)|null  $pageScript
     */
    public function render(string $html, ?callable $pageScript = null): string
    {
        $this->raiseMemoryLimit();

        if ($this->fontsInstalled()) {
            return $this->pdf($html, $pageScript);
        }

        return Cache::lock(self::FONT_LOCK, self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, fn (): string => $this->pdf($html, $pageScript));
    }

    /**
     * The Dompdf options: the closed switches, the chroot and the font directory under storage. Public so a test
     * pins the values.
     */
    public function options(): Options
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

    /**
     * The `file://` URL of a bundled font, which the template names in its `@font-face` rule.
     */
    public function fontUrl(string $style): string
    {
        $path = realpath(resource_path('fonts/'.self::FONTS[$style]));

        if ($path === false) {
            throw new RuntimeException('The bundled font '.self::FONTS[$style].' is missing.');
        }

        return 'file://'.$path;
    }

    private function pdf(string $html, ?callable $pageScript): string
    {
        $dompdf = new Dompdf($this->options());
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        // The page count is known only after the layout; a decoration is a page script that receives it.
        if ($pageScript !== null) {
            $dompdf->getCanvas()->page_script($pageScript);
        }

        return (string) $dompdf->output();
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

            if (! is_string($file)) {
                return false;
            }

            // Dompdf writes an entry either as an absolute path or relative to the font directory.
            $base = str_starts_with($file, '/') ? $file : $this->fontDir().'/'.$file;

            if (! is_file($base.'.ttf') || ! is_file($base.'.ufm')) {
                return false;
            }
        }

        return true;
    }

    private function raiseMemoryLimit(): void
    {
        $bytes = (int) ini_parse_quantity((string) ini_get('memory_limit'));

        if ($bytes !== -1 && $bytes < ini_parse_quantity(self::MEMORY_FLOOR)) {
            ini_set('memory_limit', self::MEMORY_FLOOR);
        }
    }
}
