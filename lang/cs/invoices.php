<?php

declare(strict_types=1);

/*
 * The Czech words of the invoicing domain. For now only the error contract of the whole phase: every domain
 * error message the Actions of the billing documents raise, written in one place so that later Actions read the
 * file and never edit it in parallel. The screens add their own sections in plan 10-21. Strings are Unicode NFC.
 */
return [
    'errors' => [
        // The draft and its header.
        'not_draft' => 'Doklad už je vystavený a nelze ho upravit.',
        'client_archived' => 'Archivovanému klientovi nelze vytvořit doklad.',
        'client_unknown' => 'Vyberte klienta.',
        'kind' => 'Zvolte fakturu nebo zálohovou fakturu.',
        'currency' => 'Zvolte platnou měnu.',
        'language' => 'Zvolte jazyk dokladu.',
        'date_invalid' => 'Zadejte platné datum.',
        'due_before_issue' => 'Datum splatnosti nesmí být dříve než datum vystavení.',
        'note_length' => 'Poznámka na dokladu může mít nejvýše 500 znaků.',
        'internal_note_length' => 'Interní poznámka může mít nejvýše 2 000 znaků.',

        // The items of a draft.
        'item_unknown' => 'Položka nepatří k tomuto dokladu.',
        'item_description' => 'Zadejte popis položky (nejvýše 500 znaků).',
        'item_quantity' => 'Zadejte množství větší než nula s nejvýše čtyřmi desetinnými místy.',
        'item_unit' => 'Zadejte jednotku (nejvýše 16 znaků).',
        'item_price' => 'Zadejte jednotkovou cenu v měně dokladu.',
        'item_zero_price' => 'Jednotkovou cenu nula potvrďte u položky.',
        'items_cap' => 'Doklad může mít nejvýše :max položek.',

        // The blockers of issuing.
        'no_items' => 'Doklad nemá žádnou položku.',
        'total_not_positive' => 'Celková částka musí být větší než nula.',
        'supplier_missing' => 'Doplňte údaje dodavatele v Nastavení.',
        'bank_missing' => 'Pro měnu :currency není nastaven bankovní účet. Přidejte ho v Nastavení.',
        'cnb_missing' => 'Pro měnu :currency není ke dni :date k dispozici kurz ČNB. Stáhněte kurzy v přehledu Kurzy ČNB.',
        'client_address_missing' => 'Klient nemá vyplněnou fakturační adresu.',
        'issue_date_future' => 'Datum vystavení nesmí být později než dnes.',
        'issue_date_before_latest' => 'Datum vystavení nesmí být dříve než :date, kdy byl vystaven poslední doklad této řady.',
        'pattern_invalid' => 'Číselná řada pro :kind není platná. Opravte ji v Nastavení.',
        'entries_changed' => 'Některé připojené záznamy času se mezitím změnily. Zkontrolujte koncept a vystavte ho znovu.',

        // Time added to a draft.
        'period_order' => 'Datum Od nesmí být později než Do.',
        'projects_unknown' => 'Vyberte projekty tohoto klienta.',
        'grouping' => 'Zvolte seskupení podle projektu nebo podle úkolu.',
        'fixed_project' => 'Zvolený projekt nemá pevnou cenu.',
        'fixed_amount' => 'Zadejte částku větší než nula v měně dokladu.',
        'entries_foreign' => 'Vybrané záznamy už nejsou k dispozici.',

        // Payments.
        'payment_amount' => 'Zadejte částku větší než nula.',
        'payment_date_future' => 'Datum platby nesmí být v budoucnosti.',
        'payment_not_allowed' => 'Platbu nelze zaznamenat u dobropisu ani u stornovaného dokladu.',
        'payment_category' => 'Zvolte kategorii příjmů.',
        'payment_method' => 'Zvolte platbu převodem nebo hotově.',
        'payment_note' => 'Poznámka k platbě může mít nejvýše 500 znaků.',
        'payment_transaction_locked' => 'Transakce vznikla z platby faktury :number. Změnit lze jen datum a kategorii; chybnou platbu smažte v detailu faktury.',
        'payment_transaction_delete' => 'Transakce vznikla z platby faktury :number. Smazat ji lze jen smazáním platby v detailu faktury.',
        'payment_delete_carried' => 'Platba je přenesená na fakturu :number. Nejdřív smažte platbu na faktuře.',
        'payment_missing' => 'Platba už neexistuje. Obnovte stránku.',

        // Conversion of a proforma.
        'proforma_not_paid' => 'Zálohu lze převést na fakturu až po úplném zaplacení.',
        'proforma_converted' => 'K záloze už byla faktura vystavena.',

        // Credit notes.
        'credit_not_allowed' => 'Dobropis lze vystavit jen k vystavené, nestornované faktuře.',
        'credit_nothing' => 'Vyberte alespoň jednu položku.',
        'credit_quantity' => 'Množství k dobropisu nesmí být vyšší než původní množství.',
        'credit_total' => 'Součet dobropisů nesmí přesáhnout hodnotu faktury.',

        // E-mail.
        'email_recipient' => 'Zadejte platnou e-mailovou adresu příjemce.',
        'email_subject' => 'Zadejte předmět (nejvýše 200 znaků).',
        'email_body' => 'Zadejte text e-mailu (nejvýše 5 000 znaků).',
        'email_not_issued' => 'E-mail lze odeslat jen u vystaveného dokladu.',

        // The stored PDF.
        'pdf_unconfirmed' => 'PDF vystaveného dokladu lze smazat jen s výslovným potvrzením.',
        'pdf_missing' => 'PDF dokladu chybí.',
    ],

    // The fixed text of the income transaction a payment books (R-16-2).
    'payment_income' => [
        'description' => 'Platba faktury :number',
    ],
];
