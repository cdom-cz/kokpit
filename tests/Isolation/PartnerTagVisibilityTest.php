<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Tag;
use App\Domain\Shared\Tags\TagType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Canary;

/*
 * Partner tag visibility (D-07, PR-04, T-04-07). A Partner sees a project-type
 * tag only through a project the Partner can see; Tag has a real Partner
 * constraint and the policy stays Admin-only. Every name is fictional and built
 * at runtime.
 */

/**
 * Creates a project of the given client in a system run.
 *
 * @param  array<string, mixed>  $attributes
 */
function tagTestProject(string $clientId, array $attributes = []): Project
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Project => Project::factory()->create([
        'client_id' => $clientId,
        'key' => Canary::projectKey(),
        'client_visible' => true,
        ...$attributes,
    ]));
}

/**
 * Attaches a tag of the given type to a project in a system run and returns the tag name.
 */
function tagTestAttach(Project $project, string $label, TagType $type = TagType::Project): string
{
    $name = Canary::canary($label);
    app(PartnerContext::class)->runAsSystem(static fn () => $project->attachTag($name, $type->value));

    return $name;
}

/**
 * The names of every tag the current user sees through Tag::query().
 *
 * @return list<string>
 */
function visibleTagNames(): array
{
    $names = Tag::query()->get()->map(static fn (Tag $tag): string => (string) $tag->name)->all();
    sort($names);

    return $names;
}

beforeEach(function (): void {
    [$this->clientA, $this->clientB] = Canary::twoClients();
    $this->projectA = tagTestProject($this->clientA, ['name' => Canary::canary('project_a')]);
    $this->projectB = tagTestProject($this->clientB, ['name' => Canary::canary('project_b')]);
});

it('shows a Partner the project-type tag of the own visible project and exactly that tag', function (): void {
    $own = tagTestAttach($this->projectA, 'own_tag');
    tagTestAttach($this->projectB, 'other_tag');

    $this->actingAs(Canary::partnerFor($this->clientA));

    expect(visibleTagNames())->toBe([$own])
        ->and(Tag::query()->count())->toBe(1)
        ->and(Project::query()->findOrFail($this->projectA->id)->tags->pluck('name')->all())->toBe([$own]);
});

it('hides the project-type tag of another client\'s visible project from a Partner', function (): void {
    $other = tagTestAttach($this->projectB, 'other_tag');

    $this->actingAs(Canary::partnerFor($this->clientA));

    expect(visibleTagNames())->toBe([])
        ->and(Tag::query()->where('name->'.app()->getLocale(), $other)->count())->toBe(0);

    $this->actingAs(Canary::partnerFor($this->clientB));

    expect(visibleTagNames())->toBe([$other]);
});

it('hides a client-type tag attached to the own client from a Partner', function (): void {
    $clientTag = Canary::canary('client_tag');

    app(PartnerContext::class)->runAsSystem(function () use ($clientTag): void {
        $tag = Tag::findOrCreate($clientTag, TagType::Client->value);

        // The client model carries no tag trait yet, so the row is written directly.
        DB::table('taggables')->insert([
            'tag_id' => $tag->id,
            'taggable_type' => 'client',
            'taggable_id' => $this->clientA,
        ]);
    });
    $own = tagTestAttach($this->projectA, 'own_tag');

    $this->actingAs(Canary::partnerFor($this->clientA));

    expect(visibleTagNames())->toBe([$own]);
});

it('hides a client-type tag attached to the own visible project from a Partner', function (): void {
    $clientTypeOnProject = tagTestAttach($this->projectA, 'client_type_on_project', TagType::Client);
    $own = tagTestAttach($this->projectA, 'own_tag');

    $this->actingAs(Canary::partnerFor($this->clientA));

    expect(visibleTagNames())->toBe([$own])
        ->and(visibleTagNames())->not->toContain($clientTypeOnProject);
});

it('hides an untyped tag and a tag attached to nothing from a Partner', function (): void {
    $untyped = Canary::canary('untyped');
    $unattached = Canary::canary('unattached');

    app(PartnerContext::class)->runAsSystem(function () use ($untyped, $unattached): void {
        $this->projectA->attachTag($untyped);
        Tag::findOrCreate($unattached, TagType::Project->value);
    });
    $own = tagTestAttach($this->projectA, 'own_tag');

    $this->actingAs(Canary::partnerFor($this->clientA));

    expect(visibleTagNames())->toBe([$own]);
});

it('shows the Admin every tag', function (): void {
    $own = tagTestAttach($this->projectA, 'own_tag');
    $other = tagTestAttach($this->projectB, 'other_tag');
    $clientType = tagTestAttach($this->projectA, 'client_type', TagType::Client);
    $untyped = Canary::canary('untyped');
    app(PartnerContext::class)->runAsSystem(fn () => $this->projectA->attachTag($untyped));

    $this->actingAs(Canary::admin());

    $expected = [$own, $other, $clientType, $untyped];
    sort($expected);

    expect(visibleTagNames())->toBe($expected);
});

it('shows a Partner without a client and a guest no tag', function (): void {
    tagTestAttach($this->projectA, 'own_tag');

    expect(visibleTagNames())->toBe([]);

    $this->actingAs(Canary::partnerFor(null));

    expect(visibleTagNames())->toBe([]);
});

it('keeps the Tag policy Admin-only so a Partner has no tag screen', function (): void {
    $this->actingAs(Canary::partnerFor($this->clientA));

    expect(Gate::allows('viewAny', Tag::class))->toBeFalse()
        ->and(Gate::allows('create', Tag::class))->toBeFalse();

    $this->actingAs(Canary::admin());

    expect(Gate::allows('viewAny', Tag::class))->toBeTrue();
});

it('hides a project-type tag attached only to a hidden project from a Partner', function (): void {
    $hidden = tagTestProject($this->clientA, ['name' => Canary::canary('hidden_a'), 'client_visible' => false]);
    $hiddenOnly = tagTestAttach($hidden, 'hidden_only_tag');
    $own = tagTestAttach($this->projectA, 'own_tag');

    $this->actingAs(Canary::partnerFor($this->clientA));

    expect(visibleTagNames())->toBe([$own])
        ->and(visibleTagNames())->not->toContain($hiddenOnly);

    $this->actingAs(Canary::admin());

    expect(visibleTagNames())->toContain($hiddenOnly);
});

it('shows a Partner a tag shared by a hidden and a visible project once, through the visible one', function (): void {
    $hidden = tagTestProject($this->clientA, ['name' => Canary::canary('hidden_a'), 'client_visible' => false]);
    $shared = tagTestAttach($hidden, 'shared_tag');
    app(PartnerContext::class)->runAsSystem(fn () => $this->projectA->attachTag($shared, TagType::Project->value));

    $this->actingAs(Canary::partnerFor($this->clientA));

    expect(visibleTagNames())->toBe([$shared]);
});

it('hides a project-type tag attached only to an archived project from a Partner and from the project tags', function (): void {
    $archived = tagTestProject($this->clientA, ['name' => Canary::canary('archived_a')]);
    $archivedOnly = tagTestAttach($archived, 'archived_only_tag');
    app(PartnerContext::class)->runAsSystem(static fn () => $archived->delete());

    $this->actingAs(Canary::partnerFor($this->clientA));

    expect(visibleTagNames())->toBe([]);

    $this->actingAs(Canary::admin());

    expect(visibleTagNames())->toBe([$archivedOnly]);
});

it('hides the tags of every project of an archived client from a Partner', function (): void {
    tagTestAttach($this->projectA, 'own_tag');

    $this->actingAs(Canary::partnerFor($this->clientA));
    expect(visibleTagNames())->toHaveCount(1);

    app(PartnerContext::class)->runAsSystem(fn () => Client::query()->whereKey($this->clientA)->firstOrFail()->delete());

    expect(visibleTagNames())->toBe([]);
});

it('keeps the taggables rows when a project is archived and shows the tags again after the restore', function (): void {
    $name = tagTestAttach($this->projectA, 'own_tag');
    $second = tagTestAttach($this->projectA, 'second_tag');
    $count = static fn (): int => DB::table('taggables')->where('taggable_type', 'project')->where('taggable_id', test()->projectA->id)->count();

    app(PartnerContext::class)->runAsSystem(fn () => $this->projectA->delete());

    expect($count())->toBe(2);

    $this->actingAs(Canary::partnerFor($this->clientA));
    expect(visibleTagNames())->toBe([]);

    $this->actingAs(Canary::admin());
    $archived = Project::withTrashed()->findOrFail($this->projectA->id);
    expect($archived->tags->pluck('name')->sort()->values()->all())->toBe(collect([$name, $second])->sort()->values()->all());

    app(PartnerContext::class)->runAsSystem(fn () => Project::withTrashed()->findOrFail($this->projectA->id)->restore());

    expect($count())->toBe(2)
        ->and(Project::query()->findOrFail($this->projectA->id)->tags->pluck('name')->sort()->values()->all())->toBe(collect([$name, $second])->sort()->values()->all());

    $this->actingAs(Canary::partnerFor($this->clientA));
    expect(visibleTagNames())->toBe(collect([$name, $second])->sort()->values()->all());
});

it('detaches the tags only when a project is force deleted', function (): void {
    tagTestAttach($this->projectA, 'own_tag');
    $count = static fn (): int => DB::table('taggables')->where('taggable_type', 'project')->where('taggable_id', test()->projectA->id)->count();

    app(PartnerContext::class)->runAsSystem(fn () => $this->projectA->delete());
    expect($count())->toBe(1);

    app(PartnerContext::class)->runAsSystem(fn () => Project::withTrashed()->findOrFail($this->projectA->id)->forceDelete());
    expect($count())->toBe(0);
});

it('detaches the tags when a project that is not archived is force deleted', function (): void {
    tagTestAttach($this->projectA, 'own_tag');

    app(PartnerContext::class)->runAsSystem(fn () => $this->projectA->forceDelete());

    expect(DB::table('taggables')->where('taggable_type', 'project')->where('taggable_id', $this->projectA->id)->count())->toBe(0);
});
