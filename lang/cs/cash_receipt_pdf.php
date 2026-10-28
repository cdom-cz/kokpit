<?php

declare(strict_types=1);

/*
 * The Czech words of the cash receipt PDF (plans 15-08 and 15-09; D-11, AS-5, AS-6). The keys are exactly those of
 * lang/en/cash_receipt_pdf.php; a test compares them. The receipt prints no VAT breakdown, variable symbol, QR code,
 * bank data, pay button, payer signature box or amount in words, so none of those words exists here.
 */
return [
    'purpose' => [
        'invoice' => 'Úhrada faktury č. :number',
        'proforma' => 'Úhrada zálohové faktury č. :number',
    ],
    'title' => 'Příjmový pokladní doklad',
    'number' => 'Číslo dokladu',
    'issued_on' => 'Datum vystavení',
    'received_on' => 'Datum přijetí hotovosti',
    'received_from' => 'Přijato od',
    'received_by' => 'Příjemce',
    'amount' => 'Přijatá částka',
    'purpose_label' => 'Účel platby',
    'document' => 'Hrazený doklad',
    'issuer' => 'Doklad vystaven elektronicky: :name',
    'company_id' => 'IČO',
    'vat_id' => 'DIČ',
    'not_vat_payer' => 'Dodavatel není plátce DPH.',
    'cancelled' => 'STORNO - doklad zrušen :date',
    'page' => 'Strana :n / :total',
    'file_name' => 'prijmovy-doklad-:number.pdf',
];
