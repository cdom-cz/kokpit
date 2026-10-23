<?php

declare(strict_types=1);

/*
 * The Czech wording of the payment confirmation e-mail sent to the client (Phase 13, D-05, D-06). The document is named
 * "doklad č. :number" so the kind (invoice, proforma) is never inflected. The English column lives in
 * lang/en/payment_mail.php; a test compares the keys.
 */
return [
    'subject' => [
        'full' => 'Potvrzení platby dokladu :number',
        'partial' => 'Potvrzení částečné platby dokladu :number',
    ],

    'greeting' => 'Dobrý den,',

    'received' => 'děkujeme, přijali jsme vaši platbu :amount ze dne :date k dokladu č. :number.',

    // The payment that leaves nothing owed, an overpayment included.
    'paid_in_full' => 'Doklad je tímto zcela uhrazen.',

    // A partial payment: what is still owed.
    'remaining' => 'Zbývá doplatit :remaining.',

    'thanks' => 'Děkujeme.',
];
