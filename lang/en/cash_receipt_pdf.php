<?php

declare(strict_types=1);

/*
 * The English words of the cash receipt PDF (plans 15-08 and 15-09; D-11, AS-5, AS-6). The keys are exactly those of
 * lang/cs/cash_receipt_pdf.php; a test compares them.
 */
return [
    'purpose' => [
        'invoice' => 'Payment of invoice no. :number',
        'proforma' => 'Payment of proforma invoice no. :number',
    ],
    'title' => 'Cash receipt',
    'number' => 'Document no.',
    'issued_on' => 'Date of issue',
    'received_on' => 'Date cash received',
    'received_from' => 'Received from',
    'received_by' => 'Received by',
    'amount' => 'Amount received',
    'purpose_label' => 'Purpose',
    'document' => 'Document paid',
    'issuer' => 'Issued electronically by :name',
    'company_id' => 'Company ID',
    'vat_id' => 'VAT ID',
    'not_vat_payer' => 'The supplier is not a VAT payer.',
    'cancelled' => 'CANCELLED on :date',
    'page' => 'Page :n / :total',
    'file_name' => 'cash-receipt-:number.pdf',
];
