<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Payments\Stripe\CreatedPaymentLink;
use App\Domain\Payments\Stripe\PaymentLinkRequest;
use App\Domain\Payments\Stripe\StripeGateway;
use App\Domain\Payments\Stripe\StripeGatewayFailure;
use Illuminate\Support\Str;

/**
 * The in-memory Stripe gateway every payments test binds (D-18): CI never calls Stripe.
 *
 * It behaves like Stripe where the application relies on it: the same idempotency key returns the link already
 * created, a configured failure is raised once, every deactivation is recorded. Link ids and addresses are
 * assembled at runtime so no test file or fixture holds a Stripe-shaped value (R11-01-6).
 */
final class FakeStripeGateway implements StripeGateway
{
    /** @var array<string, array{request: PaymentLinkRequest, link: CreatedPaymentLink}> */
    private array $links = [];

    /** @var list<string> */
    private array $deactivated = [];

    private int $createCalls = 0;

    private ?string $nextCreateFailure = null;

    private ?string $nextDeactivateFailure = null;

    /**
     * Binds a fresh fake as the StripeGateway of the container and returns it.
     */
    public static function install(): self
    {
        $fake = new self;
        app()->instance(StripeGateway::class, $fake);

        return $fake;
    }

    public function createLink(PaymentLinkRequest $request): CreatedPaymentLink
    {
        $this->createCalls++;

        if ($this->nextCreateFailure !== null) {
            $class = $this->nextCreateFailure;
            $this->nextCreateFailure = null;

            throw StripeGatewayFailure::forClass($class);
        }

        if (isset($this->links[$request->idempotencyKey])) {
            return $this->links[$request->idempotencyKey]['link'];
        }

        $link = new CreatedPaymentLink(
            id: implode('_', ['plink', Str::random(24)]),
            url: 'https://'.implode('.', ['buy', 'stripe', 'com']).'/'.Str::random(20),
        );
        $this->links[$request->idempotencyKey] = ['request' => $request, 'link' => $link];

        return $link;
    }

    public function deactivateLink(string $stripeLinkId): void
    {
        if ($this->nextDeactivateFailure !== null) {
            $class = $this->nextDeactivateFailure;
            $this->nextDeactivateFailure = null;

            throw StripeGatewayFailure::forClass($class);
        }

        $this->deactivated[] = $stripeLinkId;
    }

    /**
     * The next createLink() call fails with the given failure class, once.
     */
    public function failNextCreate(string $failureClass): self
    {
        $this->nextCreateFailure = StripeGatewayFailure::forClass($failureClass)->failureClass;

        return $this;
    }

    /**
     * The next deactivateLink() call fails with the given failure class, once.
     */
    public function failNextDeactivate(string $failureClass): self
    {
        $this->nextDeactivateFailure = StripeGatewayFailure::forClass($failureClass)->failureClass;

        return $this;
    }

    /**
     * One request per link actually created, in creation order; a replayed idempotency key adds none.
     *
     * @return list<PaymentLinkRequest>
     */
    public function created(): array
    {
        return array_values(array_map(fn (array $entry): PaymentLinkRequest => $entry['request'], $this->links));
    }

    /**
     * The link ids deactivated, in call order.
     *
     * @return list<string>
     */
    public function deactivated(): array
    {
        return $this->deactivated;
    }

    /**
     * Every createLink() call that reached the fake, replays and failures included.
     */
    public function createCalls(): int
    {
        return $this->createCalls;
    }
}
