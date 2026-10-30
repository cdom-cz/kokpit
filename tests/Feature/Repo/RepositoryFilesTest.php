<?php

declare(strict_types=1);

use App\Domain\Audit\ActivitySourceLabel;
use App\Domain\Clients\Models\ClientInvitation;
use App\Domain\Invoicing\Actions\QueuePaymentConfirmation;
use App\Domain\Invoicing\Enums\DocumentPaymentMethod;
use App\Domain\Invoicing\Enums\InvoiceEmailState;
use App\Domain\Invoicing\Support\CashReceiptPdfUrl;
use App\Domain\Notifications\Mail\MailText;
use App\Domain\Projects\Models\Project;
use App\Domain\Settings\Numbering\DocumentKind;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Database\CzechCollation;
use App\Filament\Support\DocumentColumns;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

/**
 * The public repository files must agree with each other and with the code:
 * LICENSE, the composer.json licence id, README, SECURITY.md and CONTRIBUTING.md.
 * Only presence and agreement are checked here; whether the README steps still
 * work is proven by the boot job in CI and by the clean-clone check at the phase gate.
 */

/**
 * The licence the owner chose in plan 02-01 (option agpl-only). A change of this
 * value is a licensing decision, so the test names it instead of accepting both ids.
 */
const REPO_LICENCE_ID = 'AGPL-3.0-only';

function repoFile(string $name): string
{
    $path = base_path($name);
    expect($path)->toBeFile();

    return (string) file_get_contents($path);
}

/**
 * @return array<string, mixed>
 */
function repoComposer(): array
{
    $decoded = json_decode(repoFile('composer.json'), true, flags: JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
}

/**
 * E-mail addresses in a text whose domain is not example.com. The pattern is
 * assembled at runtime so this file holds no complete address either.
 *
 * @return list<string>
 */
function repoForeignEmails(string $text): array
{
    $pattern = '/'.implode('', ['[A-Za-z0-9._%+-]+', '@', '([A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,})']).'/';
    preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);

    $foreign = [];
    foreach ($matches as $match) {
        if (strtolower($match[1]) !== implode('.', ['example', 'com'])) {
            $foreign[] = $match[0];
        }
    }

    return $foreign;
}

/**
 * Commands that appear on a line of an indented code block, in document order.
 *
 * @return list<string>
 */
function repoCodeBlockLines(string $markdown): array
{
    $lines = [];
    foreach (explode("\n", $markdown) as $line) {
        if (str_starts_with($line, '    ') && trim($line) !== '') {
            $lines[] = trim($line);
        }
    }

    return $lines;
}

it('ships the GNU AGPL v3 text as LICENSE', function () {
    $licence = repoFile('LICENSE');

    expect($licence)->toContain('GNU AFFERO GENERAL PUBLIC LICENSE')
        ->and($licence)->toContain('Version 3, 19 November 2007');
});

it('declares the chosen AGPL licence id in composer.json and names exactly that id in the README', function () {
    $declared = repoComposer()['license'] ?? null;

    expect($declared)->toBe(REPO_LICENCE_ID)
        ->and(repoFile('README.md'))->toContain(REPO_LICENCE_ID);
});

it('does not grant an or-any-later-version option the owner did not choose', function () {
    $readme = repoFile('README.md');

    expect($readme)->not->toContain('AGPL-3.0-or-later')
        ->and(strtolower($readme))->not->toContain('any later version')
        ->and(strtolower($readme))->not->toContain('or later');
});

it('lists the install commands of the README in the documented order', function () {
    $commands = [
        'git clone <repository URL>',
        'cd kokpit',
        'ddev start',
        'cp .env.example .env',
        'ddev composer install',
        'ddev artisan key:generate',
        'ddev artisan migrate',
        'ddev artisan kokpit:install',
    ];

    $lines = repoCodeBlockLines(repoFile('README.md'));

    $previous = -1;
    foreach ($commands as $command) {
        $position = array_search($command, $lines, true);

        expect($position)->not->toBeFalse("README install block misses: {$command}")
            ->and($position)->toBeGreaterThan($previous, "README install step out of order: {$command}");
        $previous = (int) $position;
    }
});

it('only documents artisan commands that exist', function () {
    $registered = array_keys(Artisan::all());

    preg_match_all('/ddev artisan ([a-z][a-z0-9:_-]*)/', repoFile('README.md'), $matches);
    preg_match_all('/artisan (kokpit:[a-z0-9:_-]+)/', repoFile('README.md'), $php);

    $documented = array_values(array_unique([...$matches[1], ...$php[1]]));

    expect($documented)->toContain('kokpit:install', 'kokpit:admin:reset-2fa', 'key:generate', 'migrate');

    foreach ($documented as $command) {
        expect($registered)->toContain($command);
    }
});

it('only documents composer scripts that exist', function () {
    $scripts = array_keys((array) (repoComposer()['scripts'] ?? []));

    foreach (['README.md', 'CONTRIBUTING.md'] as $file) {
        preg_match_all('/composer (test|partner-audit|lint|stan|check-licenses|licenses|ci)\b/', repoFile($file), $matches);

        foreach (array_unique($matches[1]) as $script) {
            // "composer licenses" is Composer's own command, not a project script, and must not be documented as one.
            expect(in_array($script, $scripts, true))->toBeTrue("{$file} documents composer {$script}");
        }
    }
});

it('explains TOTP recovery, the install-only password and the gates in the README', function () {
    $readme = repoFile('README.md');

    foreach ([
        'kokpit:admin:reset-2fa',
        'APP_PREVIOUS_KEYS',
        'KOKPIT_ADMIN_PASSWORD',
        'ddev composer ci',
        'bash scripts/tests/run.sh',
        'Fictional data only',
        'example.com',
        'lang/cs',
        'Europe/Prague',
        'CONTRIBUTING.md',
        'SECURITY.md',
    ] as $phrase) {
        expect($readme)->toContain($phrase);
    }
});

it('routes vulnerability reports privately and carries the leak runbook in SECURITY.md', function () {
    $security = repoFile('SECURITY.md');

    expect($security)->toContain('Report a vulnerability')
        ->and($security)->toContain('private vulnerability reporting')
        ->and($security)->toContain('Safe harbour')
        ->and($security)->toContain('Scope')
        ->and($security)->toContain('Supported versions')
        ->and($security)->toContain('Rotate or revoke it first')
        ->and($security)->toContain('Never attach real data');
});

it('keeps real addresses out of README, SECURITY.md and CONTRIBUTING.md', function () {
    foreach (['README.md', 'SECURITY.md', 'CONTRIBUTING.md'] as $file) {
        expect(repoForeignEmails(repoFile($file)))->toBe([], "{$file} contains an address outside example.com");
    }

    expect(strtolower(repoFile('SECURITY.md')))->not->toContain('mailto:');
});

it('reports an address outside example.com and accepts one inside it', function () {
    $foreign = implode('@', ['jane', implode('.', ['mail', 'test'])]);
    $own = implode('@', ['jane', implode('.', ['example', 'com'])]);

    expect(repoForeignEmails("write to {$foreign} or {$own}"))->toBe([$foreign])
        ->and(repoForeignEmails("only {$own}"))->toBe([]);
});

it('points CONTRIBUTING.md at SECURITY.md and carries a Development section', function () {
    $contributing = repoFile('CONTRIBUTING.md');

    expect($contributing)->toContain('## Development')
        ->and($contributing)->toContain('SECURITY.md')
        ->and($contributing)->not->toContain('will live in `SECURITY.md`');
});

it('lists every job of the Hygiene workflow in the CI section of CONTRIBUTING.md', function () {
    $contributing = repoFile('CONTRIBUTING.md');
    $workflow = Yaml::parseFile(base_path('.github/workflows/hygiene.yml'));
    $jobs = is_array($workflow) && is_array($workflow['jobs'] ?? null) ? $workflow['jobs'] : [];

    expect(array_keys($jobs))->not->toBeEmpty();

    foreach ($jobs as $id => $job) {
        $name = is_array($job) && is_string($job['name'] ?? null) ? $job['name'] : (string) $id;
        // Jobs are listed by id (scan, tests, ...) except the aggregator, which is listed by its display name.
        expect(str_contains($contributing, "`{$id}`") || str_contains($contributing, "`{$name}`"))
            ->toBeTrue("CONTRIBUTING.md does not mention the CI job {$id}");
    }
});

it('documents the manual GitHub and Zerops deploy settings in CONTRIBUTING.md', function () {
    $contributing = repoFile('CONTRIBUTING.md');

    expect($contributing)->toContain('## Deploy (maintainer, manual)');

    foreach ([
        'environment',
        'production',
        'required reviewer',
        'ZEROPS_TOKEN',
        'environment secret',
        'Git integration',
        'maxmemory-policy',
        'volatile-lru',
        'backward compatible',
        'rollback',
        'ZEROPS_PROJECT_ID',
        'ZEROPS_SERVICE_ID',
        '`kokpit`',
        'Zerops UI',
    ] as $phrase) {
        expect($contributing)->toContain($phrase);
    }
});

it('names the deploy readiness and storage checks in the README', function () {
    $readme = repoFile('README.md');

    expect($readme)->toContain('## Deploy')
        ->and($readme)->toContain('kokpit:deploy:verify')
        ->and($readme)->toContain('kokpit:storage:check');
});

it('names every Phase 3 operations mechanism in CONTRIBUTING.md and the named class exists', function () {
    $contributing = repoFile('CONTRIBUTING.md');

    // The documentation must not name a convention, class or trait that is gone (T-03-58).
    $mechanisms = [
        'App\Domain\Settings\Settings\ValidatedSettings',
        'App\Domain\Settings\SettingsMigration',
        'App\Domain\Settings\Numbering\DocumentNumbering',
        'App\Domain\Audit\LoggedAttributes',
        'App\Domain\Audit\LogsAllowlistedActivity',
        'App\Filament\RelationManagers\ActivityHistoryRelationManager',
        'App\Domain\Operations\Jobs\KokpitJob',
        'App\Domain\Operations\Jobs\Idempotent',
        'App\Domain\Operations\Health\HealthIndicatorRegistry',
        'App\Domain\Operations\Storage\StorageCheck',
    ];

    foreach ($mechanisms as $class) {
        $short = substr($class, (int) strrpos($class, '\\') + 1);

        expect($contributing)->toContain($short);
        expect(class_exists($class) || trait_exists($class) || interface_exists($class))
            ->toBeTrue("CONTRIBUTING.md names {$short} but {$class} does not exist");
    }
});

it('only names Phase 3 classes in CONTRIBUTING.md that exist in app/', function () {
    $contributing = repoFile('CONTRIBUTING.md');

    // Secondary names the Conventions section relies on; each is a class, enum or trait under app/.
    $names = [
        'ActivitySourceLabel' => 'App\Domain\Audit\ActivitySourceLabel',
        'ActivitySource' => 'App\Domain\Audit\ActivitySource',
        'KokpitCleanActivityLogAction' => 'App\Domain\Audit\KokpitCleanActivityLogAction',
        'RunsAsSystem' => 'App\Domain\Operations\Jobs\Middleware\RunsAsSystem',
        'ReportFailedJob' => 'App\Domain\Operations\Alerts\ReportFailedJob',
        'AdminAlerter' => 'App\Domain\Operations\Alerts\AdminAlerter',
        'HealthIndicator' => 'App\Domain\Operations\Health\HealthIndicator',
        'HealthSlot' => 'App\Domain\Operations\Health\HealthSlot',
        'SettingsProperty' => 'App\Domain\Shared\Models\SettingsProperty',
        'SequenceAllocator' => 'App\Domain\Shared\Sequences\SequenceAllocator',
    ];

    foreach ($names as $short => $class) {
        expect($contributing)->toContain($short);
        expect(class_exists($class) || trait_exists($class) || interface_exists($class) || enum_exists($class))
            ->toBeTrue("CONTRIBUTING.md names {$short} but {$class} does not exist");
    }

    expect(defined('App\Domain\Shared\Models\SettingsProperty::PARTNER_VISIBLE_GROUPS'))->toBeTrue()
        ->and($contributing)->toContain('PARTNER_VISIBLE_GROUPS');
});

it('only names Phase 4 classes in CONTRIBUTING.md that exist in app/', function () {
    $contributing = repoFile('CONTRIBUTING.md');

    // The documentation must not name a Phase 4 mechanism that is gone (T-04-50).
    $names = [
        'Project' => 'App\Domain\Projects\Models\Project',
        'ProjectBilling' => 'App\Domain\Projects\Models\ProjectBilling',
        'TagType' => 'App\Domain\Shared\Tags\TagType',
        'ProjectColumns' => 'App\Filament\Support\ProjectColumns',
        'RethrowsDomainValidation' => 'App\Filament\Concerns\RethrowsDomainValidation',
        'ClientInvitation' => 'App\Domain\Clients\Models\ClientInvitation',
        'AresClient' => 'App\Domain\Clients\Ares\AresClient',
        'ProjectKeySuggester' => 'App\Domain\Projects\ProjectKeySuggester',
        'AcceptInvitation' => 'App\Domain\Clients\Actions\AcceptInvitation',
        'FictionalCompanyId' => 'Tests\Support\FictionalCompanyId',
    ];

    foreach ($names as $short => $class) {
        expect($contributing)->toContain($short);
        expect(class_exists($class) || trait_exists($class) || interface_exists($class) || enum_exists($class))
            ->toBeTrue("CONTRIBUTING.md names {$short} but {$class} does not exist");
    }

    expect(method_exists(ClientInvitation::class, 'findAcceptable'))->toBeTrue()
        ->and($contributing)->toContain('findAcceptable')
        ->and(method_exists(Project::class, 'scopeSelectable'))->toBeTrue()
        ->and($contributing)->toContain('Project::selectable')
        ->and($contributing)->toContain('Audience::Guest')
        ->and(enum_exists(Audience::class))->toBeTrue()
        ->and(constant('App\Domain\Shared\Auth\Audience::Guest'))->not->toBeNull();
});

it('only names Phase 4 tests in CONTRIBUTING.md that exist', function () {
    $contributing = repoFile('CONTRIBUTING.md');

    preg_match_all('#`(tests/[A-Za-z0-9_/]+\.php)`#', $contributing, $matches);

    expect($matches[1])->not->toBeEmpty();

    foreach (array_unique($matches[1]) as $path) {
        expect(is_file(base_path($path)))->toBeTrue("CONTRIBUTING.md names {$path} but the file does not exist");
    }
});

it('documents the Phase 4 hand-over notes and the password reset path', function () {
    $contributing = repoFile('CONTRIBUTING.md');
    $readme = repoFile('README.md');

    expect($contributing)->toContain('task:<project uuid>')
        ->and($contributing)->toContain('PartnerSafeColumnsTest')
        ->and($contributing)->toContain('### Hand-over notes for later phases')
        ->and($readme)->not->toContain('There is no password recovery path for the Admin yet')
        ->and($readme)->toContain('forgot password');
});

it('documents the audit step in the add-a-model checklist and the operations section of the README', function () {
    $contributing = repoFile('CONTRIBUTING.md');
    $readme = repoFile('README.md');

    expect($contributing)->toContain('### Add-a-model checklist')
        ->and($contributing)->toContain('#[LoggedAttributes([...])]')
        ->and($readme)->toContain('## Operations')
        ->and($readme)->toContain('kokpit:storage:check')
        ->and(repoCodeBlockLines($readme))->toContain('ddev artisan kokpit:storage:check');
});

it('documents the project and service id variables the deploy workflow reads', function () {
    $workflow = repoFile('.github/workflows/deploy.yml');
    $contributing = repoFile('CONTRIBUTING.md');

    preg_match_all('/vars\.(ZEROPS_[A-Z_]+)/', $workflow, $matches);

    $names = array_values(array_unique($matches[1]));
    sort($names);

    // One service, one push, a project id and a service id variable; the per-setup variables of the earlier three-service layout are gone.
    expect($names)->toBe(['ZEROPS_PROJECT_ID', 'ZEROPS_SERVICE_ID'])
        ->and($contributing)->toContain('ZEROPS_PROJECT_ID')
        ->and($contributing)->toContain('ZEROPS_SERVICE_ID')
        ->and($contributing)->not->toMatch('/ZEROPS_('.implode('|', ['APP', 'WORKER', 'SCHEDULER']).')_SERVICE_ID/');
});

it('names the production environment variables for the Zerops UI and each is a documented key', function () {
    $contributing = repoFile('CONTRIBUTING.md');

    preg_match_all('/^#?\s*([A-Z][A-Z0-9_]*)=/m', repoFile('.env.example'), $matches);
    $documented = array_unique($matches[1]);

    foreach ([
        'APP_KEY', 'APP_URL', 'APP_ENV', 'APP_DEBUG', 'DB_HOST', 'DB_PASSWORD', 'REDIS_HOST', 'QUEUE_CONNECTION',
        'CACHE_STORE', 'SESSION_DRIVER', 'MAIL_MAILER', 'FILESYSTEM_DISK', 'S3_ACCESS_KEY_ID', 'S3_BUCKET', 'S3_ENDPOINT',
        'KOKPIT_REQUIRE_ADMIN_2FA', 'KOKPIT_CANARY_HARNESS',
    ] as $name) {
        expect($contributing)->toContain("`{$name}`")
            ->and($documented)->toContain($name);
    }
});

it('only names Phase 5 classes in CONTRIBUTING.md that exist in app/', function () {
    $contributing = repoFile('CONTRIBUTING.md');

    // The documentation must not name a Phase 5 mechanism that is gone (T-05-41).
    $names = [
        'CreateTask' => 'App\Domain\Tasks\Actions\CreateTask',
        'UpdateTaskDescription' => 'App\Domain\Tasks\Actions\UpdateTaskDescription',
        'TaskBoard' => 'App\Domain\Tasks\Board\TaskBoard',
        'MoveTask' => 'App\Domain\Tasks\Actions\MoveTask',
        'RichText' => 'App\Domain\Shared\Text\RichText',
        'TaskColumns' => 'App\Filament\Support\TaskColumns',
        'TaskBillingResolver' => 'App\Domain\Tasks\Billing\TaskBillingResolver',
        'TaskNotifier' => 'App\Domain\Tasks\Notifications\TaskNotifier',
        'TaskNotification' => 'App\Domain\Tasks\Notifications\TaskNotification',
        'NotificationPreferences' => 'App\Domain\Notifications\NotificationPreferences',
        'TaskComment' => 'App\Domain\Tasks\Models\TaskComment',
        'TaskBilling' => 'App\Domain\Tasks\Models\TaskBilling',
        'TaskChecklistItem' => 'App\Domain\Tasks\Models\TaskChecklistItem',
    ];

    foreach ($names as $short => $class) {
        expect($contributing)->toContain($short);
        expect(class_exists($class) || trait_exists($class) || interface_exists($class) || enum_exists($class))
            ->toBeTrue("CONTRIBUTING.md names {$short} but {$class} does not exist");
    }

    // Methods and constants the Phase 5 conventions rely on.
    expect(method_exists('App\Domain\Tasks\Board\TaskBoard', 'appendToColumn'))->toBeTrue()
        ->and(method_exists('App\Domain\Tasks\Board\TaskBoard', 'move'))->toBeTrue()
        ->and(method_exists('App\Domain\Shared\Text\RichText', 'clean'))->toBeTrue()
        ->and(method_exists('App\Domain\Shared\Text\RichText', 'render'))->toBeTrue()
        ->and(method_exists('App\Domain\Settings\Numbering\DocumentNumbering', 'nextTaskNumber'))->toBeTrue()
        ->and(method_exists('App\Domain\Tasks\Policies\TaskPolicy', 'editDescription'))->toBeTrue()
        ->and(defined('App\Domain\Tasks\Policies\TaskPolicy::DESCRIPTION_EDITABLE_STATUSES'))->toBeTrue()
        ->and(defined('App\Domain\Shared\Text\RichText::MAX_LENGTH'))->toBeTrue()
        ->and(defined('App\Filament\Support\TaskColumns::PARTNER_COLUMN_NAMES'))->toBeTrue()
        ->and(defined('App\Filament\Support\TaskColumns::PARTNER_ENTRY_NAMES'))->toBeTrue()
        ->and($contributing)->toContain('nextTaskNumber')
        ->and($contributing)->toContain('editDescription')
        ->and($contributing)->toContain('DESCRIPTION_EDITABLE_STATUSES')
        ->and($contributing)->toContain('appendToColumn')
        ->and($contributing)->toContain('RichText::clean()')
        ->and($contributing)->toContain('KP002')
        ->and($contributing)->toContain('projects_key_frozen_guard')
        ->and($contributing)->toContain('PARTNER_COLUMN_NAMES')
        ->and($contributing)->toContain('PARTNER_ENTRY_NAMES')
        // The backticked name: a plain substring would also match TaskNotificationsTest.
        ->and($contributing)->toContain('`TaskNotification`');
});

it('names the Phase 5 enforcing tests', function () {
    $contributing = repoFile('CONTRIBUTING.md');

    foreach ([
        'TaskActionsTest', 'TaskKeyTest', 'TaskNumberConcurrencyTest', 'TasksTableTest', 'TaskBoardTest',
        'TaskBoardConcurrencyTest', 'RichTextSanitiserTest', 'PartnerSafeColumnsTest', 'PartnerTaskVisibilityTest',
        'TaskBillingResolverTest', 'TaskNotificationsTest', 'NotificationLeakTest', 'NotificationMarkupTest',
        'PartnerTaskDescriptionTest',
    ] as $test) {
        expect($contributing)->toContain($test);
    }

    // The earlier single Phase 5 hand-over note is replaced, not kept next to the new ones.
    expect($contributing)->not->toContain('**Phase 5 (tasks).**');
});

it('only names Phase 6 classes in CONTRIBUTING.md that exist in app/', function () {
    $contributing = repoFile('CONTRIBUTING.md');

    // The documentation must not name a Phase 6 mechanism that is gone (research Pitfall 12).
    $names = [
        'StartTimer' => 'App\Domain\TimeTracking\Actions\StartTimer',
        'StopTimer' => 'App\Domain\TimeTracking\Actions\StopTimer',
        'CreateTimeEntry' => 'App\Domain\TimeTracking\Actions\CreateTimeEntry',
        'UpdateTimeEntry' => 'App\Domain\TimeTracking\Actions\UpdateTimeEntry',
        'DeleteTimeEntry' => 'App\Domain\TimeTracking\Actions\DeleteTimeEntry',
        'MarkEntriesBilled' => 'App\Domain\TimeTracking\Actions\MarkEntriesBilled',
        'CancelEntriesBilling' => 'App\Domain\TimeTracking\Actions\CancelEntriesBilling',
        'TimerLock' => 'App\Domain\TimeTracking\TimerLock',
        'TimerClock' => 'App\Domain\TimeTracking\Support\TimerClock',
        'TimerRaceLost' => 'App\Domain\TimeTracking\TimerRaceLost',
        'DurationFormat' => 'App\Domain\TimeTracking\Support\DurationFormat',
        'TimeEntryInput' => 'App\Domain\TimeTracking\TimeEntryInput',
        'TimeEntryRateResolver' => 'App\Domain\TimeTracking\Billing\TimeEntryRateResolver',
        'BillableDefault' => 'App\Domain\TimeTracking\Billing\BillableDefault',
        'OverlapFinder' => 'App\Domain\TimeTracking\Queries\OverlapFinder',
        'CzechCollation' => 'App\Domain\Shared\Database\CzechCollation',
        'RequiresAdmin' => 'App\Livewire\TimeTracking\RequiresAdmin',
        'TimeTotals' => 'App\Domain\TimeTracking\Queries\TimeTotals',
        'NotifyLongRunningTimers' => 'App\Domain\TimeTracking\Jobs\NotifyLongRunningTimers',
        'EndOverrunTimers' => 'App\Domain\TimeTracking\Actions\EndOverrunTimers',
        'EntryContextOptions' => 'App\Domain\TimeTracking\Queries\EntryContextOptions',
    ];

    foreach ($names as $short => $class) {
        expect($contributing)->toContain($short);
        expect(class_exists($class) || trait_exists($class) || interface_exists($class) || enum_exists($class))
            ->toBeTrue("CONTRIBUTING.md names {$short} but {$class} does not exist");
    }

    // Methods and constants the Phase 6 conventions rely on.
    expect(method_exists('App\Domain\TimeTracking\TimerLock', 'lock'))->toBeTrue()
        ->and(method_exists('App\Domain\Shared\Database\CzechCollation', 'orderBy'))->toBeTrue()
        ->and(method_exists('App\Domain\Shared\Database\CzechCollation', 'isAvailable'))->toBeTrue()
        ->and(method_exists('App\Filament\Resources\TimeEntryResource', 'entryFields'))->toBeTrue()
        ->and(method_exists('App\Domain\TimeTracking\TimeEntryInput', 'instant'))->toBeTrue()
        ->and(defined('App\Domain\Shared\Database\CzechCollation::NAME'))->toBeTrue()
        ->and($contributing)->toContain('CzechCollation::orderBy()')
        ->and($contributing)->toContain('CzechCollation::isAvailable()')
        ->and($contributing)->toContain('TimerLock::lock()')
        ->and($contributing)->toContain('TimeEntryInput::instant()')
        ->and($contributing)->toContain('time_entries_frozen_guard')
        ->and($contributing)->toContain('time_entries_one_running_per_user')
        ->and($contributing)->toContain('`billing_state`, `billed_at` and `duration_seconds`')
        ->and($contributing)->toContain('`RequiresAdmin`')
        ->and(CzechCollation::NAME)->toBe('cs-CZ-x-icu');
});

it('names the Phase 6 enforcing tests, and each of them exists', function () {
    $contributing = repoFile('CONTRIBUTING.md');

    $tests = [
        'TimerActionsTest' => 'tests/Feature/TimeTracking/TimerActionsTest.php',
        'TimeEntriesTableTest' => 'tests/Feature/Schema/TimeEntriesTableTest.php',
        'TimerConcurrencyTest' => 'tests/Concurrency/TimerConcurrencyTest.php',
        'TimeEntryActionsTest' => 'tests/Feature/TimeTracking/TimeEntryActionsTest.php',
        'BillingLockTest' => 'tests/Feature/TimeTracking/BillingLockTest.php',
        'EntryRateResolverTest' => 'tests/Feature/TimeTracking/EntryRateResolverTest.php',
        'BillableDefaultTest' => 'tests/Feature/TimeTracking/BillableDefaultTest.php',
        'DurationFormatTest' => 'tests/Unit/TimeTracking/DurationFormatTest.php',
        'CzechOrderingTest' => 'tests/Feature/TimeTracking/CzechOrderingTest.php',
        'LivewireComponentContractTest' => 'tests/Arch/LivewireComponentContractTest.php',
        'TimeLeakTest' => 'tests/Isolation/TimeLeakTest.php',
        'TimesheetTest' => 'tests/Feature/TimeTracking/TimesheetTest.php',
        'ProjectTimeOverviewTest' => 'tests/Feature/TimeTracking/ProjectTimeOverviewTest.php',
        'LongRunningTimerTest' => 'tests/Feature/TimeTracking/LongRunningTimerTest.php',
        'EndOverrunTimersTest' => 'tests/Feature/TimeTracking/EndOverrunTimersTest.php',
        'DeployVerifyCommandTest' => 'tests/Feature/Operations/DeployVerifyCommandTest.php',
    ];

    foreach ($tests as $short => $path) {
        expect($contributing)->toContain($short);
        expect(is_file(base_path($path)))->toBeTrue("CONTRIBUTING.md names {$short} but {$path} does not exist");
    }
});

it('only names Phase 7 classes in CONTRIBUTING.md that exist in app/', function () {
    $contributing = repoFile('CONTRIBUTING.md');

    // The documentation must not name a Phase 7 mechanism that is gone (research Pitfall 12).
    $names = [
        'ApiAbility' => 'App\Domain\Identity\ApiAbility',
        'ReferenceResolver' => 'App\Domain\Api\ReferenceResolver',
        'TimeEntryPayload' => 'App\Http\Requests\Api\V1\TimeEntryPayload',
        'EnsureIdempotency' => 'App\Http\Middleware\EnsureIdempotency',
        'IdempotencyStore' => 'App\Domain\Api\Idempotency\IdempotencyStore',
        'PurgeExpiredIdempotencyKeys' => 'App\Domain\Api\Jobs\PurgeExpiredIdempotencyKeys',
        'EnsureApiDocsGenerated' => 'App\Http\Middleware\EnsureApiDocsGenerated',
        'LabelApiActivitySource' => 'App\Http\Middleware\LabelApiActivitySource',
        'ApiExamples' => 'App\Http\Controllers\Api\V1\ApiExamples',
        'PragueDay' => 'App\Domain\TimeTracking\Support\PragueDay',
        'ActivitySourceLabel' => 'App\Domain\Audit\ActivitySourceLabel',
    ];

    foreach ($names as $short => $class) {
        expect($contributing)->toContain($short);
        expect(class_exists($class) || trait_exists($class) || interface_exists($class) || enum_exists($class))
            ->toBeTrue("CONTRIBUTING.md names {$short} but {$class} does not exist");
    }

    // The conventions the API documentation rests on: the constants the list controllers declare.
    foreach (['ClientController', 'ProjectController', 'TaskController', 'TimeEntryController'] as $controller) {
        expect(defined("App\\Http\\Controllers\\Api\\V1\\{$controller}::FILTERS"))->toBeTrue("{$controller} has no FILTERS constant")
            ->and(defined("App\\Http\\Controllers\\Api\\V1\\{$controller}::SORTS"))->toBeTrue("{$controller} has no SORTS constant");
    }

    expect($contributing)->toContain('ddev exec php artisan scribe:generate --no-interaction')
        ->and($contributing)->toContain('web, console, job, webhook, api')
        ->and($contributing)->toContain('999.1')
        ->and($contributing)->toContain('`FILTERS`')
        ->and($contributing)->toContain('`SORTS`')
        ->and($contributing)->toContain('/docs.openapi')
        ->and(ActivitySourceLabel::Api->value)->toBe('api');
});

it('names the Phase 7 enforcing tests, and each of them exists', function () {
    $contributing = repoFile('CONTRIBUTING.md');

    $tests = [
        'ApiAccessTest' => 'tests/Isolation/ApiAccessTest.php',
        'ApiDocumentationTest' => 'tests/Feature/Api/ApiDocumentationTest.php',
        'ApiDocumentationHygieneTest' => 'tests/Feature/Api/ApiDocumentationHygieneTest.php',
        'ZeropsConfigTest' => 'tests/Feature/Repo/ZeropsConfigTest.php',
        'IdempotencyTest' => 'tests/Feature/Api/IdempotencyTest.php',
        'IdempotencyConcurrencyTest' => 'tests/Concurrency/IdempotencyConcurrencyTest.php',
        'TimeEntryContractTest' => 'tests/Feature/Api/TimeEntryContractTest.php',
    ];

    foreach ($tests as $short => $path) {
        expect($contributing)->toContain($short);
        expect(is_file(base_path($path)))->toBeTrue("CONTRIBUTING.md names {$short} but {$path} does not exist");
    }
});

it('only names Phase 8 classes in CONTRIBUTING.md that exist in app/', function () {
    $contributing = repoFile('CONTRIBUTING.md');

    // The documentation must not name a Phase 8 mechanism that is gone (research Pitfall 12).
    $names = [
        'CnbClient' => 'App\Domain\ExchangeRates\Cnb\CnbClient',
        'DownloadCnbRates' => 'App\Domain\ExchangeRates\Actions\DownloadCnbRates',
        'HealCnbRateGaps' => 'App\Domain\ExchangeRates\Actions\HealCnbRateGaps',
        'BackfillCnbRates' => 'App\Domain\ExchangeRates\Jobs\BackfillCnbRates',
        'CzechPublicHolidays' => 'App\Domain\ExchangeRates\CzechPublicHolidays',
        'RateBook' => 'App\Domain\ExchangeRates\RateBook',
        'ExchangeRateConverter' => 'App\Domain\ExchangeRates\ExchangeRateConverter',
        'ExchangeRateMissing' => 'App\Domain\ExchangeRates\ExchangeRateMissing',
        'RateFormat' => 'App\Domain\ExchangeRates\RateFormat',
        'ExchangeRate' => 'App\Domain\ExchangeRates\Models\ExchangeRate',
        'LastRateDateIndicator' => 'App\Domain\Operations\Health\Indicators\LastRateDateIndicator',
        'TimeReportReader' => 'App\Domain\Reports\Queries\TimeReportReader',
        'EntryAmounts' => 'App\Domain\Reports\EntryAmounts',
        'TimeReport' => 'App\Domain\Reports\TimeReport',
        'FixedPriceSummary' => 'App\Domain\Reports\FixedPriceSummary',
        'ReportFilters' => 'App\Domain\Reports\ReportFilters',
        'SpreadsheetCells' => 'App\Domain\Reports\Exports\SpreadsheetCells',
        'BuildReportExport' => 'App\Domain\Reports\Jobs\BuildReportExport',
        'PruneExpiredReportExports' => 'App\Domain\Reports\Jobs\PruneExpiredReportExports',
        'ReportExport' => 'App\Domain\Reports\Models\ReportExport',
        'WorkReportPdf' => 'App\Domain\Reports\WorkReport\WorkReportPdf',
        'WorkReportSource' => 'App\Domain\Reports\WorkReport\WorkReportSource',
        'HoursStats' => 'App\Filament\Widgets\HoursStats',
        'UnbilledPerClient' => 'App\Filament\Widgets\UnbilledPerClient',
        'UpcomingDeadlines' => 'App\Filament\Widgets\UpcomingDeadlines',
        'TodayPlan' => 'App\Filament\Widgets\TodayPlan',
        'PartnerTasks' => 'App\Filament\Widgets\PartnerTasks',
        'PartnerProjects' => 'App\Filament\Widgets\PartnerProjects',
        'DashboardWidget' => 'App\Domain\Dashboard\Enums\DashboardWidget',
        'DashboardWidgetPreference' => 'App\Domain\Dashboard\Models\DashboardWidgetPreference',
        'DashboardWidgetPreferencePolicy' => 'App\Domain\Dashboard\Policies\DashboardWidgetPreferencePolicy',
        'SetDashboardWidgetCollapsed' => 'App\Domain\Dashboard\Actions\SetDashboardWidgetCollapsed',
        'CollapsedWidgets' => 'App\Domain\Dashboard\Queries\CollapsedWidgets',
        'CollapsibleDashboardWidget' => 'App\Filament\Concerns\CollapsibleDashboardWidget',
        'TaskDueDate' => 'App\Filament\Support\TaskDueDate',
        'ToggleTaskChecklistItem' => 'App\Domain\Tasks\Actions\ToggleTaskChecklistItem',
        'TimeReportPage' => 'App\Filament\Pages\TimeReportPage',
        'ExchangeRateResource' => 'App\Filament\Resources\ExchangeRateResource',
        'ReportExportResource' => 'App\Filament\Resources\ReportExportResource',
    ];

    foreach ($names as $short => $class) {
        expect($contributing)->toContain($short);
        expect(class_exists($class) || trait_exists($class) || interface_exists($class) || enum_exists($class))
            ->toBeTrue("CONTRIBUTING.md names {$short} but {$class} does not exist");
    }

    // Methods the Phase 8 conventions rely on, and the spelling the text uses for each of them.
    $methods = [
        ['App\Domain\Shared\Money\Money', 'forDurationConverted', 'Money::forDurationConverted'],
        ['App\Domain\Shared\Money\Money', 'hourlyRateFor', 'Money::hourlyRateFor'],
        ['App\Domain\ExchangeRates\ExchangeRateConverter', 'toCzk', 'ExchangeRateConverter::toCzk'],
        ['App\Domain\ExchangeRates\RateBook', 'load', 'RateBook::load'],
        ['App\Domain\Reports\WorkReport\WorkReportSource', 'fromEntryIds', 'WorkReportSource::fromEntryIds'],
        ['App\Domain\Reports\WorkReport\WorkReportSource', 'forPeriod', 'WorkReportSource::forPeriod'],
        ['App\Domain\Reports\FixedPriceSummary', 'for', 'FixedPriceSummary::for'],
        ['App\Domain\ExchangeRates\Jobs\BackfillCnbRates', 'violations', 'BackfillCnbRates::violations'],
        ['App\Domain\Reports\Models\ReportExport', 'isDownloadable', 'isDownloadable'],
    ];

    foreach ($methods as [$class, $method, $spelled]) {
        expect(method_exists($class, $method))->toBeTrue("{$class}::{$method} does not exist");
        expect($contributing)->toContain($spelled);
    }

    // The phrases that carry a rule of the phase, and the file of the coverage matrix.
    foreach ([
        'COVERAGE.md', 'validFor', 'kokpit:rates:download', 'kokpit-cnb-rates-final', 'Dompdf', 'LGPL-2.1',
        'Dashboard::getWidgets()', 'in_review', 'PartnerContext',
    ] as $phrase) {
        expect($contributing)->toContain($phrase);
    }

    // The coverage matrix the text points at exists, in the phase directory.
    expect(is_file(base_path('.planning/phases/08-exchange-rates-and-reports/COVERAGE.md')))->toBeTrue();
});

it('names the Phase 8 enforcing tests, and each of them exists', function () {
    $contributing = repoFile('CONTRIBUTING.md');

    $tests = [
        'ExchangeRatesTableTest' => 'tests/Feature/Schema/ExchangeRatesTableTest.php',
        'ReportExportsTableTest' => 'tests/Feature/Schema/ReportExportsTableTest.php',
        'DownloadCnbRatesTest' => 'tests/Feature/ExchangeRates/DownloadCnbRatesTest.php',
        'RateAlertTest' => 'tests/Feature/ExchangeRates/RateAlertTest.php',
        'HealCnbRateGapsTest' => 'tests/Feature/ExchangeRates/HealCnbRateGapsTest.php',
        'BackfillCnbRatesTest' => 'tests/Feature/ExchangeRates/BackfillCnbRatesTest.php',
        'ExchangeRateConverterTest' => 'tests/Feature/ExchangeRates/ExchangeRateConverterTest.php',
        'ExchangeRateResourceTest' => 'tests/Feature/ExchangeRates/ExchangeRateResourceTest.php',
        'RatesContractTest' => 'tests/Feature/ExchangeRates/RatesContractTest.php',
        'CzechPublicHolidaysTest' => 'tests/Unit/ExchangeRates/CzechPublicHolidaysTest.php',
        'RateFormatTest' => 'tests/Unit/ExchangeRates/RateFormatTest.php',
        'LastRateDateIndicatorTest' => 'tests/Feature/Operations/LastRateDateIndicatorTest.php',
        'MoneyTest' => 'tests/Unit/Money/MoneyTest.php',
        'TimeReportTest' => 'tests/Feature/Reports/TimeReportTest.php',
        'EffectiveBillingParityTest' => 'tests/Feature/Reports/EffectiveBillingParityTest.php',
        'EffectiveRateTest' => 'tests/Feature/Reports/EffectiveRateTest.php',
        'ReportFiltersTest' => 'tests/Feature/Reports/ReportFiltersTest.php',
        'ReportExportTest' => 'tests/Feature/Reports/ReportExportTest.php',
        'ReportExportRecordTest' => 'tests/Feature/Reports/ReportExportRecordTest.php',
        'ReportExportResourceTest' => 'tests/Feature/Reports/ReportExportResourceTest.php',
        'ReportExportPruneTest' => 'tests/Feature/Reports/ReportExportPruneTest.php',
        'SpreadsheetCellsTest' => 'tests/Unit/Reports/SpreadsheetCellsTest.php',
        'WorkReportPdfTest' => 'tests/Feature/Reports/WorkReportPdfTest.php',
        'WorkReportDownloadTest' => 'tests/Feature/Reports/WorkReportDownloadTest.php',
        'LicenceCheckTest' => 'tests/Unit/LicenceCheckTest.php',
        'AdminDashboardTest' => 'tests/Feature/Dashboard/AdminDashboardTest.php',
        'PartnerDashboardTest' => 'tests/Feature/Dashboard/PartnerDashboardTest.php',
        'TimeLeakTest' => 'tests/Isolation/TimeLeakTest.php',
        'CanaryRegistryTest' => 'tests/Isolation/CanaryRegistryTest.php',
        'ScheduleOnOneServerTest' => 'tests/Feature/Operations/ScheduleOnOneServerTest.php',
        'JobContractTest' => 'tests/Arch/JobContractTest.php',
    ];

    foreach ($tests as $short => $path) {
        expect($contributing)->toContain($short);
        expect(is_file(base_path($path)))->toBeTrue("CONTRIBUTING.md names {$short} but {$path} does not exist");
    }
});

it('only names Phase 9 classes in CONTRIBUTING.md that exist in app/', function () {
    $contributing = repoFile('CONTRIBUTING.md');

    // The documentation must not name a Phase 9 mechanism that is gone.
    $names = [
        'AttachDocuments' => 'App\Domain\Documents\Actions\AttachDocuments',
        'DeleteDocument' => 'App\Domain\Documents\Actions\DeleteDocument',
        'RequestDocumentArchive' => 'App\Domain\Documents\Actions\RequestDocumentArchive',
        'DocumentOwnerType' => 'App\Domain\Documents\Enums\DocumentOwnerType',
        'BuildDocumentArchive' => 'App\Domain\Documents\Jobs\BuildDocumentArchive',
        'PruneExpiredDocumentArchives' => 'App\Domain\Documents\Jobs\PruneExpiredDocumentArchives',
        'RemoveDocumentObjects' => 'App\Domain\Documents\Jobs\RemoveDocumentObjects',
        'Document' => 'App\Domain\Documents\Models\Document',
        'DocumentArchive' => 'App\Domain\Documents\Models\DocumentArchive',
        'DocumentArchiveReadyNotification' => 'App\Domain\Documents\Notifications\DocumentArchiveReadyNotification',
        'DocumentArchiveFailedNotification' => 'App\Domain\Documents\Notifications\DocumentArchiveFailedNotification',
        'DocumentPolicy' => 'App\Domain\Documents\Policies\DocumentPolicy',
        'ArchiveEntryNames' => 'App\Domain\Documents\Support\ArchiveEntryNames',
        'DocumentDisposition' => 'App\Domain\Documents\Support\DocumentDisposition',
        'DocumentFileName' => 'App\Domain\Documents\Support\DocumentFileName',
        'DocumentOwnerResolver' => 'App\Domain\Documents\Support\DocumentOwnerResolver',
        'DocumentUrl' => 'App\Domain\Documents\Support\DocumentUrl',
        'DocumentFileController' => 'App\Http\Controllers\DocumentFileController',
        'DocumentArchiveFileController' => 'App\Http\Controllers\DocumentArchiveFileController',
        'ManagesDocuments' => 'App\Filament\Concerns\ManagesDocuments',
        'ShowsPartnerDocuments' => 'App\Filament\Concerns\ShowsPartnerDocuments',
        'DocumentColumns' => 'App\Filament\Support\DocumentColumns',
        'DocumentActions' => 'App\Filament\Support\DocumentActions',
        'Transaction' => 'App\Domain\Finance\Models\Transaction',
        'TransactionCategory' => 'App\Domain\Finance\Models\TransactionCategory',
        'CzkSource' => 'App\Domain\Finance\Enums\CzkSource',
        'RecordTransaction' => 'App\Domain\Finance\Actions\RecordTransaction',
        'UpdateTransaction' => 'App\Domain\Finance\Actions\UpdateTransaction',
        'DeleteTransaction' => 'App\Domain\Finance\Actions\DeleteTransaction',
        'SaveTransactionCategory' => 'App\Domain\Finance\Actions\SaveTransactionCategory',
        'MoveTransactionCategory' => 'App\Domain\Finance\Actions\MoveTransactionCategory',
        'ArchiveTransactionCategory' => 'App\Domain\Finance\Actions\ArchiveTransactionCategory',
        'DeleteTransactionCategory' => 'App\Domain\Finance\Actions\DeleteTransactionCategory',
        'TransactionInput' => 'App\Domain\Finance\TransactionInput',
        'TransactionCzk' => 'App\Domain\Finance\TransactionCzk',
        'FinanceFilters' => 'App\Domain\Finance\FinanceFilters',
        'FinanceOverview' => 'App\Domain\Finance\Queries\FinanceOverview',
    ];

    foreach ($names as $short => $class) {
        expect($contributing)->toContain($short);
        expect(class_exists($class) || trait_exists($class) || interface_exists($class) || enum_exists($class))
            ->toBeTrue("CONTRIBUTING.md names {$short} but {$class} does not exist");
    }

    // Methods and constants the Phase 9 conventions rely on, and the spelling the text uses for each of them.
    $methods = [
        ['App\Domain\Documents\Models\Document', 'scopeWithoutArchivedOwners', 'Document::withoutArchivedOwners()'],
        ['App\Domain\Documents\Models\Document', 'scopeOnlyArchivedOwners', 'Document::onlyArchivedOwners()'],
        ['App\Domain\Documents\Models\Document', 'constrainForPartner', 'Document::constrainForPartner()'],
        ['App\Domain\Finance\Models\TransactionCategory', 'invoicePayments', 'TransactionCategory::invoicePayments()'],
        ['App\Domain\ExchangeRates\ExchangeRateConverter', 'rateFor', 'ExchangeRateConverter::rateFor()'],
    ];

    foreach ($methods as [$class, $method, $spelled]) {
        expect(method_exists($class, $method))->toBeTrue("{$class}::{$method} does not exist");
        expect($contributing)->toContain($spelled);
    }

    expect(DocumentColumns::PARTNER_COLUMN_NAMES)->not->toBe([])
        ->and($contributing)->toContain('PARTNER_COLUMN_NAMES');

    // The phrases that carry a rule of the phase, the schedule event and the bullets themselves.
    foreach ([
        '- **Documents.**', '- **Finance.**', 'fileAttachments(false)', 'kokpit-document-archive-prune', 'D-21', 'D-22',
        'consistencyViolations()', 'streamed through the application',
    ] as $phrase) {
        expect($contributing)->toContain($phrase);
    }

    // The schedule event the text names exists.
    expect(repoFile('routes/console.php'))->toContain('kokpit-document-archive-prune');

    // The old toCzk() hand-over note is gone with the Phase 9 note; the converter is still named for rates in general.
    expect($contributing)->not->toContain('Transaction amounts in CZK (FI-01) come through');
});

it('names the Phase 9 enforcing tests, and each of them exists', function () {
    $contributing = repoFile('CONTRIBUTING.md');

    $tests = [
        'AttachDocumentsTest' => 'tests/Feature/Documents/AttachDocumentsTest.php',
        'PartnerUploadTest' => 'tests/Feature/Documents/PartnerUploadTest.php',
        'DeleteDocumentTest' => 'tests/Feature/Documents/DeleteDocumentTest.php',
        'DocumentFileRouteTest' => 'tests/Feature/Documents/DocumentFileRouteTest.php',
        'DocumentResourceTest' => 'tests/Feature/Documents/DocumentResourceTest.php',
        'DocumentsRelationManagerTest' => 'tests/Feature/Documents/DocumentsRelationManagerTest.php',
        'CommentAttachmentsTest' => 'tests/Feature/Documents/CommentAttachmentsTest.php',
        'StorageTotalsTest' => 'tests/Feature/Documents/StorageTotalsTest.php',
        'ArchivedOwnerDocumentsTest' => 'tests/Feature/Documents/ArchivedOwnerDocumentsTest.php',
        'DocumentOwnerDeletionTest' => 'tests/Feature/Documents/DocumentOwnerDeletionTest.php',
        'DocumentArchiveTest' => 'tests/Feature/Documents/DocumentArchiveTest.php',
        'DocumentArchiveRecordTest' => 'tests/Feature/Documents/DocumentArchiveRecordTest.php',
        'DocumentArchivePruneTest' => 'tests/Feature/Documents/DocumentArchivePruneTest.php',
        'DocumentArchiveCleanupTest' => 'tests/Feature/Documents/DocumentArchiveCleanupTest.php',
        'RemoveDocumentObjectsTest' => 'tests/Feature/Documents/RemoveDocumentObjectsTest.php',
        'DocumentArchiveResourceTest' => 'tests/Feature/Documents/DocumentArchiveResourceTest.php',
        'UploadConfigTest' => 'tests/Feature/Documents/UploadConfigTest.php',
        'TemporaryUploadS3Test' => 'tests/Feature/Documents/TemporaryUploadS3Test.php',
        'DocumentStorageConsistencyTest' => 'tests/Feature/Documents/DocumentStorageConsistencyTest.php',
        'DocumentVisibilityTest' => 'tests/Isolation/DocumentVisibilityTest.php',
        'DocumentFinanceLeakTest' => 'tests/Isolation/DocumentFinanceLeakTest.php',
        'DocumentsTableTest' => 'tests/Feature/Schema/DocumentsTableTest.php',
        'TransactionCzkTest' => 'tests/Feature/Finance/TransactionCzkTest.php',
        'CategoryRulesTest' => 'tests/Feature/Finance/CategoryRulesTest.php',
        'TransactionResourceTest' => 'tests/Feature/Finance/TransactionResourceTest.php',
        'TransactionCategoryResourceTest' => 'tests/Feature/Finance/TransactionCategoryResourceTest.php',
        'TransactionReceiptsTest' => 'tests/Feature/Finance/TransactionReceiptsTest.php',
        'FinanceOverviewTest' => 'tests/Feature/Finance/FinanceOverviewTest.php',
        'TransactionTablesTest' => 'tests/Feature/Schema/TransactionTablesTest.php',
        'TransactionCategoriesTableTest' => 'tests/Feature/Schema/TransactionCategoriesTableTest.php',
    ];

    foreach ($tests as $short => $path) {
        expect($contributing)->toContain($short);
        expect(is_file(base_path($path)))->toBeTrue("CONTRIBUTING.md names {$short} but {$path} does not exist");
    }
});

it('tells the operator what the Phase 9 documents need from the bucket', function () {
    $readme = repoFile('README.md');

    expect($readme)->toContain('### Documents and finance')
        ->and($readme)->toContain('CORS')
        ->and($readme)->toContain('livewire-tmp/')
        ->and($readme)->toContain('php artisan livewire:configure-s3-upload-cleanup')
        ->and($readme)->toContain('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK')
        ->and($readme)->toContain('streamed through the application')
        ->and($readme)->toContain('7 days');
});

it('hands over to Phases 7, 10 and 12, carries no Phase 8, Phase 9 or Phase 11 note and no longer carries the Phase 6 note or the stale labels', function () {
    $contributing = repoFile('CONTRIBUTING.md');

    foreach (['Phase 7 (', 'Phase 10 (', 'Phase 12.'] as $note) {
        expect($contributing)->toContain("**{$note}");
    }

    // The Phase 6 note is gone now that the phase is built, and so are the Phase 8 and the Phase 9 notes; the labels that
    // did not match the ROADMAP are gone with them.
    expect($contributing)->not->toContain('**Phase 6 (')
        ->and($contributing)->not->toContain('**Phase 8 (')
        ->and($contributing)->not->toContain('**Phase 9 (')
        ->and($contributing)->not->toContain('**Phase 7 (calendar and reports)')
        ->and($contributing)->not->toContain('**Phase 8 (exports)')
        ->and($contributing)->not->toContain('**Phase 12 (')
        ->and($contributing)->not->toContain('**Phase 11 (')
        ->and($contributing)->toContain('**Phase 7 (REST API).**')
        ->and($contributing)->toContain('**Phase 10 (invoicing).**')
        ->and($contributing)->toContain('**Phase 12.**');

    $phaseTen = substr($contributing, (int) strpos($contributing, '**Phase 10 (invoicing).**'));
    $phaseTen = substr($phaseTen, 0, (int) strpos($phaseTen, '**Phase 12.**'));

    // What Phase 10 built and the rules later phases inherit (plan 10-32): the single writer of the billing columns
    // (R-09-2 replaces the Phase 6 wording), the issue pipeline and its order, the guards and their limits, the payment
    // and income rules, the single rounding, the frozen appearance and the licence notes.
    foreach ([
        'EntryBilling', 'R-09-2', 'DocumentIssuer', 'last write on the `invoices` row', 'a locked `invoices` row and its items and item sources accept no write', 'R-04-4', 'R-04-5', 'R-04-6',
        'R-06-1', 'R-06-6', 'amending D-13', 'DocumentOwnerType::Invoice', 'DocumentOwnerResolver', 'StoreGeneratedDocument', 'AttachDocuments',
        'archived-owner scopes', 'InvoicePdfController', 'files.invoice', 'DeletePayment', 'kokpit.payment_deletion', 'R-19-1', 'R-41-1 to R-41-5',
        'D-19 as amended on 2026-10-17', 'TransactionCategory::invoicePayments()', 'TransactionCzk', 'RecordPayment', 'only in its date and category',
        'Transactions API', 'Money::timesThroughCzk()', 'R-01-1', 'R-17-1', 'ExchangeRateConverter', 'R-03-1', 'DocumentImage', 'OFL-1.1',
        'dompdf/dompdf', 'TimeEntryRateResolver', 'TaskBillingResolver', 'WorkReportSource::fromEntryIds()', 'WorkReportPdf', 'Dashboard',
        'time_entries_frozen_guard', 'MarkEntriesBilled', 'InvoiceLeakTest',
    ] as $phrase) {
        expect($phaseTen)->toContain($phrase);
    }

    // What Phase 11 built and the rules later phases inherit: the pay button, payment matching, the kind-scoped variable
    // symbol and the only write paths (the Card payments bullet of the conventions).
    $cardPayments = repoContributingBullet('Card payments.');

    foreach (['ViewPartnerInvoice', 'R-07-4', 'VariableSymbol', 'invoice_payments', 'RecordPayment', 'DeletePayment', 'UnpaidInvoices', 'Dashboard::getWidgets()'] as $phrase) {
        expect($cardPayments)->toContain($phrase);
    }

    // The superseded Phase 6 wording is gone from the time entry rule as well.
    expect($contributing)->not->toContain('Only `MarkEntriesBilled` and `CancelEntriesBilling` change the billed state');
});

it('only names Phase 10 classes, methods and tests in CONTRIBUTING.md that exist', function () {
    $contributing = repoFile('CONTRIBUTING.md');

    // The documentation must not name a Phase 10 mechanism that is gone.
    $names = [
        'EntryBilling' => 'App\Domain\TimeTracking\Billing\EntryBilling',
        'TimeEntryRateResolver' => 'App\Domain\TimeTracking\Billing\TimeEntryRateResolver',
        'TaskBillingResolver' => 'App\Domain\Tasks\Billing\TaskBillingResolver',
        'EntryAmounts' => 'App\Domain\Reports\EntryAmounts',
        'CreateDraft' => 'App\Domain\Invoicing\Actions\CreateDraft',
        'UpdateDraft' => 'App\Domain\Invoicing\Actions\UpdateDraft',
        'AddEntriesToDraft' => 'App\Domain\Invoicing\Actions\AddEntriesToDraft',
        'AddFixedAmount' => 'App\Domain\Invoicing\Actions\AddFixedAmount',
        'ReleaseEntries' => 'App\Domain\Invoicing\Actions\ReleaseEntries',
        'DeleteDraft' => 'App\Domain\Invoicing\Actions\DeleteDraft',
        'IssueDocument' => 'App\Domain\Invoicing\Actions\IssueDocument',
        'ConvertProforma' => 'App\Domain\Invoicing\Actions\ConvertProforma',
        'IssueCreditNote' => 'App\Domain\Invoicing\Actions\IssueCreditNote',
        'DocumentIssuer' => 'App\Domain\Invoicing\Issuing\DocumentIssuer',
        'IssueContext' => 'App\Domain\Invoicing\Issuing\IssueContext',
        'RecordPayment' => 'App\Domain\Invoicing\Actions\RecordPayment',
        'DeletePayment' => 'App\Domain\Invoicing\Actions\DeletePayment',
        'RegeneratePdf' => 'App\Domain\Invoicing\Actions\RegeneratePdf',
        'QueueInvoiceEmail' => 'App\Domain\Invoicing\Actions\QueueInvoiceEmail',
        'SendInvoiceEmail' => 'App\Domain\Invoicing\Jobs\SendInvoiceEmail',
        'InvoicePdf' => 'App\Domain\Invoicing\Pdf\InvoicePdf',
        'InvoicePdfUrl' => 'App\Domain\Invoicing\Support\InvoicePdfUrl',
        'InvoicePdfController' => 'App\Http\Controllers\InvoicePdfController',
        'InvoiceAdmin' => 'App\Domain\Invoicing\Models\InvoiceAdmin',
        'InvoicePayment' => 'App\Domain\Invoicing\Models\InvoicePayment',
        'InvoiceBalances' => 'App\Domain\Invoicing\Queries\InvoiceBalances',
        'ReceivablesReader' => 'App\Domain\Invoicing\Queries\ReceivablesReader',
        'VariableSymbol' => 'App\Domain\Invoicing\Support\VariableSymbol',
        'StoreGeneratedDocument' => 'App\Domain\Documents\Actions\StoreGeneratedDocument',
        'DocumentImage' => 'App\Domain\Settings\DocumentImage',
        'PartnerInvoiceResource' => 'App\Filament\Partner\Resources\PartnerInvoiceResource',
        'ViewPartnerInvoice' => 'App\Filament\Partner\Resources\PartnerInvoiceResource\Pages\ViewPartnerInvoice',
        'InvoiceResource' => 'App\Filament\Resources\InvoiceResource',
        'UnpaidInvoices' => 'App\Filament\Widgets\UnpaidInvoices',
        'WorkReportSource' => 'App\Domain\Reports\WorkReport\WorkReportSource',
        'WorkReportPdf' => 'App\Domain\Reports\WorkReport\WorkReportPdf',
        'TransactionCzk' => 'App\Domain\Finance\TransactionCzk',
        'ExchangeRateConverter' => 'App\Domain\ExchangeRates\ExchangeRateConverter',
    ];

    foreach ($names as $short => $class) {
        expect($contributing)->toContain($short);
        expect(class_exists($class) || trait_exists($class) || interface_exists($class) || enum_exists($class))
            ->toBeTrue("CONTRIBUTING.md names {$short} but {$class} does not exist");
    }

    // Methods, enum cases and constants the text spells out.
    expect(method_exists('App\Domain\Shared\Money\Money', 'timesThroughCzk'))->toBeTrue()
        ->and(method_exists('App\Domain\Invoicing\Support\InvoicePdfUrl', 'download'))->toBeTrue()
        ->and(method_exists('App\Domain\Reports\WorkReport\WorkReportSource', 'fromEntryIds'))->toBeTrue()
        ->and(method_exists('App\Domain\Finance\Models\TransactionCategory', 'invoicePayments'))->toBeTrue()
        ->and(method_exists('App\Filament\Pages\Dashboard', 'getWidgets'))->toBeTrue()
        ->and(constant('App\Domain\Documents\Enums\DocumentOwnerType::Invoice'))->not->toBeNull();

    // The signed route the text names exists, and the deletion flag is set by DeletePayment alone.
    expect(repoFile('app/Providers/Filament/AdminPanelProvider.php'))->toContain("->name('files.invoice')")
        ->and(repoFile('app/Domain/Invoicing/Actions/DeletePayment.php'))->toContain('kokpit.payment_deletion');

    // The enforcing tests the text names.
    foreach ([
        'tests/Isolation/InvoiceLeakTest.php', 'tests/Arch/PaymentDeletionPathTest.php',
    ] as $path) {
        expect($contributing)->toContain(basename($path, '.php'));
        expect(is_file(base_path($path)))->toBeTrue("CONTRIBUTING.md names {$path} but the file does not exist");
    }
});

it('hands the send-driven lock over to later phases: the Immutability rule and the Editable invoices bullet say who may lock, unlock and edit, and name only what exists', function () {
    $contributing = repoFile('CONTRIBUTING.md');
    $immutability = repoContributingBullet('Immutability.');
    $bullet = repoContributingBullet('Editable invoices.');

    // The superseded wording (an issued row accepts no write at all) is gone, rewritten and not relaxed (plan 10.1-10).
    expect($contributing)->not->toContain('accept no write at all')
        ->and($contributing)->not->toContain('accepts no write at all')
        ->and($immutability)->toContain('issued documents once locked by a successful send')
        ->and($immutability)->toContain("or by the Admin's manual lock")
        ->and($immutability)->toContain('credit notes from their issue')
        ->and($immutability)->toContain('billed time entries')
        ->and($immutability)->toContain('always frozen')
        ->and($immutability)->toContain('`Immutability`')
        ->and($immutability)->toContain('KP001');

    expect($bullet)->not->toBe('');

    foreach ([
        '`invoices.lock_state`', '`kokpit_guard_invoice_row()`', 'The code that locks is `SendInvoiceEmail`', '`LockInvoice`', 'nothing but `SendInvoiceEmail` and `LockInvoice` may lock', 'the only code that unlocks is `UnlockInvoice`',
        '`kokpit.invoice_unlock`', '`UpdateIssuedDocument`', '`AddEntriesToDraft`', '`ReleaseEntries`', '`AddFixedAmount`', '`IssuedEditFinisher`',
        '`PaymentLinks::reconcile()`', '`ReplaceIssuedPdf`', '`InvoiceAudit`', '`IssuedDocumentRules`', '`email_in_flight`', '`invoice_edited`',
        '`kokpit_invoice_accepts_time_changes()`', '`payment_links_invoice_fk`', 'must not call the lock step',
        '`RecordCardPayment`', '`kokpit_guard_card_payment_currency()`', '`InvoiceEmail::staleBefore()`', '`bank_fingerprint`',
    ] as $phrase) {
        expect($bullet)->toContain($phrase);
    }

    $classes = [
        'SendInvoiceEmail' => 'App\Domain\Invoicing\Jobs\SendInvoiceEmail',
        'UnlockInvoice' => 'App\Domain\Invoicing\Actions\UnlockInvoice',
        'LockInvoice' => 'App\Domain\Invoicing\Actions\LockInvoice',
        'UpdateIssuedDocument' => 'App\Domain\Invoicing\Actions\UpdateIssuedDocument',
        'AddEntriesToDraft' => 'App\Domain\Invoicing\Actions\AddEntriesToDraft',
        'ReleaseEntries' => 'App\Domain\Invoicing\Actions\ReleaseEntries',
        'AddFixedAmount' => 'App\Domain\Invoicing\Actions\AddFixedAmount',
        'IssuedEditFinisher' => 'App\Domain\Invoicing\Support\IssuedEditFinisher',
        'PaymentLinks' => 'App\Domain\Payments\Support\PaymentLinks',
        'ReplaceIssuedPdf' => 'App\Domain\Invoicing\Actions\ReplaceIssuedPdf',
        'InvoiceAudit' => 'App\Domain\Invoicing\Support\InvoiceAudit',
        'IssuedDocumentRules' => 'App\Domain\Invoicing\Support\IssuedDocumentRules',
        'RecordCardPayment' => 'App\Domain\Payments\Actions\RecordCardPayment',
        'InvoiceEmail' => 'App\Domain\Invoicing\Models\InvoiceEmail',
    ];

    foreach ($classes as $short => $class) {
        expect($bullet)->toContain($short);
        expect(class_exists($class))->toBeTrue("CONTRIBUTING.md names {$short} but {$class} does not exist");
    }

    // The database functions and the key the text names are in the migrations of the phase.
    expect(repoFile('database/migrations/2026_10_22_000100_add_lock_state_to_invoices.php'))->toContain('kokpit_guard_invoice_row')
        ->and(repoFile('database/migrations/2026_10_22_000200_follow_invoice_lock_in_time_entry_link.php'))->toContain('kokpit_invoice_accepts_time_changes')
        ->and(repoFile('database/migrations/2026_10_22_000300_relax_payment_link_currency_key.php'))->toContain('payment_links_invoice_fk')
        ->and(method_exists('App\Domain\Payments\Support\PaymentLinks', 'reconcile'))->toBeTrue();

    // The seams closed by the gap-closure round (plans 10.1-13 to 10.1-15): the card currency pin, the stale e-mail cutoff, the bank fingerprint.
    expect(repoFile('database/migrations/2026_10_23_000100_pin_card_payment_currency_to_its_payment.php'))->toContain('kokpit_guard_card_payment_currency')
        ->and(method_exists('App\Domain\Invoicing\Models\InvoiceEmail', 'staleBefore'))->toBeTrue()
        ->and(repoFile('app/Domain/Invoicing/Support/InvoiceAudit.php'))->toContain('bank_fingerprint');

    // The two lock writers and the one unlock flag the text names.
    expect(repoFile('app/Domain/Invoicing/Jobs/SendInvoiceEmail.php'))->toContain('InvoiceLockState::Locked')
        ->and(repoFile('app/Domain/Invoicing/Actions/LockInvoice.php'))->toContain('InvoiceLockState::Locked')
        ->and(repoFile('app/Domain/Invoicing/Actions/UnlockInvoice.php'))->toContain('kokpit.invoice_unlock');

    // The enforcing tests the text names.
    foreach ([
        'tests/Feature/Schema/InvoiceLockGuardTest.php', 'tests/Arch/InvoiceUnlockPathTest.php', 'tests/Feature/Invoicing/UnlockInvoiceTest.php',
        'tests/Feature/Invoicing/UpdateIssuedDocumentTest.php', 'tests/Feature/Invoicing/UpdateIssuedDocumentPdfLinkTest.php',
        'tests/Feature/Invoicing/InvoiceEditAuditTest.php', 'tests/Feature/Invoicing/IssuedEditorTest.php', 'tests/Feature/Invoicing/IssuedTimeEditTest.php',
        'tests/Concurrency/InvoiceEditSendConcurrencyTest.php', 'tests/Feature/Invoicing/EditableInvoiceJourneyTest.php',
        'tests/Feature/Payments/PaymentClassificationTest.php', 'tests/Feature/Payments/CardPaymentRecordTest.php',
        'tests/Feature/Invoicing/SendInvoiceEmailTest.php', 'tests/Feature/Invoicing/EmailDialogTest.php',
    ] as $path) {
        expect($bullet)->toContain(basename($path, '.php'));
        expect(is_file(base_path($path)))->toBeTrue("CONTRIBUTING.md names {$path} but the file does not exist");
    }
});

it('names the bundled Roboto fonts with their OFL-1.1 licence in the README, and the fonts and licence text are there', function () {
    $readme = repoFile('README.md');

    expect($readme)->toContain('Roboto')
        ->and($readme)->toContain('OFL-1.1')
        ->and($readme)->toContain('resources/fonts/OFL.txt');

    foreach (['resources/fonts/OFL.txt', 'resources/fonts/Roboto-Regular.ttf', 'resources/fonts/Roboto-Bold.ttf'] as $path) {
        expect(is_file(base_path($path)))->toBeTrue("{$path} is named in the documentation but does not exist");
    }

    // The Dompdf font cache is generated at the first render and stays out of git, and so do the test PDFs.
    $ignore = repoFile('.gitignore');

    expect($ignore)->toContain('/storage/framework/dompdf/')
        ->and($ignore)->toContain('/storage/framework/testing/*');
});

it('documents the Czech ICU collation requirement and its check for the operator', function () {
    $readme = repoFile('README.md');

    expect($readme)->toContain('cs-CZ-x-icu')
        ->and($readme)->toContain('kokpit:deploy:verify')
        ->and($readme)->toContain('forgotten-timer notice')
        ->and(repoFile('CONTRIBUTING.md'))->toContain('cs-CZ-x-icu');
});

it('documents the queued task notifications for the operator in the README', function () {
    $readme = repoFile('README.md');

    expect($readme)->toContain('Task notifications')
        ->and($readme)->toContain('queue worker')
        ->and($readme)->toContain('MAIL_*');
});

it('no longer points the import at a projects counter column', function () {
    $requirements = file_get_contents(base_path('.planning/REQUIREMENTS.md'));

    // .planning is a public part of the repository; the stale wording is research correction C1.
    expect($requirements)->not->toContain('next_task_number')
        ->and($requirements)->toContain('task:<project uuid>');
});

/**
 * The class a documentation name stands for: the one file of that name under app/ or tests/Support, as its full
 * class name, or null when there is none. A name that two files share (a widget and a query) resolves to the first
 * that exists.
 *
 * @return list<string>
 */
function repoClassesNamed(string $short): array
{
    $found = [];

    foreach (['app' => 'App', 'tests/Support' => 'Tests\Support'] as $directory => $namespace) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getFilename() !== $short.'.php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen(base_path($directory)) + 1, -4);
            $class = $namespace.'\\'.str_replace('/', '\\', $relative);

            if (class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class)) {
                $found[] = $class;
            }
        }
    }

    return $found;
}

/**
 * The text of the CONTRIBUTING.md bullet that starts with the given bold label, to the end of its line.
 */
function repoContributingBullet(string $label): string
{
    foreach (explode("\n", repoFile('CONTRIBUTING.md')) as $line) {
        if (str_starts_with($line, '- **'.$label.'**')) {
            return $line;
        }
    }

    return '';
}

it('hands the card payments over to Phase 12: the Phase 11 bullet is gone and the two public routes are named', function () {
    $contributing = repoFile('CONTRIBUTING.md');
    $handOver = repoContributingBullet('Phase 12.');

    expect($contributing)->not->toContain('Phase 11 (Stripe)')
        ->and($handOver)->not->toBe('')
        ->and($handOver)->toContain('pay.show')
        ->and($handOver)->toContain('webhooks.stripe')
        ->and($handOver)->toContain('payment_links')
        ->and($handOver)->toContain('card_payments');

    // The routes the text names are the routes of routes/public.php, and nothing else is declared there.
    $source = repoFile('routes/public.php');

    preg_match_all("/->name\('([^']+)'\)/", $source, $names);

    expect($names[1])->toEqualCanonicalizing(['pay.show', 'webhooks.stripe'])
        ->and(Route::has('pay.show'))->toBeTrue()
        ->and(Route::has('webhooks.stripe'))->toBeTrue();
});

it('hands over the Partner audit: the Phase 12 bullet names only what exists', function () {
    $bullet = repoContributingBullet('Phase 12.');

    expect($bullet)->not->toBe('');

    // What a later phase must find in the text: the suite, its command, the three declarations, the two Phase 11 routes
    // and the two Admin-only tables (R-12-09-1).
    foreach ([
        'tests/Audit', 'composer partner-audit', 'RouteRules', 'PartnerMatrix', 'ChannelMatrix',
        'pay.show', 'webhooks.stripe', 'payment_links', 'card_payments',
    ] as $name) {
        expect($bullet)->toContain($name);
    }

    // Every CamelCase name in backticks resolves to a class under app/ or tests/Support.
    preg_match_all('/`([A-Z][A-Za-z0-9]+)`/', $bullet, $matches);

    expect(count($matches[1]))->toBeGreaterThanOrEqual(6);

    foreach ($matches[1] as $name) {
        expect(repoClassesNamed($name))->not->toBe([], "CONTRIBUTING.md names {$name} but no such class exists");
    }

    // Every test file the text names exists, and so does the directory of the suite.
    preg_match_all('#`(tests/[A-Za-z0-9_/]+\.php)`#', $bullet, $paths);

    expect($paths[1])->not->toBe([]);

    foreach ($paths[1] as $path) {
        expect(is_file(base_path($path)))->toBeTrue("CONTRIBUTING.md names {$path} but the file does not exist");
    }

    expect(is_dir(base_path('tests/Audit')))->toBeTrue();

    // The script the text names exists, and the Livewire endpoint is named as it is routed.
    expect(array_keys((array) (repoComposer()['scripts'] ?? [])))->toContain('partner-audit')
        ->and(repoFile('CONTRIBUTING.md'))->toContain('livewire-{hash}/update')
        ->and(repoFile('CONTRIBUTING.md'))->not->toContain('`/livewire/update`');
});

it('only names Phase 11 classes, methods and tests in CONTRIBUTING.md that exist', function () {
    $bullet = repoContributingBullet('Card payments.');

    expect($bullet)->not->toBe('');

    // The mechanisms the text must keep naming (R11-20-3).
    foreach ([
        'PaymentLinks::reconcile()', 'RecordPayment::recordLocked()', 'RecordCardPayment', 'RecordRefundEvent', 'CardPayment::recordOnce()',
        'DeletePayment', 'StripeGateway', 'FakeStripeGateway', 'pay.show', 'webhooks.stripe',
        'PaymentLinks::deactivateLive()', 'PayUrl::forInvoice()',
    ] as $name) {
        expect($bullet)->toContain($name);
    }

    // Phase 14 (OA-1): the card switch is editable while the document is open, so the Phase 11 wording is gone.
    expect($bullet)->not->toContain('is frozen at issue');

    // Gap closure (14-REVIEW.md IN-02, OA-1): a first pay token may be set later, on an open issued document.
    expect($bullet)->toContain('later, on an open issued document')
        ->and($bullet)->not->toContain('set once, with the first issue, and never changes');

    // Every CamelCase name in backticks (a class, or Class::method()) resolves to a class, and the method exists.
    preg_match_all('/`([A-Z][A-Za-z0-9]+)(?:::([a-z][A-Za-z0-9]*)\(\))?`/', $bullet.repoContributingBullet('Phase 12.'), $matches, PREG_SET_ORDER);

    expect(count($matches))->toBeGreaterThan(15);

    foreach ($matches as $match) {
        $classes = repoClassesNamed($match[1]);

        expect($classes)->not->toBe([], "CONTRIBUTING.md names {$match[1]} but no such class exists");

        if (isset($match[2])) {
            $exists = array_filter($classes, static fn (string $class): bool => method_exists($class, $match[2]));

            expect($exists)->not->toBe([], "CONTRIBUTING.md names {$match[1]}::{$match[2]}() but no such method exists");
        }
    }

    // The enforcing tests the text names.
    foreach (['tests/Feature/Payments/CardPaymentJourneyTest.php', 'tests/Isolation/CardPaymentLeakTest.php', 'tests/Support/FakeStripeGateway.php'] as $path) {
        expect($bullet.repoContributingBullet('Phase 12.'))->toContain(basename($path, '.php'));
        expect(is_file(base_path($path)))->toBeTrue("CONTRIBUTING.md names {$path} but the file does not exist");
    }
});

it('hands the payment method over to later phases: the Payment method and bank accounts bullet names only what exists', function () {
    $bullet = repoContributingBullet('Payment method and bank accounts.');

    expect($bullet)->not->toBe('');

    // The mechanisms the text must keep naming (R-14-11-2).
    foreach ([
        'DocumentPayment::preselect()', 'PayUrl::forInvoice()', 'PaymentLinks::deactivateLive()', 'PayLinkBlock::applies()',
        'BankAccountByIdTest', 'invoice_admin.bank_account_id', 'Phase 13',
        // Gap closure (14-REVIEW.md IN-01, D-03, OA-2, OA-7): card availability on issued documents, the application-level tie, the converted snapshot.
        'application-level only', 'card_payment_unavailable', 'IssueContext',
    ] as $name) {
        expect($bullet)->toContain($name);
    }

    // Every CamelCase name in backticks (a class, or Class::method()) resolves to a class, and the method exists. A test class lives
    // under tests/ and is checked as a file below.
    preg_match_all('/`([A-Z][A-Za-z0-9]+)(?:::([a-z][A-Za-z0-9]*)\(\))?`/', $bullet, $matches, PREG_SET_ORDER);

    expect(count($matches))->toBeGreaterThan(8);

    foreach ($matches as $match) {
        if (str_ends_with($match[1], 'Test')) {
            continue;
        }

        $classes = repoClassesNamed($match[1]);

        expect($classes)->not->toBe([], "CONTRIBUTING.md names {$match[1]} but no such class exists");

        if (isset($match[2])) {
            $exists = array_filter($classes, static fn (string $class): bool => method_exists($class, $match[2]));

            expect($exists)->not->toBe([], "CONTRIBUTING.md names {$match[1]}::{$match[2]}() but no such method exists");
        }
    }

    // The enforcing test the text names.
    expect(is_file(base_path('tests/Arch/BankAccountByIdTest.php')))->toBeTrue('CONTRIBUTING.md names BankAccountByIdTest but the file does not exist');

    // The columns, the checks and the open editing rules the text names are in the migrations of the phase.
    expect(repoFile('database/migrations/2026_10_24_000200_add_payment_method_to_invoices.php'))->toContain('invoices_payment_method_kind_check')
        ->and(repoFile('database/migrations/2026_10_24_000200_add_payment_method_to_invoices.php'))->toContain('invoices_issued_fields_check')
        ->and(repoFile('database/migrations/2026_10_24_000300_add_bank_account_and_open_card_switch_to_invoice_admin.php'))->toContain('bank_account_id');

    // The Editable invoices bullet lists the new write-path fields, and the Phase 11 wording "frozen at issue" is gone everywhere.
    expect(repoContributingBullet('Editable invoices.'))->toContain('payment method, bank account and card switch')
        ->and(repoFile('CONTRIBUTING.md'))->not->toContain('is frozen at issue');
});

it('hands the cash receipts over: the Cash receipts bullet names only what exists', function () {
    $bullet = repoContributingBullet('Cash receipts.');

    expect($bullet)->not->toBe('');

    // The mechanisms and decisions the text must keep naming (R-15-14-4).
    foreach ([
        'DocumentPaymentMethod', 'printsBankDetails()', 'allowsCardChoice()', 'forcesCardPayment()', '`cash_receipts`', 'kokpit_guard_cash_receipt_row()',
        '`IssueCashReceipt`', '`RecordPayment::recordLocked()`', 'DocumentKind::CashReceipt', 'cash_receipt_pattern', '`StoreCashReceiptPdf`',
        '`SendPaymentConfirmation`', '`CancelCashReceipt`', '`UpdateCashReceipt`', '`LockCashReceipt`', '`UnlockCashReceipt`', 'kokpit.cash_receipt_unlock',
        '`CashReceiptUnlockPathTest`', '`RegenerateCashReceiptPdf`', '`CashReceiptPdfController`', 'files.cash-receipt',
        '`PartnerCashReceiptsRelationManager`', '`CashReceiptAudit`', '`InvoicePaymentsRelationManager`',
        // The owner's acceptance of 2026-10-27 (AS-2, R-15-02-6, T-15-17) and the legal facts the owner tracks (AS-14, AS-15).
        '2026-10-27', 'in the application only', 'no database constraint trigger', 'AS-1 to AS-16', 'AS-14', 'AS-15', '270,000 CZK', 'EET 2.0',
    ] as $name) {
        expect($bullet)->toContain($name);
    }

    // Every CamelCase name in backticks (a class, or Class::method()) resolves to a class, and the method exists. A test class is
    // checked as a file below.
    preg_match_all('/`([A-Z][A-Za-z0-9]+)(?:::([a-z][A-Za-z0-9]*)\(\))?`/', $bullet, $matches, PREG_SET_ORDER);

    expect(count($matches))->toBeGreaterThan(10);

    foreach ($matches as $match) {
        if (str_ends_with($match[1], 'Test')) {
            continue;
        }

        $classes = repoClassesNamed($match[1]);

        expect($classes)->not->toBe([], "CONTRIBUTING.md names {$match[1]} but no such class exists");

        if (isset($match[2])) {
            $exists = array_filter($classes, static fn (string $class): bool => method_exists($class, $match[2]));

            expect($exists)->not->toBe([], "CONTRIBUTING.md names {$match[1]}::{$match[2]}() but no such method exists");
        }
    }

    // The enum case, the predicates and the document kind the text names.
    expect(DocumentPaymentMethod::Cash->value)->toBe('cash')
        ->and(method_exists(DocumentPaymentMethod::class, 'printsBankDetails'))->toBeTrue()
        ->and(method_exists(DocumentPaymentMethod::class, 'allowsCardChoice'))->toBeTrue()
        ->and(method_exists(DocumentPaymentMethod::class, 'forcesCardPayment'))->toBeTrue()
        ->and(DocumentKind::CashReceipt->value)->toBe('cash_receipt')
        ->and(CashReceiptPdfUrl::ROUTE)->toBe('filament.admin.files.cash-receipt');

    // The enforcing tests the text names exist, in the file the text implies.
    $tests = [
        'CashReceiptModelTest' => 'tests/Feature/Invoicing/CashReceiptModelTest.php',
        'CashReceiptIssueTest' => 'tests/Feature/Invoicing/CashReceiptIssueTest.php',
        'CashReceiptPdfTest' => 'tests/Feature/Invoicing/CashReceiptPdfTest.php',
        'CashReceiptDeliveryTest' => 'tests/Feature/Invoicing/CashReceiptDeliveryTest.php',
        'CashReceiptLockTest' => 'tests/Feature/Invoicing/CashReceiptLockTest.php',
        'CashReceiptEditTest' => 'tests/Feature/Invoicing/CashReceiptEditTest.php',
        'CashReceiptCancelTest' => 'tests/Feature/Invoicing/CashReceiptCancelTest.php',
        'CashReceiptPartnerTest' => 'tests/Feature/Invoicing/CashReceiptPartnerTest.php',
        'CashReceiptAdminTest' => 'tests/Feature/Invoicing/CashReceiptAdminTest.php',
        'CashReceiptUnlockPathTest' => 'tests/Arch/CashReceiptUnlockPathTest.php',
        'CashReceiptsTableTest' => 'tests/Feature/Schema/CashReceiptsTableTest.php',
    ];

    foreach ($tests as $name => $path) {
        expect($bullet)->toContain($name);
        expect(is_file(base_path($path)))->toBeTrue("CONTRIBUTING.md names {$name} but {$path} does not exist");
    }

    // The migration holds the guard function and the unlock setting the text names; the assumptions it cites are in the plan.
    expect(repoFile('database/migrations/2026_10_27_000200_create_cash_receipts_table.php'))->toContain('kokpit_guard_cash_receipt_row')
        ->and(repoFile('database/migrations/2026_10_27_000200_create_cash_receipts_table.php'))->toContain('kokpit.cash_receipt_unlock')
        ->and(is_file(base_path('.planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-01-PLAN.md')))->toBeTrue();

    // The Payment method bullet says cash is the third method and prints no bank details, and points here.
    expect(repoContributingBullet('Payment method and bank accounts.'))->toContain('`cash` as the third method')
        ->and(repoContributingBullet('Payment method and bank accounts.'))->toContain('prints no bank details');
});

it('hands the per-client e-mail switch over: the Per-client e-mail switch bullet names only what exists', function () {
    $bullet = repoContributingBullet('Per-client e-mail switch.');

    expect($bullet)->not->toBe('');

    // The mechanisms and decisions the text must keep naming (R-16-06-3).
    foreach ([
        'Phase 16', 'P16-01 to P16-11', '`clients.document_emails_enabled`', '`ClientDocumentEmails`', '`enabledFor()`', '`enabledForInvoice()`', '`allows()`',
        'fresh on every call', 'fails closed', '`QueueInvoiceEmail`', '`SendInvoiceEmail`', '`SendPaymentConfirmation`', '`QueuePaymentConfirmation`',
        '`InvoiceEmailState::Skipped`', '`payment_confirmations_recipient_check`', '`UnsentInvoiceEmailsIndicator`', '`SendInvoiceEmailAction`',
        '`InvoiceTable`', '`InvoiceEmailsRelationManager`', '`ViewInvoice`', '`LockInvoice`', '`LockCashReceipt`', '`invoice_locked_manually`',
        '`cash_receipt_locked_manually`', '`InvoiceAudit::everSent()`', '`InvoiceUnlockPathTest`', '`CashReceiptUnlockPathTest`',
        '`InvitePartner`', '`SendPartnerPasswordReset`', '`TaskNotifier`', 'never revived', 'owner answer A1', 'owner answer A2', 'owner answer A3',
    ] as $name) {
        expect($bullet)->toContain($name);
    }

    // Every CamelCase name in backticks (a class, or Class::method()) resolves to a class, and the method exists. A test class is
    // checked as a file below.
    preg_match_all('/`([A-Z][A-Za-z0-9]+)(?:::([a-z][A-Za-z0-9]*)\(\))?`/', $bullet, $matches, PREG_SET_ORDER);

    expect(count($matches))->toBeGreaterThan(15);

    foreach ($matches as $match) {
        if (str_ends_with($match[1], 'Test')) {
            continue;
        }

        $classes = repoClassesNamed($match[1]);

        expect($classes)->not->toBe([], "CONTRIBUTING.md names {$match[1]} but no such class exists");

        if (isset($match[2])) {
            $exists = array_filter($classes, static fn (string $class): bool => method_exists($class, $match[2]));

            expect($exists)->not->toBe([], "CONTRIBUTING.md names {$match[1]}::{$match[2]}() but no such method exists");
        }
    }

    // The methods of the gate and the enum case the text names.
    foreach (['enabledFor', 'enabledForInvoice', 'allows'] as $method) {
        expect(method_exists('App\Domain\Invoicing\Support\ClientDocumentEmails', $method))->toBeTrue("ClientDocumentEmails::{$method}() does not exist");
    }

    expect(InvoiceEmailState::Skipped->value)->toBe('skipped');

    // The enforcing tests the text names exist, in the file the text implies.
    $tests = [
        'ClientDocumentEmailsTest' => 'tests/Feature/Invoicing/ClientDocumentEmailsTest.php',
        'HandServedDocumentTest' => 'tests/Feature/Invoicing/HandServedDocumentTest.php',
        'LockInvoiceTest' => 'tests/Feature/Invoicing/LockInvoiceTest.php',
        'HandServedClientJourneyTest' => 'tests/Feature/Invoicing/HandServedClientJourneyTest.php',
        'DocumentEmailsScopeTest' => 'tests/Feature/Clients/DocumentEmailsScopeTest.php',
        'InvoiceUnlockPathTest' => 'tests/Arch/InvoiceUnlockPathTest.php',
        'CashReceiptUnlockPathTest' => 'tests/Arch/CashReceiptUnlockPathTest.php',
    ];

    foreach ($tests as $name => $path) {
        expect($bullet)->toContain($name);
        expect(is_file(base_path($path)))->toBeTrue("CONTRIBUTING.md names {$name} but {$path} does not exist");
    }

    // The migration of the check the text names, and the two manual lock events the Actions write.
    expect(repoFile('database/migrations/2026_10_28_000300_relax_payment_confirmation_recipient_check.php'))->toContain('payment_confirmations_recipient_check')
        ->and(repoFile('database/migrations/2026_10_28_000100_add_document_emails_enabled_to_clients_table.php'))->toContain('document_emails_enabled')
        ->and(repoFile('app/Domain/Invoicing/Support/InvoiceAudit.php'))->toContain('invoice_locked_manually')
        ->and(repoFile('app/Domain/Invoicing/Support/CashReceiptAudit.php'))->toContain('cash_receipt_locked_manually');

    // The older bullets point at the manual lock too, so no rule still says only the send locks.
    expect(repoFile('CONTRIBUTING.md'))->not->toContain('only code that locks is');
});

it('hands Phase 17 over: the Phase 17 refinements bullet names only what exists', function () {
    $bullet = repoContributingBullet('Phase 17 refinements.');

    expect($bullet)->not->toBe('');

    // The mechanisms the text must keep naming (R-17-08-5).
    foreach ([
        'Phase 17', 'P17-01 to P17-10', '`tasks.hidden_on_board`', '`signal_tasks.task_id`', 'SET NULL', '`LinkableTasks`',
        '`LinkableTasks::search()`', '`LinkableTasks::find()`', '`LinkableTasks::label()`', '{PROJECT}: {task name} [{task code}]',
        '`AddSignalTask`', '`SignalDay::toggleTaskTimer()`', '`TaskTimerToggle`', '`Project::scopeOpen()`',
        '`TaskResource::quickCreateAction()`', '`TimeEntryResource::entryFields()`', '`DocumentDisposition::isInlineImage()`',
        '`DocumentActions::preview()`', '`RichText::render()`', '`fi-prose`',
        '`SignalTaskLinkTest`', '`TaskAddTimeTest`', '`ProjectViewActionsTest`', '`RichTextRenderingTest`',
    ] as $name) {
        expect($bullet)->toContain($name);
    }

    // Every CamelCase name in backticks (a class, or Class::method()) resolves to a class, and the method exists.
    preg_match_all('/`([A-Z][A-Za-z0-9]+)(?:::([a-z][A-Za-z0-9]*)\(\))?`/', $bullet, $matches, PREG_SET_ORDER);

    expect(count($matches))->toBeGreaterThan(15);

    foreach ($matches as $match) {
        if (str_ends_with($match[1], 'Test')) {
            continue;
        }

        $classes = repoClassesNamed($match[1]);

        expect($classes)->not->toBe([], "CONTRIBUTING.md names {$match[1]} but no such class exists");

        if (isset($match[2])) {
            $exists = array_filter($classes, static fn (string $class): bool => method_exists($class, $match[2]));

            expect($exists)->not->toBe([], "CONTRIBUTING.md names {$match[1]}::{$match[2]}() but no such method exists");
        }
    }

    // `Project::scopeOpen()` is the scope behind `->open()`; the method the text names exists under its real name.
    expect(method_exists('App\Domain\Projects\Models\Project', 'scopeOpen'))->toBeTrue();

    // The enforcing tests the text names exist, in the file the text implies.
    $tests = [
        'SignalTaskLinkTest' => 'tests/Feature/Signal/SignalTaskLinkTest.php',
        'TaskAddTimeTest' => 'tests/Feature/TimeTracking/TaskAddTimeTest.php',
        'ProjectViewActionsTest' => 'tests/Feature/Projects/ProjectViewActionsTest.php',
        'RichTextRenderingTest' => 'tests/Feature/Tasks/RichTextRenderingTest.php',
    ];

    foreach ($tests as $name => $path) {
        expect($bullet)->toContain($name);
        expect(is_file(base_path($path)))->toBeTrue("CONTRIBUTING.md names {$name} but {$path} does not exist");
    }

    // The two migrations carry the column and the link the text names.
    expect(repoFile('database/migrations/2026_10_29_000100_add_hidden_on_board_to_tasks_table.php'))->toContain('hidden_on_board')
        ->and(repoFile('database/migrations/2026_10_29_000200_link_signal_tasks_to_tasks.php'))->toContain('task_id');

    // The Board and Search sentences and the single PDF-link exception are in the file, and the absolute rule is gone.
    $contributing = repoFile('CONTRIBUTING.md');

    expect($contributing)->toContain('`TaskBoard::applyFilters()` drops the tasks with `tasks.hidden_on_board` set')
        ->and($contributing)->toContain('Signed PDF link exception')
        ->and($contributing)->not->toContain('never rendered into a page')
        ->and(repoFile('app/Domain/Documents/Support/DocumentUrl.php'))->toContain('_blank')
        ->and(repoFile('app/Domain/Documents/Support/DocumentUrl.php'))->not->toContain('never renders such a link');
});

it('hands Phase 17.1 over: the gap closure bullet names only what exists', function () {
    $bullet = repoContributingBullet('Phase 17.1 gap closure.');

    expect($bullet)->not->toBe('');

    // The rules the text must keep naming, and the deferred part it must not claim.
    foreach ([
        'G1 and G2', '`IssueCreditNote::partial()`', '`EntryBilling::release()`', '`IssueCreditNote::creditedInFull()`',
        '`CreditableQuantities::for()`', '`cancels_invoice`', '`Invoice::isCancelled()`', '`kokpit_invoice_fully_credited()`',
        '`kokpit_guard_time_entry_link()`', '`RecordPayment::refuses()`', '`Invoice::invoiceFromProforma()`',
        '`payment_on_converted_proforma`', '`RecordPayment::recordLocked()`', 'deferred', 'no earlier row was repaired',
        '`CreditNoteTest`', '`CreditNoteActionsTest`', '`TimeEntryInvoiceLinkTest`', '`CreditNoteConcurrencyTest`',
        '`PaymentsTest`', '`PaymentModalTest`', '`CardPaymentCoreTest`', '`CardPaymentResolutionTest`',
    ] as $name) {
        expect($bullet)->toContain($name);
    }

    // Every CamelCase name in backticks (a class, or Class::method()) resolves to a class, and the method exists.
    preg_match_all('/`([A-Z][A-Za-z0-9]+)(?:::([a-z][A-Za-z0-9]*)\(\))?`/', $bullet, $matches, PREG_SET_ORDER);

    expect(count($matches))->toBeGreaterThan(10);

    foreach ($matches as $match) {
        if (str_ends_with($match[1], 'Test')) {
            continue;
        }

        $classes = repoClassesNamed($match[1]);

        expect($classes)->not->toBe([], "CONTRIBUTING.md names {$match[1]} but no such class exists");

        if (isset($match[2])) {
            $exists = array_filter($classes, static fn (string $class): bool => method_exists($class, $match[2]));

            expect($exists)->not->toBe([], "CONTRIBUTING.md names {$match[1]}::{$match[2]}() but no such method exists");
        }
    }

    // The enforcing tests the text names exist, in the file the text implies.
    $tests = [
        'CreditNoteTest' => 'tests/Feature/Invoicing/CreditNoteTest.php',
        'CreditNoteActionsTest' => 'tests/Feature/Invoicing/CreditNoteActionsTest.php',
        'TimeEntryInvoiceLinkTest' => 'tests/Feature/Schema/TimeEntryInvoiceLinkTest.php',
        'CreditNoteConcurrencyTest' => 'tests/Concurrency/CreditNoteConcurrencyTest.php',
        'PaymentsTest' => 'tests/Feature/Invoicing/PaymentsTest.php',
        'PaymentModalTest' => 'tests/Feature/Invoicing/PaymentModalTest.php',
        'CardPaymentCoreTest' => 'tests/Feature/Invoicing/CardPaymentCoreTest.php',
        'CardPaymentResolutionTest' => 'tests/Feature/Payments/CardPaymentResolutionTest.php',
    ];

    foreach ($tests as $name => $path) {
        expect($bullet)->toContain($name);
        expect(is_file(base_path($path)))->toBeTrue("CONTRIBUTING.md names {$name} but {$path} does not exist");
    }

    // The migration and the message the text names exist.
    expect(repoFile('database/migrations/2026_10_30_000200_release_time_of_fully_credited_invoice.php'))->toContain('kokpit_invoice_fully_credited')
        ->and(repoFile('lang/cs/invoices.php'))->toContain('payment_on_converted_proforma');
});

it('hands the e-mail layout over: the E-mail bullet names only what exists', function () {
    $bullet = repoContributingBullet('E-mail.');

    expect($bullet)->not->toBe('');

    // The rules the text must keep stating (R-13-09-1, D-01 to D-07).
    foreach ([
        'published `kokpit` theme', '`config/mail.php`', '`resources/views/vendor/mail`', 'MailBrand', 'system context', 'field whitelist',
        'fallback to the application name', 'inline cid part', 'never a URL or a route', '`MailText::escapeMarkdown()`', '`PlainTextHtml::paragraphs()`',
        '`PayLinkBlock::forHtml()`', '`PayLinkBlock::applies()`', '`QueuePaymentConfirmation`', '`RecordPayment::recordLocked()`',
        '`payment_confirmations`', '`SendPaymentConfirmation`', 'QueuePaymentConfirmation::GRACE_SECONDS', 'never locks', '`ResetPasswordNotification`',
        '`mail_layout`', '`payment_mail`', 'never `view()->render()`',
    ] as $phrase) {
        expect($bullet)->toContain($phrase);
    }

    // Every CamelCase name in backticks (a class, or Class::method()) resolves to a class, and the method exists. A test class is
    // checked as a file below.
    preg_match_all('/`([A-Z][A-Za-z0-9]+)(?:::([a-z][A-Za-z0-9]*)\(\))?`/', $bullet, $matches, PREG_SET_ORDER);

    expect(count($matches))->toBeGreaterThan(10);

    foreach ($matches as $match) {
        if (str_ends_with($match[1], 'Test')) {
            continue;
        }

        $classes = repoClassesNamed($match[1]);

        expect($classes)->not->toBe([], "CONTRIBUTING.md names {$match[1]} but no such class exists");

        if (isset($match[2])) {
            $exists = array_filter($classes, static fn (string $class): bool => method_exists($class, $match[2]));

            expect($exists)->not->toBe([], "CONTRIBUTING.md names {$match[1]}::{$match[2]}() but no such method exists");
        }
    }

    // The other names the text gives: the grace window constant and the unescape step of the text layout.
    expect(defined(QueuePaymentConfirmation::class.'::GRACE_SECONDS'))->toBeTrue()
        ->and(method_exists(MailText::class, 'unescapeMarkdown'))->toBeTrue()
        ->and($bullet)->toContain('unescapeMarkdown()');

    // The enforcing tests the text names exist.
    $tests = [
        'MailThemeTest' => 'tests/Feature/Mail/MailThemeTest.php',
        'MailBrandTest' => 'tests/Feature/Mail/MailBrandTest.php',
        'InvoiceMailHtmlTest' => 'tests/Feature/Mail/InvoiceMailHtmlTest.php',
        'NotificationMailsTest' => 'tests/Feature/Mail/NotificationMailsTest.php',
        'PaymentConfirmationTriggerTest' => 'tests/Feature/Invoicing/PaymentConfirmationTriggerTest.php',
        'PaymentConfirmationMailTest' => 'tests/Feature/Invoicing/PaymentConfirmationMailTest.php',
        'PaymentConfirmationSchemaTest' => 'tests/Feature/Invoicing/PaymentConfirmationSchemaTest.php',
        'PaymentConfirmationAdminTest' => 'tests/Feature/Invoicing/PaymentConfirmationAdminTest.php',
    ];

    foreach ($tests as $name => $path) {
        expect($bullet)->toContain($name);
        expect(is_file(base_path($path)))->toBeTrue("CONTRIBUTING.md names {$name} but {$path} does not exist");
    }

    // The theme, the views and the lang files the text names exist.
    expect(config('mail.markdown.theme'))->toBe('kokpit')
        ->and(is_file(base_path('resources/views/vendor/mail/html/themes/kokpit.css')))->toBeTrue()
        ->and(is_file(base_path('lang/cs/mail_layout.php')))->toBeTrue()
        ->and(is_file(base_path('lang/en/payment_mail.php')))->toBeTrue();

    // The Task notifications bullet says the escape lives in MailText, and the neighbours keep their tokens.
    expect(repoContributingBullet('Task notifications.'))->toContain('MailText')
        ->and(repoContributingBullet('Payment method and bank accounts.'))->toContain('PayLinkBlock::applies()')
        ->and(repoContributingBullet('Payment method and bank accounts.'))->toContain('Phase 13')
        ->and(repoContributingBullet('Editable invoices.'))->toContain('must not call the lock step');
});

it('documents the owner\'s Stripe setup in the README with placeholders only', function () {
    $readme = repoFile('README.md');

    foreach ([
        'Card payments with Stripe', 'STRIPE_SECRET', 'STRIPE_WEBHOOK_SECRET', 'https://kokpit.example.com/webhooks/stripe',
        'checkout.session.completed', 'checkout.session.async_payment_succeeded', 'refund.created',
        'used only for invoicing', 'Stripe CLI', 'stripe listen',
    ] as $needle) {
        expect($readme)->toContain($needle);
    }

    // No Stripe key, signing secret, account or object id: the prefixes are assembled so this file holds none either.
    $prefix = implode('|', ['sk_', 'pk_', 'rk_', 'whsec_', 'acct_', 'evt_', 'cs_', 'pi_', 'plink_']);

    foreach (['README.md', 'CONTRIBUTING.md'] as $file) {
        expect(preg_match('/\b('.$prefix.')[A-Za-z0-9_]{6,}/', repoFile($file)))->toBe(0, "{$file} must hold no Stripe-shaped value");
    }

    // Every environment key it names is a documented key of the example file.
    $example = repoFile('.env.example');

    expect($example)->toContain('STRIPE_SECRET=')
        ->and($example)->toContain('STRIPE_WEBHOOK_SECRET=');
});
