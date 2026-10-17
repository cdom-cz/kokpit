<?php

declare(strict_types=1);

/*
 * The Czech wording the e-mail dialog pre-fills for a billing document (UI-SPEC "E-mail wording"). The English column
 * lives in lang/en/invoice_mail.php; a test compares the keys. The Admin may edit every text in the dialog.
 */
return [
    'subject' => [
        'invoice' => 'Faktura :number',
        'proforma' => 'Zálohová faktura :number',
        'credit_note' => 'Dobropis :number',
    ],

    // The kind as the object of the sentence in the body ("posíláme fakturu").
    'kind' => [
        'invoice' => 'fakturu',
        'proforma' => 'zálohovou fakturu',
        'credit_note' => 'dobropis',
    ],

    'body' => [
        'with_due_date' => "Dobrý den,\n\nv příloze posíláme :kind č. :number na částku :amount se splatností :due.\n\nPlatební údaje najdete v přiloženém dokumentu.\n\nS pozdravem\n:supplier",
        'credit_note' => "Dobrý den,\n\nv příloze posíláme :kind č. :number na částku :amount.\n\nPlatba bude vrácena samostatně.\n\nS pozdravem\n:supplier",
    ],

    // The generated name of the work report attachment: no user text reaches it.
    'work_report_file' => 'pracovni-vykaz-:number.pdf',
];
