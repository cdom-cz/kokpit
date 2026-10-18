<?php

declare(strict_types=1);

use App\Domain\Payments\Jobs\ProcessStripeWebhook;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\WebhookCall;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Support\StripeEvents;
use Tests\Support\StripeTestConfig;
use Tests\Support\StripeWebhookSigner;

/*
 * The Stripe webhook endpoint (PA-05, PA-07; D-12, D-15, D-16, D-17, D-18). Every body is signed with a random secret
 * the test configures itself; every id and address is assembled at runtime.
 */

beforeEach(function (): void {
    StripeTestConfig::configure();
});

/**
 * The stored Stripe rows, read as system because the model denies everybody else.
 *
 * @return list<WebhookCall>
 */
function storedStripeCalls(): array
{
    return app(PartnerContext::class)->runAsSystem(
        static fn (): array => WebhookCall::query()->where('name', 'stripe')->orderBy('created_at')->get()->all(),
    );
}

// --- Task 1: the tracer ---------------------------------------------------------------------------------------

it('stores a signed checkout event once, answers 200 at once and queues its job', function (): void {
    Queue::fake();
    $event = StripeEvents::checkoutCompleted();

    StripeWebhookSigner::post($this, $event)->assertOk();

    $calls = storedStripeCalls();

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->name)->toBe('stripe')
        ->and($calls[0]->event_id)->toBe($event['id'])
        ->and($calls[0]->event_name)->toBe('checkout.session.completed')
        ->and($calls[0]->processed_at)->toBeNull()
        ->and($calls[0]->failed_at)->toBeNull();

    Queue::assertPushed(ProcessStripeWebhook::class, 1);
    Queue::assertPushed(ProcessStripeWebhook::class, fn (ProcessStripeWebhook $job): bool => $job->webhookCall->getKey() === $calls[0]->getKey());
});

it('processes the event in the job and leaves the row marked processed', function (): void {
    $event = StripeEvents::checkoutCompleted();

    StripeWebhookSigner::post($this, $event)->assertOk();

    $calls = storedStripeCalls();

    expect($calls)->toHaveCount(1)
        ->and($calls[0]->processed_at)->not->toBeNull()
        ->and($calls[0]->failed_at)->toBeNull()
        ->and($calls[0]->failure_class)->toBeNull();
});

it('runs the public route without a session, cookie or CSRF middleware', function (): void {
    $route = Route::getRoutes()->getByName('webhooks.stripe');
    $middleware = $route?->gatherMiddleware() ?? ['missing'];

    expect($route)->not->toBeNull()
        ->and($route?->uri())->toBe('webhooks/stripe')
        ->and($route?->methods())->toBe(['POST'])
        ->and($middleware)->toBe([]);

    Queue::fake();
    $response = StripeWebhookSigner::post($this, StripeEvents::checkoutCompleted());

    $response->assertOk();
    expect($response->headers->getCookies())->toBe([])
        ->and($response->headers->has('Set-Cookie'))->toBeFalse();
});
