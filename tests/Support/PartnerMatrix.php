<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Filament\Auth\EditProfile;
use App\Filament\Finance\Widgets\FinanceCategoryTable;
use App\Filament\Finance\Widgets\FinanceClientTable;
use App\Filament\Finance\Widgets\FinanceMonthsChart;
use App\Filament\Finance\Widgets\FinanceStats;
use App\Filament\Pages\Auth\AcceptInvitation;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\FinanceOverviewPage;
use App\Filament\Pages\SettingsPage;
use App\Filament\Pages\Signal\SignalDashboardPage;
use App\Filament\Pages\Signal\SignalReflectionPage;
use App\Filament\Pages\Signal\SignalSettingsPage;
use App\Filament\Pages\Signal\SignalTodayPage;
use App\Filament\Pages\Signal\SignalWeekPage;
use App\Filament\Pages\SystemPage;
use App\Filament\Pages\TaskBoardPage;
use App\Filament\Pages\TimeReportPage;
use App\Filament\Pages\TimesheetPage;
use App\Filament\Partner\Resources\PartnerInvoiceResource;
use App\Filament\Partner\Resources\PartnerProjectResource;
use App\Filament\Partner\Resources\PartnerProjectResource\RelationManagers\PartnerProjectDocumentsRelationManager;
use App\Filament\Partner\Resources\PartnerTaskResource;
use App\Filament\Partner\Resources\PartnerTaskResource\RelationManagers\PartnerTaskCommentsRelationManager;
use App\Filament\Partner\Resources\PartnerTaskResource\RelationManagers\PartnerTaskDocumentsRelationManager;
use App\Filament\RelationManagers\CardPaymentHistoryRelationManager;
use App\Filament\RelationManagers\ClientDocumentsRelationManager;
use App\Filament\RelationManagers\ClientHistoryRelationManager;
use App\Filament\RelationManagers\InvoiceDocumentsRelationManager;
use App\Filament\RelationManagers\InvoiceHistoryRelationManager;
use App\Filament\RelationManagers\ProjectDocumentsRelationManager;
use App\Filament\RelationManagers\ProjectHistoryRelationManager;
use App\Filament\RelationManagers\TaskDocumentsRelationManager;
use App\Filament\RelationManagers\TaskHistoryRelationManager;
use App\Filament\RelationManagers\TimeEntryHistoryRelationManager;
use App\Filament\RelationManagers\TransactionDocumentsRelationManager;
use App\Filament\RelationManagers\TransactionHistoryRelationManager;
use App\Filament\Resources\ActivityResource;
use App\Filament\Resources\ApiTokenResource;
use App\Filament\Resources\CardPaymentResource;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ClientResource\RelationManagers\ClientInvoicesRelationManager;
use App\Filament\Resources\ClientResource\RelationManagers\ClientProjectsRelationManager;
use App\Filament\Resources\ClientResource\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\ClientResource\RelationManagers\InvitationsRelationManager;
use App\Filament\Resources\ClientResource\RelationManagers\PartnerAccountsRelationManager;
use App\Filament\Resources\ClientResource\Widgets\ClientOverviewStats;
use App\Filament\Resources\DocumentArchiveResource;
use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentResource\Widgets\DocumentStorageByClient;
use App\Filament\Resources\DocumentResource\Widgets\DocumentStorageStats;
use App\Filament\Resources\ExchangeRateResource;
use App\Filament\Resources\ExchangeRateResource\Widgets\ExchangeRateStats;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\InvoiceResource\RelationManagers\InvoiceEmailsRelationManager;
use App\Filament\Resources\InvoiceResource\RelationManagers\InvoiceEntriesRelationManager;
use App\Filament\Resources\InvoiceResource\RelationManagers\InvoicePaymentsRelationManager;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\Pages\ProjectBoard;
use App\Filament\Resources\ProjectResource\RelationManagers\ProjectTasksTimeRelationManager;
use App\Filament\Resources\ProjectResource\RelationManagers\ProjectTimeEntriesRelationManager;
use App\Filament\Resources\ProjectResource\Widgets\ProjectTimeStats;
use App\Filament\Resources\ReportExportResource;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TaskResource\RelationManagers\SubtasksRelationManager;
use App\Filament\Resources\TaskResource\RelationManagers\TaskCommentsRelationManager;
use App\Filament\Resources\TimeEntryResource;
use App\Filament\Resources\TransactionCategoryResource;
use App\Filament\Resources\TransactionResource;
use App\Filament\Widgets\HoursStats;
use App\Filament\Widgets\PartnerProjects;
use App\Filament\Widgets\PartnerTasks;
use App\Filament\Widgets\TodayPlan;
use App\Filament\Widgets\UnbilledPerClient;
use App\Filament\Widgets\UnpaidInvoices;
use App\Filament\Widgets\UnresolvedPayments;
use App\Filament\Widgets\UpcomingDeadlines;
use App\Livewire\Signal\SignalDay;
use App\Livewire\TimeTracking\RecentEntriesPanel;
use App\Livewire\TimeTracking\TimerBar;
use Tests\Support\Filament\CanaryRecordResource;

/**
 * The permission matrix of the screens and endpoints (US-01, AUD-02; D-01, D-02, D-11, R-12-03-1).
 *
 * Pure data: the expected behaviour of the Admin and of a Partner with a client for every Filament Resource,
 * Page, Widget, cluster and relation manager of the admin panel, every /api/v1 route, every plain Livewire
 * component and the Horizon dashboard. It was drafted from PROJECT.md, REQUIREMENTS.md and the screens as they
 * were first described, for the owner to review and sign off (D-02); it is never derived from the code under
 * test (D-01), so a regression cannot change both sides. A new screen or endpoint is declared here in the same
 * pull request that adds it (D-11): the completeness tests in tests/Audit/AccessMatrixTest.php fail on a
 * discovered class or route without a row and on a row without one.
 *
 * Class keys are `::class` constants, which are identifiers and run no production code. This file refers to
 * nothing that decides access; tests/Audit/AccessMatrixTest.php fails if it ever does.
 *
 * Row shapes (R-12-03-1):
 *  - panelClasses():     class-string => ['admin' => bool, 'partner' => bool, 'reason' => string], plus
 *                        'testOnly' => true for a row that exists only while the canary harness is on.
 *  - apiEndpoints():     route name   => ['ability' => string, 'admin' => true, 'partner' => false, 'reason' => string]
 *  - livewireComponents(): class-string => ['admin' => bool, 'partner' => bool, 'reason' => string]
 *  - horizon():          ['dashboard' => route name, 'prefix' => route name prefix, 'admin' => bool, 'partner' => bool, 'reason' => string]
 *
 * "partner" is a Partner with a client. For a relation manager the observation is made on a record of the owning
 * resource's model that belongs to that client; for a Partner relation manager that record is an own visible
 * project or task. Where a row says admin false for a Partner screen, the Admin is refused there because the Admin
 * has the Admin resource or widget for the same data.
 */
final class PartnerMatrix
{
    /**
     * Every screen of the admin panel, keyed by class.
     *
     * @return array<class-string, array{admin: bool, partner: bool, reason: string, testOnly?: bool}>
     */
    public static function panelClasses(): array
    {
        return [
            // Partner pages: both roles
            Dashboard::class => ['admin' => true, 'partner' => true, 'reason' => 'Landing page of both roles; a Partner sees neutral text and the Partner widgets only.'],
            EditProfile::class => ['admin' => true, 'partner' => true, 'reason' => 'Every signed-in account edits its own profile and notification switches.'],

            // Partner resources: the Admin uses the Admin resources instead
            PartnerProjectResource::class => ['admin' => false, 'partner' => true, 'reason' => 'Read-only list of the own client\'s visible projects; opens only for a Partner with a client.'],
            PartnerTaskResource::class => ['admin' => false, 'partner' => true, 'reason' => 'Tasks of the own client\'s visible projects; opens only for a Partner with a client.'],
            PartnerInvoiceResource::class => ['admin' => false, 'partner' => true, 'reason' => 'Issued documents of the own client, read-only; opens only for a Partner with a client.'],

            // Partner relation managers: checked on an own visible project or task
            PartnerProjectDocumentsRelationManager::class => ['admin' => true, 'partner' => true, 'reason' => 'Shared files of an own visible project, read-only.'],
            PartnerTaskCommentsRelationManager::class => ['admin' => true, 'partner' => true, 'reason' => 'Non-internal comments of an own task and adding a comment.'],
            PartnerTaskDocumentsRelationManager::class => ['admin' => true, 'partner' => true, 'reason' => 'Shared files of an own task.'],

            // Partner widgets: the Admin dashboard has its own widgets
            PartnerProjects::class => ['admin' => false, 'partner' => true, 'reason' => 'Own client\'s visible projects on the Partner dashboard; the widget refuses the Admin.'],
            PartnerTasks::class => ['admin' => false, 'partner' => true, 'reason' => 'Open tasks of the own client on the Partner dashboard; the widget refuses the Admin.'],

            // Guest pages: reached only by their own guest or signed route (covered by RouteRules), denied to everyone by the panel
            RequestPasswordReset::class => ['admin' => false, 'partner' => false, 'reason' => 'Public forgot-password page; never opened through the panel checks.'],
            AcceptInvitation::class => ['admin' => false, 'partner' => false, 'reason' => 'Invitation page; reached only through its own signed route.'],

            // Admin resources
            ActivityResource::class => ['admin' => true, 'partner' => false, 'reason' => 'Audit trail of who changed what.'],
            ApiTokenResource::class => ['admin' => true, 'partner' => false, 'reason' => 'API tokens grant access to time and finance.'],
            CardPaymentResource::class => ['admin' => true, 'partner' => false, 'reason' => 'Card payments and their Stripe references are finance.'],
            ClientResource::class => ['admin' => true, 'partner' => false, 'reason' => 'Clients carry rates, terms and billing data.'],
            DocumentArchiveResource::class => ['admin' => true, 'partner' => false, 'reason' => 'ZIP archives bundle files of every client.'],
            DocumentResource::class => ['admin' => true, 'partner' => false, 'reason' => 'Central listing of the files of every client.'],
            ExchangeRateResource::class => ['admin' => true, 'partner' => false, 'reason' => 'Exchange rates feed invoice amounts.'],
            InvoiceResource::class => ['admin' => true, 'partner' => false, 'reason' => 'Billing documents carry rates, payments and drafts.'],
            ProjectResource::class => ['admin' => true, 'partner' => false, 'reason' => 'Projects carry rates, prices and billing terms.'],
            ReportExportResource::class => ['admin' => true, 'partner' => false, 'reason' => 'Report exports hold measured time and rates.'],
            TaskResource::class => ['admin' => true, 'partner' => false, 'reason' => 'Admin task screens carry people, billing and internal data.'],
            TimeEntryResource::class => ['admin' => true, 'partner' => false, 'reason' => 'Measured time is closed to Partners.'],
            TransactionCategoryResource::class => ['admin' => true, 'partner' => false, 'reason' => 'Categories of the finance module.'],
            TransactionResource::class => ['admin' => true, 'partner' => false, 'reason' => 'Income and expense are finance.'],

            // Admin pages
            FinanceOverviewPage::class => ['admin' => true, 'partner' => false, 'reason' => 'Income, expense and balance.'],
            SettingsPage::class => ['admin' => true, 'partner' => false, 'reason' => 'Operator settings: supplier data, bank accounts, numbering.'],
            SignalDashboardPage::class => ['admin' => true, 'partner' => false, 'reason' => 'Overview of the personal planner of the Admin.'],
            SignalReflectionPage::class => ['admin' => true, 'partner' => false, 'reason' => 'Personal recaps of the Admin.'],
            SignalSettingsPage::class => ['admin' => true, 'partner' => false, 'reason' => 'Planner settings of the Admin.'],
            SignalTodayPage::class => ['admin' => true, 'partner' => false, 'reason' => 'Daily planner of the Admin.'],
            SignalWeekPage::class => ['admin' => true, 'partner' => false, 'reason' => 'Weekly goals and recap of the Admin.'],
            SystemPage::class => ['admin' => true, 'partner' => false, 'reason' => 'Queue, scheduler and failure state.'],
            TaskBoardPage::class => ['admin' => true, 'partner' => false, 'reason' => 'Board that moves cards across all clients.'],
            TimeReportPage::class => ['admin' => true, 'partner' => false, 'reason' => 'Measured time, rates and amounts.'],
            TimesheetPage::class => ['admin' => true, 'partner' => false, 'reason' => 'Measured time of the Admin.'],
            ProjectBoard::class => ['admin' => true, 'partner' => false, 'reason' => 'Board of one project; moves cards in the global column order.'],

            // Admin widgets (UnresolvedPayments shows only while at least one card payment is unresolved; the test seeds one)
            FinanceCategoryTable::class => ['admin' => true, 'partner' => false, 'reason' => 'Income and expense by category.'],
            FinanceClientTable::class => ['admin' => true, 'partner' => false, 'reason' => 'Income and expense by client.'],
            FinanceMonthsChart::class => ['admin' => true, 'partner' => false, 'reason' => 'Income and expense by month.'],
            FinanceStats::class => ['admin' => true, 'partner' => false, 'reason' => 'Income, expense and balance.'],
            ClientOverviewStats::class => ['admin' => true, 'partner' => false, 'reason' => 'Unbilled time and receivables of a client.'],
            DocumentStorageByClient::class => ['admin' => true, 'partner' => false, 'reason' => 'Stored volume per client.'],
            DocumentStorageStats::class => ['admin' => true, 'partner' => false, 'reason' => 'Stored volume across all clients.'],
            ExchangeRateStats::class => ['admin' => true, 'partner' => false, 'reason' => 'Whether the exchange rates are current.'],
            ProjectTimeStats::class => ['admin' => true, 'partner' => false, 'reason' => 'Worked and billed time of a project.'],
            HoursStats::class => ['admin' => true, 'partner' => false, 'reason' => 'Own measured time of the Admin.'],
            TodayPlan::class => ['admin' => true, 'partner' => false, 'reason' => 'Daily plan of the Admin.'],
            UnbilledPerClient::class => ['admin' => true, 'partner' => false, 'reason' => 'Unbilled time and amounts per client.'],
            UnpaidInvoices::class => ['admin' => true, 'partner' => false, 'reason' => 'Receivables, amounts and due dates.'],
            UnresolvedPayments::class => ['admin' => true, 'partner' => false, 'reason' => 'Unresolved card payments.'],
            UpcomingDeadlines::class => ['admin' => true, 'partner' => false, 'reason' => 'Task deadlines across all clients.'],

            // Admin relation managers
            CardPaymentHistoryRelationManager::class => ['admin' => true, 'partner' => false, 'reason' => 'Change history of a card payment.'],
            ClientDocumentsRelationManager::class => ['admin' => true, 'partner' => false, 'reason' => 'All files of a client, hidden ones included.'],
            ClientHistoryRelationManager::class => ['admin' => true, 'partner' => false, 'reason' => 'Change history of a client.'],
            InvoiceDocumentsRelationManager::class => ['admin' => true, 'partner' => false, 'reason' => 'Files of a billing document, its PDF first.'],
            InvoiceHistoryRelationManager::class => ['admin' => true, 'partner' => false, 'reason' => 'Change history of a billing document.'],
            ProjectDocumentsRelationManager::class => ['admin' => true, 'partner' => false, 'reason' => 'Admin management of every project file.'],
            ProjectHistoryRelationManager::class => ['admin' => true, 'partner' => false, 'reason' => 'Change history of a project.'],
            TaskDocumentsRelationManager::class => ['admin' => true, 'partner' => false, 'reason' => 'Admin management of every task file.'],
            TaskHistoryRelationManager::class => ['admin' => true, 'partner' => false, 'reason' => 'Change history of a task.'],
            TimeEntryHistoryRelationManager::class => ['admin' => true, 'partner' => false, 'reason' => 'Change history of a time entry.'],
            TransactionDocumentsRelationManager::class => ['admin' => true, 'partner' => false, 'reason' => 'Receipts of a transaction.'],
            TransactionHistoryRelationManager::class => ['admin' => true, 'partner' => false, 'reason' => 'Change history of a transaction.'],
            ClientInvoicesRelationManager::class => ['admin' => true, 'partner' => false, 'reason' => 'Documents and amounts owed by a client.'],
            ClientProjectsRelationManager::class => ['admin' => true, 'partner' => false, 'reason' => 'Unbilled time and billing types of a client.'],
            ContactsRelationManager::class => ['admin' => true, 'partner' => false, 'reason' => 'Personal data of the contacts of a client.'],
            InvitationsRelationManager::class => ['admin' => true, 'partner' => false, 'reason' => 'Invitations that create Partner accounts.'],
            PartnerAccountsRelationManager::class => ['admin' => true, 'partner' => false, 'reason' => 'Partner accounts of a client.'],
            InvoiceEmailsRelationManager::class => ['admin' => true, 'partner' => false, 'reason' => 'Send log with recipients and bodies.'],
            InvoiceEntriesRelationManager::class => ['admin' => true, 'partner' => false, 'reason' => 'Time linked to a document, with rates and amounts.'],
            InvoicePaymentsRelationManager::class => ['admin' => true, 'partner' => false, 'reason' => 'Payments of a document.'],
            ProjectTasksTimeRelationManager::class => ['admin' => true, 'partner' => false, 'reason' => 'Worked time, estimates and billing state per task.'],
            ProjectTimeEntriesRelationManager::class => ['admin' => true, 'partner' => false, 'reason' => 'Measured time of a project.'],
            SubtasksRelationManager::class => ['admin' => true, 'partner' => false, 'reason' => 'Subtasks with the internal task fields.'],
            TaskCommentsRelationManager::class => ['admin' => true, 'partner' => false, 'reason' => 'Internal comments and the internal flag.'],

            // Test-only
            CanaryRecordResource::class => ['admin' => true, 'partner' => true, 'reason' => 'Canary resource of the isolation harness; registered only while the harness is on.', 'testOnly' => true],
        ];
    }

    /**
     * Every /api/v1 route, keyed by route name, with the one ability it demands. An Admin token carrying that
     * ability is not refused; a Partner token is answered 401 whatever its abilities.
     *
     * @return array<string, array{ability: string, admin: bool, partner: bool, reason: string}>
     */
    public static function apiEndpoints(): array
    {
        return [
            // ability time:read
            'api.v1.time-entries.index' => ['ability' => 'time:read', 'admin' => true, 'partner' => false, 'reason' => 'Read of time and lookups.'],
            'api.v1.time-entries.show' => ['ability' => 'time:read', 'admin' => true, 'partner' => false, 'reason' => 'Read of time and lookups.'],
            'api.v1.clients.index' => ['ability' => 'time:read', 'admin' => true, 'partner' => false, 'reason' => 'Read of time and lookups.'],
            'api.v1.clients.show' => ['ability' => 'time:read', 'admin' => true, 'partner' => false, 'reason' => 'Read of time and lookups.'],
            'api.v1.projects.index' => ['ability' => 'time:read', 'admin' => true, 'partner' => false, 'reason' => 'Read of time and lookups.'],
            'api.v1.projects.show' => ['ability' => 'time:read', 'admin' => true, 'partner' => false, 'reason' => 'Read of time and lookups.'],
            'api.v1.tasks.index' => ['ability' => 'time:read', 'admin' => true, 'partner' => false, 'reason' => 'Read of time and lookups.'],
            'api.v1.tasks.show' => ['ability' => 'time:read', 'admin' => true, 'partner' => false, 'reason' => 'Read of time and lookups.'],

            // ability time:write
            'api.v1.time-entries.store' => ['ability' => 'time:write', 'admin' => true, 'partner' => false, 'reason' => 'Write of time entries.'],
            'api.v1.time-entries.batch' => ['ability' => 'time:write', 'admin' => true, 'partner' => false, 'reason' => 'Write of time entries.'],
            'api.v1.time-entries.update' => ['ability' => 'time:write', 'admin' => true, 'partner' => false, 'reason' => 'Write of time entries.'],
            'api.v1.time-entries.destroy' => ['ability' => 'time:write', 'admin' => true, 'partner' => false, 'reason' => 'Write of time entries.'],

            // ability finance:read
            'api.v1.transactions.index' => ['ability' => 'finance:read', 'admin' => true, 'partner' => false, 'reason' => 'Read of finance.'],
            'api.v1.transactions.show' => ['ability' => 'finance:read', 'admin' => true, 'partner' => false, 'reason' => 'Read of finance.'],
            'api.v1.transaction-categories.index' => ['ability' => 'finance:read', 'admin' => true, 'partner' => false, 'reason' => 'Read of finance.'],
            'api.v1.transaction-categories.show' => ['ability' => 'finance:read', 'admin' => true, 'partner' => false, 'reason' => 'Read of finance.'],

            // ability finance:write
            'api.v1.transactions.store' => ['ability' => 'finance:write', 'admin' => true, 'partner' => false, 'reason' => 'Write of finance.'],
            'api.v1.transactions.batch' => ['ability' => 'finance:write', 'admin' => true, 'partner' => false, 'reason' => 'Write of finance.'],
            'api.v1.transactions.update' => ['ability' => 'finance:write', 'admin' => true, 'partner' => false, 'reason' => 'Write of finance.'],
            'api.v1.transactions.destroy' => ['ability' => 'finance:write', 'admin' => true, 'partner' => false, 'reason' => 'Write of finance.'],
        ];
    }

    /**
     * Every plain Livewire component under app/Livewire. They sit outside the panel registry, so each refuses a
     * Partner on its own.
     *
     * @return array<class-string, array{admin: bool, partner: bool, reason: string}>
     */
    public static function livewireComponents(): array
    {
        return [
            SignalDay::class => ['admin' => true, 'partner' => false, 'reason' => 'Daily planner card of the Admin.'],
            RecentEntriesPanel::class => ['admin' => true, 'partner' => false, 'reason' => 'Recent measured time entries of the Admin.'],
            TimerBar::class => ['admin' => true, 'partner' => false, 'reason' => 'Timer of the Admin.'],
        ];
    }

    /**
     * The Horizon dashboard: the Admin opens it, a Partner is answered 403 on every Horizon GET route.
     *
     * @return array{dashboard: string, prefix: string, admin: bool, partner: bool, reason: string}
     */
    public static function horizon(): array
    {
        return [
            'dashboard' => 'horizon.index',
            'prefix' => 'horizon.',
            'admin' => true,
            'partner' => false,
            'reason' => 'The dashboard lists job payloads, so only the Admin may open it.',
        ];
    }

    /**
     * What is wrong with the declaration itself; empty when it is fine. A row needs its keys, boolean answers and
     * a reason that is not empty or whitespace.
     *
     * @return list<string>
     */
    public static function problems(): array
    {
        $problems = [];

        foreach (['panel class' => self::panelClasses(), 'Livewire component' => self::livewireComponents()] as $kind => $rows) {
            foreach ($rows as $class => $row) {
                $problems = [...$problems, ...self::rowProblems("{$kind} {$class}", $row, ['admin', 'partner', 'reason'])];
            }
        }

        foreach (self::apiEndpoints() as $route => $row) {
            $problems = [...$problems, ...self::rowProblems("API route {$route}", $row, ['ability', 'admin', 'partner', 'reason'])];

            if (($row['admin'] ?? null) !== true || ($row['partner'] ?? null) !== false) {
                $problems[] = "API route {$route} must declare admin true and partner false";
            }
        }

        return [...$problems, ...self::rowProblems('Horizon', self::horizon(), ['dashboard', 'prefix', 'admin', 'partner', 'reason'])];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     * @return list<string>
     */
    private static function rowProblems(string $label, array $row, array $keys): array
    {
        $problems = [];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $row)) {
                $problems[] = "{$label} has no {$key}";
            }
        }

        foreach (['admin', 'partner'] as $key) {
            if (array_key_exists($key, $row) && ! is_bool($row[$key])) {
                $problems[] = "{$label} must declare {$key} as a boolean";
            }
        }

        if (array_key_exists('reason', $row) && (! is_string($row['reason']) || trim($row['reason']) === '')) {
            $problems[] = "{$label} has an empty reason";
        }

        return $problems;
    }
}
