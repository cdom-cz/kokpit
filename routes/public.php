<?php

declare(strict_types=1);

use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Public routes that run outside the `web` middleware group (R11-04-5): no session, no cookie and no CSRF check, so
 * a caller that holds no browser state is answered without any of them and the answer sets no cookie. They are
 * authenticated by their own means (a signed body), never by a login. Loaded by bootstrap/app.php.
 */
Route::post('/webhooks/stripe', StripeWebhookController::class)->name('webhooks.stripe');
