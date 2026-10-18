<?php

declare(strict_types=1);

/*
 * The English wording the e-mail dialog pre-fills for a billing document (UI-SPEC "E-mail wording", English column).
 * The keys are exactly those of lang/cs/invoice_mail.php; a test compares them.
 */
return [
    'subject' => [
        'invoice' => 'Invoice :number',
        'proforma' => 'Proforma invoice :number',
        'credit_note' => 'Credit note :number',
    ],

    'kind' => [
        'invoice' => 'invoice',
        'proforma' => 'proforma invoice',
        'credit_note' => 'credit note',
    ],

    'body' => [
        'with_due_date' => "Hello,\n\nplease find attached :kind no. :number for :amount, due on :due.\n\nPayment details are in the attached document.\n\nKind regards\n:supplier",
        'credit_note' => "Hello,\n\nplease find attached :kind no. :number for :amount.\n\nAny refund will be made separately.\n\nKind regards\n:supplier",
    ],

    'work_report_file' => 'work-report-:number.pdf',
];
