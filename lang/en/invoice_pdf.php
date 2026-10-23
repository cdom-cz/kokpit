<?php

declare(strict_types=1);

/*
 * The English words of the invoice PDF (UI-SPEC Surface H, "PDF strings", English column). The keys are exactly
 * those of lang/cs/invoice_pdf.php; a test compares them.
 */
return [
    'headings' => [
        'invoice' => 'INVOICE',
        'proforma' => 'PROFORMA INVOICE',
        'credit_note' => 'CREDIT NOTE',
    ],
    'supplier' => 'SUPPLIER',
    'customer' => 'CUSTOMER',
    'company_id' => 'Company ID',
    'vat_id' => 'VAT ID',
    'not_vat_payer' => 'The supplier is not a VAT payer.',
    'issue_date' => 'Issue date',
    'taxable_supply_date' => 'Taxable supply date',
    'due_date' => 'Due date',
    'quantity' => 'Qty',
    'unit_price' => 'Unit price',
    'total' => 'Total',
    'vat_rate' => 'VAT %',
    'amount_due_bar' => 'AMOUNT DUE',
    'credit_total_bar' => 'CREDIT TOTAL',
    'payment_sentence' => 'Please pay :amount to account :account with variable symbol :vs by :due.',
    'online_sentence' => 'Please pay :amount online by card by :due.',
    'account' => 'Account',
    'iban' => 'IBAN',
    'bic' => 'BIC',
    'variable_symbol' => 'Variable symbol',
    'payment_method' => 'Payment method',
    'bank_transfer' => 'Bank transfer',
    'online_payment' => 'Online by card',
    'amount_due' => 'Amount due',
    'card_lead' => 'Or pay online by card:',
    'online_card_lead' => 'Pay online by card:',
    'card_button' => 'Pay by card',
    'proforma_note' => 'This is not a tax document.',
    'corrects_document' => 'Corrects document no. :number',
    'credit_note_no_payment' => 'This credit note carries no payment details. Any refund is made separately.',
    'page' => 'Page :n / :total',
    'qr_message' => 'Invoice :number',
    'file_name' => [
        'invoice' => 'invoice-:number.pdf',
        'proforma' => 'proforma-:number.pdf',
        'credit_note' => 'credit-note-:number.pdf',
    ],
];
