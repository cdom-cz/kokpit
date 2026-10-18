<?php

declare(strict_types=1);

/*
 * The wording of the Admin's card payments overview "Platby kartou" and its record view (Phase 11, UI-SPEC Surface H and
 * the Copywriting Contract "Payments overview and record view"). Admin only: nothing here is ever shown to a Partner.
 * The enum labels (type, reason, resolution, status) live in lang/cs/enums.php.
 */
return [
    'navigation' => [
        'label' => 'Platby kartou',
        'heading' => 'Platby kartou',
        'model' => 'platba kartou',
        'plural' => 'Platby kartou',
        'empty_value' => '—',
    ],

    'tabs' => [
        'unresolved' => 'Nevyřešené',
        'all' => 'Všechny',
        'matched' => 'Spárované',
        'duplicate' => 'Duplicitní',
        'unmatched' => 'Nespárované',
        'refund' => 'Vrácení',
        'resolved' => 'Vyřešené',
    ],

    'columns' => [
        'received' => 'Přijato',
        'type' => 'Typ',
        'invoice' => 'Doklad',
        'client' => 'Klient',
        'amount' => 'Částka',
        'reason' => 'Důvod',
        'status' => 'Stav',
        'payment' => 'Platba',
        'event' => 'Událost',
        'offered' => 'Nabízeno: :offered',
    ],

    'actions' => [
        'open' => 'Otevřít',
    ],
];
