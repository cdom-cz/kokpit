<?php

declare(strict_types=1);

/*
 * The Czech words of the invoicing domain: the error contract of the whole phase (every domain error message the
 * Actions of the billing documents raise) and, from plan 10-21, the copy of every Phase 10 screen, so later UI plans
 * only read keys. Strings are Unicode NFC.
 */
return [
    'errors' => [
        // The draft and its header.
        'not_draft' => 'Doklad už je vystavený a nelze ho upravit.',
        'client_archived' => 'Archivovanému klientovi nelze vytvořit doklad.',
        'client_unknown' => 'Vyberte klienta.',
        'kind' => 'Zvolte fakturu nebo zálohovou fakturu.',
        'currency' => 'Zvolte platnou měnu.',
        'language' => 'Zvolte jazyk dokladu.',
        'date_invalid' => 'Zadejte platné datum.',
        'due_before_issue' => 'Datum splatnosti nesmí být dříve než datum vystavení.',
        'note_length' => 'Poznámka na dokladu může mít nejvýše 500 znaků.',
        'internal_note_length' => 'Interní poznámka může mít nejvýše 2 000 znaků.',
        'card_payment_invalid' => 'Platbu kartou lze zapnout nebo vypnout jen u faktury a zálohové faktury.',

        // The items of a draft.
        'item_unknown' => 'Položka nepatří k tomuto dokladu.',
        'item_description' => 'Zadejte popis položky (nejvýše 500 znaků).',
        'item_quantity' => 'Zadejte množství větší než nula s nejvýše čtyřmi desetinnými místy.',
        'item_unit' => 'Zadejte jednotku (nejvýše 16 znaků).',
        'item_price' => 'Zadejte jednotkovou cenu v měně dokladu.',
        'item_zero_price' => 'Jednotkovou cenu nula potvrďte u položky.',
        'items_cap' => 'Doklad může mít nejvýše :max položek.',

        // The blockers of issuing.
        'no_items' => 'Doklad nemá žádnou položku.',
        'total_not_positive' => 'Celková částka musí být větší než nula.',
        'supplier_missing' => 'Doplňte údaje dodavatele v Nastavení.',
        'bank_missing' => 'Pro měnu :currency není nastaven bankovní účet. Přidejte ho v Nastavení.',
        'cnb_missing' => 'Pro měnu :currency není ke dni :date k dispozici kurz ČNB. Stáhněte kurzy v přehledu Kurzy ČNB.',
        'vat_payer_unsupported' => 'Režim plátce DPH zatím není podporován. Přepněte režim DPH v Nastavení.',
        'client_address_missing' => 'Klient nemá vyplněnou fakturační adresu.',
        'issue_date_future' => 'Datum vystavení nesmí být později než dnes.',
        'issue_date_before_latest' => 'Datum vystavení nesmí být dříve než :date, kdy byl vystaven poslední doklad této řady.',
        'pattern_invalid' => 'Číselná řada pro :kind není platná. Opravte ji v Nastavení.',
        'entries_changed' => 'Některé připojené záznamy času se mezitím změnily. Zkontrolujte koncept a vystavte ho znovu.',

        // Time added to a draft.
        'period_order' => 'Datum Od nesmí být později než Do.',
        'projects_unknown' => 'Vyberte projekty tohoto klienta.',
        'grouping' => 'Zvolte seskupení podle projektu nebo podle úkolu.',
        'fixed_project' => 'Zvolený projekt nemá pevnou cenu.',
        'fixed_amount' => 'Zadejte částku větší než nula v měně dokladu.',
        'entries_foreign' => 'Vybrané záznamy už nejsou k dispozici.',

        // Payments.
        'payment_amount' => 'Zadejte částku větší než nula.',
        'payment_date_future' => 'Datum platby nesmí být v budoucnosti.',
        'payment_not_allowed' => 'Platbu nelze zaznamenat u dobropisu ani u stornovaného dokladu.',
        'payment_category' => 'Zvolte kategorii příjmů.',
        'payment_method' => 'Zvolte platbu převodem nebo hotově.',
        'payment_note' => 'Poznámka k platbě může mít nejvýše 500 znaků.',
        'payment_transaction_locked' => 'Transakce vznikla z platby faktury :number. Změnit lze jen datum a kategorii; chybnou platbu smažte v detailu faktury.',
        'payment_transaction_delete' => 'Transakce vznikla z platby faktury :number. Smazat ji lze jen smazáním platby v detailu faktury.',
        'payment_delete_carried' => 'Platba je přenesená na fakturu :number. Nejdřív smažte platbu na faktuře.',
        'payment_missing' => 'Platba už neexistuje. Obnovte stránku.',

        // Conversion of a proforma.
        'proforma_not_issued' => 'Fakturu lze vystavit jen ze zálohy, která je vystavená a není stornovaná.',
        'proforma_not_paid' => 'Zálohu lze převést na fakturu až po úplném zaplacení.',
        'proforma_converted' => 'K záloze už byla faktura vystavena.',

        // Credit notes.
        'credit_not_allowed' => 'Dobropis lze vystavit jen k vystavené, nestornované faktuře.',
        'credit_nothing' => 'Vyberte alespoň jednu položku.',
        'credit_quantity' => 'Množství k dobropisu nesmí být vyšší než původní množství.',
        'credit_total' => 'Součet dobropisů nesmí přesáhnout hodnotu faktury.',

        // E-mail.
        'email_recipient' => 'Zadejte platnou e-mailovou adresu příjemce.',
        'email_subject' => 'Zadejte předmět (nejvýše 200 znaků).',
        'email_body' => 'Zadejte text e-mailu (nejvýše 5 000 znaků).',
        'email_not_issued' => 'E-mail lze odeslat jen u vystaveného dokladu.',

        // The stored PDF.
        'pdf_unconfirmed' => 'PDF vystaveného dokladu lze smazat jen s výslovným potvrzením.',
        'pdf_missing' => 'PDF dokladu chybí.',
    ],

    // The fixed text of the income transaction a payment books (R-16-2).
    'payment_income' => [
        'description' => 'Platba faktury :number',
        'card_description' => 'Platba kartou faktury :number',
    ],

    // Navigation and titles (UI-SPEC Copywriting Contract, "Navigation, titles, enum labels").
    'navigation' => [
        'label' => 'Faktury',
        'model' => 'doklad',
        'plural' => 'Doklady',
        'heading' => 'Faktury',
        'draft_invoice' => 'Koncept faktury',
        'draft_proforma' => 'Koncept zálohové faktury',
        'draft_credit_note' => 'Koncept dobropisu',
        'empty_value' => '—',
    ],

    // Surface A: the document list.
    'list' => [
        'columns' => [
            'number' => 'Číslo',
            'kind' => 'Druh',
            'client' => 'Klient',
            'issued' => 'Vystaveno',
            'due' => 'Splatnost',
            'total' => 'Celkem',
            'remaining' => 'K úhradě',
            'status' => 'Stav',
            'email' => 'E-mail',
            'currency' => 'Měna',
            'created' => 'Vytvořeno',
            'variable_symbol' => 'Variabilní symbol',
        ],
        // Czech plural of "den": 1, 2 to 4, 0 and 5 or more.
        'overdue_days' => 'Po splatnosti o :count den|Po splatnosti o :count dny|Po splatnosti o :count dní',
        'partially_paid' => 'Částečně zaplaceno',
        'czk' => 'v Kč: :czk',
        'czk_total' => 'Celkem v Kč',
        'email' => [
            'sent' => 'Odesláno :date',
            'queued' => 'Ve frontě',
            'failed' => 'Odeslání selhalo',
            'none' => 'Neodesláno',
        ],
        'filters' => [
            'kind' => 'Druh',
            'client' => 'Klient',
            'archived' => ':name (archivovaný)',
            'issued' => 'Vystaveno',
            'from' => 'Od',
            'until' => 'Do',
            'currency' => 'Měna',
            'email' => 'E-mail',
            'email_sent' => 'Odesláno',
            'email_queued' => 'Ve frontě',
            'email_failed' => 'Selhalo',
            'email_none' => 'Neodesláno',
        ],
        'actions' => [
            'create' => 'Nová faktura',
            'download' => 'Stáhnout PDF',
            'delete_draft' => 'Smazat koncept',
            'edit' => 'Upravit',
        ],
        'delete' => [
            'heading' => 'Smazat koncept?',
            'body' => 'Koncept se trvale smaže a všechny připojené záznamy času se vrátí mezi nevyfakturované. Koncept nemá přidělené číslo.',
            'submit' => 'Smazat koncept',
            'cancel' => 'Ponechat koncept',
            'toast' => 'Koncept byl smazán, záznamy času jsou znovu nevyfakturované',
        ],
        'empty' => [
            'all_heading' => 'Zatím tu nejsou žádné doklady',
            'all_body' => 'Fakturu vytvoříte tlačítkem Nová faktura. Můžete ji sestavit z nevyfakturovaného času.',
            'drafts_heading' => 'Žádné koncepty',
            'unpaid_heading' => 'Nic k úhradě',
            'overdue_heading' => 'Nic po splatnosti',
            'overdue_body' => 'Všechny faktury jsou v termínu.',
            'paid_heading' => 'Zatím žádné zaplacené doklady',
            'cancelled_heading' => 'Žádné stornované doklady',
            'awaiting_heading' => 'Žádná záloha nečeká na fakturu',
            'filtered_heading' => 'Filtrům neodpovídá žádný doklad',
        ],
    ],

    // Surface B: the "Nová faktura" modal.
    'create' => [
        'heading' => 'Nová faktura',
        'submit' => 'Vytvořit koncept',
        'cancel' => 'Nevytvářet',
        'toast' => 'Koncept byl vytvořen',
        'fields' => [
            'kind' => 'Druh dokladu',
            'client' => 'Klient',
            'source' => 'Zdroj položek',
            'source_time' => 'Nevyfakturovaný čas',
            'source_empty' => 'Prázdný koncept',
            'period' => 'Období',
            'period_this_month' => 'Tento měsíc',
            'period_last_month' => 'Minulý měsíc',
            'period_custom' => 'Vlastní',
            'period_from' => 'Od',
            'period_to' => 'Do',
            'projects' => 'Projekty',
            'without_project' => 'Záznamy bez projektu',
            'grouping' => 'Seskupení položek',
            'currency' => 'Měna',
        ],
        'client_helper' => 'Měna :currency, splatnost :days dní, jazyk dokladu :language.',
        'currency_differs' => 'Měna se liší od měny klienta (:client). Sazby se přepočtou kurzem ČNB ke dni vystavení.',
        'archived_project' => ':name (archivovaný)',
        // Czech plural of "záznam": 1, 2 to 4, 0 and 5 or more.
        'live' => 'Nalezeno :count záznam, :duration, odhad :amount|Nalezeno :count záznamy, :duration, odhad :amount|Nalezeno :count záznamů, :duration, odhad :amount',
        'live_none' => 'Pro zvolené období není žádný nevyfakturovaný čas.',
        // Ruling R-13-1: the hours of a fixed-price project are never added (D-07 wins over UI-SPEC Surface B).
        'fixed_price' => 'Projekt :name má pevnou cenu. Jeho hodiny se do položek nepřidají; pevnou částku přidáte v konceptu.',
        'reserved_elsewhere' => 'Další :count záznam je rezervován v jiném konceptu.|Další :count záznamy jsou rezervovány v jiných konceptech.|Dalších :count záznamů je rezervováno v jiných konceptech.',
    ],

    // Surface C: the draft editor, its items and its linked time. The kind labels and the grouping come from enums.php.
    'draft' => [
        'badge' => 'Koncept',
        'settings_link' => 'Nastavení',
        'sections' => [
            'document' => 'Doklad',
            'items' => 'Položky',
            'entries' => 'Záznamy času',
            'summary' => 'Souhrn',
        ],
        'fields' => [
            'client' => 'Klient',
            'kind' => 'Druh',
            'currency' => 'Měna',
            'bank_account' => 'Bankovní účet',
            'language' => 'Jazyk dokladu',
            'issue_date' => 'Datum vystavení',
            'taxable_supply_date' => 'Datum zdanitelného plnění',
            'due_date' => 'Datum splatnosti',
            'payment_method' => 'Způsob úhrady',
            'card_payment' => 'Platba kartou',
            'variable_symbol' => 'Variabilní symbol',
            'note' => 'Poznámka na dokladu',
            'internal_note' => 'Interní poznámka',
            'confirm_zero_price' => 'Potvrzuji nulovou cenu',
        ],
        'payment_method_value' => 'Převodem',
        'helpers' => [
            'card_payment' => 'Na dokladu a v e-mailu se zobrazí tlačítko Zaplatit kartou. Po vystavení už nejde změnit.',
            'card_payment_settings_off' => 'Online platby jsou vypnuté v Nastavení.',
            'card_payment_stripe_missing' => 'Stripe není nastavený. Doplňte klíče v prostředí aplikace.',
            'variable_symbol' => 'Přidělí se při vystavení.',
            'internal_note' => 'Vidíte jen vy. Na dokladu se nezobrazí.',
            'items' => 'Úpravy položek nemění, které záznamy času jsou k faktuře připojeny.',
        ],
        'columns' => [
            'index' => '#',
            'description' => 'Popis',
            'quantity' => 'Množství',
            'unit' => 'Jednotka',
            'unit_price' => 'Jednotková cena',
            'total' => 'Celkem',
        ],
        'badges' => [
            'from_time' => 'Z času',
            'from_time_tooltip' => 'Vygenerováno ze záznamů času',
            'convert_at_issue' => 'Přepočet při vystavení',
            'preview_rate' => 'Přepočet kurzem ČNB (náhled): :rate',
        ],
        'item_actions' => [
            'add' => 'Přidat položku',
            'add_fixed' => 'Přidat pevnou částku',
            'add_time' => 'Přidat nový čas',
            'delete' => 'Smazat položku :n',
            'move_up' => 'Posunout nahoru',
            'move_down' => 'Posunout dolů',
        ],
        'empty_items' => 'Zatím žádné položky. Přidejte položku nebo připojte čas.',
        'actions' => [
            'save' => 'Uložit koncept',
            'issue' => 'Vystavit',
            'open' => 'Otevřít koncept',
        ],
        'currency_change' => [
            'heading' => 'Změnit měnu konceptu?',
            'body' => 'Ručně zadané jednotkové ceny se nepřepočítají. Zkontrolujte je po změně.',
            'submit' => 'Změnit měnu',
            'cancel' => 'Ponechat měnu',
        ],
        // Ruling R-14-3: a fixed amount is one line of quantity 1 and the unit "paušál".
        'fixed_amount' => [
            'heading' => 'Přidat pevnou částku',
            'fields' => [
                'project' => 'Projekt',
                'description' => 'Popis',
                'amount' => 'Částka',
                'mark_time' => 'Označit čas projektu jako vyfakturovaný',
            ],
            'helper' => 'Pevná cena projektu: :price. Dosud vyfakturováno: :billed.',
            // Czech plural of "záznam": 1, 2 to 4, 0 and 5 or more.
            'mark_time_helper' => ':count záznam, :duration.|:count záznamy, :duration.|:count záznamů, :duration.',
            'toast' => 'Pevná částka byla přidána',
            'submit' => 'Přidat položku',
            'cancel' => 'Nepřidávat',
            'unit' => 'paušál',
        ],
        'summary' => [
            'subtotal' => 'Mezisoučet',
            'vat' => 'DPH',
            'total' => 'Celkem k úhradě',
            'czk_preview' => 'Náhled v Kč: :czk, kurz ČNB :rate ke dni :date',
            'czk_frozen' => 'Konečný kurz a částka v Kč se zmrazí při vystavení.',
        ],
        'entries' => [
            'title' => 'Záznamy času',
            'columns' => [
                'date' => 'Datum',
                'project' => 'Projekt',
                'task' => 'Úkol',
                'description' => 'Popis',
                'duration' => 'Trvání',
                'rate' => 'Sazba',
                'amount' => 'Částka',
            ],
            'footer' => 'Celkem',
            'empty_heading' => 'K faktuře není připojen žádný čas',
            'empty_body' => 'Čas připojíte tlačítkem Přidat nový čas. Faktura může obsahovat i jen ruční položky.',
            'add' => [
                'heading' => 'Přidat nový čas',
                'submit' => 'Připojit záznamy',
                'cancel' => 'Nepřipojovat',
                'toast' => 'Připojeno :count záznamů, přidáno :items položek',
            ],
            'unlink' => [
                'trigger' => 'Odpojit od faktury',
                'heading' => 'Odpojit záznamy od faktury?',
                'body' => ':count záznamů se vrátí mezi nevyfakturované. Položky faktury se nezmění, upravte nebo smažte je ručně.',
                'submit' => 'Odpojit od faktury',
                'toast' => 'Záznamy byly odpojeny',
            ],
        ],
    ],

    // Surface D: the issue flow.
    'issue' => [
        'kind_accusative' => [
            'invoice' => 'fakturu',
            'proforma' => 'zálohovou fakturu',
            'credit_note' => 'dobropis',
        ],
        'heading' => 'Vystavit :kind?',
        'summary' => [
            'client' => 'Klient',
            'total' => 'Celkem k úhradě',
            'issue_date' => 'Datum vystavení',
            'due_date' => 'Splatnost',
            'currency' => 'Měna',
            'card_payment' => 'Platba kartou',
            'card_payment_yes' => 'Ano, odkaz se vytvoří po vystavení',
            'card_payment_no' => 'Ne',
            'rate' => 'Kurz ČNB ke dni vystavení :rate, částka v Kč :czk',
        ],
        'callout' => 'Číslo se přidělí při vystavení (očekávané číslo :number, může se změnit, pokud mezitím vznikne jiný doklad). Po vystavení doklad nelze upravit a nelze ho smazat; změnit půjde jen stav platby a interní poznámka.',
        'submit' => [
            'invoice' => 'Vystavit fakturu',
            'proforma' => 'Vystavit zálohovou fakturu',
        ],
        'cancel' => 'Ještě upravit',
        'toast' => [
            'invoice' => 'Faktura byla vystavena',
            'proforma' => 'Zálohová faktura byla vystavena',
            'credit_note' => 'Dobropis :number byl vystaven',
        ],
        'failed_title' => 'Doklad se nepodařilo vystavit',
        'failed_body' => 'Nebylo přiděleno žádné číslo a nic se nezměnilo. Zkuste to znovu. Pokud chyba přetrvá, zkontrolujte, že je dostupné úložiště souborů.',
        'blockers_title' => 'Doklad nelze vystavit',
        'links' => [
            'settings' => 'Otevřít Nastavení',
            'rates' => 'Otevřít Kurzy ČNB',
        ],
    ],

    // Surface E: the issued document page.
    'detail' => [
        'sections' => [
            'document' => 'Doklad',
            'parties' => 'Strany',
            'items' => 'Položky',
            'summary' => 'Souhrn',
            'related' => 'Související doklady',
            'notes' => 'Poznámky',
        ],
        'parties' => [
            'supplier' => 'Dodavatel',
            'customer' => 'Odběratel',
            'frozen' => 'Údaje jsou zmrazené k datu vystavení.',
        ],
        'fields' => [
            'number' => 'Číslo',
            'kind' => 'Druh',
            'issue_date' => 'Datum vystavení',
            'taxable_supply_date' => 'Datum zdanitelného plnění',
            'due_date' => 'Datum splatnosti',
            'variable_symbol' => 'Variabilní symbol',
            'language' => 'Jazyk dokladu',
            'currency' => 'Měna',
            'bank_account' => 'Bankovní účet',
            'source_proforma' => 'Zdrojová záloha',
            'payment_method' => 'Způsob úhrady',
        ],
        'columns' => [
            'description' => 'Popis',
            'quantity' => 'Množství',
            'unit_price' => 'Jednotková cena',
            'total' => 'Celkem',
        ],
        'no_items' => 'Zatím žádné položky.',
        'summary' => [
            'total' => 'Celkem k úhradě',
            'paid' => 'Uhrazeno',
            'remaining' => 'Zbývá uhradit',
            'rate' => 'Kurz ČNB ke dni vystavení :date: :rate',
            'czk_total' => 'Celkem v Kč: :czk',
        ],
        'tabs' => [
            'payments' => 'Platby',
            'time' => 'Navázaný čas',
            'files' => 'Soubory',
            'emails' => 'Odeslání',
            'history' => 'Historie změn',
        ],
        'banners' => [
            'overdue' => 'Faktura je po splatnosti o :count den. Zbývá uhradit :amount.|Faktura je po splatnosti o :count dny. Zbývá uhradit :amount.|Faktura je po splatnosti o :count dní. Zbývá uhradit :amount.',
            'awaiting_invoice' => 'Záloha je zaplacená, ale faktura k ní zatím nebyla vystavena. Příjem se zaúčtuje až při vystavení faktury.',
            'cancelled_paid' => 'Faktura byla stornována, ale platba zůstává zaevidovaná. Vrácení peněz a výdaj zaznamenejte ručně.',
            'credit_exists' => 'K dokladu existuje dobropis :number.',
        ],
        'actions' => [
            'download' => 'Stáhnout PDF',
            'send' => 'Odeslat e-mailem',
            'record_payment' => 'Zaznamenat platbu',
            'issue_invoice' => 'Vystavit fakturu',
            'more' => 'Další akce',
            'credit_items' => 'Dobropis na položky',
            'storno' => 'Stornovat',
            'edit_note' => 'Upravit poznámku',
            'open' => 'Otevřít doklad',
        ],
        'note' => [
            'heading' => 'Interní poznámka',
            'submit' => 'Uložit poznámku',
            'cancel' => 'Neukládat poznámku',
            'toast' => 'Poznámka byla uložena',
            'printed' => 'Poznámka na dokladu',
        ],
        'pdf_missing' => 'PDF dokladu chybí.',
        'pdf_regenerate' => 'Vytvořit PDF znovu',
        'pdf_failed_title' => 'PDF se nepodařilo vytvořit',
        'pdf_failed_body' => 'Zkuste to znovu. Pokud chyba přetrvá, zkontrolujte úložiště souborů.',
        'pdf_created' => 'PDF bylo vytvořeno',
        'related_columns' => [
            'kind' => 'Druh',
            'number' => 'Číslo',
            'status' => 'Stav',
        ],
    ],

    // Surface F: payments. A stored payment is never edited, only deleted together with its income (D-19 as amended
    // on 2026-10-17; rulings R-06-1, R-16-1, R-19-1, R-41-1, R-41-2).
    'payments' => [
        'tab' => 'Platby',
        'columns' => [
            'paid_on' => 'Datum platby',
            'amount' => 'Částka',
            'method' => 'Způsob',
            'category' => 'Kategorie příjmu',
            'note' => 'Poznámka',
        ],
        'helper' => 'Uloženou platbu nelze upravit. Chybnou platbu smažte akcí Smazat platbu; smaže se i příjem, který z ní vznikl.',
        'empty_heading' => 'Zatím žádná platba',
        'empty_body' => 'Platbu zaznamenáte tlačítkem Zaznamenat platbu.',
        'record' => [
            'heading' => 'Zaznamenat platbu',
            'fields' => [
                'amount' => 'Částka',
                'paid_on' => 'Datum platby',
                'method' => 'Způsob',
                'category' => 'Kategorie příjmu',
                'income_date' => 'Datum příjmu',
                'note' => 'Poznámka',
            ],
            'proforma_callout' => 'Platba se u zálohy jen eviduje. Příjem se zaúčtuje až při vystavení faktury.',
            'already_paid' => 'Faktura je již zaplacená.',
            'over_amount' => 'Zadaná částka je vyšší než zbývající částka (:remaining).',
            'not_editable' => 'Uloženou platbu nelze upravit, jen smazat.',
            'submit' => 'Uložit platbu',
            'submit_anyway' => 'Přesto uložit platbu',
            'cancel' => 'Nezaznamenávat',
            'toast_invoice' => 'Platba byla uložena',
            'toast_proforma' => 'Platba u zálohy byla uložena',
        ],
        'delete' => [
            'trigger' => 'Smazat platbu',
            'heading' => 'Smazat platbu?',
            'body_invoice' => 'Platba :amount ze dne :date se trvale smaže spolu s příjmem, který z ní vznikl ve financích. Stav úhrady dokladu se přepočítá ze zbývajících plateb. Tento krok nelze vrátit zpět.',
            'body_card' => 'Platba :amount ze dne :date přišla kartou přes Stripe. Smaže se z dokladu spolu s příjmem, ale peníze se tím nevrací; vrácení proveďte ve Stripe. Opakované doručení stejné události platbu nevytvoří znovu. Tento krok nelze vrátit zpět.',
            'body_proforma' => 'Platba :amount ze dne :date se trvale smaže. U zálohy z ní žádný příjem nevznikl. Tento krok nelze vrátit zpět.',
            'submit' => 'Smazat platbu',
            'cancel' => 'Ponechat platbu',
            'toast' => 'Platba byla smazána',
            'carried' => 'Platba je přenesená na fakturu :number. Nejdřív smažte platbu na faktuře.',
        ],
    ],

    // Surface G: the e-mail dialog and the send log (ruling R-24-1: every send is one attempt group, one row).
    'email' => [
        'dialog' => [
            'heading' => 'Odeslat :kind e-mailem',
            'fields' => [
                'recipient' => 'Příjemce',
                'subject' => 'Předmět',
                'body' => 'Text',
                'attach_report' => 'Přiložit pracovní výkaz',
            ],
            'report_helper' => 'Výkaz obsahuje odpracované hodiny bez cen.',
            'attachments' => 'Přílohy: :files',
            'report_file' => 'pracovní výkaz.pdf',
            'missing_address' => 'Klient nemá vyplněný fakturační e-mail.',
            'submit' => 'Odeslat',
            'cancel' => 'Neodesílat teď',
            'queued' => 'E-mail je ve frontě',
        ],
        'actions' => [
            'send' => 'Odeslat e-mailem',
            'resend' => 'Odeslat znovu',
        ],
        'log' => [
            'title' => 'Odeslání',
            'columns' => [
                'created' => 'Vytvořeno',
                'recipient' => 'Příjemce',
                'attachments' => 'Přílohy',
                'state' => 'Stav',
                'note' => 'Poznámka',
            ],
            'attachments_pdf' => 'PDF',
            'attachments_pdf_report' => 'PDF a výkaz',
            'failure_note' => 'Odeslání se nepodařilo. Zkuste ho zopakovat.',
            'empty' => 'Doklad zatím nebyl odeslán',
        ],
        'notice' => [
            'title' => 'E-mail s dokladem se nepodařilo odeslat',
            'body' => 'Doklad :number se nepodařilo odeslat na adresu klienta. Odešlete ho znovu z detailu dokladu.',
            'action' => 'Otevřít doklad',
        ],
    ],

    // Surface E6 and E7: the storno and the partial credit note.
    'credit' => [
        'storno' => [
            'heading' => 'Stornovat fakturu :number?',
            'body' => 'Vystavíme dobropis na celou fakturu (vlastní číselná řada) a fakturu označíme jako stornovanou. Záznamy času se vrátí mezi nevyfakturované.',
            'paid_callout' => 'Faktura je zaplacená (:paid). Platba a příjem zůstanou beze změny. Vrácení peněz a výdaj zaznamenejte ručně.',
            'submit' => 'Vystavit dobropis a stornovat',
            'cancel' => 'Nestornovat',
            'toast' => 'Dobropis :number byl vystaven',
        ],
        'partial' => [
            'heading' => 'Dobropis k faktuře :number',
            'columns' => [
                'select' => 'Vybrat',
                'item' => 'Položka',
                'original_quantity' => 'Původní množství',
                'credit_quantity' => 'Množství k dobropisu',
                'total' => 'Celkem',
            ],
            'total' => 'Dobropis celkem',
            'callout' => 'Záznamy času zůstanou vyfakturované.',
            'submit' => 'Vystavit dobropis',
            'cancel' => 'Nevystavovat',
        ],
    ],

    // Surface E5: the conversion of a paid proforma into an invoice.
    'conversion' => [
        'heading' => 'Vystavit fakturu ze zálohy :number?',
        'body' => 'Faktura dostane vlastní číslo z řady faktur, převezme položky zálohy a bude s ní propojena. Záznamy času zůstanou vyfakturované. Platba ze zálohy se převede na fakturu a příjem se zaúčtuje s původním datem platby.',
        'submit' => 'Vystavit fakturu',
        'cancel' => 'Nevystavovat',
    ],

    // Surface I: the client detail panels.
    'client_panel' => [
        'stats' => [
            'unbilled' => 'Nevyfakturováno',
            'to_pay' => 'K úhradě',
            'overdue' => 'Po splatnosti',
        ],
        'lines' => [
            'in_drafts' => 'z toho v konceptech: :duration',
            'overdue_part' => 'z toho po splatnosti: :count',
            'nothing_overdue' => 'Nic po splatnosti',
            'fixed_price_note' => 'Hodiny s pevnou cenou jsou bez částky.',
            'show_in_report' => 'Zobrazit v reportu',
        ],
        'tabs' => [
            'projects' => 'Projekty',
            'invoices' => 'Faktury',
        ],
        'projects' => [
            'columns' => [
                'name' => 'Název',
                'key' => 'Klíč',
                'status' => 'Stav',
                'priority' => 'Priorita',
                'unbilled' => 'Nevyfakturováno',
                'fixed_price' => 'Pevná cena',
            ],
            'archived' => 'Archivovaný',
            'empty_heading' => 'Klient zatím nemá žádný projekt',
            'empty_body' => 'Projekt vytvoříte v nabídce Projekty.',
        ],
        'invoices' => [
            'to_pay_total' => 'K úhradě',
            'empty_heading' => 'Klient zatím nemá žádný doklad',
            'empty_body' => 'Fakturu vytvoříte tlačítkem Nová faktura. Můžete ji sestavit z nevyfakturovaného času.',
        ],
    ],

    // Surface J: the dashboard card, collapsible with its own widget key (ruling R-28-1).
    'dashboard' => [
        'heading' => 'Nezaplacené faktury',
        'summary' => 'Po splatnosti: :count',
        'stats' => [
            'overdue' => 'Po splatnosti',
            'to_pay' => 'K úhradě',
            'awaiting_invoice' => 'Zálohy k vyfakturování',
        ],
        'lines' => [
            'czk' => 'v Kč: :czk',
            'nothing_overdue' => 'Nic po splatnosti',
            'awaiting_invoice' => 'Platba čeká na fakturu',
        ],
    ],

    // Surface K: the System page slot "Neodeslané e-maily s fakturou" (the label is the enum label).
    'system' => [
        'ok' => 'Všechny e-maily s fakturou byly odeslány.',
        'warning' => 'Čekající e-maily s fakturou: :count.',
        'error' => 'Neodesláno: :count. Odešlete je znovu z přehledu faktur.',
        'link' => 'Zobrazit neodeslané',
    ],

    // Surface L: the settings additions. Accent, footer, logo and signature are frozen at issue, the two images by
    // object path and SHA-256, so replacing a file keeps the old object (ruling R-03-1; D-21, D-23).
    'settings' => [
        'section' => 'Vzhled dokladů',
        'save' => 'Uložit nastavení',
        'saved' => 'Nastavení bylo uloženo',
        'fields' => [
            'accent' => 'Barva dokladů',
            'footer' => 'Patička dokladů',
            'logo' => 'Logo',
            'signature' => 'Podpis nebo razítko',
        ],
        'helpers' => [
            'accent' => 'Barva proužků, nadpisu a součtu na PDF. Ostatní barvy se odvodí automaticky.',
            'footer' => 'Zobrazí se pod platebními údaji, například údaj o zápisu v rejstříku.',
            'files' => 'Nahrazený soubor zůstane v úložišti, protože na něj odkazují už vystavené doklady.',
        ],
        'accent_invalid' => 'Zadejte barvu ve tvaru #rrggbb.',
        'contrast' => 'Kontrast textu na součtu: :ratio:1',
    ],

    // Surface M: the invoice reference on transactions (ruling R-19-1: a payment income changes only its date and
    // category and is deleted only together with its payment).
    'transactions' => [
        'column' => 'Faktura',
        'filter' => 'Faktura',
        'filter_with' => 's fakturou',
        'filter_without' => 'bez faktury',
        'description' => 'Platba faktury :number',
        'helper' => 'Transakce vznikla z platby faktury :number.',
        'helper_editable' => 'Změnit lze jen datum a kategorii; chybnou platbu smažte v detailu faktury.',
        'delete_refusal' => 'Transakce vznikla z platby faktury :number. Smazat ji lze jen smazáním platby v detailu faktury.',
        'open_invoice' => 'Otevřít fakturu',
    ],

    // Surface M: the billing marker on time entries (ruling R-10-1: a reserved entry is locked, not just flagged).
    'time' => [
        'badge' => 'V konceptu',
        'badge_tooltip' => 'Záznam je připojen ke konceptu faktury.',
        'refusal' => 'Záznam je připojen ke konceptu faktury. Odpojte ho v konceptu.',
        'report_in_drafts' => 'z toho v konceptech: :duration',
        'invoice_link' => 'Faktura :number',
        'invoice_field' => 'Doklad',
        'draft_field' => 'Koncept faktury',
        'open_draft' => 'Otevřít koncept',
        'skipped_reserved' => '{1} Přeskočen :count záznam připojený ke konceptu faktury. Odpojte ho v konceptu.|[2,4] Přeskočeny :count záznamy připojené ke konceptu faktury. Odpojte je v konceptu.|[5,*] Přeskočeno :count záznamů připojených ke konceptu faktury. Odpojte je v konceptu.',
        'unlock_refusal' => 'Záznam patří k vystavené faktuře :number a nelze ho odemknout. Vystavte dobropis.',
    ],

    // Surface N: the Partner view. The Partner state labels are in enums.php (invoice_status_partner).
    'partner' => [
        'navigation' => 'Faktury',
        'list' => [
            'columns' => [
                'number' => 'Číslo',
                'kind' => 'Druh',
                'issued' => 'Vystaveno',
                'due' => 'Splatnost',
                'total' => 'Celkem',
                'remaining' => 'K úhradě',
                'status' => 'Stav',
            ],
            'download' => 'Stáhnout PDF',
            'empty_heading' => 'Zatím žádné doklady',
            'empty_body' => 'Vystavené faktury a zálohové faktury se objeví tady.',
        ],
        'detail' => [
            'sections' => [
                'document' => 'Doklad',
                'supplier' => 'Dodavatel',
                'customer' => 'Odběratel',
                'payment' => 'Platební údaje',
                'items' => 'Položky',
                'summary' => 'Souhrn',
                'note' => 'Poznámka',
            ],
            'fields' => [
                'number' => 'Číslo',
                'kind' => 'Druh',
                'issue_date' => 'Datum vystavení',
                'taxable_supply_date' => 'Datum zdanitelného plnění',
                'due_date' => 'Datum splatnosti',
                'variable_symbol' => 'Variabilní symbol',
                'currency' => 'Měna',
                'bank_account' => 'Bankovní účet',
                'amount_due' => 'Částka k úhradě',
                'total' => 'Celkem',
                'paid' => 'Uhrazeno',
                'remaining' => 'Zbývá uhradit',
                'company_id' => 'IČO',
                'vat_id' => 'DIČ',
                'iban' => 'IBAN',
            ],
            'overdue' => 'Faktura je po splatnosti o :count den. Zbývá uhradit :amount.|Faktura je po splatnosti o :count dny. Zbývá uhradit :amount.|Faktura je po splatnosti o :count dní. Zbývá uhradit :amount.',
        ],
    ],
];
