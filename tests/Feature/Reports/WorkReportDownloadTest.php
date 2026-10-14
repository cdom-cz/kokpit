<?php

declare(strict_types=1);

use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Reports\WorkReport\WorkReportPdf;
use App\Domain\Reports\WorkReport\WorkReportSource;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Pages\TimeReportPage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The "Pracovní výkaz PDF" item of the report page (RE-03; D-10; UI-SPEC B1 and Surface D): the Admin downloads
 * the work report of the chosen client, project and period straight from the report. Every name below is invented.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    // Wednesday 2026-10-14, 12:00 in Prague. Last month is September 2026.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));

    $this->client = Client::factory()->create([
        'name' => 'Ukázkový odběratel a.s.',
        'hourly_rate' => Money::ofMinor(98765, 'CZK'),
    ]);
    $this->project = Project::factory()->create(['client_id' => $this->client->id, 'name' => 'Redesign webu']);
    $this->other = Project::factory()->create(['client_id' => $this->client->id, 'name' => 'Jiný projekt']);

    workReportDownloadEntry($this->client, '2026-09-02 06:00:00', '2026-09-02 07:30:00', ['project_id' => $this->project->id]);
    workReportDownloadEntry($this->client, '2026-09-03 06:00:00', '2026-09-03 08:00:00', ['project_id' => $this->other->id]);
});

/**
 * A stored entry of the signed-in Admin from UTC instants.
 *
 * @param  array<string, mixed>  $attributes
 */
function workReportDownloadEntry(Client $client, string $start, string $end, array $attributes = []): TimeEntry
{
    return TimeEntry::factory()->create([
        'user_id' => test()->admin->id,
        'client_id' => $client->id,
        'started_at' => CarbonImmutable::parse($start, 'UTC'),
        'ended_at' => CarbonImmutable::parse($end, 'UTC'),
        ...$attributes,
    ]);
}

/**
 * Replaces the renderer in the container by a recording double that returns fixed bytes, so a test can see which
 * source and language the page hands it (the real renderer is final).
 *
 * @return object{calls: list<array{WorkReportSource, InvoiceLanguage}>}
 */
function workReportDownloadDouble(): object
{
    $double = new class
    {
        /** @var list<array{WorkReportSource, InvoiceLanguage}> */
        public array $calls = [];

        public function render(WorkReportSource $source, InvoiceLanguage $language, ?CarbonInterface $generatedAt = null): string
        {
            $this->calls[] = [$source, $language];

            return '%PDF-1.4 double';
        }
    };

    app()->instance(WorkReportPdf::class, $double);

    return $double;
}

describe('the menu item', function (): void {
    it('is not rendered without a client, neither in the component nor in the page', function (): void {
        Livewire::test(TimeReportPage::class)->assertActionHidden('workReport');

        expect((string) $this->get('/admin/time-report')->assertOk()->getContent())->not->toContain('Pracovní výkaz PDF');
    });

    it('is rendered when a client is chosen, with the other export items', function (): void {
        Livewire::test(TimeReportPage::class)
            ->set('client', $this->client->id)
            ->assertActionVisible('workReport')
            ->assertActionExists('workReport', static fn ($action): bool => $action->getLabel() === 'Pracovní výkaz PDF');

        $html = (string) $this->get('/admin/time-report?client='.$this->client->id)->assertOk()->getContent();

        expect($html)->toContain('Pracovní výkaz PDF')->toContain('Tabulka CSV')->toContain('Tabulka XLSX');
    });
});

describe('the download', function (): void {
    it('downloads the work report PDF of the client and the period under a generated file name', function (): void {
        $page = Livewire::test(TimeReportPage::class)
            ->set('client', $this->client->id)
            ->set('period', 'last_month')
            ->callAction('workReport')
            ->assertHasNoActionErrors()
            ->assertFileDownloaded('pracovni-vykaz-2026-09-01_2026-09-30.pdf', contentType: 'application/pdf');

        $bytes = base64_decode((string) data_get($page->effects, 'download.content'), true);

        expect($bytes)->toBeString()
            ->and(str_starts_with((string) $bytes, '%PDF-'))->toBeTrue();
    });

    it('limits the source to the chosen project and hands it the client language', function (): void {
        $double = workReportDownloadDouble();

        Livewire::test(TimeReportPage::class)
            ->set('client', $this->client->id)
            ->set('project', $this->project->id)
            ->set('period', 'last_month')
            ->callAction('workReport')
            ->assertFileDownloaded('pracovni-vykaz-2026-09-01_2026-09-30.pdf');

        [$source, $language] = $double->calls[0];

        expect($double->calls)->toHaveCount(1)
            ->and($language)->toBe(InvoiceLanguage::Czech)
            ->and($source->clientName)->toBe('Ukázkový odběratel a.s.')
            ->and($source->projectName)->toBe('Redesign webu')
            ->and($source->from)->toBe('2026-09-01')
            ->and($source->to)->toBe('2026-09-30')
            ->and(array_sum(array_column($source->rows, 'seconds')))->toBe(5400);
    });

    it('renders the report of every project of the client when no project is chosen', function (): void {
        $double = workReportDownloadDouble();

        Livewire::test(TimeReportPage::class)
            ->set('client', $this->client->id)
            ->set('period', 'last_month')
            ->callAction('workReport');

        expect($double->calls[0][0]->projectName)->toBeNull()
            ->and(array_sum(array_column($double->calls[0][0]->rows, 'seconds')))->toBe(12600);
    });

    it('renders in English and names the file in English for a client with the English invoice language', function (): void {
        $this->client->forceFill(['invoice_language' => InvoiceLanguage::English])->save();
        $double = workReportDownloadDouble();

        Livewire::test(TimeReportPage::class)
            ->set('client', $this->client->id)
            ->set('period', 'last_month')
            ->callAction('workReport')
            ->assertFileDownloaded('work-report-2026-09-01_2026-09-30.pdf');

        expect($double->calls[0][1])->toBe(InvoiceLanguage::English);
    });

    it('takes the days of a custom period into the file name', function (): void {
        workReportDownloadDouble();

        Livewire::test(TimeReportPage::class)
            ->set('client', $this->client->id)
            ->set('period', 'custom')
            ->set('from', '2026-09-02')
            ->set('to', '2026-09-03')
            ->callAction('workReport')
            ->assertFileDownloaded('pracovni-vykaz-2026-09-02_2026-09-03.pdf');
    });
});

describe('a failure', function (): void {
    it('shows the danger toast without exception text and offers no file', function (): void {
        // The text is assembled from fragments so no line holds a ready-made leak.
        $detail = 'secret-'.'renderer-'.'detail';
        app()->instance(WorkReportPdf::class, new class($detail)
        {
            public function __construct(private readonly string $detail) {}

            public function render(WorkReportSource $source, InvoiceLanguage $language): string
            {
                throw new RuntimeException($this->detail);
            }
        });

        $page = Livewire::test(TimeReportPage::class)
            ->set('client', $this->client->id)
            ->set('period', 'last_month')
            ->callAction('workReport')
            ->assertNotified(
                Notification::make()
                    ->danger()
                    ->title('Pracovní výkaz se nepodařilo vytvořit')
                    ->body('Zkuste to znovu. Pokud chyba přetrvá, zkuste jiné období.'),
            )
            ->assertNoFileDownloaded();

        expect(json_encode(session('filament.notifications', []), JSON_THROW_ON_ERROR))->not->toContain($detail)
            ->and(data_get($page->effects, 'download'))->toBeNull();
    });
});

describe('who may download', function (): void {
    it('refuses a Partner every Livewire call of the page, the item included', function (): void {
        $this->actingAs(Canary::partnerFor($this->client->id));

        Livewire::test(TimeReportPage::class)->assertForbidden();
    });
});
