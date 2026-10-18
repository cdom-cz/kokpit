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

    'sections' => [
        'payment' => 'Platba',
        'matching' => 'Spárování',
        'resolution' => 'Řešení',
    ],

    'fields' => [
        'amount' => 'Částka',
        'currency' => 'Měna',
        'received' => 'Přijato',
        'payment' => 'Platba',
        'event' => 'Událost',
        'link' => 'Odkaz',
        'invoice' => 'Doklad',
        'client' => 'Klient',
        'offered' => 'Nabízená částka',
        'remaining' => 'Zbývá na dokladu',
        'reason' => 'Důvod',
        'status' => 'Stav',
        'resolved_at' => 'Vyřešeno',
        'method' => 'Způsob',
        'note' => 'Poznámka',
        'expense' => 'Navázaný výdaj',
    ],

    'banners' => [
        'duplicate' => 'Doklad :number už byl zaplacený. Tato platba nevytvořila příjem a nezměnila doklad.',
        'amount' => 'Platba :paid neodpovídá částce, kterou odkaz nabízel (:offered). Nevznikl příjem a doklad se nezměnil.',
        'currency' => 'Platba je v měně :currency, odkaz nabízel :offered_currency. Nevznikl příjem a doklad se nezměnil.',
        'undetermined' => 'Platbu se nepodařilo přiřadit k žádnému dokladu. Nevznikl příjem.',
        'cancelled' => 'Doklad :number je stornovaný. Nevznikl příjem.',
        'refund' => 'Stripe oznámil vrácení platby :amount. Doklad ani příjem se nezměnily. Upravte je ručně, pokud je to potřeba.',
        'matched' => 'Platba je zapsaná u dokladu :number. Opravit ji lze smazáním platby v detailu dokladu.',
        'matched_deleted' => 'Platba byla z dokladu smazána. Událost zůstává rezervovaná, takže její opakované doručení platbu nevytvoří znovu.',
        'resolved' => 'Vyřešeno :date: :resolution.',
    ],

    'actions' => [
        'open' => 'Otevřít',
    ],
];
