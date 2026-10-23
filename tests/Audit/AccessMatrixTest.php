<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\AcceptInvitation;
use App\Filament\Pages\Dashboard;
use App\Filament\Partner\Resources\PartnerTaskResource;
use App\Filament\Partner\Resources\PartnerTaskResource\RelationManagers\PartnerTaskCommentsRelationManager;
use App\Filament\Resources\ClientResource\RelationManagers\ContactsRelationManager;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\Pages\ProjectBoard;
use App\Filament\Widgets\PartnerTasks;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Tests\Support\Canary;
use Tests\Support\CanaryRegistry;
use Tests\Support\CardPaymentFixtures;
use Tests\Support\PanelInventory;
use Tests\Support\PartnerMatrix;

/*
 * US-01, AUD-02, D-01, D-02, D-11, R-12-03-1/2/3: the permission matrix of the screens and endpoints.
 *
 * tests/Support/PartnerMatrix.php declares, as literal data, what the Admin and a Partner with a client may
 * open: every Resource, Page, Widget, cluster and relation manager of the admin panel (plus the test-only canary
 * resource while the harness is on). Completeness is checked in both directions, so a discovered class without a
 * row fails the build and so does a row without a class (R-12-03-1). For every row the observed access of the
 * Admin and of a Partner (canAccess, canView or canViewForRecord on a real canary record of client A, R-12-03-2)
 * equals the declared one, and every Resource index and panel Page answers an HTTP request 200 when declared
 * allowed and 403 when refused. The declaration is never derived from the code under test, and a guard test
 * fails if the matrix file refers to the access-rule reader, the audience enum or a policy (D-01).
 * Where the code differs from the draft the row states what the code does and the difference is reported to the
 * owner for sign-off (R-12-03-3, D-02).
 */

/**
 * What differs between the discovered set and the declared set, one line per offender; empty when they match.
 * Pure over its arguments, so the self-tests can feed it planted classes and planted rows.
 *
 * @param  list<string>  $discovered
 * @param  list<string>  $declared
 * @return list<string>
 */
function auditAccessDifferences(string $kind, array $discovered, array $declared): array
{
    $differences = [];

    foreach (array_diff($discovered, $declared) as $class) {
        $differences[] = "{$kind} {$class} has no PartnerMatrix row";
    }

    foreach (array_diff($declared, $discovered) as $class) {
        $differences[] = "PartnerMatrix declares {$kind} {$class} but no such {$kind} exists";
    }

    sort($differences);

    return $differences;
}

/**
 * The declared panel rows that apply to this run: the test-only canary row only counts while the harness is on.
 *
 * @return array<class-string, array{admin: bool, partner: bool, reason: string, testOnly?: bool}>
 */
function auditAccessPanelRows(): array
{
    return array_filter(
        PartnerMatrix::panelClasses(),
        static fn (array $row): bool => ! ($row['testOnly'] ?? false) || config('kokpit.canary_harness') === true,
    );
}

/**
 * What in the matrix source breaks the rule that the declaration is literal: a reference to the access-rule
 * reader, the audience enum, a policy, a gate or an access method. Pure over the source text.
 *
 * @return list<string>
 */
function auditAccessLiteralProblems(string $source): array
{
    $problems = [];

    foreach (['AccessRule', 'Audience', 'Policy', 'Policies', 'Gate::', 'canAccess', 'canView', 'canViewForRecord'] as $needle) {
        if (str_contains($source, $needle)) {
            $problems[] = "PartnerMatrix refers to {$needle}";
        }
    }

    return $problems;
}

it('declares every panel class and only existing ones', function (): void {
    $differences = auditAccessDifferences('panel class', PanelInventory::panelClasses(), array_keys(auditAccessPanelRows()));

    expect($differences)->toBe([], implode("\n", $differences));
});

it('does not pass vacuously: the discovery sees every kind of panel class', function (): void {
    $classes = PanelInventory::panelClasses();

    expect(count($classes))->toBeGreaterThan(60)
        ->and($classes)->toContain(Dashboard::class)
        ->and($classes)->toContain(ProjectResource::class)
        ->and($classes)->toContain(ProjectBoard::class)
        ->and($classes)->toContain(PartnerTasks::class)
        ->and($classes)->toContain(PartnerTaskResource::class)
        ->and($classes)->toContain(PartnerTaskCommentsRelationManager::class)
        ->and($classes)->toContain(AcceptInvitation::class)
        ->and(array_keys(PanelInventory::relationManagers()))->toContain(ContactsRelationManager::class);
});

it('reports an undeclared panel class and a row without a class', function (): void {
    $planted = 'App\\Filament\\Pages\\PlantedAuditPage';
    $ghost = 'App\\Filament\\Pages\\GhostAuditPage';

    $undeclared = auditAccessDifferences('panel class', [...PanelInventory::panelClasses(), $planted], array_keys(auditAccessPanelRows()));
    $stale = auditAccessDifferences('panel class', PanelInventory::panelClasses(), [...array_keys(auditAccessPanelRows()), $ghost]);

    expect($undeclared)->toBe(["panel class {$planted} has no PartnerMatrix row"])
        ->and($stale)->toBe(["PartnerMatrix declares panel class {$ghost} but no such panel class exists"])
        ->and(auditAccessDifferences('panel class', ['A', 'B'], ['A', 'B']))->toBe([]);
});

it('keeps every row reasoned', function (): void {
    expect(PartnerMatrix::problems())->toBe([]);
});

it('keeps the matrix literal', function (): void {
    $source = file_get_contents(base_path('tests/Support/PartnerMatrix.php'));

    expect(auditAccessLiteralProblems((string) $source))->toBe([]);
});

it('reports a matrix source that reads the access rules', function (): void {
    expect(auditAccessLiteralProblems('<?php return AccessRules::allows(Foo::class);'))->toBe(['PartnerMatrix refers to AccessRule'])
        ->and(auditAccessLiteralProblems('<?php return Audience::AdminOnly;'))->toBe(['PartnerMatrix refers to Audience'])
        ->and(auditAccessLiteralProblems('<?php return Foo::class => [];'))->toBe([]);
});

describe('with canaries of two clients seeded', function (): void {
    beforeEach(function (): void {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        CanaryRegistry::prepare();

        [$this->clientA, $this->clientB] = Canary::twoClients();
        $this->canaryA = Canary::canary('client_a');
        $this->canaryB = Canary::canary('client_b');

        CanaryRegistry::seedAll($this->clientA, $this->canaryA);
        CanaryRegistry::seedAll($this->clientB, $this->canaryB);

        // The unresolved-payments widget shows itself only while one card payment is unresolved.
        CardPaymentFixtures::unmatched();
    });

    afterEach(function (): void {
        CanaryRegistry::cleanup();
    });

    it('behaves for the Admin and for a Partner as {0} declares', function (string $class): void {
        $row = auditAccessPanelRows()[$class] ?? null;

        if ($row === null) {
            $this->markTestSkipped("{$class} is a test-only row and the canary harness is off.");
        }

        $owner = array_key_exists($class, PanelInventory::relationManagers())
            ? PanelInventory::canaryRecord(PanelInventory::ownerModel($class), $this->canaryA)
            : null;

        $observed = [];

        foreach (['admin' => static fn () => Canary::admin(), 'partner' => fn () => Canary::partnerFor($this->clientA)] as $role => $make) {
            Auth::forgetGuards();
            $this->actingAs($make());
            $observed[$role] = PanelInventory::observedAccess($class, $owner);
        }

        expect($observed)->toBe(['admin' => $row['admin'], 'partner' => $row['partner']], $class);
    })->with(array_map(
        static fn (string $class): array => [$class],
        array_keys(PartnerMatrix::panelClasses()),
    ));

    it('answers 200 or 403 on every Resource index and panel Page as declared', function (): void {
        $offenders = [];
        $requested = 0;

        foreach (auditAccessPanelRows() as $class => $row) {
            $url = PanelInventory::requestUrl($class, $this->canaryA);

            if ($url === null) {
                continue;
            }

            foreach (['admin' => static fn () => Canary::admin(), 'partner' => fn () => Canary::partnerFor($this->clientA)] as $role => $make) {
                Auth::forgetGuards();
                $expected = $row[$role] ? 200 : 403;
                $status = $this->actingAs($make())->get($url)->getStatusCode();
                $requested++;

                if ($status !== $expected) {
                    $offenders[] = "{$class} as {$role}: expected {$expected}, got {$status}";
                }
            }
        }

        expect($requested)->toBeGreaterThan(40)
            ->and($offenders)->toBe([], implode("\n", $offenders));
    });
});
