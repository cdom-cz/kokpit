<?php

declare(strict_types=1);

/*
 * The public pay page (Phase 11, UI-SPEC Surface A): English copy of the four cards, used for an English-language
 * document and as the second half of the invalid card. The keys are those of lang/cs/pay_page.php.
 */
return [
    'preparing' => [
        'heading' => 'The payment link is being prepared',
        'body' => 'Document no. :number. Please try again shortly. If the link is still not available later, contact the supplier.',
        'retry' => 'Try again',
    ],
    'paid' => [
        'heading' => 'This document is paid',
        'body' => 'Document no. :number is paid. Thank you.',
    ],
    'cancelled' => [
        'heading' => 'This document was cancelled',
        'body' => 'Document no. :number was cancelled, so card payment is no longer possible. Contact the supplier with any questions.',
    ],
    'invalid' => [
        'heading' => 'This link is not valid',
        'body' => 'This payment link cannot be used. Ask the supplier for a new one.',
    ],
];
