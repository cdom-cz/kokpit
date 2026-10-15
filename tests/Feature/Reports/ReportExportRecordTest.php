<?php

declare(strict_types=1);

use App\Domain\Reports\Enums\ReportExportFormat;
use App\Domain\Reports\Enums\ReportExportStatus;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Canary;

/*
 * The record behind every queued report export (RE-03, D-09): owned by the Admin who made it, invisible to
 * a second Admin and to every Partner. Names, ids and paths are invented.
 */

/**
 * The filters of an invented export: validated ids and Prague days only.
 *
 * @return array<string, string|null>
 */
function reportExportFilters(): array
{
    return [
        'client' => '0190b3a0-0000-7000-8000-000000000001',
        'project' => null,
        'from' => '2026-09-01',
        'to' => '2026-09-30',
    ];
}

/**
 * Creates an export for the signed-in user.
 *
 * @param  array<string, mixed>  $overrides
 */
function reportExportCreate(array $overrides = []): ReportExport
{
    return ReportExport::query()->create([
        'format' => ReportExportFormat::Csv,
        'summary' => 'Všichni klienti, všechny projekty, 1. 9. 2026 – 30. 9. 2026',
        'filters' => reportExportFilters(),
        ...$overrides,
    ]);
}

describe('creating (Admin)', function (): void {
    it('stores a pending export for the signed-in Admin that expires seven days after creation', function (): void {
        $admin = Canary::admin();
        $this->actingAs($admin);

        $before = CarbonImmutable::now();
        $export = reportExportCreate()->refresh();

        expect($export->user_id)->toBe($admin->getKey())
            ->and($export->status)->toBe(ReportExportStatus::Pending)
            ->and($export->format)->toBe(ReportExportFormat::Csv)
            // jsonb does not keep the key order, so the filters compare as a set of pairs.
            ->and($export->filters)->toEqual(reportExportFilters())
            ->and($export->path)->toBeNull()
            ->and($export->row_count)->toBeNull()
            ->and($export->completed_at)->toBeNull()
            ->and($export->expires_at->greaterThanOrEqualTo($before->addDays(7)->startOfSecond()))->toBeTrue()
            ->and($export->expires_at->lessThanOrEqualTo(CarbonImmutable::now()->addDays(7)))->toBeTrue();
    });

    it('refuses to create an export for nobody', function (): void {
        app(PartnerContext::class)->runAsSystem(static function (): void {
            reportExportCreate();
        });
    })->throws(LogicException::class);
});

describe('visibility', function (): void {
    it('shows a second Admin none of the exports of the first', function (): void {
        $first = Canary::admin();
        $second = Canary::admin();

        $this->actingAs($first);
        reportExportCreate();
        expect(ReportExport::query()->count())->toBe(1);

        $this->actingAs($second);
        expect(ReportExport::query()->count())->toBe(0);
    });

    it('shows a Partner with a client no export and denies every ability', function (): void {
        [$clientA] = Canary::twoClients();
        $this->actingAs(Canary::admin());
        $export = reportExportCreate();

        $partner = Canary::partnerFor($clientA);
        $this->actingAs($partner);

        expect(ReportExport::query()->count())->toBe(0);

        foreach (['viewAny', 'create'] as $ability) {
            expect(Gate::forUser($partner)->allows($ability, ReportExport::class))->toBeFalse($ability);
        }

        foreach (['view', 'update', 'delete'] as $ability) {
            expect(Gate::forUser($partner)->allows($ability, $export))->toBeFalse($ability);
        }
    });

    it('shows a Partner without a client nothing', function (): void {
        $this->actingAs(Canary::admin());
        reportExportCreate();

        $this->actingAs(Canary::partnerFor(null));

        expect(ReportExport::query()->count())->toBe(0);
    });

    it('grants the owner Admin the abilities', function (): void {
        $admin = Canary::admin();
        $this->actingAs($admin);
        $export = reportExportCreate();

        expect(Gate::forUser($admin)->allows('viewAny', ReportExport::class))->toBeTrue()
            ->and(Gate::forUser($admin)->allows('view', $export))->toBeTrue();
    });
});

describe('labels', function (): void {
    it('reads the Czech enum labels', function (): void {
        expect(ReportExportStatus::Pending->getLabel())->toBe('Připravuje se')
            ->and(ReportExportStatus::Ready->getLabel())->toBe('Hotovo')
            ->and(ReportExportStatus::Failed->getLabel())->toBe('Selhalo')
            ->and(ReportExportFormat::Csv->getLabel())->toBe('CSV')
            ->and(ReportExportFormat::Xlsx->getLabel())->toBe('XLSX')
            ->and(ReportExportStatus::Pending->getColor())->toBe('info')
            ->and(ReportExportStatus::Ready->getColor())->toBe('success')
            ->and(ReportExportStatus::Failed->getColor())->toBe('danger')
            ->and(ReportExportFormat::Xlsx->extension())->toBe('xlsx');
    });
});

describe('naming', function (): void {
    it('builds the storage path from the export id and the format only', function (): void {
        $this->actingAs(Canary::admin());
        $export = reportExportCreate(['format' => ReportExportFormat::Xlsx]);

        expect($export->storagePath())->toBe('report-exports/'.$export->getKey().'.xlsx')
            ->and(reportExportCreate()->storagePath())->toEndWith('.csv');
    });

    it('builds the download name from the stored period only, with no client or project name', function (): void {
        $this->actingAs(Canary::admin());
        $export = reportExportCreate(['summary' => 'Příklad Klient s.r.o., Projekt Alfa, 1. 9. 2026 – 30. 9. 2026']);

        expect($export->downloadFileName())->toBe('report-casu-2026-09-01_2026-09-30.csv')
            ->and($export->downloadFileName())->not->toContain('Klient')
            ->and($export->downloadFileName())->not->toContain('Alfa')
            ->and(reportExportCreate(['format' => ReportExportFormat::Xlsx])->downloadFileName())->toBe('report-casu-2026-09-01_2026-09-30.xlsx');
    });

    it('never lets a tampered period reach the download name', function (): void {
        $this->actingAs(Canary::admin());
        $export = reportExportCreate(['filters' => ['from' => '../../etc/passwd', 'to' => '2026-09-30']]);

        expect($export->downloadFileName())->toBe('report-casu.csv');

        $missing = reportExportCreate(['filters' => ['client' => null]]);

        expect($missing->downloadFileName())->toBe('report-casu.csv');
    });
});

describe('lifecycle', function (): void {
    it('marks an export ready with the path, the row count, the completion time and a fresh expiry', function (): void {
        $this->actingAs(Canary::admin());
        $export = reportExportCreate();
        $now = CarbonImmutable::parse('2026-10-01 10:00:00');

        $export->markReady($export->storagePath(), 12, $now);
        $fresh = ReportExport::query()->findOrFail($export->getKey());

        expect($fresh->status)->toBe(ReportExportStatus::Ready)
            ->and($fresh->path)->toBe($export->storagePath())
            ->and($fresh->row_count)->toBe(12)
            ->and($fresh->completed_at?->equalTo($now))->toBeTrue()
            ->and($fresh->expires_at->equalTo($now->addDays(7)))->toBeTrue();
    });

    it('marks an export failed and keeps the expiry of its creation', function (): void {
        $this->actingAs(Canary::admin());
        $export = reportExportCreate();
        $expires = ReportExport::query()->findOrFail($export->getKey())->expires_at;

        $export->markFailed();
        $fresh = ReportExport::query()->findOrFail($export->getKey());

        expect($fresh->status)->toBe(ReportExportStatus::Failed)
            ->and($fresh->path)->toBeNull()
            ->and($fresh->completed_at)->toBeNull()
            ->and($fresh->expires_at->equalTo($expires))->toBeTrue();
    });

    it('is downloadable only when ready, unexpired and with a path', function (): void {
        $this->actingAs(Canary::admin());
        $now = CarbonImmutable::parse('2026-10-01 10:00:00');

        $pending = reportExportCreate();
        $failed = reportExportCreate();
        $failed->markFailed();
        $ready = reportExportCreate();
        $ready->markReady($ready->storagePath(), 1, $now);
        $atExpiry = $ready->expires_at;

        expect($pending->isDownloadable($now))->toBeFalse()
            ->and($failed->isDownloadable($now))->toBeFalse()
            ->and($ready->isDownloadable($now))->toBeTrue()
            ->and($ready->isDownloadable($atExpiry->subSecond()))->toBeTrue()
            ->and($ready->isDownloadable($atExpiry))->toBeFalse()
            ->and($ready->isDownloadable($atExpiry->addDay()))->toBeFalse();
    });

    it('reads the retention from the configuration and never returns less than one day', function (): void {
        expect(ReportExport::ttlDays())->toBe(7);

        config(['kokpit.exports.ttl_days' => 3]);
        expect(ReportExport::ttlDays())->toBe(3);

        config(['kokpit.exports.ttl_days' => 0]);
        expect(ReportExport::ttlDays())->toBe(1);

        config(['kokpit.exports.ttl_days' => -5]);
        expect(ReportExport::ttlDays())->toBe(1);
    });

    it('uses the configured retention for a new record', function (): void {
        config(['kokpit.exports.ttl_days' => 2]);
        $this->actingAs(Canary::admin());

        $export = reportExportCreate()->refresh();

        expect($export->expires_at->lessThanOrEqualTo(CarbonImmutable::now()->addDays(2)))->toBeTrue()
            ->and($export->expires_at->greaterThan(CarbonImmutable::now()->addDay()))->toBeTrue();
    });
});

describe('the exports contract', function (): void {
    it('keeps the finished files seven days and writes the CSV the way Czech Excel reads it', function (): void {
        expect(config('kokpit.exports.ttl_days'))->toBe(7)
            ->and(config('kokpit.exports.csv.delimiter'))->toBe(';')
            ->and(config('kokpit.exports.csv.decimal_separator'))->toBe(',');
    });

    it('words the subheading with the retention from the configuration', function (): void {
        expect(__('kokpit.exports.subheading', ['days' => 7]))->toBe('Hotové exporty se uchovávají 7 dní, potom se smažou.');
    });

    it('words the bell without a client name, an amount or a path', function (): void {
        $period = '1. 9. 2026 – 30. 9. 2026';

        expect(__('kokpit.exports.bell.ready.title'))->toBe('Export je připraven')
            ->and(__('kokpit.exports.bell.ready.body', ['format' => 'CSV', 'period' => $period, 'date' => '7. 10. 2026']))
            ->toBe('CSV, 1. 9. 2026 – 30. 9. 2026. Soubor je ke stažení do 7. 10. 2026.')
            ->and(__('kokpit.exports.bell.failed.title'))->toBe('Export se nepodařil')
            ->and(__('kokpit.exports.bell.failed.body', ['period' => $period]))
            ->toBe('Export za 1. 9. 2026 – 30. 9. 2026 se nepodařilo vytvořit. Zkuste ho spustit znovu.')
            ->and(__('kokpit.exports.bell.action'))->toBe('Otevřít exporty');
    });

    it('words the list, the toast and the file texts verbatim from the UI contract', function (): void {
        expect(__('kokpit.exports.gone'))->toBe('Soubor už není k dispozici. Spusťte export znovu.')
            ->and(__('kokpit.exports.file.missing_rate_comment'))->toBe('Chybí kurz ČNB')
            ->and(__('kokpit.exports.navigation'))->toBe('Exporty')
            ->and(__('kokpit.exports.model_label'))->toBe('Export')
            ->and(__('kokpit.exports.plural_label'))->toBe('Exporty')
            ->and(__('kokpit.exports.actions.download'))->toBe('Stáhnout export')
            ->and(__('kokpit.exports.queued.title'))->toBe('Export se připravuje')
            ->and(__('kokpit.exports.queued.body'))->toBe('Až bude hotový, přijde upozornění. Soubor najdete v nabídce Exporty.')
            ->and(__('kokpit.exports.failure_reason'))->toBe('Export se nepodařilo vytvořit. Spusťte ho znovu v Reportu času.')
            ->and(__('kokpit.exports.empty.heading'))->toBe('Zatím tu nejsou žádné exporty')
            ->and(__('kokpit.exports.empty.description'))->toBe('Export spustíte v Reportu času tlačítkem Exportovat report.')
            ->and(__('kokpit.exports.summary.all_clients'))->toBe('Všichni klienti')
            ->and(__('kokpit.exports.summary.all_projects'))->toBe('Všechny projekty')
            ->and(__('kokpit.exports.file.yes'))->toBe('ano')
            ->and(__('kokpit.exports.file.no'))->toBe('ne')
            ->and(__('kokpit.exports.file.sheets.entries'))->toBe('Záznamy')
            ->and(__('kokpit.exports.file.sheets.summary'))->toBe('Souhrn');
    });

    it('names the list columns', function (): void {
        expect(array_values((array) trans('kokpit.exports.columns')))->toBe(['Vytvořeno', 'Formát', 'Zadání', 'Stav', 'Platí do'])
            ->and(array_keys((array) trans('kokpit.exports.columns')))->toBe(['created_at', 'format', 'summary', 'status', 'expires_at']);
    });

    it('names the twelve file columns in order', function (): void {
        expect(array_values((array) trans('kokpit.exports.file.columns')))->toBe([
            'Datum', 'Klient', 'Projekt', 'Úkol', 'Uživatel', 'Sekundy', 'Hodiny', 'Fakturovatelné', 'Vyfakturováno', 'Částka', 'Měna', 'Částka v Kč',
        ])->and(array_keys((array) trans('kokpit.exports.file.columns')))->toBe([
            'date', 'client', 'project', 'task', 'user', 'seconds', 'hours', 'billable', 'billed', 'amount', 'currency', 'amount_czk',
        ]);
    });

    it('names the summary sheet rows and the notes', function (): void {
        expect(array_values((array) trans('kokpit.exports.file.summary_rows')))->toBe([
            'Klient', 'Projekt', 'Období', 'Odpracováno', 'Fakturovatelné', 'Nefakturovatelné', 'Vyfakturováno', 'Nevyfakturováno', 'Částky po měnách', 'Částka v Kč', 'Poznámka',
        ])->and(__('kokpit.exports.file.notes.fixed_price'))->toBe('Hodiny projektů a úkolů s pevnou cenou jsou bez částky.')
            ->and(__('kokpit.exports.file.notes.missing_rate', ['count' => 3]))->toBe('Součet v Kč nezahrnuje 3 záznamů bez kurzu ČNB.');
    });

    it('has a real translation behind every key of the contract', function (string $key): void {
        expect(__($key))->not->toBe($key);
    })->with([
        'kokpit.exports.navigation', 'kokpit.exports.model_label', 'kokpit.exports.plural_label', 'kokpit.exports.subheading',
        'kokpit.exports.columns.created_at', 'kokpit.exports.columns.format', 'kokpit.exports.columns.summary', 'kokpit.exports.columns.status', 'kokpit.exports.columns.expires_at',
        'kokpit.exports.actions.download', 'kokpit.exports.queued.title', 'kokpit.exports.queued.body', 'kokpit.exports.gone', 'kokpit.exports.failure_reason',
        'kokpit.exports.empty.heading', 'kokpit.exports.empty.description',
        'kokpit.exports.bell.ready.title', 'kokpit.exports.bell.ready.body', 'kokpit.exports.bell.failed.title', 'kokpit.exports.bell.failed.body', 'kokpit.exports.bell.action',
        'kokpit.exports.summary.all_clients', 'kokpit.exports.summary.all_projects',
        'kokpit.exports.file.yes', 'kokpit.exports.file.no', 'kokpit.exports.file.sheets.entries', 'kokpit.exports.file.sheets.summary',
        'kokpit.exports.file.notes.fixed_price', 'kokpit.exports.file.notes.missing_rate', 'kokpit.exports.file.missing_rate_comment',
        'kokpit.exports.file.summary_rows.client', 'kokpit.exports.file.summary_rows.note', 'kokpit.exports.file.columns.amount_czk',
    ]);
});
