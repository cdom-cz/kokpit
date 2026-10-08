<?php

declare(strict_types=1);

return [

    'install' => [
        'prompt_name' => 'Zadejte jméno správce',
        'prompt_email' => 'Zadejte e-mail správce',
        'prompt_password' => 'Zadejte heslo (nejméně 12 znaků)',
        'prompt_password_confirmation' => 'Zopakujte heslo',
        'password_mismatch' => 'Hesla se neshodují.',
        'password_env_missing' => 'Heslo správce nebylo zadáno. V neinteraktivním režimu ho předejte v proměnné prostředí KOKPIT_ADMIN_PASSWORD.',
        'name_email_required' => 'V neinteraktivním režimu je nutné zadat volby --name a --email.',
        'password_too_long' => 'Heslo smí mít nejvýše 72 bajtů.',
        'admin_exists' => 'Správce již existuje. Druhý účet správce nelze vytvořit.',
        'created' => 'Správce byl vytvořen. Přihlaste se a nastavte dvoufázové ověření.',
        'login_url' => 'Přihlášení: :url',
        'attributes' => [
            'name' => 'jméno',
            'email' => 'e-mail',
            'password' => 'heslo',
        ],
    ],

    'reset_2fa' => [
        'not_found' => 'Uživatel s tímto e-mailem neexistuje.',
        'force_required' => 'V neinteraktivním režimu je nutné zadat volbu --force.',
        'confirm' => 'Opravdu chcete zrušit dvoufázové ověření uživatele :email?',
        'aborted' => 'Nic se nezměnilo.',
        'done' => 'Dvoufázové ověření bylo zrušeno. Uživatel ho nastaví znovu při příštím přihlášení.',
    ],

    'dashboard' => [
        'empty_heading' => 'Zatím tu nic není',
        'empty_description_admin' => 'Nástěnka se naplní, jakmile v Kokpitu přibudou klienti, projekty a odpracovaný čas.',
        'empty_description_partner' => 'Jakmile pro vás bude něco připraveno, objeví se to tady.',
    ],

    'settings' => [
        'navigation_group' => 'Správa',
        'navigation_label' => 'Nastavení',
        'title' => 'Nastavení',
        'save' => 'Uložit',
        'saved' => 'Nastavení bylo uloženo.',
        'tabs' => [
            'supplier' => 'Dodavatel',
            'defaults' => 'Výchozí hodnoty',
        ],
        'supplier' => [
            'company_name' => 'Název společnosti',
            'street' => 'Ulice a číslo',
            'city' => 'Město',
            'postal_code' => 'PSČ',
            'country' => 'Země (kód ISO)',
            'country_hint' => 'Dvoupísmenný kód, například CZ.',
            'company_id' => 'IČO',
            'vat_id' => 'DIČ',
            'email' => 'E-mail',
            'phone' => 'Telefon',
            'website' => 'Web',
            'registration_note' => 'Poznámka o registraci',
        ],
        'defaults' => [
            'default_currency' => 'Výchozí měna',
            'default_hourly_rate' => 'Výchozí hodinová sazba',
            'default_hourly_rate_hint' => 'Částka s desetinnou čárkou nebo tečkou, například 1250,50.',
            'per_hour' => 'hod.',
            'rate_invalid' => 'Zadejte nezáporné číslo s nejvýše tolika desetinnými místy, kolik měna dovoluje.',
            'rate_currency_mismatch' => 'Měna výchozí sazby musí být stejná jako výchozí měna.',
        ],
    ],

];
