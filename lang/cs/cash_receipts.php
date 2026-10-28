<?php

declare(strict_types=1);

/*
 * The Czech texts of the cash receipt domain (Phase 15): the errors the Actions raise for a receipt (issue, edit,
 * send, lock, unlock, cancel, PDF). The full `errors` set is created here so the plans that use the keys run side by
 * side without touching this file; `partner.*` (the read-only list of the Partner, plan 15-11) follows `errors`; `admin.*` (the receipt next to a payment in the Platby tab of the Admin, plan 15-14) follows `partner`.
 */
return [
    'errors' => [
        'not_cash' => 'Příjmový doklad se vystavuje jen k platbě v hotovosti.',
        'payment_has_receipt' => 'K platbě už byl vystaven příjmový doklad :number. Nejdřív ho stornujte.',
        'cancelled' => 'Příjmový doklad je stornovaný a nelze ho měnit.',
        'already_cancelled' => 'Příjmový doklad už je stornovaný.',
        'locked' => 'Příjmový doklad je uzamčený. Pro úpravu ho nejdřív odemkněte.',
        'already_locked' => 'Příjmový doklad je už uzamčený.',
        'not_locked' => 'Příjmový doklad není uzamčený.',
        'reason_length' => 'Důvod musí mít 10 až 500 znaků.',
        'sending' => 'Příjmový doklad se právě odesílá. Zkuste to za chvíli.',
        'purpose_invalid' => 'Účel platby musí mít 1 až 500 znaků.',
        'received_on_invalid' => 'Datum přijetí musí být platné datum, které není po dni vystavení.',
        'pdf_protected' => 'PDF příjmového dokladu nelze smazat, dokud je doklad platný.',
        'pdf_present' => 'PDF příjmového dokladu už existuje.',
    ],
    'partner' => [
        'title' => 'Příjmové doklady',
        'columns' => [
            'number' => 'Číslo',
            'received_on' => 'Přijato dne',
            'amount' => 'Částka',
            'state' => 'Stav',
        ],
        'state' => [
            'valid' => 'Platný',
            'cancelled' => 'Zrušen',
        ],
        'download' => 'Stáhnout PDF',
        'empty_heading' => 'Žádný příjmový doklad',
        'empty_body' => 'K tomuto dokladu zatím nebyl vystaven žádný příjmový doklad.',
    ],
    'admin' => [
        'columns' => [
            'receipt' => 'Příjmový doklad',
            'state' => 'Stav dokladu',
            'warning' => 'Upozornění',
        ],
        'carried' => 'ze zálohové faktury',
        'state' => [
            'open' => 'Otevřený',
            'locked' => 'Uzamčený',
            'cancelled' => 'Zrušený',
        ],
        'without_valid_receipt' => 'Platba bez platného dokladu',
        'delete_blocked' => 'Nejdříve zrušte příjmový doklad :number.',
        'missing' => 'Příjmový doklad k této platbě už neexistuje.',
        'group' => 'Příjmový doklad',
        'actions' => [
            'download' => 'Stáhnout PDF',
            'edit' => 'Upravit',
            'lock' => 'Uzamknout',
            'cancel' => 'Zrušit doklad',
            'unlock' => 'Odemknout',
            'regenerate' => 'Vytvořit PDF znovu',
        ],
        'cancel' => [
            'heading' => 'Zrušit příjmový doklad :number?',
            'description' => 'Doklad si ponechá číslo i PDF a označí se jako zrušený. Platbu pak půjde smazat. Důvod se zapíše do auditního logu.',
            'reason' => 'Důvod zrušení',
            'submit' => 'Zrušit doklad',
            'done' => 'Příjmový doklad byl zrušen',
        ],
        'edit' => [
            'heading' => 'Upravit příjmový doklad :number',
            'submit' => 'Uložit doklad',
            'done' => 'Příjmový doklad byl upraven',
            'received_on' => 'Přijato dne',
            'purpose' => 'Účel platby',
            'customer' => 'Odběratel',
            'supplier' => 'Dodavatel',
            'name' => 'Název',
            'street' => 'Ulice',
            'city' => 'Město',
            'postal_code' => 'PSČ',
            'country' => 'Země (kód ISO)',
            'company_id' => 'IČO',
            'vat_id' => 'DIČ',
        ],
        'lock' => [
            'heading' => 'Uzamknout příjmový doklad :number?',
            'description' => 'Doklad se uzamkne bez odeslání potvrzení platby, například když ho klient dostal osobně. Upravit ho pak půjde jen po odemknutí s uvedením důvodu.',
            'submit' => 'Uzamknout',
            'done' => 'Příjmový doklad je uzamčen',
        ],
        'unlock' => [
            'heading' => 'Odemknout příjmový doklad :number',
            'description' => 'Doklad se otevře pro úpravy. Zamkne se znovu až dalším úspěšným odesláním potvrzení platby nebo ručním uzamčením. Důvod se zapíše do auditního logu.',
            'reason' => 'Důvod odemknutí',
            'submit' => 'Odemknout',
            'done' => 'Příjmový doklad byl odemknut',
        ],
        'regenerate' => [
            'done' => 'PDF příjmového dokladu bylo vytvořeno znovu',
            'failed' => 'PDF příjmového dokladu se nepodařilo vytvořit.',
        ],
    ],
];
