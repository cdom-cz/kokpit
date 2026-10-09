<?php

declare(strict_types=1);

namespace App\Domain\Operations\Health;

use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Maps every HealthSlot to exactly one indicator (D-13).
 *
 * Bound as a singleton in OperationsServiceProvider with one indicator per
 * slot. Later phases swap a placeholder through replace(), which refuses an
 * indicator that belongs to another slot.
 */
class HealthIndicatorRegistry
{
    /**
     * @var array<string, HealthIndicator> keyed by the slot value
     */
    private array $indicators = [];

    /**
     * Adds the indicator of a slot that has none yet.
     *
     * @throws LogicException when the slot already has an indicator; use replace() to swap it
     */
    public function register(HealthIndicator $indicator): void
    {
        $key = $indicator->slot()->value;

        if (isset($this->indicators[$key])) {
            throw new LogicException("The health slot [{$key}] already has an indicator; use replace() to swap it.");
        }

        $this->indicators[$key] = $indicator;
    }

    /**
     * Swaps the indicator of one slot.
     *
     * @throws InvalidArgumentException when the indicator reports a different slot
     */
    public function replace(HealthSlot $slot, HealthIndicator $indicator): void
    {
        if ($indicator->slot() !== $slot) {
            throw new InvalidArgumentException("The indicator reports the slot [{$indicator->slot()->value}] and cannot replace the slot [{$slot->value}].");
        }

        $this->indicators[$slot->value] = $indicator;
    }

    /**
     * The registered indicators in HealthSlot::cases() order, keyed by slot value.
     *
     * @return array<string, HealthIndicator>
     */
    public function indicators(): array
    {
        $ordered = [];

        foreach (HealthSlot::cases() as $slot) {
            if (isset($this->indicators[$slot->value])) {
                $ordered[$slot->value] = $this->indicators[$slot->value];
            }
        }

        return $ordered;
    }

    /**
     * The slots no indicator in the list reports. Pure, so a test can hand it a
     * hand-built list and prove that a gap is found.
     *
     * @param  iterable<HealthIndicator>  $indicators
     * @return list<HealthSlot>
     */
    public static function missingSlots(iterable $indicators): array
    {
        $covered = [];

        foreach ($indicators as $indicator) {
            $covered[$indicator->slot()->value] = true;
        }

        return array_values(array_filter(
            HealthSlot::cases(),
            static fn (HealthSlot $slot): bool => ! isset($covered[$slot->value]),
        ));
    }

    /**
     * One result per slot, in HealthSlot::cases() order. Never throws: an
     * indicator that throws, and a slot without an indicator, both give Error
     * (D-12), and the text names the exception class only, never its message.
     *
     * @return array<string, HealthResult>
     */
    public function results(): array
    {
        $results = [];

        foreach (HealthSlot::cases() as $slot) {
            $indicator = $this->indicators[$slot->value] ?? null;

            if ($indicator === null) {
                $results[$slot->value] = new HealthResult(HealthStatus::Error, null, __('kokpit.system.no_indicator'));

                continue;
            }

            try {
                $results[$slot->value] = $indicator->check();
            } catch (Throwable $exception) {
                $results[$slot->value] = new HealthResult(HealthStatus::Error, null, $exception::class);
            }
        }

        return $results;
    }
}
