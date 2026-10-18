<?php

declare(strict_types=1);

namespace App\Domain\Payments\Jobs;

use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\Middleware\RunsAsSystem;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\WebhookCall;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\WebhookClient\Jobs\ProcessWebhookJob;
use Throwable;

/**
 * Processes one stored Stripe event in the queue (D-15, D-17, R11-04-6).
 *
 * The package forces this class to extend ProcessWebhookJob, so it cannot extend KokpitJob; it carries the same
 * contract by hand, and tests/Arch/JobContractTest.php checks that it keeps it: 3 tries, a wait of 10 s, 60 s and 5 min,
 * a 60 s timeout, #[Idempotent] and the system context.
 *
 * The restored model comes back without any scope, so handle() reads the row again under a lock, as system. The
 * lock order of every handler is: the event row, then the document, then the payment, then the income. An event
 * that is already processed does nothing, and a handler runs in the same transaction as the processed marker, so
 * its writes and the marker stand or fall together.
 *
 * A final failure marks the event failed with a fixed class (`validation` when a rule of the application refused it,
 * for example a missing exchange rate list for the income; `unexpected` otherwise) and never stores the exception
 * text: a provider payload and the text of a database error can hold personal data. A failed event stays unprocessed,
 * so Stripe's redelivery or a manual rerun processes it again, and a later success clears the failure.
 */
#[Idempotent(how: 'identified by the event row id; the job locks the row and returns when processed_at is set, a handler writes only under its own unique keys, and the processed marker is set in the transaction of the handler')]
#[Tries(3)]
#[Backoff(10, 60, 300)]
#[Timeout(60)]
final class ProcessStripeWebhook extends ProcessWebhookJob
{
    public const string FAILURE_VALIDATION = 'validation';

    public const string FAILURE_UNEXPECTED = 'unexpected';

    /** The container key under which a test binds its own handler map. */
    public const string HANDLERS_BINDING = 'payments.stripe.handlers';

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new RunsAsSystem];
    }

    /**
     * The handler class of each event type; a type without a handler is only marked processed. The checkout
     * handlers arrive with plan 11-13 and the refund handler with plan 11-16. A test may bind its own map.
     *
     * @return array<string, class-string<StripeEventHandler>>
     */
    public static function handlers(): array
    {
        /** @var array<string, class-string<StripeEventHandler>> */
        return app()->bound(self::HANDLERS_BINDING) ? app(self::HANDLERS_BINDING) : [];
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            $call = WebhookCall::query()->whereKey($this->webhookCall->getKey())->lockForUpdate()->first();

            if ($call === null || $call->processed_at !== null) {
                return;
            }

            $handler = self::handlers()[(string) $call->event_name] ?? null;

            if ($handler !== null) {
                app($handler)->handle($call);
            }

            $call->forceFill(['processed_at' => now(), 'failed_at' => null, 'failure_class' => null])->save();
        });
    }

    /**
     * The queue gave up. The middleware does not wrap this method, so the system context is entered here.
     */
    public function failed(Throwable $exception): void
    {
        app(PartnerContext::class)->runAsSystem(function () use ($exception): void {
            WebhookCall::query()
                ->whereKey($this->webhookCall->getKey())
                ->whereNull('processed_at')
                ->update([
                    'failed_at' => now(),
                    'failure_class' => $exception instanceof ValidationException ? self::FAILURE_VALIDATION : self::FAILURE_UNEXPECTED,
                    'updated_at' => now(),
                ]);
        });
    }
}
