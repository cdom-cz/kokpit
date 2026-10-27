<?php

declare(strict_types=1);

use App\Domain\Invoicing\Pdf\ClosedDompdf;
use App\Domain\Invoicing\Pdf\InvoicePdf;

/*
 * The closed Dompdf engine shared by every PDF of the application (P15-08, ruling R-15-03-1; threat T-15-42):
 * the safety options are pinned here, so an extraction or a later edit cannot quietly open remote access, inline
 * PHP or JavaScript, or a wider chroot. Every text is fictional.
 */

describe('the closed engine', function (): void {
    it('runs Dompdf with remote access, PHP and JavaScript off', function (): void {
        $options = app(ClosedDompdf::class)->options();

        expect($options->getIsRemoteEnabled())->toBeFalse()
            ->and($options->getIsPhpEnabled())->toBeFalse()
            ->and($options->getIsJavascriptEnabled())->toBeFalse();
    });

    it('limits the chroot to the PDF views and the bundled fonts', function (): void {
        $options = app(ClosedDompdf::class)->options();

        expect($options->getChroot())->toBe([resource_path('views/pdf'), resource_path('fonts')]);
    });

    it('keeps the DejaVu Sans default font and the installed fonts under storage', function (): void {
        $options = app(ClosedDompdf::class)->options();

        expect($options->getDefaultFont())->toBe('DejaVu Sans')
            ->and($options->getFontDir())->toBe(storage_path('framework/dompdf'))
            ->and($options->getFontCache())->toBe(storage_path('framework/dompdf'))
            ->and($options->getTempDir())->toBe(storage_path('framework/dompdf'));
    });

    it('renders a minimal escaped page into PDF bytes', function (): void {
        $html = '<html><body><p>'.e('Example <b>text</b> & more').'</p></body></html>';

        expect(app(ClosedDompdf::class)->render($html))->toStartWith('%PDF-');
    });

    it('calls the page script once the layout is known', function (): void {
        $calls = 0;
        $script = function () use (&$calls): void {
            $calls++;
        };

        app(ClosedDompdf::class)->render('<html><body><p>Example page</p></body></html>', $script);

        expect($calls)->toBeGreaterThanOrEqual(1);
    });

    it('names the bundled fonts by file URL', function (): void {
        $engine = app(ClosedDompdf::class);

        expect($engine->fontUrl('normal'))->toStartWith('file://')->toEndWith('Roboto-Regular.ttf')
            ->and($engine->fontUrl('bold'))->toStartWith('file://')->toEndWith('Roboto-Bold.ttf');
    });

    it('shares one font lock between the invoice renderer and the engine', function (): void {
        expect(InvoicePdf::FONT_LOCK)->toBe(ClosedDompdf::FONT_LOCK);
    });
});
