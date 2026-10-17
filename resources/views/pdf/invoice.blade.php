@php
    /** @var \App\Domain\Invoicing\Pdf\InvoicePdfData $data */
    $palette = $data->palette;
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
        .muted { color: {{ $palette->muted }}; }
        table.header { width: 100%; border-bottom: 1.5pt solid {{ $palette->ink }}; }
        table.header td { vertical-align: top; padding: 0 0 16pt 0; }
        table.header td.title { text-align: right; }
        .heading { font-size: 8pt; font-weight: bold; letter-spacing: 2.5pt; color: {{ $palette->accentText }}; line-height: 1.4; }
        .number { font-size: 22pt; font-weight: bold; line-height: 1.2; }
        table.parties { width: 100%; margin-top: 24pt; }
        table.parties td { vertical-align: top; }
        table.parties td.party { width: 241pt; padding: 0 0 0 12pt; }
        table.parties td.supplier { border-left: 2.5pt solid {{ $palette->accent }}; }
        table.parties td.customer { border-left: 2.5pt solid {{ $palette->ink }}; }
        table.parties td.gap { width: 16pt; }
        .label { font-size: 7pt; letter-spacing: 1.2pt; color: {{ $palette->muted }}; line-height: 1.4; }
        .party-name { font-size: 12pt; font-weight: bold; line-height: 1.3; margin-bottom: 2pt; }
        table.ids td { padding: 0; font-size: 9pt; }
        table.ids td.key { width: 36pt; color: {{ $palette->muted }}; }
        .note { font-size: 8pt; line-height: 1.4; color: {{ $palette->muted }}; }
        .vat-note { font-size: 7pt; line-height: 1.4; color: {{ $palette->muted }}; margin-top: 4pt; }
        .proforma-note { margin-top: 16pt; font-weight: bold; }
        table.dates { width: 100%; margin-top: 24pt; border-top: 0.75pt solid {{ $palette->hairline }}; border-bottom: 0.75pt solid {{ $palette->hairline }}; }
        table.dates td { padding: 8pt 12pt; vertical-align: top; }
        table.dates td.next { border-left: 0.75pt solid {{ $palette->hairline }}; }
        .key { font-size: 7pt; color: {{ $palette->muted }}; line-height: 1.4; }
        table.items { width: 100%; margin-top: 24pt; }
        table.items thead { display: table-header-group; }
        table.items th { background-color: {{ $palette->ink }}; color: #ffffff; font-size: 8pt; font-weight: bold; text-align: right; padding: 6pt 8pt 6pt 0; }
        table.items th.index, table.items td.index { width: 32pt; text-align: center; }
        table.items th.description { text-align: left; }
        table.items th.quantity, table.items td.quantity { width: 60pt; }
        table.items th.unit-price, table.items td.unit-price { width: 84pt; }
        table.items th.total, table.items td.total { width: 88pt; }
        table.items th.vat-rate, table.items td.vat-rate { width: 40pt; }
        table.items tr { page-break-inside: avoid; }
        table.items td { padding: 8pt 8pt 8pt 0; vertical-align: top; text-align: right; }
        table.items td.description { text-align: left; padding-right: 8pt; word-wrap: break-word; overflow-wrap: break-word; }
        table.items tr.odd td { background-color: {{ $palette->rowTint }}; }
        table.summary { width: 100%; margin-top: 16pt; page-break-inside: avoid; }
        table.summary td.spacer { }
        table.summary td.bar { width: 192pt; background-color: {{ $palette->accent }}; color: {{ $palette->onAccent }}; padding: 12pt 16pt; }
        table.bar-inner { width: 100%; }
        table.bar-inner td { vertical-align: middle; padding: 0; color: {{ $palette->onAccent }}; }
        .bar-label { font-size: 8pt; font-weight: bold; letter-spacing: 1.2pt; }
        .bar-amount { font-size: 15pt; font-weight: bold; text-align: right; white-space: nowrap; }
        .printed-note { margin-top: 16pt; font-size: 8pt; line-height: 1.4; color: {{ $palette->muted }}; white-space: pre-line; }
        table.payment { width: 100%; margin-top: 24pt; background-color: {{ $palette->tint }}; border-top: 2pt solid {{ $palette->accent }}; page-break-inside: avoid; }
        table.payment td { vertical-align: top; padding: 16pt; }
        table.payment td.qr { width: 96pt; padding-right: 0; }
        .qr-box { width: 88pt; height: 88pt; padding: 4pt; background-color: #ffffff; }
        table.facts td { padding: 0; font-size: 8pt; line-height: 1.4; }
        table.facts td.key { width: 64pt; font-size: 8pt; }
        .sentence { margin-bottom: 8pt; }
        .credit-notice { margin-top: 24pt; }
        .footer { margin-top: 16pt; font-size: 8pt; line-height: 1.4; color: {{ $palette->muted }}; }
        .signature { margin-top: 16pt; text-align: right; }
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
                <div class="number">{{ $data->header['number'] }}</div>
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            @foreach ([$data->supplier, $data->customer] as $party)
                @if (! $loop->first)
                    <td class="gap"></td>
                @endif
                <td @class(['party', 'supplier' => $loop->first, 'customer' => ! $loop->first])>
                    <div class="label">{{ $party['label'] }}</div>
                    <div class="party-name">{{ $party['name'] }}</div>
                    @foreach ($party['lines'] as $line)
                        <div>{{ $line }}</div>
                    @endforeach
                    @if ($party['ids'] !== [])
                        <table class="ids">
                            @foreach ($party['ids'] as $id)
                                <tr>
                                    <td class="key">{{ $id['key'] }}</td>
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

    @if ($data->proformaNote !== null)
        <div class="proforma-note">{{ $data->proformaNote }}</div>
    @endif

    <table class="dates">
        <tr>
            @foreach ($data->dates as $cell)
                <td @class(['next' => ! $loop->first]) style="width: {{ round(100 / count($data->dates), 2) }}%;">
                    @if ($cell['label'] !== null)
                        <div class="key">{{ $cell['label'] }}</div>
                    @endif
                    <div @class(['bold' => $cell['strong']])>{{ $cell['value'] }}</div>
                </td>
            @endforeach
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th class="index"></th>
                <th class="description"></th>
                <th class="quantity">{{ $data->columns['quantity'] }}</th>
                <th class="unit-price">{{ $data->columns['unitPrice'] }}</th>
                @if ($data->columns['vatRate'] !== null)
                    <th class="vat-rate">{{ $data->columns['vatRate'] }}</th>
                @endif
                <th class="total">{{ $data->columns['total'] }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($data->items as $item)
                <tr @class(['odd' => $item['index'] % 2 === 1])>
                    <td class="index">{{ $item['index'] }}</td>
                    <td class="description">{{ $item['description'] }}</td>
                    <td class="quantity">{{ $item['quantity'] }}</td>
                    <td class="unit-price">{{ $item['unitPrice'] }}</td>
                    @if ($data->columns['vatRate'] !== null)
                        <td class="vat-rate">{{ $item['vatRate'] }}</td>
                    @endif
                    <td class="total bold">{{ $item['total'] }}</td>
                </tr>
            @endforeach
        </tbody>
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

    @if ($data->note !== null)
        <div class="printed-note">{{ $data->note }}</div>
    @endif

    @if ($data->payment !== null)
        <table class="payment">
            <tr>
                @if ($data->payment['qrDataUri'] !== null)
                    <td class="qr">
                        <div class="qr-box">
                            <img src="{{ $data->payment['qrDataUri'] }}" alt="" style="width: 88pt; height: 88pt;">
                        </div>
                    </td>
                @endif
                <td>
                    <div class="sentence">
                        @foreach ($data->payment['sentence'] as $part)
                            <span @class(['bold' => $part['bold']])>{{ $part['text'] }}</span>
                        @endforeach
                    </div>
                    <table class="facts">
                        @foreach ($data->payment['facts'] as $fact)
                            <tr>
                                <td class="key">{{ $fact['key'] }}</td>
                                <td>{{ $fact['value'] }}</td>
                            </tr>
                        @endforeach
                    </table>
                </td>
            </tr>
        </table>
    @endif

    @if ($data->creditNoteNotice !== null)
        <div class="credit-notice">{{ $data->creditNoteNotice }}</div>
    @endif

    @if ($data->footer !== [])
        <div class="footer">
            @foreach ($data->footer as $line)
                <div>{{ $line }}</div>
            @endforeach
        </div>
    @endif

    @if ($data->signature !== null)
        <div class="signature">
            <img src="{{ $data->signature['uri'] }}" alt="" style="width: {{ $data->signature['width'] }}pt; height: {{ $data->signature['height'] }}pt;">
        </div>
    @endif
</body>
</html>
