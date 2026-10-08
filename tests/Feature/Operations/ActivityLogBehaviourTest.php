<?php

declare(strict_types=1);

use App\Domain\Audit\LoggedAttributes;
use App\Domain\Audit\LogsAllowlistedActivity;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Models\KokpitModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Support\Canary;
use Tests\Support\Probes\ActivityProbe;

/*
 * The allowlisted activity log (D-06): a model logs only the attributes it
 * declares with #[LoggedAttributes], through the LogsAllowlistedActivity
 * wrapper, and no option of the package can widen that list.
 */

beforeEach(function (): void {
    ActivityProbe::provision();
    $this->actingAs(Canary::admin());
});

afterEach(function (): void {
    ActivityProbe::restoreMorphMap();
});

/**
 * Activity rows of one probe for one event.
 *
 * @return Collection<int, Activity>
 */
function probeActivityRows(ActivityProbe $probe, string $event): Collection
{
    return Activity::query()->forSubject($probe)->forEvent($event)->get();
}

/**
 * Creates a probe carrying runtime canary strings in both non-allowlisted columns.
 *
 * @return array{0: ActivityProbe, 1: string, 2: string}
 */
function probeWithCanaries(): array
{
    $note = Canary::canary('note');
    $token = Canary::canary('token');
    $probe = ActivityProbe::query()->create([
        'title' => 'Fictional probe',
        'status' => 'open',
        'secret_note' => $note,
        'api_token' => $token,
    ]);

    return [$probe, $note, $token];
}

it('logs a create with the allowlisted attributes only', function (): void {
    [$probe] = probeWithCanaries();

    $rows = probeActivityRows($probe, 'created');

    expect($rows)->toHaveCount(1);

    $row = $rows->first();
    $attributes = $row->attribute_changes->get('attributes');
    ksort($attributes);

    expect($row->log_name)->toBe('activity_probe')
        ->and($attributes)->toBe(['status' => 'open', 'title' => 'Fictional probe']);
});

it('writes no row for an update that touches only non-allowlisted attributes', function (): void {
    [$probe] = probeWithCanaries();

    $probe->update(['secret_note' => Canary::canary('other')]);
    $probe->update(['api_token' => Canary::canary('other')]);

    expect(probeActivityRows($probe, 'updated'))->toHaveCount(0)
        ->and(Activity::query()->forSubject($probe)->count())->toBe(1);
});

it('logs only the allowlisted old and new values when an update mixes both kinds', function (): void {
    [$probe] = probeWithCanaries();

    $probe->update(['status' => 'closed', 'secret_note' => Canary::canary('other')]);

    $rows = probeActivityRows($probe, 'updated');

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->attribute_changes->get('attributes'))->toBe(['status' => 'closed'])
        ->and($rows->first()->attribute_changes->get('old'))->toBe(['status' => 'open']);
});

it('logs a delete with the old allowlisted values only', function (): void {
    [$probe] = probeWithCanaries();

    $probe->delete();

    $rows = Activity::query()->forSubject($probe)->forEvent('deleted')->get();
    $old = $rows->first()?->attribute_changes->get('old');
    ksort($old);

    expect($rows)->toHaveCount(1)
        ->and($old)->toBe(['status' => 'open', 'title' => 'Fictional probe'])
        ->and($rows->first()->attribute_changes->has('attributes'))->toBeFalse();
});

it('never lets a non-allowlisted or hidden value into any column of the log', function (): void {
    [$probe, $note, $token] = probeWithCanaries();

    $probe->update(['status' => 'closed', 'secret_note' => Canary::canary('other')]);
    $probe->delete();

    $everything = json_encode(DB::table('activity_log')->get()->all(), JSON_THROW_ON_ERROR);

    expect(Activity::query()->forSubject($probe)->count())->toBe(3)
        ->and($everything)->not->toContain($note)
        ->and($everything)->not->toContain($token);
});

it('fails loudly on the first save when the wrapper has no allowlist attribute', function (): void {
    $model = new class extends KokpitModel
    {
        use LogsAllowlistedActivity;

        protected $table = 'activity_probes';

        protected $guarded = [];
    };

    expect(fn () => $model->newQuery()->create(['title' => 'Fictional probe']))
        ->toThrow(LogicException::class);
});

it('fails loudly on the first save when the allowlist is empty', function (): void {
    $model = new #[LoggedAttributes([])] class extends KokpitModel
    {
        use LogsAllowlistedActivity;

        protected $table = 'activity_probes';

        protected $guarded = [];
    };

    expect(fn () => $model->newQuery()->create(['title' => 'Fictional probe']))
        ->toThrow(LogicException::class);
});

it('writes no row for a bulk query update, a documented limit', function (): void {
    [$probe] = probeWithCanaries();

    ActivityProbe::query()->whereKey($probe->getKey())->update(['status' => 'closed']);

    expect(probeActivityRows($probe, 'updated'))->toHaveCount(0)
        ->and(Activity::query()->forSubject($probe)->count())->toBe(1);
});
