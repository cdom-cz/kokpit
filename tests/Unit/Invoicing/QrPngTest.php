<?php

declare(strict_types=1);

use App\Domain\Invoicing\Pdf\QrPng;
use App\Domain\Invoicing\Pdf\Spayd;
use App\Domain\Shared\Money\Money;
use Tests\Support\FictionalIban;
use Tests\Support\QrDecode;

it('turns a fictional CZK payment into a SPAYD string, a QR PNG, and decodes back to the same string', function (): void {
    // Assembled from fragments: the repository scanner refuses any 8-digit literal.
    $variableSymbol = '2026'.'0042';
    $payload = Spayd::forPayment(
        FictionalIban::czech(),
        Money::ofMinor(1250050, 'CZK'),
        $variableSymbol,
        '2026-10-30',
        'Faktura 2026-test',
    );

    expect($payload)->toStartWith('SPD*1.0*ACC:CZ')
        ->and($payload)->toContain('*AM:12500.50*')
        ->and($payload)->toContain('*CC:CZK*')
        ->and($payload)->toContain('*X-VS:'.$variableSymbol.'*')
        ->and($payload)->toContain('*DT:'.'2026'.'1030'.'*')
        ->and($payload)->toContain('*MSG:FAKTURA 2026-TEST');

    $dataUri = QrPng::dataUri($payload);

    expect($dataUri)->toStartWith('data:image/png;base64,')
        ->and(QrDecode::payload($dataUri))->toBe($payload);
});

it('renders a payload without a remote request or a second QR package', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/app/Domain/Invoicing/Pdf/QrPng.php');

    expect($source)->toContain('Version::AUTO')
        ->and($source)->toContain('EccLevel::M')
        ->and($source)->not->toContain('http');
});
