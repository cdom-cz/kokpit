<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Identity\Models\User;
use Filament\Facades\Filament;
use Filament\GlobalSearch\GlobalSearchResult;
use Filament\GlobalSearch\GlobalSearchResults;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;
use LogicException;
use Tests\Support\Filament\CanaryRecordResource;

/**
 * Drives the leak channels of the entity x channel matrix as Partner A (AUD-01; D-07, D-12; R-12-05-2).
 *
 * seed() builds the two-client scene: two clients, one canary string for each, the fixtures of every registered
 * entity for both (CanaryRegistry::seedAll), Partner A (a Partner of client A) and the Admin that owns the
 * planner rows. blob($channel, $model) then returns everything Partner A can obtain through one channel for one
 * entity as a single string, which ChannelMatrixTest searches for the needles of client B and, for a `none`
 * cell, of client A. A channel without a driver throws, so a channel listed in ChannelMatrix but not in DRIVEN
 * is visibly not walked yet and never silently passes.
 *
 * The test calls CanaryRegistry::cleanup() in afterEach; the scene lives inside the test transaction.
 */
final class ChannelProbe
{
    /**
     * The channels that have a driver here. Plans 12-06 and 12-07 add ui, downloads, exports and notifications.
     *
     * @var list<string>
     */
    public const array DRIVEN = ['search'];

    /**
     * The prefix every canary string starts with: a search for it reaches the rows of both clients.
     */
    private const string COMMON_PREFIX = 'CANARY';

    /** @var array<string, array<class-string, list<string>>> memo of the per-test searches */
    private array $memo = [];

    private function __construct(
        private readonly string $clientA,
        private readonly string $clientB,
        private readonly string $canaryA,
        private readonly string $canaryB,
        private readonly User $partnerA,
        private readonly User $admin,
    ) {}

    /**
     * Sets the admin panel, prepares the registry, creates two clients with one canary each, seeds every
     * registered entity for both, and creates Partner A and the Admin.
     */
    public static function seed(): self
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        CanaryRegistry::prepare();

        [$clientA, $clientB] = Canary::twoClients();
        $canaryA = Canary::canary('channel_a');
        $canaryB = Canary::canary('channel_b');

        CanaryRegistry::seedAll($clientA, $canaryA);
        CanaryRegistry::seedAll($clientB, $canaryB);

        return new self($clientA, $clientB, $canaryA, $canaryB, Canary::partnerFor($clientA), CanaryRegistry::plannerOwner());
    }

    public function clientId(string $side): string
    {
        return $this->side($side, $this->clientA, $this->clientB);
    }

    public function canary(string $side): string
    {
        return $this->side($side, $this->canaryA, $this->canaryB);
    }

    /**
     * The needles of one entity for one side: the strings that identify its canary row in any output.
     *
     * @param  class-string  $model
     * @return list<string>
     */
    public function needles(string $model, string $side): array
    {
        return CanaryRegistry::needles($model, $this->canary($side));
    }

    public function partnerA(): User
    {
        return $this->partnerA;
    }

    public function admin(): User
    {
        return $this->admin;
    }

    /**
     * Signs the user in on the web guard, the way the test case's actingAs() does.
     */
    public function actAs(User $user): void
    {
        Auth::guard('web')->setUser($user);
        Auth::shouldUse('web');
    }

    /**
     * Everything Partner A can obtain through the channel for the entity, as one string.
     *
     * @param  class-string  $model
     */
    public function blob(string $channel, string $model): string
    {
        $this->actAs($this->partnerA);

        return match ($channel) {
            'search' => $this->searchBlob($model),
            default => throw new LogicException("The channel {$channel} has no driver in ChannelProbe yet; it is not in ChannelProbe::DRIVEN."),
        };
    }

    /**
     * Partner A's global search for each needle of both sides and for the common canary prefix, as the text of
     * the results that belong to the entity: the resources of the entity's model, their category title, result
     * title, details and URL. A result is attributed to the model of the resource that produced it, because two
     * entities can carry the same canary text (a project name and a task title), so the canary text alone cannot
     * tell which entity a result shows (R-12-05-3).
     *
     * @param  class-string  $model
     */
    private function searchBlob(string $model): string
    {
        $terms = array_values(array_unique([
            ...$this->needles($model, 'a'),
            ...$this->needles($model, 'b'),
            self::COMMON_PREFIX,
        ]));
        $lines = [];

        foreach ($terms as $term) {
            array_push($lines, ...($this->search($this->partnerA, $term)[$model] ?? []));
        }

        return implode("\n", $lines);
    }

    /**
     * What the global search of the panel shows a user for one term, as text lines grouped by the model of the
     * resource that produced them. The panel's provider is the real channel; the test-only canary resource does
     * not opt in to global search, so it is asked directly, the way RouteWalkTest does, because it is the one
     * Partner-readable resource that carries nothing but the canary.
     *
     * @return array<class-string, list<string>>
     */
    public function search(User $user, string $term): array
    {
        $key = 'search:'.$user->getKey().':'.$term;

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $this->actAs($user);

        $byModel = [];
        $results = Filament::getGlobalSearchProvider()?->getResults($term);

        if ($results instanceof GlobalSearchResults) {
            foreach ($results->getCategories() as $category => $items) {
                foreach ($this->modelsOfCategory((string) $category) as $model) {
                    $byModel[$model][] = (string) $category;

                    foreach ($items as $item) {
                        array_push($byModel[$model], ...$this->resultLines($item));
                    }
                }
            }
        }

        if (config('kokpit.canary_harness') === true && class_exists(CanaryRecordResource::class)) {
            foreach (CanaryRecordResource::getGlobalSearchResults($term) as $item) {
                $byModel[CanaryRecord::class] ??= [];
                array_push($byModel[CanaryRecord::class], ...$this->resultLines($item));
            }
        }

        return $this->memo[$key] = $byModel;
    }

    /**
     * The models of the panel resources whose plural label is the category title (the provider keys a category
     * by that label).
     *
     * @return list<class-string>
     */
    private function modelsOfCategory(string $category): array
    {
        $models = [];

        foreach (Filament::getResources() as $resource) {
            if ($resource::getPluralModelLabel() === $category) {
                $models[] = $resource::getModel();
            }
        }

        if ($models === []) {
            throw new LogicException("The global search category {$category} belongs to no resource of the panel.");
        }

        return array_values(array_unique($models));
    }

    /**
     * @return list<string>
     */
    private function resultLines(GlobalSearchResult $item): array
    {
        $lines = [$this->text($item->title), $item->url];

        foreach ($item->details as $label => $value) {
            $lines[] = $label.' '.$value;
        }

        return $lines;
    }

    private function text(string|Htmlable $value): string
    {
        return $value instanceof Htmlable ? $value->toHtml() : $value;
    }

    private function side(string $side, string $a, string $b): string
    {
        return match ($side) {
            'a' => $a,
            'b' => $b,
            default => throw new LogicException("A side is 'a' or 'b', got {$side}."),
        };
    }
}
