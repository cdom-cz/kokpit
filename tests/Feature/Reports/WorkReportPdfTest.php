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
