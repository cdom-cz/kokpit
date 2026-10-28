<?php

declare(strict_types=1);

/*
 * The English wording of the payment confirmation e-mail sent to the client (Phase 13, D-05, D-06).
 * The keys are exactly those of lang/cs/payment_mail.php; a test compares them.
 */
return [
    'subject' => [
        'full' => 'Payment received for document :number',
        'partial' => 'Partial payment received for document :number',
    ],

    'greeting' => 'Hello,',

    'received' => 'thank you, we have received your payment of :amount dated :date for document no. :number.',

    // The payment that leaves nothing owed, an overpayment included.
    'paid_in_full' => 'The document is now paid in full.',

    // A partial payment: what is still owed.
    'remaining' => 'The remaining amount is :remaining.',

    // A cash payment: the receipt is the attachment (Phase 15, D-12).
    'receipt_attached' => 'The cash receipt no. :number is attached.',

    'thanks' => 'Thank you.',
];
