<!DOCTYPE html>
<html lang="{{ $language->value }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('work_report.title') }}</title>
    <style>
        @page { size: A4 portrait; margin: 72pt 48pt 56pt 48pt; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10pt; font-weight: 400; color: #18181b; margin: 0; }
        h1 { font-size: 18pt; font-weight: 700; margin: 0 0 16pt 0; }
        h2 { font-size: 12pt; font-weight: 700; margin: 0 0 8pt 0; }
        .block { margin-bottom: 16pt; }
        .parties { width: 100%; border-collapse: collapse; }
        .parties td { width: 50%; vertical-align: top; padding: 0 8pt 0 0; font-size: 10pt; }
        .label { color: #52525b; font-size: 10pt; margin-bottom: 4pt; }
        .name { font-weight: 700; }
        .period { margin-top: 16pt; }
        table.entries { width: 100%; border-collapse: collapse; }
        table.entries th { text-align: left; font-weight: 700; background-color: #f4f4f5; border-bottom: 1pt solid #d4d4d8; padding: 4pt 8pt; }
        table.entries td { border-bottom: 1pt solid #d4d4d8; padding: 4pt 8pt; vertical-align: top; }
        table.entries thead { display: table-header-group; }
        table.entries tr { page-break-inside: avoid; }
        .hours { text-align: right; width: 20%; }
        .date { width: 22%; }
        .total td { font-weight: 700; background-color: #f4f4f5; }
        .empty { color: #52525b; }
    </style>
</head>
<body>
    <h1>{{ __('work_report.title') }}</h1>

    <table class="parties block">
        <tr>
            <td>
                <div class="label">{{ __('work_report.supplier') }}</div>
                @foreach ($supplierLines as $line)
                    <div @class(['name' => $loop->first])>{{ $line }}</div>
                @endforeach
            </td>
            <td>
                <div class="label">{{ __('work_report.customer') }}</div>
                <div class="name">{{ $clientName }}</div>
                @foreach ($clientLines as $line)
                    <div>{{ $line }}</div>
                @endforeach
            </td>
        </tr>
    </table>

    <div class="block">
        <div>{{ __('work_report.period', ['from' => $from, 'to' => $to]) }}</div>
        @if ($projectName !== null)
            <div>{{ __('work_report.project', ['name' => $projectName]) }}</div>
        @endif
    </div>

    @if ($billable !== [])
        <h2>{{ __('work_report.billable') }}</h2>
        <table class="entries block">
            <thead>
                <tr>
                    <th class="date">{{ __('work_report.date') }}</th>
                    <th>{{ __('work_report.task') }}</th>
                    <th class="hours">{{ __('work_report.hours') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($billable as $row)
                    <tr>
                        <td class="date">{{ $row['date'] }}</td>
                        <td>{{ $row['task'] ?? __('work_report.no_task') }}</td>
                        <td class="hours">{{ $row['hours'] }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td colspan="2">{{ __('work_report.total_billable') }}</td>
                    <td class="hours">{{ $billableTotal }}</td>
                </tr>
            </tbody>
        </table>
    @elseif ($empty)
        <table class="entries block">
            <tbody>
                <tr>
                    <td class="empty">{{ __('work_report.empty') }}</td>
                </tr>
            </tbody>
        </table>
    @endif
</body>
</html>
