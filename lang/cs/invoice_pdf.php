<?php

declare(strict_types=1);

/*
 * The Czech words of the invoice PDF (UI-SPEC Surface H, "PDF strings"). The English column lives in
 * lang/en/invoice_pdf.php; a test compares the keys. The headings and party labels are written in capitals
 * because they are printed that way.
 */
return [
    'headings' => [
        'invoice' => 'FAKTURA',
        'proforma' => 'ZÁLOHOVÁ FAKTURA',
        'credit_note' => 'DOBROPIS',
    ],
    'supplier' => 'DODAVATEL',
    'customer' => 'ODBĚRATEL',
    'company_id' => 'IČO',
    'vat_id' => 'DIČ',
    'not_vat_payer' => 'Dodavatel není plátce DPH.',
    'issue_date' => 'Datum vystavení',
    'taxable_supply_date' => 'Datum zdanitelného plnění',
    'due_date' => 'Datum splatnosti',
    'quantity' => 'Množství',
    'unit_price' => 'Jednotková cena',
    'total' => 'Celkem',
    'vat_rate' => 'DPH %',
    'amount_due_bar' => 'K ÚHRADĚ',
    'credit_total_bar' => 'DOBROPIS CELKEM',
    'payment_sentence' => 'Prosíme o úhradu částky :amount na účet :account s variabilním symbolem :vs do :due.',
    'online_sentence' => 'Prosíme o úhradu částky :amount online platební kartou do :due.',
    // A cash document prints no bank details, so this sentence names neither an account nor a variable symbol (Phase 15, D-02).
    'cash_sentence' => 'Prosíme o úhradu částky :amount v hotovosti do :due.',
    'account' => 'Číslo účtu',
    'iban' => 'IBAN',
    'bic' => 'BIC',
    'variable_symbol' => 'Variabilní symbol',
    'payment_method' => 'Způsob úhrady',
    'bank_transfer' => 'Převodem',
    'online_payment' => 'Online platební kartou',
    'cash_payment' => 'Hotově',
    'amount_due' => 'K úhradě',
    'card_lead' => 'Nebo zaplaťte online kartou:',
    'online_card_lead' => 'Zaplaťte online kartou:',
    'card_button' => 'Zaplatit kartou',
    'proforma_note' => 'Nejde o daňový doklad.',
    'corrects_document' => 'Opravuje doklad č. :number',
    'credit_note_no_payment' => 'Dobropis neobsahuje platební údaje. Případné vrácení platby proběhne samostatně.',
    'page' => 'Strana :n / :total',
    'qr_message' => 'Faktura :number',
    'file_name' => [
        'invoice' => 'faktura-:number.pdf',
        'proforma' => 'zalohova-faktura-:number.pdf',
        'credit_note' => 'dobropis-:number.pdf',
    ],
];
