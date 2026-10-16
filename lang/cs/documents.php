<?php

declare(strict_types=1);

/*
 * The Czech words of the documents module, namespace `documents.*` (DO-01). Upload errors come first;
 * the keys of later documents plans are added below them. Activity labels stay in lang/cs/kokpit.php.
 */
return [
    'errors' => [
        'invalid_upload' => 'Nahrát lze pouze soubory vybrané v prohlížeči. Zkuste soubor vybrat znovu.',
        'empty_upload' => 'Nevybrali jste žádný soubor.',
        'too_large' => 'Soubor je větší než povolených :max MB.',
        'too_many_files' => 'Najednou lze nahrát nejvýše :max souborů.',
        'extension_not_allowed' => 'Soubor „:name“ má nepovolenou příponu.',
        'owner_archived' => 'Do archivované položky nelze přidávat soubory.',
        'upload_unreadable' => 'Nahraný soubor se nepodařilo přečíst. Zkuste ho nahrát znovu.',
    ],

    // The tab on a client, project, task or transaction (plan 09-05). The Partner tabs (plan 09-08) read the same keys.
    'relation_title' => 'Soubory',

    'columns' => [
        'name' => 'Název',
        'description' => 'Popis',
        'owner' => 'Patří k',
        'client' => 'Klient',
        'size' => 'Velikost',
        'mime_type' => 'Typ',
        'uploaded_by' => 'Nahrál',
        'created_at' => 'Nahráno',
        'visible_to_client' => 'Vidí klient',
    ],

    'actions' => [
        'upload' => 'Nahrát soubory',
        'upload_submit' => 'Nahrát',
        'download' => 'Stáhnout',
        'preview' => 'Zobrazit',
    ],

    'fields' => [
        'files' => 'Soubory',
        'description' => 'Popis',
        'visible_to_client' => 'Zobrazit klientovi',
    ],

    'hints' => [
        'files' => 'Najednou nejvýše :count souborů, každý do :max MB.',
        'visible_to_client' => 'Klient uvidí tento soubor i jeho popis. Později to můžete změnit.',
        'client_hidden' => 'Soubory přidané ke klientovi vidíte jen vy.',
    ],

    'empty' => [
        'heading' => 'Zatím žádné soubory',
        'description' => 'Nahrajte první soubor tlačítkem nahoře.',
    ],

    'partner_empty' => [
        'heading' => 'Zatím žádné sdílené soubory',
        'description' => 'Až s vámi někdo soubor nasdílí, objeví se tady.',
    ],

    'owner_none' => 'Nepřiřazeno',

    'notifications' => [
        'uploaded' => '{1} Byl nahrán :count soubor.|[2,4] Byly nahrány :count soubory.|[5,*] Bylo nahráno :count souborů.',
    ],
];
