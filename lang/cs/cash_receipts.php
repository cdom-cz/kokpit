<?php

declare(strict_types=1);

/*
 * The Czech texts of the cash receipt domain (Phase 15): the errors the Actions raise for a receipt (issue, edit,
 * send, lock, unlock, cancel, PDF). The full `errors` set is created here so the plans that use the keys run side by
 * side without touching this file; `partner.*` (the read-only list of the Partner, plan 15-11) follows `errors`; `admin.*` is added by plan 15-14.
 */
return [
    'errors' => [
        'not_cash' => 'Příjmový doklad se vystavuje jen k platbě v hotovosti.',
        'payment_has_receipt' => 'K platbě už byl vystaven příjmový doklad :number. Nejdřív ho stornujte.',
        'cancelled' => 'Příjmový doklad je stornovaný a nelze ho měnit.',
        'already_cancelled' => 'Příjmový doklad už je stornovaný.',
        'locked' => 'Příjmový doklad je uzamčený odesláním. Pro úpravu ho nejdřív odemkněte.',
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
];
