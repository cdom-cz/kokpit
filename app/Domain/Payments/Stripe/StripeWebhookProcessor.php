<?php

declare(strict_types=1);

namespace App\Domain\Payments\Stripe;

use App\Domain\Payments\Jobs\ProcessStripeWebhook;
use App\Domain\Shared\Models\WebhookCall;
use Illuminate\Support\Str;
use Spatie\WebhookClient\Models\WebhookCall as BaseWebhookCall;
use Spatie\WebhookClient\WebhookProcessor;
use Symfony\Component\HttpFoundation\Response;

/**
 * The intake of one Stripe webhook call (D-12, D-15, R11-04-3).
 *
 * In order: the signature on the raw body (the package, through StripeSignatureValidator; a bad one is a 400), the
 * shape of the event (a 400 when it is not a Stripe event object), the type (an unhandled type is answered 200 and
 * stored nowhere, so Stripe stops retrying it), the single store under the unique event id, and the dispatch of the
 * job. A new row is dispatched, and so is a stored row that is not yet processed: if the first dispatch was lost
 * (the queue was down), Stripe's own redelivery repairs it. A processed row is never dispatched again. Every
 * accepted call is answered 200 at once; the work happens in the queued job.
 */
class StripeWebhookProcessor extends WebhookProcessor
{
    /** The event types the application acts on; every other type is acknowledged and dropped. */
    public const array HANDLED_TYPES = [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'refund.created',
    ];

    /**
     * Personal data of the payer that Stripe puts on a checkout session. It is dropped before the event is stored,
     * so a webhook row never holds a name, an address or an e-mail of the payer (T-11-15).
     */
    public const array PAYER_KEYS = ['customer_details', 'customer_email', 'shipping_details', 'shipping', 'collected_information'];

    public function process(): Response
    {
        $this->ensureValidSignature();

        $event = $this->event();

        if ($event === null) {
            return response()->json(['message' => 'invalid payload'], 400);
        }

        if (! in_array($event['type'], self::HANDLED_TYPES, true)) {
            return $this->createResponse();
        }

        [$call, $stored] = WebhookCall::storeStripeEvent($this->config, $event['id'], $event['type'], $event['payload']);

        if ($stored || $call->processed_at === null) {
            $this->processWebhook($call);
        }

        return $this->createResponse();
    }

    /**
     * Hands the stored row to the queue. A failure to dispatch leaves as it is (a 500, so Stripe delivers again and the
     * unprocessed row is dispatched anew); the package's own handling would copy the exception text into the row.
     */
    protected function processWebhook(BaseWebhookCall $webhookCall): void
    {
        dispatch(new ProcessStripeWebhook($webhookCall));
    }

    /**
     * The id, the type and the payload to store of a well-formed event, or null for anything else.
     *
     * @return array{id: string, type: string, payload: array<string, mixed>}|null
     */
    private function event(): ?array
    {
        $decoded = json_decode($this->request->getContent(), true);

        if (! is_array($decoded) || array_is_list($decoded)) {
            return null;
        }

        $id = $decoded['id'] ?? null;
        $type = $decoded['type'] ?? null;
        $data = $decoded['data'] ?? null;

        if (! is_string($id) || ! Str::startsWith($id, 'evt_') || strlen($id) > 255) {
            return null;
        }

        if (! is_string($type) || $type === '' || ! is_array($data) || ! is_array($data['object'] ?? null)) {
            return null;
        }

        /** @var array<string, mixed> $object */
        $object = $data['object'];
        $decoded['data']['object'] = array_diff_key($object, array_flip(self::PAYER_KEYS));

        /** @var array<string, mixed> $decoded */
        return ['id' => $id, 'type' => $type, 'payload' => $decoded];
    }
}
