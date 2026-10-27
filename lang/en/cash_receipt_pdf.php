<?php

declare(strict_types=1);

/*
 * The English words of the cash receipt PDF. Only the default purposes are here so far (plan 15-08); the other words of
 * the PDF are added by plan 15-09. The keys are exactly those of lang/cs/cash_receipt_pdf.php; a test compares them.
 */
return [
    'purpose' => [
        'invoice' => 'Payment of invoice no. :number',
        'proforma' => 'Payment of proforma invoice no. :number',
    ],
];
