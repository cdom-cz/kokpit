<?php

declare(strict_types=1);

use App\Filament\Resources\KnowledgeArticleResource;
use Filament\Facades\Filament;
use Illuminate\Contracts\Support\Htmlable;
use Tests\Support\Canary;
use Tests\Support\KnowledgeFixtures;

/*
 * The Admin knowledge screens are closed to a Partner (ROADMAP success criterion 5): no navigation group, no
 * entry, and a 403 (not a 404) on the list and on every view URL, an archived article included. Each refusal is
 * paired with the Admin opening the same URL, and a guest is sent to the login page. Every text is fictional.
 */

/**
 * The URLs of the knowledge screens for an active and an archived article.
 *
 * @return array<string, string>
 */
function knowledgeScreensUrls(): array
{
    $active = KnowledgeFixtures::article(['title' => 'Example active article', 'body_md' => 'Example active body']);
    $archived = KnowledgeFixtures::archived(['title' => 'Example archived article', 'body_md' => 'Example archived body']);

    return [
        'list' => KnowledgeArticleResource::getUrl('index'),
        'view of an active article' => KnowledgeArticleResource::getUrl('view', ['record' => $active->getKey()]),
        'view of an archived article' => KnowledgeArticleResource::getUrl('view', ['record' => $archived->getKey()]),
    ];
}

/**
 * Every label of the navigation of the signed-in user: the groups and their items.
 *
 * @return list<string>
 */
function knowledgeScreensNavigationLabels(): array
{
    $labels = [];

    foreach (Filament::getNavigation() as $group) {
        $label = $group->getLabel();
        $labels[] = $label instanceof Htmlable ? $label->toHtml() : (string) $label;

        foreach ($group->getItems() as $item) {
            $itemLabel = $item->getLabel();
            $labels[] = $itemLabel instanceof Htmlable ? $itemLabel->toHtml() : (string) $itemLabel;
        }
    }

    return $labels;
}

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('gives a Partner no Znalosti group and no knowledge entry in the menu', function (): void {
    $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]));

    expect(knowledgeScreensNavigationLabels())->not->toContain('Znalosti')
        ->and(knowledgeScreensNavigationLabels())->not->toContain('Obecné znalosti');
});

it('gives the Admin the Znalosti group with the General knowledge entry', function (): void {
    $this->actingAs(Canary::admin());

    expect(knowledgeScreensNavigationLabels())->toContain('Znalosti')
        ->and(knowledgeScreensNavigationLabels())->toContain('Obecné znalosti');
});

it('answers a Partner with 403 on the list and on every view URL while the Admin opens them', function (): void {
    $urls = knowledgeScreensUrls();

    $this->actingAs(Canary::admin());

    foreach ($urls as $url) {
        $this->get($url)->assertOk();
    }

    $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]));

    foreach ($urls as $url) {
        $this->get($url)->assertForbidden();
    }
});

it('answers a Partner without a client with 403 on every knowledge URL', function (): void {
    $urls = knowledgeScreensUrls();

    $this->actingAs(Canary::partnerFor(null));

    foreach ($urls as $url) {
        $this->get($url)->assertForbidden();
    }
});

it('sends a guest to the login page from every knowledge URL', function (): void {
    foreach (knowledgeScreensUrls() as $url) {
        $this->get($url)->assertRedirectContains('/admin/login');
    }
});

it('shows a Partner no text of an article even when asking for the view URL of the article of the own client', function (): void {
    [$clientA] = Canary::twoClients();
    $article = KnowledgeFixtures::article(['title' => Canary::canary('knowledge_screen'), 'body_md' => Canary::canary('knowledge_body'), 'client_id' => $clientA]);

    $this->actingAs(Canary::partnerFor($clientA));

    $content = (string) $this->get(KnowledgeArticleResource::getUrl('view', ['record' => $article->getKey()]))->assertForbidden()->getContent();

    expect($content)->not->toContain(Canary::canary('knowledge_screen'))
        ->and($content)->not->toContain(Canary::canary('knowledge_body'));
});
