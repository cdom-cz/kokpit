<?php

declare(strict_types=1);

use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Reports\WorkReport\WorkReportPdf;
use App\Domain\Reports\WorkReport\WorkReportSource;
use App\Domain\Settings\Settings\SupplierSettings;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\Tasks\Enums\TaskBillingType;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Support\Canary;
use Tests\Support\FictionalCompanyId;

/*
 * The work report of one client's period (RE-03; D-10): the money-free source and the Dompdf rendering.
 * Every name, id and address below is invented; the supplier uses the placeholder company ID 12345678.
 */

/**
 * Stores invented supplier settings.
 */
function reportSupplier(): void
{
    $supplier = app(SupplierSettings::class);
    $supplier->company_name = 'Příklad Dodavatel s.r.o.';
    $supplier->street = 'Vzorová 1';
    $supplier->city = 'Praha';
    $supplier->postal_code = '110 00';
    $supplier->country = 'CZ';
    $supplier->company_id = '12345678';
    $supplier->save();

    app()->forgetScopedInstances();
}

/**
 * A stored entry from UTC instants, for the user, task and project given.
 *
 * @param  array<string, mixed>  $attributes
 */
function reportEntry(Client $client, string $start, string $end, array $attributes = []): TimeEntry
{
    return TimeEntry::factory()->create([
        'user_id' => test()->admin->id,
        'client_id' => $client->id,
        'started_at' => CarbonImmutable::parse($start, 'UTC'),
        'ended_at' => CarbonImmutable::parse($end, 'UTC'),
        ...$attributes,
    ]);
}

beforeEach(function (): void {
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    reportSupplier();

    $this->clientCompanyId = FictionalCompanyId::valid();
    $this->client = Client::factory()->create([
        'name' => 'Ukázkový odběratel a.s.',
        'company_number' => $this->clientCompanyId,
        'street' => 'Příkladná 2',
        'city' => 'Brno',
        'postal_code' => '602 00',
        'country' => 'CZ',
        'hourly_rate' => Money::ofMinor(98765, 'CZK'),
    ]);
    $this->project = Project::factory()->create(['client_id' => $this->client->id, 'name' => 'Redesign žluťoučkého webu']);
    $this->taskOne = Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Příprava šablon: čeština, ěščřžýáíéů']);
    $this->taskTwo = Task::factory()->create(['project_id' => $this->project->id, 'title' => 'Ladění přihlášení']);

    $this->now = CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC');

    // Prague is UTC+2 in September. Wednesday 2 September: 08:00-09:30 and 10:00-10:45 on task one (2:15).
    reportEntry($this->client, '2026-09-02 06:00:00', '2026-09-02 07:30:00', ['project_id' => $this->project->id, 'task_id' => $this->taskOne->id]);
    reportEntry($this->client, '2026-09-02 08:00:00', '2026-09-02 08:45:00', ['project_id' => $this->project->id, 'task_id' => $this->taskOne->id]);
    // Thursday 3 September: 2:00 on task two.
    reportEntry($this->client, '2026-09-03 06:00:00', '2026-09-03 08:00:00', ['project_id' => $this->project->id, 'task_id' => $this->taskTwo->id]);
});

describe('the source', function (): void {
    it('groups the billable seconds by project, Prague day and task and leaves out other clients and other periods', function (): void {
        // 00:30 on 1 September in Prague is 22:30 on 31 August in UTC: it belongs to 1 September.
        reportEntry($this->client, '2026-08-31 22:30:00', '2026-08-31 23:00:00', ['project_id' => $this->project->id, 'task_id' => $this->taskTwo->id]);
        // 00:30 on 1 October in Prague is 22:30 on 30 September in UTC: it belongs to October, outside the period.
        reportEntry($this->client, '2026-09-30 22:30:00', '2026-09-30 23:30:00', ['project_id' => $this->project->id, 'task_id' => $this->taskTwo->id]);
        // Another client's entry in the same period.
        TimeEntry::factory()->create([
            'user_id' => $this->admin->id,
            'started_at' => CarbonImmutable::parse('2026-09-02 06:00:00', 'UTC'),
            'ended_at' => CarbonImmutable::parse('2026-09-02 07:00:00', 'UTC'),
        ]);

        $source = WorkReportSource::forPeriod($this->client->id, '2026-09-01', '2026-09-30', null, $this->now);

        expect($source->rows)->toBe([
            ['billable' => true, 'project' => 'Redesign žluťoučkého webu', 'date' => '2026-09-01', 'task' => $this->taskTwo->reference.' · Ladění přihlášení', 'seconds' => 1800],
            ['billable' => true, 'project' => 'Redesign žluťoučkého webu', 'date' => '2026-09-02', 'task' => $this->taskOne->reference.' · Příprava šablon: čeština, ěščřžýáíéů', 'seconds' => 8100],
            ['billable' => true, 'project' => 'Redesign žluťoučkého webu', 'date' => '2026-09-03', 'task' => $this->taskTwo->reference.' · Ladění přihlášení', 'seconds' => 7200],
        ])->and($source->clientName)->toBe('Ukázkový odběratel a.s.')
            ->and($source->clientLanguage)->toBe(InvoiceLanguage::Czech)
            ->and($source->from)->toBe('2026-09-01')
            ->and($source->to)->toBe('2026-09-30')
            ->and($source->projectName)->toBeNull();
    });

    it('reads the rows of a section, project and day in a stable order with the entries without a project last', function (): void {
        reportEntry($this->client, '2026-09-04 06:00:00', '2026-09-04 07:00:00', ['billable' => false]);
        reportEntry($this->client, '2026-09-04 08:00:00', '2026-09-04 08:30:00', ['project_id' => $this->project->id]);
        reportEntry($this->client, '2026-09-04 09:00:00', '2026-09-04 09:20:00');

        $rows = WorkReportSource::forPeriod($this->client->id, '2026-09-01', '2026-09-30', null, $this->now)->rows;

        expect(array_column($rows, 'billable'))->toBe([true, true, true, true, false])
            ->and($rows[2])->toBe(['billable' => true, 'project' => 'Redesign žluťoučkého webu', 'date' => '2026-09-04', 'task' => null, 'seconds' => 1800])
            ->and($rows[3])->toBe(['billable' => true, 'project' => null, 'date' => '2026-09-04', 'task' => null, 'seconds' => 1200])
            ->and($rows[4])->toBe(['billable' => false, 'project' => null, 'date' => '2026-09-04', 'task' => null, 'seconds' => 3600]);
    });

    it('limits the rows to one project and names it', function (): void {
        $other = Project::factory()->create(['client_id' => $this->client->id, 'name' => 'Jiný projekt']);
        reportEntry($this->client, '2026-09-04 06:00:00', '2026-09-04 07:00:00', ['project_id' => $other->id]);

        $source = WorkReportSource::forPeriod($this->client->id, '2026-09-01', '2026-09-30', $this->project->id, $this->now);

        expect($source->projectName)->toBe('Redesign žluťoučkého webu')
            ->and(array_unique(array_column($source->rows, 'project')))->toBe(['Redesign žluťoučkého webu']);
    });

    it('reads the supplier and the client lines with the company IDs', function (): void {
        $source = WorkReportSource::forPeriod($this->client->id, '2026-09-01', '2026-09-30', null, $this->now);

        expect($source->supplierLines)->toBe(['Příklad Dodavatel s.r.o.', 'Vzorová 1', '110 00 Praha', 'CZ', 'IČO: 12345678'])
            ->and($source->clientLines)->toBe(['Příkladná 2', '602 00 Brno', 'CZ', 'IČO: '.$this->clientCompanyId]);
    });

    it('reads an archived client', function (): void {
        $this->client->delete();

        $source = WorkReportSource::forPeriod($this->client->id, '2026-09-01', '2026-09-30', null, $this->now);

        expect($source->clientName)->toBe('Ukázkový odběratel a.s.')
            ->and($source->rows)->not->toBe([]);
    });

    it('counts a running entry at its elapsed time', function (): void {
        $client = Client::factory()->create();
        TimeEntry::factory()->create([
            'user_id' => $this->admin->id,
            'client_id' => $client->id,
            'started_at' => CarbonImmutable::parse('2026-10-14 09:00:00', 'UTC'),
            'ended_at' => null,
        ]);

        $source = WorkReportSource::forPeriod($client->id, '2026-10-14', '2026-10-14', null, $this->now);

        expect($source->rows)->toHaveCount(1)
            ->and($source->rows[0]['seconds'])->toBe(3600);
    });

    it('refuses a Partner, a guest and bad input', function (): void {
        $this->actingAs(Canary::partnerFor($this->client->id));
        expect(fn () => WorkReportSource::forPeriod($this->client->id, '2026-09-01', '2026-09-30', null, $this->now))
            ->toThrow(AuthorizationException::class);

        auth()->logout();
        expect(fn () => WorkReportSource::forPeriod($this->client->id, '2026-09-01', '2026-09-30', null, $this->now))
            ->toThrow(AuthorizationException::class);

        $this->actingAs($this->admin);
        $unknown = '0198a5c0-0000-7000-8000-000000000000';

        expect(fn () => WorkReportSource::forPeriod('not-an-id', '2026-09-01', '2026-09-30', null, $this->now))->toThrow(InvalidArgumentException::class)
            ->and(fn () => WorkReportSource::forPeriod($unknown, '2026-09-01', '2026-09-30', null, $this->now))->toThrow(InvalidArgumentException::class)
            ->and(fn () => WorkReportSource::forPeriod($this->client->id, '2026-02-30', '2026-09-30', null, $this->now))->toThrow(InvalidArgumentException::class)
            ->and(fn () => WorkReportSource::forPeriod($this->client->id, '2026-09-30', '2026-09-01', null, $this->now))->toThrow(InvalidArgumentException::class)
            ->and(fn () => WorkReportSource::forPeriod($this->client->id, '2026-09-01', '2026-09-30', 'not-an-id', $this->now))->toThrow(InvalidArgumentException::class)
            ->and(fn () => WorkReportSource::forPeriod($this->client->id, '2026-09-01', '2026-09-30', $unknown, $this->now))->toThrow(InvalidArgumentException::class);
    });

    it('lets a system run build the source', function (): void {
        auth()->logout();

        $source = app(PartnerContext::class)->runAsSystem(
            fn (): WorkReportSource => WorkReportSource::forPeriod($this->client->id, '2026-09-01', '2026-09-30', null, $this->now),
        );

        expect($source->rows)->not->toBe([]);
    });

    it('refuses a project of another client', function (): void {
        $foreign = Project::factory()->create();

        expect(fn () => WorkReportSource::forPeriod($this->client->id, '2026-09-01', '2026-09-30', $foreign->id, $this->now))
            ->toThrow(InvalidArgumentException::class);
    });

    it('never names a rate, a price or an amount', function (): void {
        $source = WorkReportSource::forPeriod($this->client->id, '2026-09-01', '2026-09-30', null, $this->now);
        $serialised = json_encode($source, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        expect($serialised)->not->toContain('98765')
            ->and($serialised)->not->toContain('987,65')
            ->and($serialised)->not->toContain('987.65')
            ->and($serialised)->not->toContain('CZK');
        foreach ($source->rows as $row) {
            expect(array_keys($row))->toBe(['billable', 'project', 'date', 'task', 'seconds']);
        }
    });
});

describe('the Czech document', function (): void {
    it('renders the html of the first page with the parties, the period and the billable table', function (): void {
        $source = WorkReportSource::forPeriod($this->client->id, '2026-09-01', '2026-09-30', null, $this->now);

        $html = app(WorkReportPdf::class)->html($source, InvoiceLanguage::Czech);

        expect($html)->toContain('Pracovní výkaz')
            ->and($html)->toContain('Dodavatel')
            ->and($html)->toContain('Odběratel')
            ->and($html)->toContain('Příklad Dodavatel s.r.o.')
            ->and($html)->toContain('Ukázkový odběratel a.s.')
            ->and($html)->toContain('IČO: 12345678')
            ->and($html)->toContain('IČO: '.$this->clientCompanyId)
            ->and($html)->toContain('Období: 1. 9. 2026 – 30. 9. 2026')
            ->and($html)->toContain('Fakturovatelné')
            ->and($html)->toContain('2. 9. 2026')
            ->and($html)->toContain('3. 9. 2026')
            ->and($html)->toContain($this->taskOne->reference.' · Příprava šablon: čeština, ěščřžýáíéů')
            ->and($html)->toContain('2:15')
            ->and($html)->toContain('2:00')
            ->and($html)->toContain('Celkem fakturovatelné')
            ->and($html)->toContain('4:15')
            ->and($html)->toContain('DejaVu Sans')
            ->and($html)->not->toContain('Projekt: ');
    });

    it('names the project when the report is limited to one', function (): void {
        $source = WorkReportSource::forPeriod($this->client->id, '2026-09-01', '2026-09-30', $this->project->id, $this->now);

        expect(app(WorkReportPdf::class)->html($source, InvoiceLanguage::Czech))->toContain('Projekt: Redesign žluťoučkého webu');
    });

    it('shows the date on the first row of a day only', function (): void {
        reportEntry($this->client, '2026-09-02 11:00:00', '2026-09-02 12:00:00', ['project_id' => $this->project->id, 'task_id' => $this->taskTwo->id]);
        $source = WorkReportSource::forPeriod($this->client->id, '2026-09-01', '2026-09-30', null, $this->now);

        $html = app(WorkReportPdf::class)->html($source, InvoiceLanguage::Czech);

        expect(substr_count($html, '2. 9. 2026'))->toBe(1);
    });

    it('shows no money, no description and no markup injected through a name', function (): void {
        $canary = 'Popis-'.bin2hex(random_bytes(4));
        $injected = Task::factory()->create(['project_id' => $this->project->id, 'title' => '<b>tučně</b> & <script>x</script>']);
        reportEntry($this->client, '2026-09-06 06:00:00', '2026-09-06 07:00:00', ['project_id' => $this->project->id, 'task_id' => $injected->id, 'description' => $canary]);
        $source = WorkReportSource::forPeriod($this->client->id, '2026-09-01', '2026-09-30', null, $this->now);

        $html = app(WorkReportPdf::class)->html($source, InvoiceLanguage::Czech);

        expect($html)->not->toContain($canary)
            ->and($html)->not->toContain('<b>tučně</b>')
            ->and($html)->not->toContain('<script>')
            ->and($html)->toContain('&lt;b&gt;tučně&lt;/b&gt; &amp; &lt;script&gt;x&lt;/script&gt;')
            ->and($html)->not->toContain('Kč')
            ->and($html)->not->toContain('CZK')
            ->and($html)->not->toContain('98765')
            ->and($html)->not->toContain('987,65')
            ->and($html)->not->toContain('987.65');
    });

    it('renders PDF bytes with the embedded DejaVu font', function (): void {
        $source = WorkReportSource::forPeriod($this->client->id, '2026-09-01', '2026-09-30', null, $this->now);

        $pdf = app(WorkReportPdf::class)->render($source, InvoiceLanguage::Czech);

        expect(str_starts_with($pdf, '%PDF-'))->toBeTrue()
            ->and($pdf)->toContain('DejaVuSans')
            ->and(strlen($pdf))->toBeGreaterThan(2000);
    });

    it('does not fetch a remote resource or read a local file named in a value', function (): void {
        $probe = storage_path('framework/dompdf-probe-'.bin2hex(random_bytes(4)).'.txt');

        try {
            file_put_contents($probe, 'probe');
            $task = Task::factory()->create([
                'project_id' => $this->project->id,
                'title' => '<img src="'.$probe.'"><link rel="stylesheet" href="http://127.0.0.1:1/x.css">',
            ]);
            reportEntry($this->client, '2026-09-07 06:00:00', '2026-09-07 07:00:00', ['project_id' => $this->project->id, 'task_id' => $task->id]);
            $source = WorkReportSource::forPeriod($this->client->id, '2026-09-01', '2026-09-30', null, $this->now);

            $pdf = app(WorkReportPdf::class)->render($source, InvoiceLanguage::Czech);
        } finally {
            @unlink($probe);
        }

        expect(str_starts_with($pdf, '%PDF-'))->toBeTrue();
    });
});

/**
 * Adds to the client of the test a second project with a fixed-price task and the entries of the two
 * sections: billable 5:45 (project one 4:45, project two 1:00, the second one fixed-price) on Sep 2, 3,
 * and non-billable 1:30 on Sep 4 and Sep 5, so 4 days with work in all, 7:15 in total.
 *
 * @return array{second: Project, fixed: Task}
 */
function reportMixedSections(): array
{
    $client = test()->client;
    $second = Project::factory()->create(['client_id' => $client->id, 'name' => 'Údržba webu']);
    $fixed = Task::factory()->create(['project_id' => $second->id, 'title' => 'Paušální správa']);
    $fixed->billing()->create(['billing_type' => TaskBillingType::FixedPrice, 'fixed_price' => Money::ofMinor(77700, 'CZK')]);

    // Sep 2: a second task of project one, 0:30 (the day totals 2:45).
    reportEntry($client, '2026-09-02 09:00:00', '2026-09-02 09:30:00', ['project_id' => test()->project->id, 'task_id' => test()->taskTwo->id]);
    // Sep 3: the fixed-price entry is billable hours of project two, 1:00.
    reportEntry($client, '2026-09-03 09:00:00', '2026-09-03 10:00:00', ['project_id' => $second->id, 'task_id' => $fixed->id]);
    // Sep 4 and Sep 5: non-billable, without a task.
    reportEntry($client, '2026-09-04 06:00:00', '2026-09-04 07:00:00', ['project_id' => test()->project->id, 'billable' => false]);
    reportEntry($client, '2026-09-05 06:00:00', '2026-09-05 06:30:00', ['project_id' => $second->id, 'billable' => false]);

    return ['second' => $second, 'fixed' => $fixed];
}

describe('the two sections', function (): void {
    it('puts the billable section before the non-billable one, each with its own table and total', function (): void {
        reportMixedSections();
        $source = WorkReportSource::forPeriod($this->client->id, '2026-09-01', '2026-09-30', null, $this->now);

        $html = app(WorkReportPdf::class)->html($source, InvoiceLanguage::Czech);

        $billable = strpos($html, '<h2>Fakturovatelné</h2>');
        $nonBillable = strpos($html, '<h2>Nefakturovatelné</h2>');

        expect($billable)->toBeInt()
            ->and($nonBillable)->toBeInt()
            ->and($billable)->toBeLessThan($nonBillable)
            ->and(substr_count($html, '<table class="entries'))->toBe(2)
            ->and($html)->toContain('Celkem fakturovatelné')
            ->and($html)->toContain('Celkem nefakturovatelné')
            ->and($html)->toMatch('/Celkem fakturovatelné<\/td>\s*<td class="hours">5:45</')
            ->and($html)->toMatch('/Celkem nefakturovatelné<\/td>\s*<td class="hours">1:30</')
            // The fixed-price task is billable hours, in the first section.
            ->and(strpos($html, 'Paušální správa'))->toBeLessThan($nonBillable);
    });

    it('closes every day with its subtotal and shows the date on the first row of the day only', function (): void {
        reportMixedSections();
        $source = WorkReportSource::forPeriod($this->client->id, '2026-09-01', '2026-09-30', null, $this->now);

        $html = app(WorkReportPdf::class)->html($source, InvoiceLanguage::Czech);

        // Billable: project one Sep 2 and Sep 3, project two Sep 3. Non-billable: Sep 4, Sep 5.
        expect(substr_count($html, 'Celkem za den'))->toBe(5)
            ->and($html)->toMatch('/Celkem za den<\/td>\s*<td class="hours">2:45</')
            ->and(substr_count($html, '2. 9. 2026'))->toBe(1)
            ->and(substr_count($html, 'Bez úkolu'))->toBe(2);
    });

    it('precedes the days of each project with a project row when there are several projects', function (): void {
        reportMixedSections();
        $source = WorkReportSource::forPeriod($this->client->id, '2026-09-01', '2026-09-30', null, $this->now);

        $html = app(WorkReportPdf::class)->html($source, InvoiceLanguage::Czech);

        // Each project is named in both sections.
        expect(substr_count($html, 'Projekt: Redesign žluťoučkého webu'))->toBe(2)
            ->and(substr_count($html, 'Projekt: Údržba webu'))->toBe(2)
            ->and(strpos($html, 'Projekt: Redesign žluťoučkého webu'))->toBeLessThan(strpos($html, 'Projekt: Údržba webu'));
    });

    it('closes the report with a summary of the days, the total of both sections and the hours per project', function (): void {
        reportMixedSections();
        $source = WorkReportSource::forPeriod($this->client->id, '2026-09-01', '2026-09-30', null, $this->now);

        $html = app(WorkReportPdf::class)->html($source, InvoiceLanguage::Czech);
        $summary = substr($html, (int) strpos($html, '<h2>Souhrn</h2>'));

        expect($html)->toContain('<h2>Souhrn</h2>')
            ->and(strrpos($html, '<table class="entries'))->toBeLessThan(strpos($html, '<h2>Souhrn</h2>'))
            ->and($summary)->toMatch('/Počet dní s prací<\/td>\s*<td class="hours">4</')
            ->and($summary)->toMatch('/Celkem hodin<\/td>\s*<td class="hours total-hours">7:15</')
            ->and($summary)->toMatch('/Celkem fakturovatelné<\/td>\s*<td class="hours">5:45</')
            ->and($summary)->toMatch('/Celkem nefakturovatelné<\/td>\s*<td class="hours">1:30</')
            ->and($summary)->toMatch('/Redesign žluťoučkého webu<\/td>\s*<td class="hours">5:45</')
            ->and($summary)->toMatch('/Údržba webu<\/td>\s*<td class="hours">1:30</')
            ->and($html)->toContain('page-break-inside: avoid');
    });

    it('leaves out the non-billable section of a client who has none and the project rows of a single project', function (): void {
        $source = WorkReportSource::forPeriod($this->client->id, '2026-09-01', '2026-09-30', null, $this->now);

        $html = app(WorkReportPdf::class)->html($source, InvoiceLanguage::Czech);

        expect($html)->toContain('<h2>Fakturovatelné</h2>')
            ->and($html)->not->toContain('<h2>Nefakturovatelné</h2>')
            ->and($html)->not->toContain('Celkem nefakturovatelné')
            ->and($html)->not->toContain('Projekt: ')
            ->and($html)->toMatch('/Počet dní s prací<\/td>\s*<td class="hours">2</')
            ->and($html)->toMatch('/Celkem hodin<\/td>\s*<td class="hours total-hours">4:15</')
            ->and(substr_count($html, 'Redesign žluťoučkého webu'))->toBe(0);
    });

    it('shows only the non-billable section of a period with non-billable entries only', function (): void {
        reportMixedSections();
        $source = WorkReportSource::forPeriod($this->client->id, '2026-09-04', '2026-09-05', null, $this->now);

        $html = app(WorkReportPdf::class)->html($source, InvoiceLanguage::Czech);

        expect($html)->not->toContain('<h2>Fakturovatelné</h2>')
            ->and($html)->toContain('<h2>Nefakturovatelné</h2>')
            ->and($html)->not->toContain('Za zvolené období nejsou žádné záznamy.')
            ->and($html)->toMatch('/Celkem hodin<\/td>\s*<td class="hours total-hours">1:30</');
    });

    it('renders a period without entries as the header, one empty row and a 0:00 summary', function (): void {
        $source = WorkReportSource::forPeriod($this->client->id, '2025-01-01', '2025-01-31', null, $this->now);

        $html = app(WorkReportPdf::class)->html($source, InvoiceLanguage::Czech);
        $pdf = app(WorkReportPdf::class)->render($source, InvoiceLanguage::Czech);

        expect($html)->toContain('Pracovní výkaz')
            ->and($html)->toContain('Období: 1. 1. 2025 – 31. 1. 2025')
            ->and(substr_count($html, 'Za zvolené období nejsou žádné záznamy.'))->toBe(1)
            ->and($html)->not->toContain('<h2>Fakturovatelné</h2>')
            ->and($html)->not->toContain('<h2>Nefakturovatelné</h2>')
            ->and($html)->toMatch('/Počet dní s prací<\/td>\s*<td class="hours">0</')
            ->and($html)->toMatch('/Celkem hodin<\/td>\s*<td class="hours total-hours">0:00</')
            ->and(str_starts_with($pdf, '%PDF-'))->toBeTrue();
    });
});

describe('the section helpers of the source', function (): void {
    /**
     * A source of fixed rows: billable 3:00 (project one 2:00 on Sep 1 and Sep 2 in one hour each, project two
     * 1:00 on Sep 2) and non-billable 0:30 (project one on Sep 3), the last row without a project.
     */
    function helperSource(): WorkReportSource
    {
        return new WorkReportSource(
            supplierLines: [],
            clientName: 'Ukázkový odběratel a.s.',
            clientLines: [],
            clientLanguage: InvoiceLanguage::Czech,
            from: '2026-09-01',
            to: '2026-09-30',
            projectName: null,
            rows: [
                ['billable' => true, 'project' => 'Projekt A', 'date' => '2026-09-01', 'task' => null, 'seconds' => 3600],
                ['billable' => true, 'project' => 'Projekt A', 'date' => '2026-09-02', 'task' => null, 'seconds' => 3600],
                ['billable' => true, 'project' => 'Projekt B', 'date' => '2026-09-02', 'task' => null, 'seconds' => 3600],
                ['billable' => false, 'project' => 'Projekt A', 'date' => '2026-09-03', 'task' => null, 'seconds' => 1800],
                ['billable' => false, 'project' => null, 'date' => '2026-09-03', 'task' => null, 'seconds' => 900],
            ],
        );
    }

    it('returns the rows and the seconds of a section', function (): void {
        $source = helperSource();

        expect($source->section(true))->toHaveCount(3)
            ->and($source->section(false))->toHaveCount(2)
            ->and($source->sectionSeconds(true))->toBe(10800)
            ->and($source->sectionSeconds(false))->toBe(2700)
            ->and($source->totalSeconds())->toBe(13500);
    });

    it('counts a day with work once, whatever the section, project or task', function (): void {
        expect(helperSource()->daysWorked())->toBe(3);
    });

    it('totals the hours per project, the project without a name last, the largest first', function (): void {
        expect(helperSource()->projectTotals())->toBe([
            ['project' => 'Projekt A', 'seconds' => 9000],
            ['project' => 'Projekt B', 'seconds' => 3600],
            ['project' => null, 'seconds' => 900],
        ]);
    });
});
