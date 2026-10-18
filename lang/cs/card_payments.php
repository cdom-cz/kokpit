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

    'filters' => [
        'client' => 'Klient',
        'currency' => 'Měna',
        'received' => 'Přijato',
        'from' => 'Od',
        'until' => 'Do',
        'archived' => ':name (archivovaný)',
    ],

    'empty' => [
        'all_heading' => 'Zatím žádné platby kartou',
        'all_body' => 'Platby přijaté přes Stripe se objeví tady. Odkaz na platbu se vytváří při vystavení dokladu, pokud je zapnutá online platba.',
        'unresolved_heading' => 'Nic k řešení',
        'unresolved_body' => 'Všechny platby kartou jsou spárované nebo vyřešené.',
        'matched_heading' => 'Zatím žádná spárovaná platba',
        'duplicate_heading' => 'Žádné duplicitní platby',
        'unmatched_heading' => 'Žádné nespárované platby',
        'refund_heading' => 'Žádná vrácení',
        'resolved_heading' => 'Zatím nic vyřešeného',
    ],

    'actions' => [
        'open' => 'Otevřít',
    ],
];
