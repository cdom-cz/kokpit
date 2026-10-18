<?php

declare(strict_types=1);

use App\Domain\Operations\Health\HealthIndicatorRegistry;
use App\Domain\Operations\Health\HealthSlot;
use App\Domain\Operations\Health\HealthStatus;
use App\Domain\Operations\Health\Indicators\PlaceholderIndicator;
use App\Domain\Operations\Health\Indicators\StripeConfigIndicator;
use App\Domain\Operations\Health\Indicators\UnprocessedWebhooksIndicator;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\WebhookCall;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\StripeTestConfig;

/*
 * The two Stripe slots of the System page (PA-05, PA-08; D-16, D-17; UI-SPEC Surface K). The indicators read counts,
 * ages and booleans only, so rows are inserted as system with a marker payload that must never reach the page.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

/**
 * Stores one Stripe event row, `$minutesAgo` minutes before the frozen clock.
 *
 * @param  array{processed?: bool, failed?: bool, name?: string, payload?: string}  $state
 */
function stripeWebhookRow(int $minutesAgo, array $state = []): void
{
    $moment = now()->subMinutes($minutesAgo);
    $processed = $state['processed'] ?? false;
    $failed = $state['failed'] ?? false;

    app(PartnerContext::class)->runAsSystem(static function () use ($moment, $processed, $failed, $state): void {
        (new WebhookCall)->forceFill([
            'name' => $state['name'] ?? 'stripe',
            'url' => 'https://example.com/webhooks/stripe',
            'payload' => ['marker' => $state['payload'] ?? 'payload-marker'],
            'event_id' => implode('_', ['evt', Str::random(24)]),
            'event_name' => 'checkout.session.completed',
            'processed_at' => $processed ? $moment->copy()->addMinute() : null,
            'failed_at' => $failed ? $moment->copy()->addMinute() : null,
            'failure_class' => $failed ? 'unexpected' : null,
            'created_at' => $moment,
            'updated_at' => $moment,
        ])->save();
    });
}

// --- Task 1: the unprocessed webhooks slot --------------------------------------------------------------------

it('answers Ok with a zero and the all-processed text when no Stripe event waits', function (): void {
    $result = (new UnprocessedWebhooksIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Ok)
        ->and($result->value)->toBe('0')
        ->and($result->detail)->toBe('Všechny události ze Stripe byly zpracovány.')
        ->and((new UnprocessedWebhooksIndicator)->slot())->toBe(HealthSlot::UnprocessedWebhooks);
});

it('does not count a processed event, nor an event of another webhook name', function (): void {
    stripeWebhookRow(300, ['processed' => true]);
    stripeWebhookRow(300, ['name' => 'other']);

    $result = (new UnprocessedWebhooksIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Ok)
        ->and($result->value)->toBe('0');
});

it('answers Ok with the count and the waiting text while the oldest event waits less than the configured age', function (): void {
    stripeWebhookRow(5);

    $result = (new UnprocessedWebhooksIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Ok)
        ->and($result->value)->toBe('1')
        ->and($result->detail)->toBe('Čekající události ze Stripe: 1. Nejstarší čeká 5 minut.');
});

it('answers Warning with the count and the age of the oldest event past the configured age', function (): void {
    stripeWebhookRow(20);
    stripeWebhookRow(2);

    $result = (new UnprocessedWebhooksIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Warning)
        ->and($result->value)->toBe('2')
        ->and($result->detail)->toBe('Čekající události ze Stripe: 2. Nejstarší čeká 20 minut.');
});

it('answers Error when an event failed for good, even next to a waiting one', function (): void {
    stripeWebhookRow(20, ['failed' => true]);
    stripeWebhookRow(2);

    $result = (new UnprocessedWebhooksIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->value)->toBe('2')
        ->and($result->detail)->toBe('Nezpracováno: 2. Nejstarší čeká 20 minut. Zkontrolujte neúspěšné úlohy.');
});

it('reads the warning age from the configuration and never below one second', function (): void {
    stripeWebhookRow(2);
    config(['kokpit.health.unprocessed_webhook_warning_after' => 60]);

    expect((new UnprocessedWebhooksIndicator)->check()->status)->toBe(HealthStatus::Warning);

    config(['kokpit.health.unprocessed_webhook_warning_after' => 0]);

    expect((new UnprocessedWebhooksIndicator)->check()->status)->toBe(HealthStatus::Warning);
});

it('shows the Admin the slot with its detail and no part of the event payload', function (): void {
    stripeWebhookRow(40, ['failed' => true, 'payload' => 'secret-payload-marker']);

    $response = $this->actingAs(Canary::admin())->get('/admin/system')->assertOk();

    $response->assertSee(__('enums.health_slot.unprocessed_webhooks'))
        ->assertSee('Nezpracováno: 1. Nejstarší čeká 40 minut. Zkontrolujte neúspěšné úlohy.')
        ->assertDontSee('secret-payload-marker')
        ->assertDontSee('unexpected');
});

it('is a real indicator, so no placeholder holds the slot', function (): void {
    $indicator = app(HealthIndicatorRegistry::class)->indicators()[HealthSlot::UnprocessedWebhooks->value];

    expect($indicator)->toBeInstanceOf(UnprocessedWebhooksIndicator::class)
        ->and($indicator)->not->toBeInstanceOf(PlaceholderIndicator::class);
});

// --- Task 2: the Stripe configured slot -----------------------------------------------------------------------

it('answers Ok, Nastaveno, when both secrets are set', function (): void {
    StripeTestConfig::configure();

    $result = (new StripeConfigIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Ok)
        ->and($result->value)->toBe('Nastaveno')
        ->and($result->detail)->toBe('Tajný klíč a podpisový klíč webhooku jsou nastavené.')
        ->and((new StripeConfigIndicator)->slot())->toBe(HealthSlot::Stripe);
});

it('answers Ok, Nenastaveno, when no secret is set and online payments are off', function (): void {
    StripeTestConfig::clear();
    CardPaymentSetup::onlinePayments(false);

    $result = (new StripeConfigIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Ok)
        ->and($result->value)->toBe('Nenastaveno')
        ->and($result->detail)->toBe('Online platby jsou vypnuté.');
});

it('answers Error when no secret is set but online payments are on', function (): void {
    StripeTestConfig::clear();
    CardPaymentSetup::onlinePayments(true);

    $result = (new StripeConfigIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->value)->toBe('Nenastaveno')
        ->and($result->detail)->toBe('Online platby jsou zapnuté, ale v prostředí chybí klíče Stripe.');
});

it('answers Error naming the missing webhook secret when only the secret key is set', function (): void {
    StripeTestConfig::configure();
    config(['services.stripe.webhook_secret' => null]);
    CardPaymentSetup::onlinePayments(false);

    $result = (new StripeConfigIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->value)->toBe('Nenastaveno')
        ->and($result->detail)->toBe('Chybí podpisový klíč webhooku.');
});

it('answers Error naming the missing secret key when only the webhook secret is set', function (): void {
    StripeTestConfig::configure();
    config(['services.stripe.secret' => null]);
    CardPaymentSetup::onlinePayments(false);

    $result = (new StripeConfigIndicator)->check();

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->value)->toBe('Nenastaveno')
        ->and($result->detail)->toBe('Chybí tajný klíč Stripe.');
});

it('shows the Admin the Stripe slot without any configured value or its first characters', function (): void {
    StripeTestConfig::configure();
    $secret = (string) config('services.stripe.secret');
    $webhookSecret = (string) config('services.stripe.webhook_secret');

    $html = (string) $this->actingAs(Canary::admin())->get('/admin/system')->assertOk()
        ->assertSee(__('enums.health_slot.stripe'))
        ->assertSee('Nastaveno')
        ->assertSee('Tajný klíč a podpisový klíč webhooku jsou nastavené.')
        ->getContent();

    expect($html)->not->toContain($secret)
        ->and($html)->not->toContain(substr($secret, 0, 6))
        ->and($html)->not->toContain($webhookSecret)
        ->and($html)->not->toContain(substr($webhookSecret, 0, 6));
});

it('measures the Stripe configuration for real, so no placeholder holds the slot', function (): void {
    expect(app(HealthIndicatorRegistry::class)->indicators()[HealthSlot::Stripe->value])
        ->toBeInstanceOf(StripeConfigIndicator::class);
});
