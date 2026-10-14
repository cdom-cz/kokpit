<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Reports\ReportFilters;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Tests\Support\Canary;

/*
 * The filters of the time report (T-08-32): the URL and the Livewire properties are client-controlled, so every
 * value is validated and anything invalid falls back to the default. Every name below is fictional.
 */

beforeEach(function (): void {
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    // Wednesday 2026-10-14.
    $this->now = CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC');
});

/**
 * A client and one project of it, written as a system run like seed code.
 *
 * @return array{client: Client, project: Project}
 */
function filtersWorld(): array
{
    return app(PartnerContext::class)->runAsSystem(static function (): array {
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);

        return ['client' => $client, 'project' => $project];
    });
}

describe('the period', function (): void {
    it('reads this month by default', function (): void {
        $filters = ReportFilters::fromInput([], $this->now);

        expect($filters->period)->toBe('this_month')
            ->and($filters->from)->toBe('2026-10-01')
            ->and($filters->to)->toBe('2026-10-31')
            ->and($filters->split)->toBe('none')
            ->and($filters->clientId)->toBeNull()
            ->and($filters->projectId)->toBeNull()
            ->and($filters->rangeError)->toBeFalse();
    });

    it('reads last month', function (): void {
        $filters = ReportFilters::fromInput(['period' => 'last_month'], $this->now);

        expect($filters->period)->toBe('last_month')
            ->and($filters->from)->toBe('2026-09-01')
            ->and($filters->to)->toBe('2026-09-30');
    });

    it('reads last month across a year boundary and a short month', function (): void {
        $january = ReportFilters::fromInput(['period' => 'last_month'], CarbonImmutable::parse('2027-01-15 10:00:00', 'UTC'));
        $march = ReportFilters::fromInput(['period' => 'last_month'], CarbonImmutable::parse('2027-03-31 10:00:00', 'UTC'));

        expect([$january->from, $january->to])->toBe(['2026-12-01', '2026-12-31'])
            ->and([$march->from, $march->to])->toBe(['2027-02-01', '2027-02-28']);
    });

    it('takes the month of the Prague day, not of the UTC day', function (): void {
        // 2026-09-30 22:30 UTC is already 2026-10-01 in Prague.
        $filters = ReportFilters::fromInput([], CarbonImmutable::parse('2026-09-30 22:30:00', 'UTC'));

        expect([$filters->from, $filters->to])->toBe(['2026-10-01', '2026-10-31']);
    });

    it('keeps a valid custom range', function (): void {
        $filters = ReportFilters::fromInput(['period' => 'custom', 'from' => '2026-10-05', 'to' => '2026-10-11'], $this->now);

        expect($filters->period)->toBe('custom')
            ->and($filters->from)->toBe('2026-10-05')
            ->and($filters->to)->toBe('2026-10-11')
            ->and($filters->rangeError)->toBeFalse();
    });

    it('keeps a custom range of a single day', function (): void {
        $filters = ReportFilters::fromInput(['period' => 'custom', 'from' => '2026-10-05', 'to' => '2026-10-05'], $this->now);

        expect([$filters->from, $filters->to, $filters->rangeError])->toBe(['2026-10-05', '2026-10-05', false]);
    });

    it('falls back to this month and flags the error when the range ends before it starts', function (): void {
        $filters = ReportFilters::fromInput(['period' => 'custom', 'from' => '2026-10-11', 'to' => '2026-10-05'], $this->now);

        expect($filters->period)->toBe('this_month')
            ->and([$filters->from, $filters->to])->toBe(['2026-10-01', '2026-10-31'])
            ->and($filters->rangeError)->toBeTrue();
    });

    it('falls back to this month without an error for a day that does not exist or is missing', function (mixed $from, mixed $to): void {
        $filters = ReportFilters::fromInput(['period' => 'custom', 'from' => $from, 'to' => $to], $this->now);

        expect($filters->period)->toBe('this_month')
            ->and([$filters->from, $filters->to])->toBe(['2026-10-01', '2026-10-31'])
            ->and($filters->rangeError)->toBeFalse();
    })->with([
        'february 30th' => ['2026-02-30', '2026-10-05'],
        'month 13' => ['2026-10-05', '2026-13-01'],
        'trailing text' => ['2026-10-05 and more', '2026-10-06'],
        'wrong format' => ['5. 10. 2026', '11. 10. 2026'],
        'missing' => [null, null],
        'array' => [['2026-10-05'], '2026-10-06'],
        'number' => [2026, '2026-10-06'],
    ]);

    it('falls back to this month for an unknown period', function (mixed $period): void {
        $filters = ReportFilters::fromInput(['period' => $period, 'from' => '2026-10-05', 'to' => '2026-10-11'], $this->now);

        expect($filters->period)->toBe('this_month')
            ->and([$filters->from, $filters->to])->toBe(['2026-10-01', '2026-10-31']);
    })->with(['year' => 'year', 'empty' => '', 'array' => [['custom']], 'null' => null, 'number' => 7]);

    it('gives the instants of the Prague days as the half-open range', function (): void {
        $filters = ReportFilters::fromInput(['period' => 'custom', 'from' => '2026-10-24', 'to' => '2026-10-25'], $this->now);

        // Summer time ends on Sunday 2026-10-25 at 03:00 local time: the range is 2026-10-24 00:00 +02:00 to
        // 2026-10-26 00:00 +01:00.
        expect($filters->startUtc()->format('Y-m-d H:i:s'))->toBe('2026-10-23 22:00:00')
            ->and($filters->endUtcExclusive()->format('Y-m-d H:i:s'))->toBe('2026-10-25 23:00:00');
    });
});

describe('the split', function (): void {
    it('keeps the three values', function (string $split): void {
        expect(ReportFilters::fromInput(['split' => $split], $this->now)->split)->toBe($split);
    })->with(['none', 'day', 'user']);

    it('falls back to none for anything else', function (mixed $split): void {
        expect(ReportFilters::fromInput(['split' => $split], $this->now)->split)->toBe('none');
    })->with(['week' => 'week', 'empty' => '', 'array' => [['day']], 'null' => null, 'number' => 1]);
});

describe('the client and the project', function (): void {
    it('keeps the id of an existing client and of a project of that client', function (): void {
        $world = filtersWorld();

        $filters = ReportFilters::fromInput(['client' => $world['client']->id, 'project' => $world['project']->id], $this->now);

        expect($filters->clientId)->toBe($world['client']->id)
            ->and($filters->projectId)->toBe($world['project']->id);
    });

    it('keeps an archived client and an archived project', function (): void {
        $world = filtersWorld();
        app(PartnerContext::class)->runAsSystem(static function () use ($world): void {
            $world['project']->delete();
            $world['client']->delete();
        });

        $filters = ReportFilters::fromInput(['client' => $world['client']->id, 'project' => $world['project']->id], $this->now);

        expect($filters->clientId)->toBe($world['client']->id)
            ->and($filters->projectId)->toBe($world['project']->id);
    });

    it('drops an id that is no uuid', function (mixed $client): void {
        expect(ReportFilters::fromInput(['client' => $client], $this->now)->clientId)->toBeNull();
    })->with(['abc' => 'abc', 'number' => 12345, 'array' => [['x']], 'empty' => '', 'sql' => "' OR 1=1 --"]);

    it('drops the uuid of no client', function (): void {
        $filters = ReportFilters::fromInput(['client' => '01960000-0000-7000-8000-000000000000'], $this->now);

        expect($filters->clientId)->toBeNull();
    });

    it('drops a project of another client', function (): void {
        $first = filtersWorld();
        $second = filtersWorld();

        $filters = ReportFilters::fromInput(['client' => $first['client']->id, 'project' => $second['project']->id], $this->now);

        expect($filters->clientId)->toBe($first['client']->id)
            ->and($filters->projectId)->toBeNull();
    });

    it('drops a project without a client', function (): void {
        $world = filtersWorld();

        $filters = ReportFilters::fromInput(['project' => $world['project']->id], $this->now);

        expect($filters->clientId)->toBeNull()
            ->and($filters->projectId)->toBeNull();
    });

    it('drops a project that is no uuid or does not exist', function (mixed $project): void {
        $world = filtersWorld();

        expect(ReportFilters::fromInput(['client' => $world['client']->id, 'project' => $project], $this->now)->projectId)->toBeNull();
    })->with(['abc' => 'abc', 'unknown uuid' => '01960000-0000-7000-8000-000000000000', 'array' => [['x']]]);

    it('finds no client for a Partner, so the ids are dropped', function (): void {
        $world = filtersWorld();
        $this->actingAs(Canary::partnerFor($world['client']->id));
        app()->forgetScopedInstances();

        $filters = ReportFilters::fromInput(['client' => $world['client']->id, 'project' => $world['project']->id], $this->now);

        expect($filters->clientId)->toBeNull()
            ->and($filters->projectId)->toBeNull();
    });
});

describe('the stored form', function (): void {
    it('round-trips through toArray and fromStored', function (): void {
        $world = filtersWorld();
        $filters = ReportFilters::period('2026-09-01', '2026-09-30', $world['client']->id, $world['project']->id);

        $stored = $filters->toArray();
        $again = ReportFilters::fromStored($stored);

        expect($stored)->toBe(['client' => $world['client']->id, 'project' => $world['project']->id, 'from' => '2026-09-01', 'to' => '2026-09-30'])
            ->and($again->toArray())->toBe($stored)
            ->and($again->period)->toBe('custom');
    });

    it('stores a report of all clients', function (): void {
        $filters = ReportFilters::period('2026-09-01', '2026-09-30');

        expect($filters->toArray())->toBe(['client' => null, 'project' => null, 'from' => '2026-09-01', 'to' => '2026-09-30'])
            ->and(ReportFilters::fromStored($filters->toArray())->toArray())->toBe($filters->toArray());
    });

    it('refuses a stored value that is no longer valid instead of exporting other data', function (array $stored): void {
        expect(fn () => ReportFilters::fromStored($stored))->toThrow(InvalidArgumentException::class);
    })->with([
        'reversed range' => [['client' => null, 'project' => null, 'from' => '2026-09-30', 'to' => '2026-09-01']],
        'bad day' => [['client' => null, 'project' => null, 'from' => '2026-02-30', 'to' => '2026-03-01']],
        'unknown client' => [['client' => '01960000-0000-7000-8000-000000000000', 'project' => null, 'from' => '2026-09-01', 'to' => '2026-09-30']],
        'not a uuid' => [['client' => 'abc', 'project' => null, 'from' => '2026-09-01', 'to' => '2026-09-30']],
        'project without client' => [['client' => null, 'project' => '01960000-0000-7000-8000-000000000000', 'from' => '2026-09-01', 'to' => '2026-09-30']],
        'missing days' => [['client' => null, 'project' => null]],
        'array day' => [['client' => null, 'project' => null, 'from' => ['2026-09-01'], 'to' => '2026-09-30']],
    ]);

    it('refuses a stored project of another client', function (): void {
        $first = filtersWorld();
        $second = filtersWorld();

        expect(fn () => ReportFilters::period('2026-09-01', '2026-09-30', $first['client']->id, $second['project']->id))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('the query string', function (): void {
    it('leaves the defaults out', function (): void {
        expect(ReportFilters::fromInput([], $this->now)->toQuery())->toBe([]);
    });

    it('carries every chosen value', function (): void {
        $world = filtersWorld();

        $query = ReportFilters::fromInput([
            'client' => $world['client']->id,
            'project' => $world['project']->id,
            'period' => 'custom',
            'from' => '2026-10-05',
            'to' => '2026-10-11',
            'split' => 'day',
        ], $this->now)->toQuery();

        expect($query)->toBe([
            'client' => $world['client']->id,
            'project' => $world['project']->id,
            'period' => 'custom',
            'from' => '2026-10-05',
            'to' => '2026-10-11',
            'split' => 'day',
        ]);
    });

    it('names a preset period without its days', function (): void {
        expect(ReportFilters::fromInput(['period' => 'last_month'], $this->now)->toQuery())->toBe(['period' => 'last_month']);
    });
});
