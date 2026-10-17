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
        'partner_not_allowed' => 'K tomuto úkolu nelze přidat soubory.',
    ],

    // The tab on a client, project, task or transaction (plan 09-05). The Partner tabs (plan 09-08) read the same keys.
    'relation_title' => 'Soubory',

    // The Admin's central listing (plan 09-14).
    'navigation' => 'Soubory',
    'model_label' => 'soubor',
    'plural_label' => 'soubory',

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
        'upload_unattached' => 'Nahrát nepřiřazené soubory',
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

        // The PDF of an issued document: the delete confirmation is extended with an explicit tick (DO-03, D-21).
        'delete_issued_pdf' => [
            'heading' => 'Smazat PDF vystaveného dokladu?',
            'description' => 'Soubor „:name“ je PDF vystaveného dokladu :number. Smazáním ho trvale odstraníte i z úložiště; doklad samotný zůstane beze změny a PDF lze vytvořit znovu ze zmrazených údajů. Tento krok nelze vrátit zpět.',
            'confirm' => 'Rozumím, chci smazat PDF vystaveného dokladu',
            'submit' => 'Smazat PDF',
            'cancel' => 'Ponechat PDF',
            'deleted' => 'PDF bylo smazáno',
            'unconfirmed' => 'Potvrďte, že chcete smazat PDF vystaveného dokladu.',
            'bulk_skipped' => 'Přeskočeno PDF vystavených dokladů: :skipped. Smažte je jednotlivě s výslovným potvrzením.',
            'regenerate_failed' => 'PDF se nepodařilo vytvořit',
            'regenerate_failed_hint' => 'Zkuste to znovu. Pokud chyba přetrvá, zkontrolujte úložiště souborů.',
        ],
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
        'unattached' => 'Soubory bez vazby na klienta, projekt nebo úkol vidíte jen vy.',
        'partner_files' => 'Soubory uvidíte vy i správce. Najednou nejvýše :count souborů, každý do :max MB.',
    ],

    // The files of a task comment, in the comment form and under each comment (plan 09-12).
    'comments' => [
        'files_label' => 'Přílohy',
        'files_hint' => 'Najednou nejvýše :count souborů, každý do :max MB. Klient uvidí soubory u komentáře, který není interní.',
        'internal_files_hint' => 'Soubory interního komentáře klient nikdy neuvidí.',
        'download' => 'Stáhnout',
        'preview' => 'Zobrazit',
        'delete' => 'Smazat',
        'delete_heading' => 'Smazat soubor?',
        'delete_description' => 'Soubor „:name“ bude trvale smazán, včetně uloženého souboru. Komentář zůstane. Tento krok nelze vrátit zpět.',
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

    // The empty central listing (plan 09-14).
    'listing_empty' => [
        'heading' => 'Zatím žádné soubory',
        'description' => 'Soubory se objeví, jakmile je nahrajete ke klientovi, projektu, úkolu nebo transakci, nebo tlačítkem nahoře.',
    ],

    // The filters of the central listing (plan 09-14, D-07, D-21).
    'filters' => [
        'owner_type' => 'Patří k',
        'unattached' => 'Nepřiřazeno',
        'client' => 'Klient',
        'project' => 'Projekt',
        'created' => 'Nahráno',
        'created_from' => 'Od',
        'created_until' => 'Do',
        'type_group' => 'Typ souboru',
        'visibility' => 'Vidí klient',
        'visibility_visible' => 'Ano, klient soubor vidí',
        'visibility_hidden' => 'Ne, soubor je skrytý',
        'visibility_all' => 'Všechny soubory',
        'archived_owners' => 'Archivovaní vlastníci',
        'archived_owners_only' => 'Jen soubory archivovaných vlastníků',
        'archived_owners_without' => 'Bez archivovaných vlastníků',
        'archived_owners_all' => 'Všechny soubory',
    ],

    // The totals above the central listing (plan 09-16 reads these keys; D-11, D-21).
    'totals' => [
        'files' => 'Souborů',
        'size' => 'Celková velikost',
        'by_client_heading' => 'Podle klientů',
        'columns' => [
            'client' => 'Klient',
            'count' => 'Souborů',
            'size' => 'Velikost',
        ],
        'no_client' => 'Nepřiřazené ke klientovi',
        'includes_archived' => 'Součty zahrnují i soubory archivovaných vlastníků.',
        'archived_client' => 'archivovaný',
    ],

    // The Admin's bell when a Partner hands over files (plan 09-10, D-05).
    'bell' => [
        'partner_uploaded' => [
            'title' => 'Klient nahrál soubory',
            'body' => '{1} :partner přidal(a) k úkolu :reference :count soubor.|[2,4] :partner přidal(a) k úkolu :reference :count soubory.|[5,*] :partner přidal(a) k úkolu :reference :count souborů.',
            'action' => 'Otevřít úkol',
        ],
    ],

    // The ZIP of selected files, built in the background (plan 09-17, D-08). The list and the download come with
    // plan 09-18, the bells with plan 09-19.
    'archives' => [
        'navigation' => 'ZIP archivy',
        'model_label' => 'Archiv',
        'plural_label' => 'ZIP archivy',
        'subheading' => 'Hotové archivy se uchovávají :days dní, potom se smažou.',
        'columns' => [
            'created_at' => 'Vytvořeno',
            'summary' => 'Obsah',
            'status' => 'Stav',
            'expires_at' => 'Platí do',
        ],
        'actions' => [
            'download' => 'Stáhnout archiv',
        ],
        'gone' => 'Archiv už není k dispozici. Vyberte soubory a požádejte o něj znovu.',
        'empty' => [
            'heading' => 'Zatím tu nejsou žádné archivy',
            'description' => 'Archiv vznikne z vybraných souborů akcí Stáhnout jako ZIP v Souborech.',
        ],
        'bulk_action' => 'Stáhnout jako ZIP',
        'queued' => [
            'title' => 'Archiv se připravuje',
            'body' => 'Z vybraných souborů se sestavuje jeden ZIP. Dáme vám vědět, až bude hotový.',
        ],
        'summary' => '{1} :count soubor, :size|[2,4] :count soubory, :size|[5,*] :count souborů, :size',
        'errors' => [
            'empty' => 'Nevybrali jste žádný soubor.',
            'too_many' => 'Do jednoho archivu lze zabalit nejvýše :max souborů.',
            'too_large' => 'Vybrané soubory jsou dohromady větší než povolených :max.',
            'unknown' => 'Výběr obsahuje soubor, který neexistuje. Obnovte stránku a zkuste to znovu.',
        ],
        // The Admin's bell when the build ends (plan 09-19, D-08).
        'bell' => [
            'ready' => [
                'title' => 'Archiv je připraven',
                'body' => '{1} ZIP obsahuje :count soubor. Je ke stažení do :date.|[2,4] ZIP obsahuje :count soubory. Je ke stažení do :date.|[5,*] ZIP obsahuje :count souborů. Je ke stažení do :date.',
            ],
            'failed' => [
                'title' => 'Archiv se nepodařilo vytvořit',
                'body' => 'ZIP z vybraných souborů se nepodařilo sestavit. Vyberte soubory a požádejte o archiv znovu.',
            ],
            'action' => 'Otevřít archivy',
        ],
    ],

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
