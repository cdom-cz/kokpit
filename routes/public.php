<?php

declare(strict_types=1);

use App\Http\Controllers\PayPageController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Middleware\SetNoReferrerPolicy;
use Illuminate\Support\Facades\Route;

/*
 * Public routes that run outside the `web` middleware group (R11-04-5): no session, no cookie and no CSRF check, so
 * a caller that holds no browser state is answered without any of them and the answer sets no cookie. They are
 * authenticated by their own means (a signed body), never by a login. Loaded by bootstrap/app.php. The webhook limiter is
 * generous on purpose: Stripe retries a throttled call, and the signature is checked before any write.
 */
Route::post('/webhooks/stripe', StripeWebhookController::class)->middleware('throttle:stripe-webhook')->name('webhooks.stripe');

/*
 * The stable pay URL printed on the PDF and sent in the e-mail (PA-02; D-01, D-02). The token is the only key. The
 * no-referrer header and the panel set-up come before the limiter, so even a throttled answer carries the header; the
 * panel set-up gives the standalone view the panel's assets and brand without starting a session (R11-08-1).
 */
Route::get('/pay/{token}', PayPageController::class)
    ->middleware([SetNoReferrerPolicy::class, 'Filament\Http\Middleware\SetUpPanel:admin', 'throttle:pay-page'])
    ->name('pay.show');
