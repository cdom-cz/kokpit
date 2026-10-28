@php
    /** @var \App\Domain\Invoicing\Pdf\CashReceiptPdfData $data */
    $palette = $data->palette;
    // The key column of the company and VAT id rows: 36 pt for "IČO" and "DIČ", wider for "Company ID".
    $idKeyWidth = $data->language === \App\Domain\Clients\Enums\InvoiceLanguage::Czech ? 36 : 58;
@endphp
<!DOCTYPE html>
<html lang="{{ $data->language->value }}">
<head>
    <meta charset="utf-8">
    <title>{{ $data->title }}</title>
    <style>
        @font-face { font-family: 'Roboto'; font-style: normal; font-weight: normal; src: url('{{ $fontRegular }}') format('truetype'); }
        @font-face { font-family: 'Roboto'; font-style: normal; font-weight: bold; src: url('{{ $fontBold }}') format('truetype'); }
        @page { size: A4 portrait; margin: 35pt 36pt 56pt 36pt; }
        body { font-family: 'Roboto', 'DejaVu Sans', sans-serif; font-size: 9pt; font-weight: normal; line-height: 1.5; color: {{ $palette->ink }}; margin: 0; }
        table { border-collapse: collapse; }
        .bold { font-weight: bold; }
        table.header { width: 100%; border-bottom: 1.5pt solid {{ $palette->ink }}; }
        table.header td { vertical-align: top; padding: 0 0 16pt 0; }
        table.header td.title { text-align: right; }
        .heading { font-size: 14pt; font-weight: bold; color: {{ $palette->accentText }}; line-height: 1.3; }
        .number-label { font-size: 7pt; letter-spacing: 1.2pt; color: {{ $palette->muted }}; line-height: 1.4; margin-top: 4pt; }
        .number { font-size: 22pt; font-weight: bold; line-height: 1.2; }
        table.cancelled { width: 100%; margin-top: 16pt; }
        table.cancelled td { background-color: #b91c1c; color: #ffffff; text-align: center; font-size: 12pt; font-weight: bold; letter-spacing: 1.2pt; padding: 8pt 12pt; }
        table.parties { width: 100%; margin-top: 24pt; }
        table.parties td { vertical-align: top; }
        table.parties td.party { width: 241pt; padding: 0 0 0 12pt; }
        table.parties td.received-by { border-left: 2.5pt solid {{ $palette->accent }}; }
        table.parties td.received-from { border-left: 2.5pt solid {{ $palette->ink }}; }
        table.parties td.gap { width: 16pt; }
        .label { font-size: 7pt; letter-spacing: 1.2pt; color: {{ $palette->muted }}; line-height: 1.4; }
        .party-name { font-size: 12pt; font-weight: bold; line-height: 1.3; margin-bottom: 2pt; }
        table.ids td { padding: 0; font-size: 9pt; }
        table.ids td.key { padding-right: 6pt; color: {{ $palette->muted }}; }
        .note { font-size: 8pt; line-height: 1.4; color: {{ $palette->muted }}; }
        .vat-note { font-size: 7pt; line-height: 1.4; color: {{ $palette->muted }}; margin-top: 4pt; }
        table.dates { width: 100%; margin-top: 24pt; border-top: 0.75pt solid {{ $palette->hairline }}; border-bottom: 0.75pt solid {{ $palette->hairline }}; }
        table.dates td { padding: 8pt 12pt; vertical-align: top; }
        table.dates td.next { border-left: 0.75pt solid {{ $palette->hairline }}; }
        .key { font-size: 7pt; color: {{ $palette->muted }}; line-height: 1.4; }
        table.summary { width: 100%; margin-top: 24pt; page-break-inside: avoid; }
        table.summary td.spacer { }
        table.summary td.bar { width: 260pt; background-color: {{ $palette->accent }}; color: {{ $palette->onAccent }}; padding: 12pt 16pt; }
        table.bar-inner { width: 100%; }
        table.bar-inner td { vertical-align: middle; padding: 0; color: {{ $palette->onAccent }}; }
        .bar-label { font-size: 8pt; font-weight: bold; letter-spacing: 1.2pt; }
        .bar-amount { font-size: 15pt; font-weight: bold; text-align: right; white-space: nowrap; }
        table.facts { width: 100%; margin-top: 24pt; }
        table.facts td { padding: 0 0 8pt 0; vertical-align: top; }
        table.facts td.fact-label { width: 110pt; font-size: 8pt; color: {{ $palette->muted }}; }
        .purpose { white-space: pre-line; word-wrap: break-word; overflow-wrap: break-word; }
        table.closing { width: 100%; margin-top: 24pt; page-break-inside: avoid; }
        table.closing td { padding: 0; vertical-align: bottom; }
        table.closing td.signature { text-align: right; }
        .issuer { font-size: 8pt; line-height: 1.4; color: {{ $palette->muted }}; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td>
                @if ($data->logo !== null)
                    <img src="{{ $data->logo['uri'] }}" alt="" style="width: {{ $data->logo['width'] }}pt; height: {{ $data->logo['height'] }}pt;">
                @endif
            </td>
            <td class="title">
                <div class="heading">{{ $data->header['heading'] }}</div>
                <div class="number-label">{{ $data->header['numberLabel'] }}</div>
                <div class="number">{{ $data->header['number'] }}</div>
            </td>
        </tr>
    </table>

    @if ($data->cancelled !== null)
        <table class="cancelled">
            <tr>
                <td>{{ $data->cancelled }}</td>
            </tr>
        </table>
    @endif

    <table class="parties">
        <tr>
            @foreach ([$data->receivedFrom, $data->receivedBy] as $party)
                @if (! $loop->first)
                    <td class="gap"></td>
                @endif
                <td @class(['party', 'received-from' => $loop->first, 'received-by' => ! $loop->first])>
                    <div class="label">{{ $party['label'] }}</div>
                    <div class="party-name">{{ $party['name'] }}</div>
                    @foreach ($party['lines'] as $line)
                        <div>{{ $line }}</div>
                    @endforeach
                    @if ($party['ids'] !== [])
                        <table class="ids">
                            @foreach ($party['ids'] as $id)
                                <tr>
                                    <td class="key" style="width: {{ $idKeyWidth }}pt;">{{ $id['key'] }}</td>
                                    <td>{{ $id['value'] }}</td>
                                </tr>
                            @endforeach
                        </table>
                    @endif
                    @foreach ($party['notes'] as $note)
                        <div class="note">{{ $note }}</div>
                    @endforeach
                    @if ($party['vatNote'] !== null)
                        <div class="vat-note">{{ $party['vatNote'] }}</div>
                    @endif
                </td>
            @endforeach
        </tr>
    </table>

    <table class="dates">
        <tr>
            @foreach ($data->dates as $cell)
                <td @class(['next' => ! $loop->first]) style="width: {{ round(100 / count($data->dates), 2) }}%;">
                    <div class="key">{{ $cell['label'] }}</div>
                    <div class="bold">{{ $cell['value'] }}</div>
                </td>
            @endforeach
        </tr>
    </table>

    <table class="summary">
        <tr>
            <td class="spacer"></td>
            <td class="bar">
                <table class="bar-inner">
                    <tr>
                        <td class="bar-label">{{ $data->summary['label'] }}</td>
                        <td class="bar-amount">{{ $data->summary['amount'] }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="facts">
        <tr>
            <td class="fact-label">{{ $data->purpose['label'] }}</td>
            <td class="purpose">{{ $data->purpose['value'] }}</td>
        </tr>
        <tr>
            <td class="fact-label">{{ $data->document['label'] }}</td>
            <td class="bold">{{ $data->document['value'] }}</td>
        </tr>
    </table>

    <table class="closing">
        <tr>
            <td>
                <div class="issuer">{{ $data->issuer }}</div>
            </td>
            <td class="signature">
                @if ($data->signature !== null)
                    <img src="{{ $data->signature['uri'] }}" alt="" style="width: {{ $data->signature['width'] }}pt; height: {{ $data->signature['height'] }}pt;">
                @endif
            </td>
        </tr>
    </table>
</body>
</html>
