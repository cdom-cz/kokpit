<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentArchive;
use App\Domain\Documents\Notifications\DocumentArchiveFailedNotification;
use App\Domain\Documents\Notifications\DocumentArchiveReadyNotification;
use App\Domain\Documents\Notifications\DocumentNotifier;
use App\Domain\Documents\Support\DocumentUrl;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Models\Task;
use App\Filament\Finance\Widgets\FinanceCategoryTable;
use App\Filament\Finance\Widgets\FinanceClientTable;
use App\Filament\Finance\Widgets\FinanceMonthsChart;
use App\Filament\Finance\Widgets\FinanceStats;
use App\Filament\Pages\FinanceOverviewPage;
use App\Filament\Partner\Resources\PartnerProjectResource\Pages\ViewPartnerProject;
use App\Filament\Partner\Resources\PartnerProjectResource\RelationManagers\PartnerProjectDocumentsRelationManager;
use App\Filament\RelationManagers\ClientDocumentsRelationManager;
use App\Filament\RelationManagers\ProjectDocumentsRelationManager;
use App\Filament\RelationManagers\TaskDocumentsRelationManager;
use App\Filament\RelationManagers\TransactionDocumentsRelationManager;
use App\Filament\Resources\ClientResource\Pages\ViewClient;
use App\Filament\Resources\DocumentArchiveResource;
use App\Filament\Resources\DocumentArchiveResource\Pages\ListDocumentArchives;
use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\DocumentResource\Widgets\DocumentStorageByClient;
use App\Filament\Resources\DocumentResource\Widgets\DocumentStorageStats;
use App\Filament\Resources\ProjectResource\Pages\ViewProject;
use App\Filament\Resources\TaskResource\Pages\ViewTask;
use App\Filament\Resources\TransactionCategoryResource;
use App\Filament\Resources\TransactionCategoryResource\Pages\ListTransactionCategories;
use App\Filament\Resources\TransactionResource;
use App\Filament\Resources\TransactionResource\Pages\EditTransaction;
use App\Filament\Resources\TransactionResource\Pages\ListTransactions;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\DatabaseNotifications;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\CanaryRegistry;
use Tests\Support\DocumentFixtures;

/*
 * Roadmap Phase 9 success criteria, threats T-09-64 and FND-18: a Partner who tries every Phase 9 surface meets
 * nothing of the Admin's files, archives or money. The two canary clients come from the registry (it seeds the five
 * files of each client, the ZIP archive, the finance category and the transaction with the canary of the client);
 * on top of that the Admin has a Partner upload bell and the two archive bells. Every refusal and every negative
 * content check below is paired with an Admin-side positive check, so the sweep cannot pass vacuously. Every value
 * is fictional and assembled at runtime from fragments.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    CanaryRegistry::prepare();
    DocumentFixtures::fakeDisk();

    // A day in the same Prague month as the seeded transactions (2026-10-14), so the overview shows them.
    $this->travelTo(CarbonImmutable::parse('2026-10-16 12:00:00', 'Europe/Prague'));

    [$this->clientA, $this->clientB] = Canary::twoClients();
    $this->canaryA = Canary::canary('client_a');
    $this->canaryB = Canary::canary('client_b');

    CanaryRegistry::seedAll($this->clientA, $this->canaryA);
    CanaryRegistry::seedAll($this->clientB, $this->canaryB);

    // The archive rows belong to the one planner owner, so this Admin is the one who sees them.
    $this->admin = CanaryRegistry::plannerOwner();

    $this->projectA = docFinSystem(fn (): Project => Project::query()->where('name', $this->canaryA)->firstOrFail());
    $this->projectB = docFinSystem(fn (): Project => Project::query()->where('name', $this->canaryB)->firstOrFail());
    $this->taskA = docFinSystem(fn (): Task => Task::query()->where('project_id', $this->projectA->id)->firstOrFail());
    $this->taskB = docFinSystem(fn (): Task => Task::query()->where('project_id', $this->projectB->id)->firstOrFail());
    $this->clientModelA = docFinSystem(fn () => Client::query()->findOrFail($this->clientA));
    $this->transactionA = docFinSystem(fn (): Transaction => Transaction::query()->where('counterparty', $this->canaryA)->firstOrFail());

    $this->partnerA = Canary::partnerFor($this->clientA);

    // The three Phase 9 bells of the Admin: a Partner upload, a ready archive and a failed archive.
    docFinSystem(function (): void {
        app(DocumentNotifier::class)->partnerUploaded($this->partnerA, $this->taskA, 2);

        $this->admin->notifyNow(new DocumentArchiveReadyNotification(3, '23. 10. 2026', '/admin/document-archives'));
        $this->admin->notifyNow(new DocumentArchiveFailedNotification('/admin/document-archives'));
    });

    $this->hiddenNames = [];

    foreach ([$this->canaryA, $this->canaryB] as $canary) {
        foreach (['-hidden', '-internal', '-client', '-unattached'] as $suffix) {
            $this->hiddenNames[] = $canary.$suffix;
            $this->hiddenNames[] = strtolower($canary.$suffix);
        }
    }

    // The visible file of the other client and every value of the money and archive fixtures.
    $this->otherClientValues = [$this->canaryB, strtolower($this->canaryB)];
    $this->archiveSummaries = [$this->canaryA, $this->canaryB];
    $this->moneyValues = ['1 234,50', "1\u{a0}234,50", '1234,50', '1234.50', '123450'];
    $this->bellTexts = [
        __('documents.bell.partner_uploaded.title'),
        __('documents.archives.bell.ready.title'),
        __('documents.archives.bell.failed.title'),
    ];
});

afterEach(function (): void {
    CanaryRegistry::cleanup();
});

/**
 * Runs the callback as a system run (all rows visible).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function docFinSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * The strings of the Phase 9 Admin surfaces that a Partner page must never carry: the labels of the archives, the
 * transactions, the categories and the finance overview, the Admin bell titles and the currency signs. Matched as
 * plain text anywhere in the HTML, the Livewire snapshots included.
 *
 * @return list<string>
 */
function docFinForbidden(): array
{
    return [
        (string) __('documents.archives.navigation'),
        (string) __('finance.overview.title'),
        (string) __('finance.transactions.navigation'),
        (string) __('finance.categories.navigation'),
        (string) __('documents.bell.partner_uploaded.title'),
        (string) __('documents.archives.bell.ready.title'),
        (string) __('documents.archives.bell.failed.title'),
        'Kč', 'EUR',
    ];
}

/**
 * Asserts that a Partner response body holds none of the Phase 9 Admin strings, no hidden file name, nothing of the
 * other client and no figure of the finance fixtures.
 *
 * @param  list<string>  $values  hidden names, canaries of the other client and money figures
 */
function docFinAssertClean(string $html, string $where, array $values): void
{
    foreach (docFinForbidden() as $text) {
        expect(str_contains($html, $text))->toBeFalse("{$where} must not hold \"{$text}\"");
    }

    foreach ($values as $value) {
        expect(str_contains($html, $value))->toBeFalse("{$where} must not hold {$value}");
    }
}

describe('proves the fixtures exist: the Admin sees what the Partner does not', function (): void {
    it('lists the files, the archive, the transaction, the category and the overview for the Admin', function (): void {
        $this->actingAs($this->admin);

        $documents = (string) $this->get('/admin/documents')->assertOk()->getContent();
        expect(DocumentResource::getUrl())->toContain('/admin/documents')
            ->and($documents)->toContain($this->canaryA)
            ->and($documents)->toContain($this->canaryB);

        $this->get('/admin/document-archives')->assertOk()->assertSee($this->canaryA)->assertSee($this->canaryB);
        $transactions = (string) $this->get('/admin/transactions')->assertOk()->assertSee($this->canaryA)->assertSee($this->canaryB)->getContent();

        // The figure of the fixture is on the Admin list in one of its spellings, so the Partner checks below look for a real value.
        expect(collect($this->moneyValues)->contains(static fn (string $figure): bool => str_contains($transactions, $figure)))->toBeTrue();

        $this->get('/admin/transaction-categories')->assertOk()->assertSee($this->canaryA)->assertSee($this->canaryB);
        $this->get('/admin/finance-overview')->assertOk();

        expect(DocumentArchiveResource::getUrl())->toContain('/admin/document-archives')
            ->and(TransactionResource::getUrl())->toContain('/admin/transactions')
            ->and(TransactionCategoryResource::getUrl())->toContain('/admin/transaction-categories')
            ->and(FinanceOverviewPage::getUrl())->toContain('/admin/finance-overview');
    });

    it('shows the Admin the hidden files on the Soubory tab of each owner and the receipt count of the transaction', function (): void {
        $this->actingAs($this->admin);

        Livewire::test(ProjectDocumentsRelationManager::class, ['ownerRecord' => $this->projectA, 'pageClass' => ViewProject::class])
            ->assertSee($this->canaryA.'-hidden');
        Livewire::test(ClientDocumentsRelationManager::class, ['ownerRecord' => $this->clientModelA, 'pageClass' => ViewClient::class])
            ->assertSee($this->canaryA.'-client');
        Livewire::test(TaskDocumentsRelationManager::class, ['ownerRecord' => $this->taskA, 'pageClass' => ViewTask::class])
            ->assertSuccessful();
        Livewire::test(TransactionDocumentsRelationManager::class, ['ownerRecord' => $this->transactionA, 'pageClass' => EditTransaction::class])
            ->assertSuccessful();
    });

    it('shows the Admin the finance figures in the widgets and the three bells in the bell', function (): void {
        $this->actingAs($this->admin);
        $filters = ['period' => 'month', 'year' => 2026, 'month' => 10];

        Livewire::test(FinanceStats::class, ['pageFilters' => $filters])->assertSuccessful();
        Livewire::test(FinanceCategoryTable::class, ['pageFilters' => $filters])->assertSee('Software');
        Livewire::test(FinanceClientTable::class, ['pageFilters' => $filters])->assertSee($this->canaryA);
        Livewire::test(FinanceMonthsChart::class, ['pageFilters' => $filters])->assertSuccessful();
        Livewire::test(DocumentStorageStats::class)->assertSuccessful();
        Livewire::test(DocumentStorageByClient::class)->assertSee($this->canaryA);

        $bell = Livewire::test(DatabaseNotifications::class)->html();

        foreach ($this->bellTexts as $text) {
            expect($bell)->toContain($text);
        }

        expect(DB::table('notifications')->where('notifiable_id', $this->admin->id)->count())->toBe(3);
    });
});

describe('what a Partner can open', function (): void {
    it('answers 403 to a Partner on every Phase 9 Admin route and leaks nothing in the refusal', function (): void {
        $this->actingAs($this->partnerA);

        $transaction = $this->transactionA;
        $urls = [
            '/admin/documents',
            '/admin/document-archives',
            '/admin/transactions',
            '/admin/transactions/create',
            '/admin/transactions/'.$transaction->getKey().'/edit',
            '/admin/transaction-categories',
            '/admin/finance-overview',
        ];

        foreach ($urls as $url) {
            $response = $this->get($url);
            $response->assertForbidden();

            docFinAssertClean((string) $response->getContent(), $url, [...$this->hiddenNames, ...$this->otherClientValues, ...$this->moneyValues]);
        }
    });

    it('answers 403 to a Partner who mounts any Phase 9 Admin page, table, widget or relation manager', function (): void {
        $this->actingAs($this->partnerA);

        foreach ([
            ListDocuments::class, ListDocumentArchives::class, ListTransactions::class, ListTransactionCategories::class,
            FinanceOverviewPage::class, DocumentStorageStats::class, DocumentStorageByClient::class,
        ] as $component) {
            Livewire::test($component)->assertForbidden();
        }

        $filters = ['period' => 'month', 'year' => 2026, 'month' => 10];

        foreach ([FinanceStats::class, FinanceCategoryTable::class, FinanceClientTable::class, FinanceMonthsChart::class] as $widget) {
            Livewire::test($widget, ['pageFilters' => $filters])->assertForbidden();
        }
    });

    it('answers 403 to a Partner on the four Admin Soubory tabs, for the own client and for the other', function (): void {
        $this->actingAs($this->partnerA);

        Livewire::test(ProjectDocumentsRelationManager::class, ['ownerRecord' => $this->projectA, 'pageClass' => ViewProject::class])->assertForbidden();
        Livewire::test(ProjectDocumentsRelationManager::class, ['ownerRecord' => $this->projectB, 'pageClass' => ViewProject::class])->assertForbidden();
        Livewire::test(TaskDocumentsRelationManager::class, ['ownerRecord' => $this->taskA, 'pageClass' => ViewTask::class])->assertForbidden();
        Livewire::test(TaskDocumentsRelationManager::class, ['ownerRecord' => $this->taskB, 'pageClass' => ViewTask::class])->assertForbidden();
        Livewire::test(ClientDocumentsRelationManager::class, ['ownerRecord' => $this->clientModelA, 'pageClass' => ViewClient::class])->assertForbidden();
        Livewire::test(TransactionDocumentsRelationManager::class, ['ownerRecord' => $this->transactionA, 'pageClass' => EditTransaction::class])->assertForbidden();
    });

    it('refuses a Partner the signed archive link of an Admin, so no archive is streamed', function (): void {
        $archive = docFinSystem(fn (): DocumentArchive => DocumentArchive::query()->where('summary', $this->canaryA)->firstOrFail());
        $url = DocumentUrl::archive($archive, $this->partnerA);

        $this->actingAs($this->partnerA);

        expect($this->get($url)->getStatusCode())->toBeIn([403, 404]);
    });

    it('refuses the signed file link of the hidden, internal, client-owned and unattached files of the own client and every file of the other', function (): void {
        $this->actingAs($this->partnerA);

        $files = docFinSystem(fn () => Document::query()->where('name', 'like', '%'.$this->canaryA.'-%')->orWhere('name', 'like', '%'.$this->canaryB.'%')->get());

        expect($files->count())->toBeGreaterThanOrEqual(8);

        foreach ($files as $file) {
            expect($this->get(DocumentUrl::download($file, $this->partnerA))->getStatusCode())->toBeIn([403, 404], 'file '.$file->name);
        }
    });
});

describe('what a Partner sees', function (): void {
    it('shows the shared file of the own project and none of the hidden, internal, client or unattached files', function (): void {
        $this->actingAs($this->partnerA);

        Livewire::test(PartnerProjectDocumentsRelationManager::class, [
            'ownerRecord' => Project::query()->whereKey($this->projectA->getKey())->firstOrFail(),
            'pageClass' => ViewPartnerProject::class,
        ])
            ->assertSee($this->canaryA)
            ->assertDontSee($this->canaryA.'-hidden')
            ->assertDontSee($this->canaryA.'-internal')
            ->assertDontSee($this->canaryA.'-client')
            ->assertDontSee($this->canaryA.'-unattached')
            ->assertDontSee($this->canaryB);
    });

    it('keeps every page a Partner can open free of the Phase 9 Admin strings, hidden file names, the other client and money', function (): void {
        $this->actingAs($this->partnerA);

        $pages = [
            'the dashboard' => '/admin',
            'the project list' => '/admin/my-projects',
            'the own project page' => '/admin/my-projects/'.$this->projectA->id,
            'the task list' => '/admin/my-tasks',
            'the own task page' => '/admin/my-tasks/'.$this->taskA->reference,
            'the task creation page' => '/admin/my-tasks/create',
            'the profile page' => '/admin/profile',
        ];

        $values = [...$this->hiddenNames, ...$this->otherClientValues, ...$this->moneyValues];

        foreach ($pages as $where => $url) {
            docFinAssertClean((string) $this->get($url)->assertOk()->getContent(), $where, $values);
        }
    });

    it('shows the Partner no Phase 9 notice in the bell and no row for the Partner in the notification table', function (): void {
        $this->actingAs($this->partnerA);

        expect(DB::table('notifications')->where('notifiable_id', $this->partnerA->id)->count())->toBe(0);

        $everything = (string) DB::table('notifications')->where('notifiable_id', $this->partnerA->id)->get()->toJson();
        $bell = Livewire::test(DatabaseNotifications::class)->html();

        foreach ([$everything, $bell] as $where => $text) {
            foreach ($this->bellTexts as $title) {
                expect($text)->not->toContain($title, "bell source {$where}");
            }

            docFinAssertClean($text, "bell source {$where}", [...$this->hiddenNames, ...$this->otherClientValues, ...$this->moneyValues]);
        }

        expect(DB::table('notifications')->where('notifiable_id', $this->admin->id)->count())->toBe(3);
    });

    it('never puts a file name into the upload bell of the Admin', function (): void {
        $row = (string) DB::table('notifications')
            ->where('notifiable_id', $this->admin->id)
            ->where('data', 'like', '%'.__('documents.bell.partner_uploaded.title').'%')
            ->value('data');

        expect($row)->not->toBe('')
            ->and($row)->toContain($this->taskA->reference);

        foreach ($this->hiddenNames as $name) {
            expect($row)->not->toContain($name);
        }
    });
});
