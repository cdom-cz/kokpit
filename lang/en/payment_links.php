<?php

declare(strict_types=1);

/*
 * The English wording sent to Stripe with the payment link of an English-language document (UI-SPEC U-15). The keys
 * are those of the `stripe` group of lang/cs/payment_links.php.
 */
return [
    'stripe' => [
        'product' => [
            'invoice' => 'Invoice :number',
            'proforma' => 'Proforma invoice :number',
        ],
        'confirmation' => 'Thank you, your payment was received. Document :number will be marked as paid within a few minutes.',
        'inactive' => 'This payment link is no longer active. Use the link on your document or contact the supplier.',
    ],
];
