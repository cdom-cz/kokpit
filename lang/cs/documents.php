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
        'name_required' => 'Název souboru musí být vyplněn.',
        'not_shareable' => 'Tento soubor nelze sdílet s klientem.',
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
        'rename' => 'Přejmenovat',
        'rename_submit' => 'Uložit',
        'show_to_client' => 'Zobrazit klientovi',
        'hide_from_client' => 'Skrýt před klientem',
        'delete' => 'Smazat',
        'delete_heading' => 'Smazat soubor?',
        'bulk_delete' => 'Smazat vybrané',
        'bulk_delete_heading' => 'Smazat vybrané soubory?',
        'bulk_delete_description' => '{1} Bude trvale smazán :count soubor, včetně uloženého souboru. Tento krok nelze vrátit zpět.|[2,4] Budou trvale smazány :count soubory, včetně uložených souborů. Tento krok nelze vrátit zpět.|[5,*] Bude trvale smazáno :count souborů, včetně uložených souborů. Tento krok nelze vrátit zpět.',
        'delete_description' => 'Soubor „:name“ bude trvale smazán, včetně uloženého souboru. Tento krok nelze vrátit zpět.',
    ],

    'fields' => [
        'name' => 'Název',
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
        'renamed' => 'Soubor byl upraven.',
        'shown' => 'Soubor nyní vidí klient.',
        'hidden' => 'Soubor je skrytý před klientem.',
        'deleted' => 'Soubor byl trvale smazán.',
        'bulk_deleted' => 'Trvale smazáno: :deleted, nepodařilo se smazat: :failed.',
        'delete_failed' => 'Soubor se nepodařilo smazat. Zkuste to znovu.',
        'uploaded' => '{1} Byl nahrán :count soubor.|[2,4] Byly nahrány :count soubory.|[5,*] Bylo nahráno :count souborů.',
    ],
];
