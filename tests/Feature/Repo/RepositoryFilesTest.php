<?php

declare(strict_types=1);

use App\Domain\Clients\Models\ClientInvitation;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\Audience;
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
        'TaskBoard' => 'App\Domain\Tasks\Board\TaskBoard',
        'MoveTask' => 'App\Domain\Tasks\Actions\MoveTask',
        'RichText' => 'App\Domain\Shared\Text\RichText',
        'TaskColumns' => 'App\Filament\Support\TaskColumns',
        'TaskBillingResolver' => 'App\Domain\Tasks\Billing\TaskBillingResolver',
        'TaskNotifier' => 'App\Domain\Tasks\Notifications\TaskNotifier',
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
        ->and(defined('App\Domain\Shared\Text\RichText::MAX_LENGTH'))->toBeTrue()
        ->and(defined('App\Filament\Support\TaskColumns::PARTNER_COLUMN_NAMES'))->toBeTrue()
        ->and(defined('App\Filament\Support\TaskColumns::PARTNER_ENTRY_NAMES'))->toBeTrue()
        ->and($contributing)->toContain('nextTaskNumber')
        ->and($contributing)->toContain('appendToColumn')
        ->and($contributing)->toContain('RichText::clean()')
        ->and($contributing)->toContain('KP002')
        ->and($contributing)->toContain('projects_key_frozen_guard')
        ->and($contributing)->toContain('PARTNER_COLUMN_NAMES')
        ->and($contributing)->toContain('PARTNER_ENTRY_NAMES');
});

it('names the Phase 5 enforcing tests and the hand-over notes for Phases 6, 7, 9 and 10', function () {
    $contributing = repoFile('CONTRIBUTING.md');

    foreach ([
        'TaskActionsTest', 'TaskKeyTest', 'TaskNumberConcurrencyTest', 'TasksTableTest', 'TaskBoardTest',
        'TaskBoardConcurrencyTest', 'RichTextSanitiserTest', 'PartnerSafeColumnsTest', 'PartnerTaskVisibilityTest',
        'TaskBillingResolverTest', 'TaskNotificationsTest', 'NotificationLeakTest',
    ] as $test) {
        expect($contributing)->toContain($test);
    }

    foreach (['Phase 6 (', 'Phase 7 (', 'Phase 9 (', 'Phase 10 ('] as $note) {
        expect($contributing)->toContain("**{$note}");
    }

    // The earlier single Phase 5 hand-over note is replaced, not kept next to the new ones.
    expect($contributing)->not->toContain('**Phase 5 (tasks).**');
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
