<?php

declare(strict_types=1);

return [

    'navigation_group' => 'Znalosti',

    'articles' => [
        'navigation_label' => 'Obecné znalosti',
        'model_label' => 'článek',
        'plural_model_label' => 'články',
    ],

    'fields' => [
        'title' => 'Název',
        'body' => 'Text článku',
        'sensitive' => 'Citlivý článek',
        'sensitive_helper' => 'Citlivý článek nepůjde sdílet s klientem ani exportovat. Označte ho, pokud text obsahuje přístupové údaje.',
    ],

    'tabs' => [
        'write' => 'Psaní',
        'preview' => 'Náhled',
    ],

    'editor' => [
        'task_list' => 'Seznam úkolů',
    ],

    'columns' => [
        'title' => 'Název',
        'version' => 'Verze',
        'edited_at' => 'Naposledy upraveno',
        'sensitive' => 'Citlivý',
        'sensitive_tooltip' => 'Citlivý článek',
    ],

    'filters' => [
        'show_archived' => 'Zobrazit archivované',
    ],

    'badges' => [
        'sensitive' => 'Citlivý',
        'archived' => 'Archivováno',
    ],

    'meta' => [
        'version' => 'Verze :version',
        'edited_at' => 'Naposledy upraveno :date',
    ],

    'actions' => [
        'create' => 'Nový článek',
        'create_submit' => 'Vytvořit článek',
        'save_submit' => 'Uložit změny',
        'cancel_create' => 'Zrušit vytvoření',
        'cancel_edit' => 'Zrušit úpravy',
        'edit' => 'Upravit článek',
        'archive' => 'Archivovat článek',
        'archive_heading' => 'Archivovat článek?',
        'archive_description' => 'Archivovaný článek zmizí ze seznamu, ale zůstane uložený i s historií. Půjde ho obnovit.',
        'restore' => 'Obnovit článek',
        'save_anyway' => 'Uložit článek i přesto',
        'load_current' => 'Načíst aktuální verzi',
    ],

    'empty' => [
        'list_heading' => 'Zatím tu nejsou žádné články',
        'list_description' => 'Napište první článek, třeba postup nebo poznámky k nastavení.',
        'archived_heading' => 'Žádné archivované články',
        'body' => 'Článek zatím nemá žádný text.',
        'preview' => 'Není co zobrazit. Napište text na kartě Psaní.',
    ],

    'notifications' => [
        'saved' => 'Článek byl uložen jako verze :version',
        'saved_sensitive' => 'Článek byl uložen jako verze :version a označen jako citlivý',
        'unchanged' => 'Nic se nezměnilo, nová verze nevznikla.',
        'sensitivity_changed' => 'Označení citlivosti bylo uloženo, nová verze nevznikla.',
        'archived' => 'Článek byl archivován',
        'restored' => 'Článek byl obnoven',
        'stale_title' => 'Článek mezitím někdo změnil',
        'stale_body' => 'Váš text zůstal v editoru, aby ho šlo zkopírovat. Načtěte aktuální verzi a změny sloučte ručně.',
        'write_failed_title' => 'Článek se nepodařilo uložit',
        'write_failed_body' => 'Váš text zůstal v editoru, aby ho šlo zkopírovat. Zkuste článek uložit znovu.',
        'archived_meanwhile_title' => 'Článek byl mezitím archivován',
        'archived_meanwhile_body' => 'Váš text zůstal v editoru, aby ho šlo zkopírovat. Článek nejprve obnovte a změny pak uložte znovu.',
    ],

    'guard' => [
        'blocking_heading' => 'Text obsahuje tajný údaj a nelze ho uložit',
        'blocking_body' => 'Odstraňte nebo nahraďte údaj na uvedených řádcích a uložte znovu.',
        'ambiguous_heading' => 'Text může obsahovat tajný údaj',
        'ambiguous_body' => 'Zkontrolujte uvedené řádky. Pokud je to v pořádku, uložte i přesto. Článek se pak označí jako citlivý.',
        'finding' => ':rule — řádek :line',
        'finding_without_line' => ':rule',

        // One label per case of App\Domain\Knowledge\Guard\SecretRule, keyed by its value.
        'rules' => [
            'pem_private_key' => 'Soukromý klíč (PEM)',
            'aws_access_key' => 'Přístupový klíč AWS',
            'stripe_secret' => 'Token Stripe',
            'stripe_test_key' => 'Testovací klíč Stripe',
            'github_token' => 'Token GitHub',
            'jwt' => 'Token JWT',
            'url_credentials' => 'Adresa s přihlašovacími údaji',
            'password_assignment' => 'Možné heslo (password=)',
            'high_entropy_string' => 'Dlouhý náhodný řetězec',
            'scan_failed' => 'Kontrolu tajných údajů nešlo dokončit',
        ],
    ],

    'errors' => [
        'title_required' => 'Vyplňte název článku.',
        'title_too_long' => 'Název může mít nejvýše :max znaků.',
        'body_too_long' => 'Text článku může mít nejvýše :max znaků.',
        'nul_character' => 'Text obsahuje neplatný znak (NUL). Odstraňte ho a uložte znovu.',
    ],

    'render' => [
        'callouts' => [
            'note' => 'Poznámka',
            'tip' => 'Tip',
            'important' => 'Důležité',
            'warning' => 'Varování',
            'caution' => 'Pozor',
        ],

        'code' => [
            'copy' => 'Kopírovat kód',
            'copied' => 'Zkopírováno',
            'copy_failed' => 'Kopírování se nezdařilo',
            'label' => 'Kód: :language',
            'label_plain' => 'Kód',
        ],

        'task' => [
            'done' => 'Hotovo:',
            'open' => 'Nehotovo:',
        ],
    ],

];
