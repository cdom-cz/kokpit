<?php

declare(strict_types=1);

use App\Domain\Clients\Models\ClientInvitation;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Database\CzechCollation;
use Illuminate\Support\Facades\Artisan;
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
        preg_match_all('/composer (test|lint|stan|check-licenses|licenses|ci)\b/', repoFile($file), $matches);

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
        'ZEROPS_SERVICE_ID',
        'backend',
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
        'RefusingCleanActivityLogAction' => 'App\Domain\Audit\RefusingCleanActivityLogAction',
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

it('documents the service id variable the deploy workflow reads', function () {
    $workflow = repoFile('.github/workflows/deploy.yml');
    $contributing = repoFile('CONTRIBUTING.md');

    preg_match_all('/vars\.(ZEROPS_[A-Z_]+)/', $workflow, $matches);

    // One backend service, one push, one variable; the per-setup variables of the earlier three-service layout are gone.
    expect(array_values(array_unique($matches[1])))->toBe(['ZEROPS_SERVICE_ID'])
        ->and($contributing)->toContain('ZEROPS_SERVICE_ID')
        ->and($contributing)->not->toMatch('/ZEROPS_('.implode('|', ['APP', 'WORKER', 'SCHEDULER']).')_SERVICE_ID/');
});

it('names the production environment variables for the Zerops UI and each is a documented key', function () {
    $contributing = repoFile('CONTRIBUTING.md');

    preg_match_all('/^#?\s*([A-Z][A-Z0-9_]*)=/m', repoFile('.env.example'), $matches);
    $documented = array_unique($matches[1]);

    foreach ([
        'APP_KEY', 'APP_URL', 'APP_ENV', 'APP_DEBUG', 'DB_HOST', 'DB_PASSWORD', 'REDIS_HOST', 'QUEUE_CONNECTION',
        'CACHE_STORE', 'SESSION_DRIVER', 'MAIL_MAILER', 'FILESYSTEM_DISK', 'AWS_BUCKET', 'AWS_ENDPOINT',
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
        'DeployVerifyCommandTest' => 'tests/Feature/Operations/DeployVerifyCommandTest.php',
    ];

    foreach ($tests as $short => $path) {
        expect($contributing)->toContain($short);
        expect(is_file(base_path($path)))->toBeTrue("CONTRIBUTING.md names {$short} but {$path} does not exist");
    }
});

it('hands over to Phases 7, 8, 9 and 10 and no longer carries the Phase 6 note or the stale labels', function () {
    $contributing = repoFile('CONTRIBUTING.md');

    foreach (['Phase 7 (', 'Phase 8 (', 'Phase 9 (', 'Phase 10 ('] as $note) {
        expect($contributing)->toContain("**{$note}");
    }

    // The Phase 6 note is gone now that the phase is built, and the labels that did not match the ROADMAP are gone with it.
    expect($contributing)->not->toContain('**Phase 6 (')
        ->and($contributing)->not->toContain('**Phase 7 (calendar and reports)')
        ->and($contributing)->not->toContain('**Phase 8 (exports)')
        ->and($contributing)->not->toContain('**Phase 12 (')
        ->and($contributing)->toContain('**Phase 7 (REST API).**')
        ->and($contributing)->toContain('**Phase 8 (exchange rates and reports).**')
        ->and($contributing)->toContain('**Phase 10 (invoicing).**');

    // Phase 10 must re-create the guard of time_entries with the extended list (research Open Question 5, Pattern 8).
    $phaseTen = substr($contributing, (int) strpos($contributing, '**Phase 10 (invoicing).**'));

    expect($phaseTen)->toContain('time_entries_frozen_guard')
        ->and($phaseTen)->toContain('Immutability::guardTriggerSql()')
        ->and($phaseTen)->toContain('mutable list')
        ->and($phaseTen)->toContain('TimeEntryRateResolver')
        ->and($phaseTen)->toContain('MarkEntriesBilled');
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
