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
];
