<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Knowledge\Support\KnowledgeMarkdown;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the knowledge domain. KnowledgeMarkdown is a singleton (its CommonMark environment and sanitiser are built
 * once) whose translator reads the Czech labels under knowledge.render; the class itself stays free of the
 * application so unit tests can pass an identity translator. Later knowledge plans register the prose assets in boot().
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

    public function boot(): void {}
}
