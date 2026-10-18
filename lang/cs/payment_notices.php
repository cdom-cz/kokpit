<?php

declare(strict_types=1);

/*
 * The Admin notices of the card payments (Phase 11, UI-SPEC Surface I): the bell notice and the e-mail carry the same
 * title and body, the mail subject is `mail_subject`. Each notice names the document number or the payment amount and
 * a fixed text, never a Stripe reference, a payer or a provider message. The other notices are added by the plans
 * that raise them.
 */
return [
    'mail_subject' => 'Kokpit: :title',

    'link_failed' => [
        'title' => 'Odkaz na platbu kartou se nepodařilo vytvořit',
        'body' => 'Doklad :number zatím nemá odkaz na platbu. Klient vidí informační stránku. Vytvořte odkaz znovu v detailu dokladu.',
        'action' => 'Otevřít doklad',
    ],

    'matched' => [
        'title' => 'Přišla platba kartou',
        'body' => 'Doklad :number byl zaplacen (:amount).',
        'body_proforma' => 'Záloha :number byla zaplacena (:amount).',
        'action' => 'Otevřít doklad',
    ],

    'duplicate' => [
        'title' => 'Duplicitní platba kartou',
        'body' => 'Doklad :number už byl zaplacený a přišla další platba :amount. Příjem nevznikl. Vyřešte ji v přehledu plateb.',
    ],

    'unmatched' => [
        'title' => 'Nespárovaná platba kartou',
        'body' => 'Přišla platba :amount, kterou nelze automaticky zapsat (:reason). Příjem nevznikl. Vyřešte ji v přehledu plateb.',
    ],

    'refund' => [
        'title' => 'Stripe oznámil vrácení platby',
        'body' => 'Vrácení :amount se týká platby kartou. Doklad ani příjem se nezměnily. Zkontrolujte ho v přehledu plateb.',
    ],

    'open_payment' => 'Otevřít platbu',
];
