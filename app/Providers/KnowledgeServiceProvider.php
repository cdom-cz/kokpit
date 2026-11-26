<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Knowledge\Support\KnowledgeMarkdown;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the knowledge domain. KnowledgeMarkdown is a singleton (its CommonMark environment and sanitiser are built
 * once) whose translator reads the Czech labels under knowledge.render; the class itself stays free of the
 * application so unit tests can pass an identity translator. boot() registers the prose assets of rendered articles.
 */
final class KnowledgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            KnowledgeMarkdown::class,
            static fn (): KnowledgeMarkdown => new KnowledgeMarkdown(
                static fn (string $key, array $replace = []): string => (string) __('knowledge.render.'.$key, $replace),
            ),
        );
    }

    /**
     * The prose stylesheet, the diff stylesheet of the version comparison, the copy script of rendered articles and the
     * editor script with the task-list button, as Filament assets of the app package (no build step). filament:assets publishes them to public/css/app and
     * public/js/app, which composer install runs through filament:upgrade; the published copies are ignored by git.
     */
    public function boot(): void
    {
        FilamentAsset::register([
            Css::make('kokpit-prose', resource_path('css/kokpit-prose.css')),
            Css::make('kokpit-diff', resource_path('css/kokpit-diff.css')),
            Js::make('knowledge-copy', resource_path('js/knowledge-copy.js')),
            Js::make('knowledge-editor', resource_path('js/knowledge-editor.js')),
        ]);
    }
}
