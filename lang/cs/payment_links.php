<?php

declare(strict_types=1);

/*
 * The wording of the payment links (Phase 11). The `stripe` group is sent to Stripe when the link is created, in the
 * language of the document (UI-SPEC U-15), each message at most 500 characters; the English counterpart is
 * lang/en/payment_links.php. The keys of the detail page of a document (`section`, `actions`, `banners`, `frozen`) and
 * of the invoice list (`list`) are Admin-only copy, so they exist in Czech only.
 */
return [
    'section' => [
        'heading' => 'Platba kartou',
        'state' => 'Stav odkazu',
        'amount' => 'Nabízená částka',
        'created' => 'Vytvořen',
        'url' => 'Odkaz pro klienta',
        'reason' => 'Důvod',
        'off' => 'Vypnuto',
        'copied' => 'Odkaz zkopírován',
        'reasons' => [
            'failed' => 'Stripe odkaz se nepodařilo vytvořit. Zkuste to znovu; pokud chyba přetrvá, zkontrolujte nastavení Stripe v Systému.',
            'off' => 'Při vystavení nebyla platba kartou zapnutá.',
        ],
    ],
    'actions' => [
        'pay_by_card' => 'Zaplatit kartou',
        'pay_by_card_tooltip' => 'Otevře platební stránku v novém okně.',
        'open_pay_page' => 'Zaplatit kartou',
        'regenerate' => [
            'label' => 'Vytvořit odkaz znovu',
            'heading' => 'Vytvořit nový odkaz na platbu?',
            'body' => 'Původní odkaz se deaktivuje a nový se vytvoří na zbývající částku :amount. Odkaz na PDF i v již odeslaném e-mailu zůstává stejný.',
            'submit' => 'Vytvořit odkaz',
            'cancel' => 'Nevytvářet',
            'toast' => 'Odkaz se vytváří',
        ],
        'deactivate' => [
            'label' => 'Deaktivovat odkaz',
            'heading' => 'Deaktivovat odkaz na platbu?',
            'body' => 'Klient už nebude moci zaplatit kartou, dokud nevytvoříte nový odkaz. Odkaz na PDF a v e-mailu zobrazí informační stránku.',
            'submit' => 'Deaktivovat odkaz',
            'cancel' => 'Ponechat odkaz',
            'toast' => 'Odkaz byl deaktivován',
        ],
        'refused_title' => 'Odkaz se nepodařilo změnit',
    ],
    'banners' => [
        'failed' => 'Odkaz na platbu kartou se nepodařilo vytvořit. Klient vidí informační stránku „Odkaz na platbu se připravuje“.',
        'inactive' => 'Doklad nemá aktivní odkaz na platbu kartou. Klient vidí informační stránku. Chcete-li nabídnout platbu kartou, vytvořte odkaz znovu na zbývající částku :amount.',
    ],
    'list' => [
        'filter' => 'Platební odkaz',
        'column' => 'Platba kartou',
        'options' => [
            'active' => 'Aktivní',
            'pending' => 'Připravuje se',
            'failed' => 'Selhalo',
            'deactivated' => 'Deaktivovaný',
            'off' => 'Vypnuto',
        ],
        'states' => [
            'active' => 'Odkaz na platbu kartou je aktivní',
            'pending' => 'Odkaz na platbu kartou se připravuje',
            'failed' => 'Odkaz na platbu kartou se nepodařilo vytvořit',
            'deactivated' => 'Odkaz na platbu kartou je deaktivovaný',
            'off' => 'Platba kartou je vypnutá',
            'none' => 'Doklad nemá odkaz na platbu kartou',
        ],
    ],
    'frozen' => [
        'label' => 'Platba kartou',
        'yes' => 'Ano',
        'no' => 'Ne',
    ],
    'errors' => [
        'not_regenerable' => 'Pro tento doklad nelze vytvořit nový odkaz na platbu. Odkaz jde vytvořit jen pro vystavenou nezaplacenou fakturu nebo zálohovou fakturu s platbou kartou.',
        'preparing' => 'Odkaz na platbu se právě připravuje. Zkuste to za chvíli.',
        'no_active_link' => 'Doklad nemá aktivní odkaz na platbu, který by šlo vypnout.',
    ],
    'stripe' => [
        'product' => [
            'invoice' => 'Faktura :number',
            'proforma' => 'Zálohová faktura :number',
        ],
        'confirmation' => 'Děkujeme, platba byla přijata. Doklad :number se označí jako zaplacený během několika minut.',
        'inactive' => 'Tento odkaz na platbu už není aktivní. Použijte odkaz z dokladu nebo kontaktujte dodavatele.',
    ],
];
