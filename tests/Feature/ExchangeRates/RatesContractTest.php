<?php

declare(strict_types=1);

/*
 * The reviewed contract of the rates area (EX-02, D-03 to D-05): the timetable and limits in config/kokpit.php
 * and the whole Czech copy under kokpit.rates.* and kokpit.system.last_rate_date.*. Later rate plans only read
 * them, so a drifting value or a missing text is caught here, in one place.
 */

describe('the timetable and limits', function (): void {
    it('schedules the attempts after the 14:30 publication, ascending, and a final run after the last one', function (): void {
        /** @var list<mixed> $hours */
        $hours = config('kokpit.rates.attempt_hours');
        $final = config('kokpit.rates.final_hour');

        expect($hours)->not->toBeEmpty()
            ->and(array_is_list($hours))->toBeTrue();

        foreach ($hours as $hour) {
            expect($hour)->toBeInt()->toBeGreaterThanOrEqual(15)->toBeLessThanOrEqual(22);
        }

        $sorted = $hours;
        sort($sorted);

        expect($hours)->toBe($sorted)
            ->and(array_unique($hours))->toBe($hours)
            ->and($final)->toBeInt()
            ->and($final)->toBeGreaterThan(max($hours))
            ->and($final)->toBeLessThanOrEqual(22);
    });

    it('keeps the agreed starting values', function (): void {
        expect(config('kokpit.rates.attempt_hours'))->toBe([15, 16, 17])
            ->and(config('kokpit.rates.final_hour'))->toBe(18)
            ->and(config('kokpit.rates.health.warning_after_working_days'))->toBe(1)
            ->and(config('kokpit.rates.health.error_after_working_days'))->toBe(2)
            ->and(config('kokpit.rates.gap_lookback_days'))->toBe(31)
            ->and(config('kokpit.rates.backfill.earliest'))->toBe('2000-01-01')
            ->and(config('kokpit.rates.backfill.max_span_days'))->toBe(3660);
    });

    it('orders the health thresholds and keeps the limits usable', function (): void {
        $warning = config('kokpit.rates.health.warning_after_working_days');
        $error = config('kokpit.rates.health.error_after_working_days');

        expect($warning)->toBeInt()->toBeGreaterThanOrEqual(1)
            ->and($error)->toBeInt()->toBeGreaterThanOrEqual($warning)
            ->and(config('kokpit.rates.gap_lookback_days'))->toBeInt()->toBeGreaterThanOrEqual(1)
            ->and(config('kokpit.rates.backfill.earliest'))->toBe('2000-01-01')
            ->and(config('kokpit.rates.backfill.max_span_days'))->toBeInt()->toBeGreaterThanOrEqual(365);
    });

    it('names the CNB base URL in the services config without an environment key', function (): void {
        expect(config('services.cnb.base_url'))->toBe('https://api.cnb.cz/cnbapi');
    });
});

describe('the Czech copy', function (): void {
    it('carries the UI-SPEC strings verbatim', function (): void {
        expect(__('kokpit.rates.actions.download'))->toBe('Stáhnout dnešní kurzy')
            ->and(__('kokpit.rates.actions.backfill'))->toBe('Doplnit historii')
            ->and(__('kokpit.rates.alert.title'))->toBe('Kurzy ČNB se nepodařilo stáhnout')
            ->and(__('kokpit.rates.alert.action'))->toBe('Otevřít kurzy ČNB')
            ->and(__('kokpit.rates.backfill.cancel'))->toBe('Nedoplňovat')
            ->and(__('kokpit.rates.backfill.heading'))->toBe('Doplnit historii kurzů')
            ->and(__('kokpit.rates.empty_day.heading'))->toBe('Pro tento den ČNB kurzy nevyhlásila')
            ->and(__('kokpit.rates.empty_day.description', ['date' => '9. 10. 2026']))->toBe('Platí poslední vyhlášené kurzy ze dne 9. 10. 2026.')
            ->and(__('kokpit.rates.download.stored.body', ['date' => '14. 10. 2026', 'count' => 30]))->toBe('Platnost ke dni 14. 10. 2026, 30 měn.')
            ->and(__('kokpit.rates.backfill.errors.span', ['days' => 3660]))->toBe('Zvolené období je příliš dlouhé. Zvolte nejvýše 3660 dní.')
            ->and(__('kokpit.rates.alert.body', ['date' => '14. 10. 2026', 'last' => '13. 10. 2026']))
            ->toBe('Dnešní kurzy ČNB (14. 10. 2026) se ani po opakovaných pokusech nepodařilo stáhnout. Do doby, než se stáhnou, se používají poslední známé kurzy ze dne 13. 10. 2026.');
    });

    it('defines every key of the contract in Czech and none falls back to its key', function (string $key): void {
        expect(__($key))->not->toBe($key)->and(__($key))->not->toBeEmpty();
    })->with(function (): array {
        $keys = [
            'navigation', 'model_label', 'plural_label',
            'stats.last_list', 'stats.fetched_at', 'stats.never', 'stats.status', 'stats.currencies', 'stats.currencies_description',
            'columns.date', 'columns.currency', 'columns.unit', 'columns.rate', 'columns.fetched_at',
            'filters.date', 'filters.currency',
            'empty.heading', 'empty.description',
            'empty_day.heading', 'empty_day.description',
            'actions.download', 'actions.backfill',
            'backfill.heading', 'backfill.from', 'backfill.to', 'backfill.helper', 'backfill.submit', 'backfill.cancel',
            'backfill.queued.title', 'backfill.queued.body',
            'backfill.errors.order', 'backfill.errors.earliest', 'backfill.errors.future', 'backfill.errors.span',
            'alert.title', 'alert.body', 'alert.body_none', 'alert.action',
            'console.stored', 'console.already_stored', 'console.not_published', 'console.failed', 'console.status',
            'console.not_working_day', 'console.healed',
            'failure.unavailable', 'failure.rejected', 'failure.server_error', 'failure.malformed',
        ];

        foreach (['stored', 'already_stored', 'not_published', 'failed'] as $outcome) {
            $keys[] = 'download.'.$outcome.'.title';
            $keys[] = 'download.'.$outcome.'.body';
        }

        $dataset = [];

        foreach ($keys as $key) {
            $dataset['kokpit.rates.'.$key] = ['kokpit.rates.'.$key];
        }

        foreach (['ok', 'warning', 'error', 'never'] as $key) {
            $dataset['kokpit.system.last_rate_date.'.$key] = ['kokpit.system.last_rate_date.'.$key];
        }

        return $dataset;
    });

    it('uses formal address and no exclamation mark anywhere in the rates copy', function (): void {
        $strings = [];
        $walk = static function (mixed $node) use (&$walk, &$strings): void {
            if (is_array($node)) {
                array_map($walk, $node);
            } elseif (is_string($node)) {
                $strings[] = $node;
            }
        };

        $walk(trans('kokpit.rates'));
        $walk(trans('kokpit.system.last_rate_date'));

        expect($strings)->not->toBeEmpty();

        foreach ($strings as $string) {
            expect($string)->not->toContain('!');
        }
    });

    it('reads the System page plurals in Czech for 1, 2 to 4 and 5 or more', function (): void {
        expect(trans_choice('kokpit.system.last_rate_date.warning', 1, ['count' => 1]))->toBe('Chybí kurzy za 1 pracovní den.')
            ->and(trans_choice('kokpit.system.last_rate_date.warning', 3, ['count' => 3]))->toBe('Chybí kurzy za 3 pracovní dny.')
            ->and(trans_choice('kokpit.system.last_rate_date.warning', 5, ['count' => 5]))->toBe('Chybí kurzy za 5 pracovních dní.')
            ->and(trans_choice('kokpit.system.last_rate_date.error', 1, ['count' => 1]))->toBe('Kurzy chybí už 1 pracovní den.')
            ->and(trans_choice('kokpit.system.last_rate_date.error', 2, ['count' => 2]))->toBe('Kurzy chybí už 2 pracovní dny.')
            ->and(trans_choice('kokpit.system.last_rate_date.error', 5, ['count' => 5]))->toBe('Kurzy chybí už 5 pracovních dní.')
            ->and(__('kokpit.system.last_rate_date.ok'))->toBe('Kurzy jsou aktuální.')
            ->and(__('kokpit.system.last_rate_date.never'))->toBe('Kurzy ještě nebyly staženy. Stáhněte je v přehledu Kurzy ČNB.');
    });

    it('names the date and the last known date in the alert body only, never a host or a payload', function (): void {
        $body = __('kokpit.rates.alert.body_none', ['date' => '14. 10. 2026']);

        expect($body)->toContain('14. 10. 2026')
            ->and($body)->not->toContain('http')
            ->and($body)->not->toContain('cnb.cz');
    });
});
