<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Payments\Stripe\StripeWebhookProcessor;
use Illuminate\Http\Request;
use Spatie\WebhookClient\Exceptions\InvalidConfig;
use Spatie\WebhookClient\WebhookConfigRepository;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /webhooks/stripe (D-15): the one public door of the Stripe event destination.
 *
 * The config entry is looked up by name, not from the route name the package uses for its own routes, because this
 * route is registered by hand in routes/public.php outside every middleware group.
 */
final class StripeWebhookController
{
    public const string CONFIG_NAME = 'stripe';

    public function __invoke(Request $request, WebhookConfigRepository $configs): Response
    {
        $config = $configs->getConfig(self::CONFIG_NAME) ?? throw InvalidConfig::couldNotFindConfig(self::CONFIG_NAME);

        return (new StripeWebhookProcessor($request, $config))->process();
    }
}
