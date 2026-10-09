<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Billing;

use App\Domain\Clients\Models\Client;
use App\Domain\Settings\Settings\DefaultsSettings;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Billing\BillingSource;
use App\Domain\Tasks\Billing\TaskBillingResolver;
use App\Domain\TimeTracking\Models\TimeEntry;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;

/**
 * Resolves the effective hourly rate of a time entry at read time, with its
 * source (TI-08). Nothing is copied or stored on the entry: Phase 6 shows the
 * rate, Phase 10 snapshots it with the amount when the entry is billed.
 *
 * Order, by the shape of the entry:
 * - task entry: the task's own rate, the parent task's, the project's, the
 *   client's, all through TaskBillingResolver (one place owns that order);
 * - project-only entry: the project billing row, then the client;
 * - client-only entry: the client.
 * The global default applies only below all of them, and only when its currency
 * equals the client's: a rate in another currency would price work in the wrong
 * money, so then the result is empty (null rate, null source). A rate of zero
 * is a value and stops the search.
 *
 * The client column holds a rate for every stored client, so the default is a
 * defensive floor rather than a routine level. The client, project, task and
 * their billing rows are read archived ones included: archiving never hides
 * tracked time.
 *
 * Admin and system contexts only (T-06-13): a Partner or a guest is refused
 * outright, so no rate can reach a Partner through this class.
 */
final class TimeEntryRateResolver
{
    public function __construct(
        private readonly PartnerContext $context,
        private readonly TaskBillingResolver $tasks,
        private readonly DefaultsSettings $defaults,
    ) {}

    /**
     * @throws AuthorizationException when neither the Admin nor a system run asks
     * @throws LogicException when the project of a task entry has no billing row, which CreateProject always writes
     */
    public function resolve(TimeEntry $entry): EntryRate
    {
        if (! $this->context->isAdmin() && ! $this->context->isSystem()) {
            throw new AuthorizationException('The effective rate of a time entry is available to the Admin only.');
        }

        $entry->loadMissing(['client', 'project.billing', 'task']);

        $client = $entry->client;
        $task = $entry->task;
        $project = $entry->project;

        if ($task !== null) {
            $billing = $this->tasks->resolve($task);

            if ($billing->hourlyRate !== null && $billing->hourlyRateSource !== null) {
                return new EntryRate($billing->hourlyRate, $this->source($billing->hourlyRateSource));
            }

            return $this->fallback($client);
        }

        $projectRate = $project?->billing?->hourly_rate;

        if ($projectRate !== null) {
            return new EntryRate($projectRate, RateSource::Project);
        }

        $clientRate = $client?->hourly_rate;

        if ($clientRate !== null) {
            return new EntryRate($clientRate, RateSource::Client);
        }

        return $this->fallback($client);
    }

    /**
     * The global default, but only in the currency of the client.
     */
    private function fallback(?Client $client): EntryRate
    {
        $default = $this->defaults->default_hourly_rate;

        if ($client === null || $default->currency !== $client->currency) {
            return new EntryRate(null, null);
        }

        return new EntryRate($default, RateSource::Default);
    }

    private function source(BillingSource $source): RateSource
    {
        return match ($source) {
            BillingSource::Task => RateSource::Task,
            BillingSource::ParentTask => RateSource::ParentTask,
            BillingSource::Project => RateSource::Project,
            BillingSource::Client => RateSource::Client,
        };
    }
}
