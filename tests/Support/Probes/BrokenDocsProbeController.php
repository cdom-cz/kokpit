<?php

declare(strict_types=1);

namespace Tests\Support\Probes;

use Knuckles\Scribe\Attributes\ResponseFromFile;

/**
 * A documentation probe: its one attribute names a file that does not exist, so Scribe reports an extraction error
 * for the route. tests/Support/scribe-broken-route.php registers it, to prove a broken attribute makes the
 * generation exit non-zero instead of publishing partial docs.
 */
final class BrokenDocsProbeController
{
    #[ResponseFromFile('tests/Support/does-not-exist.json')]
    public function __invoke(): never
    {
        abort(404);
    }
}
