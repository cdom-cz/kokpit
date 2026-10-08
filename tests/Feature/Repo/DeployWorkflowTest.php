<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * Static contract of the deploy workflow (FND-15, T-03-47 to T-03-49). The file
 * is parsed, never run. Every check is a function over a parsed array that
 * returns a list of problems, so the self-check at the end can feed a mutated
 * copy and prove each check really reports a weakening.
 */

/**
 * @return array<string|int, mixed>
 */
function deployWorkflow(): array
{
    $parsed = Yaml::parseFile(base_path('.github/workflows/deploy.yml'));

    return is_array($parsed) ? $parsed : [];
}

/**
 * The trigger map. YAML 1.1 parsers turn the key "on" into boolean true, which
 * PHP stores as the array key 1, so all spellings are accepted.
 *
 * @param  array<string|int, mixed>  $workflow
 * @return array<string, mixed>
 */
function deployTriggers(array $workflow): array
{
    $on = $workflow['on'] ?? $workflow[1] ?? $workflow[true] ?? [];

    if (is_string($on)) {
        return [$on => null];
    }

    if (is_array($on) && array_is_list($on)) {
        return array_fill_keys(array_map('strval', $on), null);
    }

    return is_array($on) ? $on : [];
}

/**
 * @param  array<string|int, mixed>  $workflow
 * @return array<string, array<string, mixed>>
 */
function deployJobs(array $workflow): array
{
    $jobs = $workflow['jobs'] ?? [];

    return is_array($jobs) ? $jobs : [];
}

/**
 * @param  array<string, mixed>  $job
 * @return list<array<string, mixed>>
 */
function deploySteps(array $job): array
{
    return array_values(array_filter((array) ($job['steps'] ?? []), 'is_array'));
}

/**
 * @param  array<string|int, mixed>  $workflow
 * @return list<string>
 */
function deployTriggerProblems(array $workflow): array
{
    $triggers = deployTriggers($workflow);
    $problems = [];

    $names = array_keys($triggers);
    sort($names);

    if ($names !== ['release', 'workflow_dispatch']) {
        $problems[] = 'triggers are ['.implode(', ', $names).'], expected exactly release and workflow_dispatch';
    }

    if (($triggers['release']['types'] ?? null) !== ['published']) {
        $problems[] = 'the release trigger must be limited to types [published]';
    }

    if (is_array($triggers['workflow_dispatch'] ?? null) && ($triggers['workflow_dispatch']['inputs'] ?? []) !== []) {
        $problems[] = 'workflow_dispatch must declare no inputs';
    }

    return $problems;
}

/**
 * @param  array<string|int, mixed>  $workflow
 * @return list<string>
 */
function deployPermissionProblems(array $workflow): array
{
    $problems = [];

    if (($workflow['permissions'] ?? null) !== []) {
        $problems[] = 'top-level permissions must be an empty map';
    }

    foreach (deployJobs($workflow) as $id => $job) {
        if (($job['runs-on'] ?? null) !== 'ubuntu-24.04') {
            $problems[] = "job {$id} must run on ubuntu-24.04";
        }

        if (($job['permissions'] ?? null) !== ['contents' => 'read']) {
            $problems[] = "job {$id} must have exactly permissions contents: read";
        }
    }

    return $problems;
}

/**
 * Event data and secrets must never be interpolated into a script.
 *
 * @param  array<string|int, mixed>  $workflow
 * @return list<string>
 */
function deployInjectionProblems(array $workflow): array
{
    $problems = [];

    foreach (deployJobs($workflow) as $id => $job) {
        foreach (deploySteps($job) as $index => $step) {
            if (str_contains((string) ($step['run'] ?? ''), '${{')) {
                $problems[] = "job {$id} step {$index} interpolates an expression into its run script";
            }
        }
    }

    return $problems;
}

/**
 * @param  array<string|int, mixed>  $workflow
 * @return list<string>
 */
function deploySecretProblems(array $workflow): array
{
    $problems = [];

    foreach (deployJobs($workflow) as $id => $job) {
        $outside = $job;
        unset($outside['steps']);

        if ($id !== 'deploy' && str_contains((string) json_encode($job), 'secrets.')) {
            $problems[] = "job {$id} references secrets, only the deploy job may";
        }

        if (str_contains((string) json_encode($outside), 'secrets.')) {
            $problems[] = "job {$id} references secrets outside a step env";
        }

        foreach (deploySteps($job) as $index => $step) {
            $withoutEnv = $step;
            unset($withoutEnv['env']);

            if (str_contains((string) json_encode($withoutEnv), 'secrets.')) {
                $problems[] = "job {$id} step {$index} references secrets outside its env";
            }
        }
    }

    return $problems;
}

/**
 * @param  array<string|int, mixed>  $workflow
 * @return list<string>
 */
function deployActionProblems(array $workflow): array
{
    $problems = [];

    foreach (deployJobs($workflow) as $id => $job) {
        foreach (deploySteps($job) as $index => $step) {
            $uses = (string) ($step['uses'] ?? '');

            if ($uses === '') {
                continue;
            }

            if (preg_match('/@[0-9a-f]{40}$/', $uses) !== 1) {
                $problems[] = "job {$id} step {$index} uses {$uses}, which is not pinned to a commit SHA";
            }

            if (str_starts_with($uses, 'actions/checkout@') && ($step['with']['persist-credentials'] ?? null) !== false) {
                $problems[] = "job {$id} step {$index} checkout keeps credentials";
            }
        }
    }

    return $problems;
}

/**
 * @param  array<string|int, mixed>  $workflow
 * @return list<string>
 */
function deployEnvironmentProblems(array $workflow): array
{
    $jobs = deployJobs($workflow);
    $problems = [];

    if (array_keys($jobs) !== ['verify', 'deploy']) {
        $problems[] = 'the workflow must have exactly the jobs verify and deploy, in that order';
    }

    if (isset($jobs['verify']['environment'])) {
        $problems[] = 'the verify job must not use an environment';
    }

    $environment = $jobs['deploy']['environment'] ?? null;
    $name = is_array($environment) ? ($environment['name'] ?? null) : $environment;

    if ($name !== 'production') {
        $problems[] = 'the deploy job must run in the production environment';
    }

    $needs = $jobs['deploy']['needs'] ?? null;

    if ($needs !== 'verify' && $needs !== ['verify']) {
        $problems[] = 'the deploy job must need the verify job';
    }

    if (($workflow['concurrency']['group'] ?? null) !== 'deploy-production' || ($workflow['concurrency']['cancel-in-progress'] ?? null) !== false) {
        $problems[] = 'concurrency must be group deploy-production without cancelling';
    }

    return $problems;
}

/**
 * @param  array<string|int, mixed>  $workflow
 * @return list<string>
 */
function deployVerifyProblems(array $workflow): array
{
    $script = '';

    foreach (deploySteps(deployJobs($workflow)['verify'] ?? []) as $step) {
        $script .= (string) ($step['run'] ?? '')."\n";
    }

    $problems = [];

    foreach (['merge-base --is-ancestor', 'origin/main', 'IS_PRERELEASE', 'REF_NAME', 'v*'] as $needle) {
        if (! str_contains($script, $needle)) {
            $problems[] = "the verify script does not contain {$needle}";
        }
    }

    return $problems;
}

/**
 * @param  array<string|int, mixed>  $workflow
 * @return list<string>
 */
function deployInstallProblems(array $workflow): array
{
    $job = deployJobs($workflow)['deploy'] ?? [];
    $problems = [];

    $digest = (string) ($job['env']['ZCLI_SHA256'] ?? '');

    if (preg_match('/^[0-9a-f]{64}$/', $digest) !== 1) {
        $problems[] = 'ZCLI_SHA256 must be 64 lowercase hex characters';
    }

    if (preg_match('/^\d+\.\d+\.\d+$/', (string) ($job['env']['ZCLI_VERSION'] ?? '')) !== 1) {
        $problems[] = 'ZCLI_VERSION must be a plain version number';
    }

    $install = array_values(array_filter(
        deploySteps($job),
        fn (array $step): bool => str_contains((string) ($step['run'] ?? ''), 'zcli-linux-amd64'),
    ));

    if (count($install) !== 1 || ! str_contains((string) $install[0]['run'], 'sha256sum --check --strict')) {
        $problems[] = 'exactly one step must download zcli and verify it with sha256sum --check --strict';
    }

    return $problems;
}

/**
 * @param  array<string|int, mixed>  $workflow
 * @return list<string>
 */
function deployPushProblems(array $workflow): array
{
    $job = deployJobs($workflow)['deploy'] ?? [];
    $problems = [];
    $pushed = [];
    $login = null;

    foreach (deploySteps($job) as $index => $step) {
        $run = (string) ($step['run'] ?? '');

        if (str_contains($run, 'zcli login')) {
            $login = $index;

            if (! str_contains((string) json_encode($step['env'] ?? []), 'secrets.ZEROPS_TOKEN')) {
                $problems[] = 'the login step must receive the token from secrets.ZEROPS_TOKEN through its env';
            }
        }

        if (str_contains($run, 'zcli service push')) {
            if ($login === null) {
                $problems[] = 'a push step runs before the login step';
            }

            if (! str_contains($run, '--zerops-yaml-path zerops.yml')) {
                $problems[] = "push step {$index} does not pass --zerops-yaml-path zerops.yml";
            }

            preg_match('/--setup (\w+)/', $run, $match);
            $pushed[] = $match[1] ?? '';

            if (! str_contains((string) json_encode($step['env'] ?? []), 'vars.')) {
                $problems[] = "push step {$index} must receive its service id from vars through its env";
            }
        }
    }

    if ($login === null) {
        $problems[] = 'no zcli login step';
    }

    if ($pushed !== ['app', 'worker', 'scheduler']) {
        $problems[] = 'push order is ['.implode(', ', $pushed).'], expected app, worker, scheduler';
    }

    return $problems;
}

/**
 * @param  array<string|int, mixed>  $workflow
 * @return array<string, list<string>>
 */
function deployAllProblems(array $workflow): array
{
    return [
        'triggers' => deployTriggerProblems($workflow),
        'permissions' => deployPermissionProblems($workflow),
        'injection' => deployInjectionProblems($workflow),
        'secrets' => deploySecretProblems($workflow),
        'actions' => deployActionProblems($workflow),
        'environment' => deployEnvironmentProblems($workflow),
        'verify' => deployVerifyProblems($workflow),
        'install' => deployInstallProblems($workflow),
        'push' => deployPushProblems($workflow),
    ];
}

it('starts on a published release or a manual dispatch and nothing else', function (): void {
    expect(deployTriggerProblems(deployWorkflow()))->toBe([]);
});

it('grants no token permission by default and only contents read per job', function (): void {
    expect(deployPermissionProblems(deployWorkflow()))->toBe([]);
});

it('never interpolates an expression into a run script', function (): void {
    expect(deployInjectionProblems(deployWorkflow()))->toBe([]);
});

it('references secrets only through the step env of the deploy job', function (): void {
    expect(deploySecretProblems(deployWorkflow()))->toBe([]);
});

it('pins every action to a commit SHA and checks out without credentials', function (): void {
    expect(deployActionProblems(deployWorkflow()))->toBe([]);
});

it('deploys only after verify, in the protected production environment, one at a time', function (): void {
    expect(deployEnvironmentProblems(deployWorkflow()))->toBe([]);
});

it('verifies release, tag and ancestry in a secret-free job', function (): void {
    expect(deployVerifyProblems(deployWorkflow()))->toBe([]);
});

it('installs zcli by version against a hard-coded digest', function (): void {
    expect(deployInstallProblems(deployWorkflow()))->toBe([]);
});

it('logs in with the environment secret and pushes app, then worker, then scheduler', function (): void {
    expect(deployPushProblems(deployWorkflow()))->toBe([]);
});

it('does not touch the hygiene workflow or its aggregator', function (): void {
    $hygiene = Yaml::parseFile(base_path('.github/workflows/hygiene.yml'));

    expect($hygiene['jobs']['ci-passed']['needs'])->toBe(['scan', 'workflow-lint', 'tests', 'static-analysis', 'dependencies'])
        ->and(array_keys($hygiene['jobs']))->not->toContain('deploy');
});

it('contains no secret value and no hosting hostname', function (): void {
    $raw = (string) file_get_contents(base_path('.github/workflows/deploy.yml'));

    expect($raw)->not->toMatch('/ZEROPS_TOKEN["\']?\s*[:=]\s*["\']?[A-Za-z0-9_-]{16,}/')
        ->and($raw)->not->toMatch('/zerops\.app/i')
        ->and($raw)->not->toContain('pull_request_target');
});

/*
 * Self-check: each mutation weakens one property and must be reported by the
 * check that guards it, otherwise that check would pass vacuously.
 */
it('reports every weakening of the workflow', function (): void {
    $good = deployWorkflow();
    expect(deployAllProblems($good))->each->toBe([]);

    $mutations = [
        'an extra push trigger' => ['triggers', function (array $w): array {
            $w['on']['push'] = ['branches' => ['main']];

            return $w;
        }],
        'a prerelease-capable release type' => ['triggers', function (array $w): array {
            $w['on']['release']['types'] = ['published', 'created'];

            return $w;
        }],
        'a dispatch input' => ['triggers', function (array $w): array {
            $w['on']['workflow_dispatch'] = ['inputs' => ['ref' => ['type' => 'string']]];

            return $w;
        }],
        'a top-level write permission' => ['permissions', function (array $w): array {
            $w['permissions'] = ['contents' => 'write'];

            return $w;
        }],
        'an event expression in a run script' => ['injection', function (array $w): array {
            $w['jobs']['verify']['steps'][1]['run'] .= "\necho \${{ github.event.release.name }}";

            return $w;
        }],
        'a secret outside the deploy job' => ['secrets', function (array $w): array {
            $w['jobs']['verify']['steps'][1]['env']['LEAK'] = '${{ secrets.ZEROPS_TOKEN }}';

            return $w;
        }],
        'a secret in a run script' => ['secrets', function (array $w): array {
            $w['jobs']['deploy']['steps'][2]['run'] = 'zcli login ${{ secrets.ZEROPS_TOKEN }}';

            return $w;
        }],
        'a secret at job level' => ['secrets', function (array $w): array {
            $w['jobs']['deploy']['env']['T'] = '${{ secrets.ZEROPS_TOKEN }}';

            return $w;
        }],
        'an unpinned action' => ['actions', function (array $w): array {
            $w['jobs']['deploy']['steps'][0]['uses'] = 'actions/checkout@v7';

            return $w;
        }],
        'a checkout that keeps credentials' => ['actions', function (array $w): array {
            $w['jobs']['deploy']['steps'][0]['with']['persist-credentials'] = true;

            return $w;
        }],
        'a missing production environment' => ['environment', function (array $w): array {
            unset($w['jobs']['deploy']['environment']);

            return $w;
        }],
        'a deploy job that does not need verify' => ['environment', function (array $w): array {
            unset($w['jobs']['deploy']['needs']);

            return $w;
        }],
        'cancelling concurrency' => ['environment', function (array $w): array {
            $w['concurrency']['cancel-in-progress'] = true;

            return $w;
        }],
        'a verify script without the ancestry check' => ['verify', function (array $w): array {
            $w['jobs']['verify']['steps'][1]['run'] = 'true';

            return $w;
        }],
        'a malformed digest' => ['install', function (array $w): array {
            $w['jobs']['deploy']['env']['ZCLI_SHA256'] = 'ABC';

            return $w;
        }],
        'a push in the wrong order' => ['push', function (array $w): array {
            $steps = $w['jobs']['deploy']['steps'];
            $last = count($steps) - 1;
            [$steps[$last], $steps[$last - 1]] = [$steps[$last - 1], $steps[$last]];
            $w['jobs']['deploy']['steps'] = $steps;

            return $w;
        }],
    ];

    foreach ($mutations as $label => [$category, $mutate]) {
        $problems = deployAllProblems($mutate($good));

        expect($problems[$category])->not->toBe([], "mutation '{$label}' was not reported by '{$category}'");
    }
});

/**
 * Runs the verify script of the workflow in a throwaway repository.
 *
 * @param  array<string, string>  $env
 */
function runDeployVerifyScript(string $repo, array $env): Process
{
    $script = '';

    foreach (deploySteps(deployJobs(deployWorkflow())['verify']) as $step) {
        $script .= (string) ($step['run'] ?? '')."\n";
    }

    $process = new Process(['bash', '-c', $script], $repo, $env + ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1']);
    $process->run();

    return $process;
}

/**
 * A repository with main (one commit) and a side branch with one more commit,
 * the way a checkout with fetch-depth 0 leaves origin/main.
 *
 * @return array{string, string, string}
 */
function deployThrowawayRepo(): array
{
    $dir = sys_get_temp_dir().'/kokpit-deploy-'.bin2hex(random_bytes(6));
    mkdir($dir);

    $git = function (string ...$args) use ($dir): string {
        $process = new Process(['git', '-c', 'user.name=Jane Example', '-c', 'user.email=jane@'.implode('.', ['example', 'com']), ...$args], $dir, ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1']);
        $process->mustRun();

        return trim($process->getOutput());
    };

    $git('init', '--quiet', '--initial-branch=main');
    $git('commit', '--quiet', '--allow-empty', '-m', 'main commit');
    $mainSha = $git('rev-parse', 'HEAD');
    $git('update-ref', 'refs/remotes/origin/main', $mainSha);
    $git('checkout', '--quiet', '-b', 'side');
    $git('commit', '--quiet', '--allow-empty', '-m', 'side commit');
    $sideSha = $git('rev-parse', 'HEAD');

    return [$dir, $mainSha, $sideSha];
}

it('lets the verify script pass a stable v* release on a commit of main', function (): void {
    [$repo, $mainSha] = deployThrowawayRepo();

    $process = runDeployVerifyScript($repo, [
        'EVENT_NAME' => 'release', 'IS_PRERELEASE' => 'false', 'REF_TYPE' => 'tag', 'REF_NAME' => 'v1.0.0', 'GITHUB_SHA' => $mainSha,
    ]);

    expect($process->getExitCode())->toBe(0);
});

it('lets the verify script pass a manual dispatch on a commit of main', function (): void {
    [$repo, $mainSha] = deployThrowawayRepo();

    $process = runDeployVerifyScript($repo, [
        'EVENT_NAME' => 'workflow_dispatch', 'IS_PRERELEASE' => '', 'REF_TYPE' => 'branch', 'REF_NAME' => 'main', 'GITHUB_SHA' => $mainSha,
    ]);

    expect($process->getExitCode())->toBe(0);
});

it('makes the verify script refuse a prerelease', function (): void {
    [$repo, $mainSha] = deployThrowawayRepo();

    $process = runDeployVerifyScript($repo, [
        'EVENT_NAME' => 'release', 'IS_PRERELEASE' => 'true', 'REF_TYPE' => 'tag', 'REF_NAME' => 'v1.0.0-rc1', 'GITHUB_SHA' => $mainSha,
    ]);

    expect($process->getExitCode())->not->toBe(0);
});

it('makes the verify script refuse a release tag outside v*', function (): void {
    [$repo, $mainSha] = deployThrowawayRepo();

    $process = runDeployVerifyScript($repo, [
        'EVENT_NAME' => 'release', 'IS_PRERELEASE' => 'false', 'REF_TYPE' => 'tag', 'REF_NAME' => 'nightly-1', 'GITHUB_SHA' => $mainSha,
    ]);

    expect($process->getExitCode())->not->toBe(0);
});

it('makes the verify script refuse a commit that is not an ancestor of main', function (): void {
    [$repo, , $sideSha] = deployThrowawayRepo();

    $process = runDeployVerifyScript($repo, [
        'EVENT_NAME' => 'release', 'IS_PRERELEASE' => 'false', 'REF_TYPE' => 'tag', 'REF_NAME' => 'v1.0.0', 'GITHUB_SHA' => $sideSha,
    ]);

    expect($process->getExitCode())->not->toBe(0);
});
