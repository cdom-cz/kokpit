<?php

declare(strict_types=1);

use App\Domain\Payments\Jobs\ProcessStripeWebhook;
use App\Domain\Payments\Stripe\StripeSignatureValidator;
use App\Domain\Shared\Models\WebhookCall;
use Spatie\WebhookClient\WebhookProfile\ProcessEverythingWebhookProfile;
use Spatie\WebhookClient\WebhookResponse\DefaultRespondsTo;

return [
    'configs' => [
        [
            /*
             * The Stripe event destination (Phase 11, R11-04-1). This is the only receiving endpoint; the
             * route is POST /webhooks/stripe in routes/public.php.
             */
            'name' => 'stripe',

            /*
             * The signing secret of the Stripe event destination, from the environment only (D-16). The
             * validator reads it through StripeConfig; it is repeated here so the package config is complete.
             */
            'signing_secret' => env('STRIPE_WEBHOOK_SECRET'),

            /*
             * The name of the header containing the signature.
             */
            'signature_header_name' => 'Stripe-Signature',

            /*
             * Checks the signature on the raw body with the Stripe SDK, never with a hand-written HMAC (D-15).
             */
            'signature_validator' => StripeSignatureValidator::class,

            /*
             * Every call passes the package profile; StripeWebhookProcessor filters the event types itself.
             */
            'webhook_profile' => ProcessEverythingWebhookProfile::class,

            /*
             * This class determines the response on a valid webhook call.
             */
            'webhook_response' => DefaultRespondsTo::class,

            /*
             * The classname of the model to be used to store webhook calls. The class should
             * be equal or extend Spatie\WebhookClient\Models\WebhookCall.
             */
            'webhook_model' => WebhookCall::class,

            /*
             * No header is stored: the signature header is not needed after the check.
             */
            'store_headers' => [

            ],

            /*
             * A Stripe event carries no file upload.
             */
            'store_attachments' => false,

            /*
             * The job that processes a stored event; it extends \Spatie\WebhookClient\Jobs\ProcessWebhookJob.
             */
            'process_webhook_job' => ProcessStripeWebhook::class,
        ],
    ],

    /*
     * The integer amount of days after which models should be deleted (D-17). The model overrides prunable() so
     * that only processed events older than this are removed; failed and unprocessed events are never pruned.
     */
    'delete_after_days' => 90,

    /*
     * Should a unique token be added to the route name
     */
    'add_unique_token_to_route_name' => false,
];
