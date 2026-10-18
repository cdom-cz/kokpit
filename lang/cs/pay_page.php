<?php

declare(strict_types=1);

/*
 * The public pay page (Phase 11, UI-SPEC Surface A): Czech copy of the four cards. The document number is the only
 * document datum; there is never an amount, a client or a Stripe reference. The invalid card is shown in Czech and
 * English together, because the language of an unknown document is unknown.
 */
return [
    'preparing' => [
        'heading' => 'Odkaz na platbu se připravuje',
        'body' => 'Doklad č. :number. Zkuste to prosím za chvíli. Pokud se odkaz nezobrazí ani později, kontaktujte dodavatele dokladu.',
        'retry' => 'Zkusit znovu',
    ],
    'paid' => [
        'heading' => 'Doklad je zaplacený',
        'body' => 'Doklad č. :number je zaplacený. Děkujeme.',
    ],
    'cancelled' => [
        'heading' => 'Doklad byl stornován',
        'body' => 'Doklad č. :number byl stornován, platba kartou už není možná. S dotazy se obraťte na dodavatele.',
    ],
    'invalid' => [
        'heading' => 'Odkaz není platný',
        'body' => 'Tento odkaz na platbu nelze použít. Požádejte dodavatele o nový.',
    ],
];
