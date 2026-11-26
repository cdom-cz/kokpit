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

    // D-01 to D-03: the folder tree, its actions and the refusals of a move or a delete.
    'folders' => [
        'heading' => 'Složky',
        'tree_label' => 'Složky',
        'all' => 'Všechny články',
        'none' => 'Bez složky',
        'current_selection' => 'Složky: :name',
        'new' => 'Nová složka',
        'create_submit' => 'Vytvořit složku',
        'new_subfolder' => 'Nová podsložka',
        'rename' => 'Přejmenovat',
        'rename_heading' => 'Přejmenovat složku',
        'move' => 'Přesunout',
        'move_heading' => 'Přesunout složku',
        'delete' => 'Smazat',
        'delete_heading' => 'Smazat složku „:name“',
        'delete_description' => 'Složka je prázdná. Smazání nelze vrátit zpět.',
        'delete_submit' => 'Smazat složku',
        'name' => 'Název složky',
        'parent' => 'Nadřazená složka',
        'root' => 'Kořen (bez nadřazené složky)',
        'move_helper' => 'Strom může mít nejvýše :max úrovní. Složky, kam přesun nejde, nelze vybrat.',
        'expand' => 'Rozbalit :name',
        'collapse' => 'Sbalit :name',
        'menu' => 'Akce složky :name',
        'chip' => 'Složka: :path (včetně podsložek)',
        'chip_remove' => 'Zrušit filtr složky',
        'contents_articles' => '{1} :count článek (včetně archivovaných)|[2,4] :count články (včetně archivovaných)|[5,*] :count článků (včetně archivovaných)',
        'contents_folders' => '{1} :count podsložku|[2,4] :count podsložky|[5,*] :count podsložek',
        'contents_join' => ':articles a :folders',
        'empty_heading' => 'Zatím tu nejsou žádné složky.',
        'empty_body' => 'Složky řadí články do stromu. Vytvořte první.',
        'notifications' => [
            'created' => 'Složka byla vytvořena',
            'renamed' => 'Složka byla přejmenována',
            'moved' => 'Složka byla přesunuta',
            'deleted' => 'Složka byla smazána',
            'missing' => 'Složka už neexistuje. Strom se načetl znovu.',
        ],
        'errors' => [
            'name_required' => 'Vyplňte název složky.',
            'name_taken' => 'Složka s tímto názvem už na stejné úrovni existuje.',
            'name_too_long' => 'Název složky může mít nejvýše :max znaků.',
            'create_depth' => 'Sem už podsložku přidat nejde: strom může mít nejvýše :max úrovní.',
            'move_title' => 'Složku se nepodařilo přesunout',
            'cycle' => 'Složku nelze přesunout do sebe ani do její vlastní podsložky.',
            'depth' => 'Složku nelze přesunout sem: strom by byl hlubší než :max úrovní. Složka s podsložkami se počítá i s nimi.',
            'delete_title' => 'Složku nelze smazat.',
            'not_empty' => 'Složka není prázdná: obsahuje :contents. Přesuňte obsah jinam a zkuste to znovu.',
        ],
    ],

    // D-04, D-05: knowledge tags, kept apart from the client tags.
    'tags' => [
        'label' => 'Štítky',
        'helper' => 'Štítky znalostí jsou oddělené od štítků klientů.',
        'filter_indicator' => 'Všechny štítky: :tags',
        'errors' => [
            'too_long' => 'Štítek může mít nejvýše :max znaků.',
            'too_many' => 'Článek může mít nejvýše :max štítků.',
        ],
    ],

    // D-06, D-07: the classification of an article and the Knowledge tab of a client and a project.
    'links' => [
        'section' => 'Zařazení',
        'folder' => 'Složka',
        'folder_placeholder' => 'Bez složky',
        'client' => 'Klient',
        'client_placeholder' => 'Bez klienta',
        'project' => 'Projekt',
        'project_placeholder' => 'Bez projektu',
        'project_needs_client' => 'Nejdřív vyberte klienta.',
        'errors' => [
            'folder_missing' => 'Vybraná složka už neexistuje.',
            'client_missing' => 'Vybraný klient už neexistuje.',
            'project_mismatch' => 'Vybraný projekt nepatří k vybranému klientovi.',
        ],
        'tab' => [
            'title' => 'Znalosti',
            'add' => 'Přidat článek',
            'empty_heading' => 'Zatím tu nejsou žádné články.',
            'empty_client' => 'K tomuto klientovi zatím není připojený žádný článek.',
            'empty_project' => 'K tomuto projektu zatím není připojený žádný článek.',
        ],
    ],

    'columns' => [
        'title' => 'Název',
        'version' => 'Verze',
        'edited_at' => 'Naposledy upraveno',
        'sensitive' => 'Citlivý',
        'sensitive_tooltip' => 'Citlivý článek',
        'folder' => 'Složka',
        'tags' => 'Štítky',
        'project' => 'Projekt',
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
        'folder_heading' => 'V této složce zatím nic není.',
        'folder_body' => 'Zařaďte sem článek v jeho úpravách nebo napište nový.',
        'unfiled_heading' => 'Žádné nezařazené články.',
        'unfiled_body' => 'Články bez složky se zobrazí tady.',
        'filtered_heading' => 'Žádný článek neodpovídá filtrům.',
        'filtered_body' => 'Zrušte filtr nebo vyberte jiné štítky.',
        'reset_filters' => 'Zrušit filtry',
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
        'classification_changed' => 'Zařazení článku bylo uloženo, nová verze nevznikla.',
        'stale_redacted_title' => 'Text článku byl mezitím změněn nebo redigován',
        'stale_redacted_body' => 'Uložení se neprovedlo, aby se do článku nevrátil redigovaný text. Znovu otevřete článek a zkontrolujte změny.',
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

    // D-08, D-10: the history page (the Versions tab of the article).
    'versions' => [
        'navigation' => 'Verze',
        'title' => 'Verze článku „:title“',
        'version_label' => 'Verze :n',
        'columns' => [
            'version' => 'Verze',
            'author' => 'Autor',
            'created_at' => 'Vytvořeno',
            'markers' => 'Značky',
        ],
        'badges' => [
            'current' => 'Aktuální',
            'restored_from' => 'Obnoveno z verze :n',
            'redacted' => 'Redigováno',
        ],
        'actions' => [
            'view' => 'Zobrazit verzi',
            'compare_previous' => 'Porovnat s předchozí',
            'more' => 'Další akce',
            'back' => 'Zpět na verze',
            'view_current' => 'Zobrazit aktuální verzi',
        ],
        'meta' => 'Verze :n · :author · :date',
        'callouts' => [
            'old' => 'Toto je starší verze. Aktuální je verze :current.',
            'redacted_heading' => 'Tato verze byla redigována',
            'redacted_body' => 'Část textu, nebo celý text, byl nahrazen značkou [REDIGOVÁNO].',
            'archived' => 'Článek je archivovaný. Chcete-li verzi obnovit, nejdřív článek obnovte.',
        ],
    ],

    // D-09: the unified line diff of two versions.
    'diff' => [
        'title' => 'Rozdíl verzí',
        'from' => 'Z verze',
        'to' => 'Na verzi',
        'option' => 'Verze :n · :date',
        'summary' => 'Přidáno :added, odstraněno :removed řádků',
        'title_unchanged' => 'Název se nezměnil: :title',
        'caption' => 'Rozdíl mezi verzí :from a verzí :to',
        'column_old' => 'Řádek ve verzi :from',
        'column_new' => 'Řádek ve verzi :to',
        'column_marker' => 'Změna',
        'column_text' => 'Text',
        'added' => 'Přidáno:',
        'removed' => 'Odstraněno:',
        'collapsed' => 'Nezměněných řádků: :count',
        'truncated' => 'Rozdíl je příliš dlouhý, zobrazeno prvních :max řádků.',
        'identical' => 'Obě verze mají stejný text.',
        'same_version' => 'Vybrali jste stejnou verzi. Zvolte dvě různé verze.',
        'too_large' => 'Verze se liší příliš na to, aby šly porovnat po řádcích. Otevřete každou zvlášť.',
        'view_to' => 'Zobrazit verzi :n',
    ],

    // D-11: restoring a version as a new version.
    'restore' => [
        'action' => 'Obnovit tuto verzi',
        'heading' => 'Obnovit verzi :from?',
        'description' => 'Název a text se vrátí na stav verze :from a uloží se jako nová verze :next. Žádná verze se nesmaže.',
        'diff_caption' => 'Změny oproti aktuální verzi :current',
        'submit' => 'Obnovit jako novou verzi',
        'submit_anyway' => 'Obnovit i přesto',
        'cancel' => 'Zrušit',
        'identical' => 'Tato verze se shoduje s aktuálním textem. Nová verze nevznikne.',
        'blocking_heading' => 'Verzi nelze obnovit, obsahuje tajný údaj',
        'blocking_body' => 'Redigujte tajný údaj v této verzi a obnovte ji znovu.',
        'ambiguous_heading' => 'Obnovovaná verze může obsahovat tajný údaj',
        'ambiguous_body' => 'Zkontrolujte uvedené řádky. Pokud je to v pořádku, obnovte verzi i přesto. Článek se pak označí jako citlivý.',
        'redact_link' => 'Redigovat verzi',
        'notifications' => [
            'restored' => 'Verze :from byla obnovena jako verze :next',
            'restored_sensitive' => 'Verze :from byla obnovena jako verze :next a článek byl označen jako citlivý',
            'stale_title' => 'Článek mezitím někdo změnil',
            'stale_body' => 'Aktuální text článku se mezitím změnil. Zavřete okno, znovu otevřete obnovení a zkontrolujte změny.',
        ],
    ],

    // D-12, D-13: redacting a stored version.
    'redact' => [
        'action' => 'Redigovat verzi',
        'heading' => 'Redigovat verzi :n',
        'irreversible_heading' => 'Redigování nelze vrátit zpět',
        'irreversible_body' => 'Vybraný text se v uložené verzi nenávratně přepíše značkou [REDIGOVÁNO]. Zobrazení, rozdíl i obnovení této verze pak použijí upravený text.',
        'rotate_heading' => 'Skutečné řešení je tajný údaj změnit',
        'rotate_body' => 'Redigování odstraní jen kopii z Kokpitu. Únik vždy řešte změnou nebo zneplatněním údaje u jeho vydavatele (heslo, klíč, token).',
        'later_versions_keep' => 'Pozdější verze, které obsahují stejný text, si ho ponechají, dokud každou z nich neredigujete zvlášť.',
        'current_body' => 'Tato verze je aktuální: redigování se projeví i v aktuálním textu článku.',
        'source_label' => 'Text verze s čísly řádků',
        'source_truncated' => 'Zobrazeno prvních :max řádků. Další řádky lze vybrat podle jejich čísla.',
        'scope' => 'Rozsah',
        'scope_lines' => 'Jen vybrané řádky',
        'scope_whole' => 'Celý text verze',
        'scope_helper' => 'U soukromého klíče zvolte raději celý text verze. Název verze se nerediguje.',
        'lines' => 'Čísla řádků',
        'lines_helper' => 'Čísla oddělte čárkou, rozsah zapište pomlčkou, například 3, 7-9.',
        'reason' => 'Důvod redigování',
        'reason_helper' => 'Do protokolu změn se zapíše jen to, že důvod existuje, ne jeho text. Nepište do něj tajný údaj.',
        'submit' => 'Redigovat verzi',
        'cancel' => 'Zrušit',
        'errors' => [
            'lines_required' => 'Vyberte alespoň jeden řádek.',
            'lines_invalid' => 'Zapište čísla řádků, například 3, 7-9.',
            'line_missing' => 'Řádek :n ve verzi neexistuje.',
            'nothing_to_redact' => 'Vybrané řádky jsou prázdné nebo už redigované, není co redigovat.',
            'too_long' => 'Po nahrazení značkou by byl text verze delší než :max znaků. Zvolte celý text verze.',
            'reason_required' => 'Vyplňte důvod redigování.',
            'reason_too_long' => 'Důvod může mít nejvýše :max znaků.',
        ],
        'notifications' => [
            'redacted' => 'Verze :n byla redigována',
            'key_extended' => 'Výběr se rozšířil na celý soukromý klíč (řádky :from–:to).',
            'remaining_title' => 'Ve verzi může dál zůstat tajný údaj',
            'remaining_body' => 'Kontrola po redigování našla: :findings. Zvažte redigování celého textu verze.',
            'failed_title' => 'Verzi se nepodařilo redigovat',
            'failed_body' => 'Nic se nezměnilo. Zkuste to znovu.',
        ],
    ],

];
