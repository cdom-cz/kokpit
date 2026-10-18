<?php

declare(strict_types=1);

/*
 * The wording of the payment links (Phase 11). The `stripe` group is sent to Stripe when the link is created, in the
 * language of the document (UI-SPEC U-15), each message at most 500 characters; the English counterpart is
 * lang/en/payment_links.php. The keys of the detail page of a document are added by the plan of that surface.
 */
return [
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
