<?php

declare(strict_types=1);

/*
 * The Czech words of the finance module: the Admin's category screen and the error contract of the
 * transaction Actions (read by the transaction input and CZK rules of the later plans). Names in examples are
 * invented. Strings are Unicode NFC.
 */
return [
    'categories' => [
        'navigation' => 'Kategorie',
        'model_label' => 'kategorie',
        'plural_label' => 'Kategorie',
        'columns' => [
            'name' => 'Název',
            'type' => 'Typ',
            'parent' => 'Nadřazená kategorie',
            'state' => 'Stav',
        ],
        'fields' => [
            'name' => 'Název',
            'type' => 'Typ',
            'parent' => 'Nadřazená kategorie',
        ],
        'hints' => [
            'parent' => 'Nechte prázdné pro kategorii nejvyšší úrovně. Podkategorie převezme typ nadřazené kategorie.',
        ],
        'placeholders' => [
            'parent' => 'Žádná, kategorie nejvyšší úrovně',
            'empty_value' => '—',
        ],
        'badges' => [
            'system' => 'Systémová',
            'archived' => 'Archivovaná',
        ],
        'filters' => [
            'type' => 'Typ',
            'archived' => 'Stav',
            'archived_all' => 'Všechny',
            'archived_only' => 'Archivované',
            'archived_active' => 'Aktivní',
        ],
        'actions' => [
            'create' => 'Nová kategorie',
            'create_heading' => 'Nová kategorie',
            'edit' => 'Upravit',
            'edit_heading' => 'Upravit kategorii',
            'move_up' => 'Posunout nahoru',
            'move_down' => 'Posunout dolů',
            'archive' => 'Archivovat',
            'archive_heading' => 'Archivovat kategorii?',
            'archive_description' => 'Archivovaná kategorie zmizí z nabídky pro nové transakce, stávající zůstanou beze změny. Můžete ji kdykoli obnovit.',
            'restore' => 'Obnovit',
            'delete' => 'Smazat',
            'delete_heading' => 'Smazat kategorii?',
            'delete_description' => 'Smazat lze jen kategorii, která nemá podkategorie a nebyla použita. Jinak ji archivujte.',
        ],
        'notifications' => [
            'created' => 'Kategorie byla vytvořena.',
            'updated' => 'Kategorie byla uložena.',
            'archived' => 'Kategorie byla archivována.',
            'restored' => 'Kategorie byla obnovena.',
            'deleted' => 'Kategorie byla smazána.',
        ],
        'errors' => [
            'in_use' => 'Kategorii nelze smazat, protože má podkategorie nebo už byla použita. Archivujte ji, zmizí z nabídky a historie zůstane.',
            'system_locked' => 'Systémovou kategorii lze jen přejmenovat nebo posunout v pořadí. Nelze ji archivovat, smazat ani změnit její typ.',
            'duplicate_name' => 'Kategorie s tímto názvem už na stejné úrovni existuje.',
            'parent_invalid' => 'Nadřazená kategorie musí být aktivní kategorie nejvyšší úrovně stejného typu.',
            'has_children' => 'Kategorie s podkategoriemi se nemůže stát podkategorií.',
            'type_locked' => 'Typ kategorie nelze změnit, protože má podkategorie nebo už byla použita.',
        ],
        'empty' => [
            'heading' => 'Zatím žádné kategorie',
            'description' => 'Vytvořte první kategorii, abyste mohli třídit příjmy a výdaje.',
        ],
    ],

    'transactions' => [
        'errors' => [
            'invalid_type' => 'Typ transakce musí být příjem nebo výdaj.',
            'invalid_date' => 'Zadejte platné datum.',
            'category_unavailable' => 'Tuto kategorii nelze použít, je archivovaná nebo neexistuje.',
            'category_type_mismatch' => 'Kategorie neodpovídá typu transakce.',
            'amount_invalid' => 'Zadejte platnou částku.',
            'amount_not_positive' => 'Částka musí být větší než nula.',
            'currency_unknown' => 'Tuto měnu nelze použít.',
            'czk_invalid' => 'Zadejte platnou částku v korunách.',
            'czk_required_missing_rate' => 'Pro měnu :currency a den :date není uložen kurz ČNB, zadejte částku v korunách ručně.',
            'client_unavailable' => 'Tohoto klienta nelze použít, je archivovaný nebo neexistuje.',
            'project_unavailable' => 'Tento projekt nelze použít, je archivovaný nebo neexistuje.',
            'project_not_of_client' => 'Projekt nepatří vybranému klientovi.',
            'text_too_long' => 'Text je příliš dlouhý.',
        ],
    ],
];
