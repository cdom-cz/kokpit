<?php

declare(strict_types=1);

/**
 * check-licenses.php - dependency licence gate for an AGPL-3.0 project (FND-13).
 *
 * Usage: composer licenses --locked --format=json | php scripts/check-licenses.php
 *
 * Rule: a package passes when AT LEAST ONE of its declared licences is on the allowlist below. Composer
 * lists the alternatives of a dual-licensed package separately (for example BSD-3-Clause or GPL-2.0-only or
 * GPL-3.0-only), so a per-licence check would wrongly reject them. A package that declares no licence fails.
 *
 * Exit codes (same convention as scripts/check-sensitive.sh):
 *   0  every package has an allowed licence
 *   1  offenders, one per line on stderr as `name [licence, licence]` or `name [no licence declared]`
 *   2  input that is not a Composer licence report
 *
 * A failure is a prompt to DECIDE, never a reason to extend the list silently: look at the package, check
 * its licence against AGPL-3.0 compatibility, and only then change the list in a reviewed commit. Absent on
 * purpose: GPL-2.0-only (incompatible with AGPL-3.0), anything proprietary, and packages with no licence.
 * This is a compatibility screen over declared licences, not legal advice; it cannot see what a package's
 * files actually contain.
 */
const ALLOWED_LICENCES = [
    'MIT',
    'MIT-0',
    'BSD-2-Clause',
    'BSD-3-Clause',
    '0BSD',
    'ISC',
    'Apache-2.0',
    'Unlicense',
    'CC0-1.0',
    'MPL-2.0',
    'LGPL-2.1-only',
    'LGPL-2.1-or-later',
    'LGPL-3.0-only',
    'LGPL-3.0-or-later',
    'GPL-3.0-only',
    'GPL-3.0-or-later',
    'GPL-2.0-or-later',
    'AGPL-3.0-only',
    'AGPL-3.0-or-later',
];

function licenceCheckMalformed(string $reason): never
{
    fwrite(STDERR, "check-licenses: input is not a composer licenses report: {$reason}\n");

    exit(2);
}

$input = stream_get_contents(STDIN);

try {
    $report = json_decode($input === false ? '' : $input, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    licenceCheckMalformed($exception->getMessage());
}

if (! is_array($report) || ! isset($report['dependencies']) || ! is_array($report['dependencies'])) {
    licenceCheckMalformed('the "dependencies" object is missing');
}

$offenders = [];

foreach ($report['dependencies'] as $name => $package) {
    if (! is_array($package)) {
        licenceCheckMalformed("package {$name} is not an object");
    }

    $licences = $package['license'] ?? [];

    if (! is_array($licences) || array_filter($licences, static fn (mixed $licence): bool => ! is_string($licence)) !== []) {
        licenceCheckMalformed("the licence of package {$name} is not a list of strings");
    }

    if ($licences === []) {
        $offenders[(string) $name] = 'no licence declared';

        continue;
    }

    if (array_intersect($licences, ALLOWED_LICENCES) === []) {
        $offenders[(string) $name] = implode(', ', $licences);
    }
}

if ($offenders !== []) {
    ksort($offenders);

    foreach ($offenders as $name => $licences) {
        fwrite(STDERR, sprintf("%s [%s]\n", $name, $licences));
    }

    exit(1);
}

fwrite(STDOUT, sprintf("check-licenses: %d packages, every one has an allowed licence\n", count($report['dependencies'])));

exit(0);
