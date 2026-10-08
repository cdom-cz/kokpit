<?php

declare(strict_types=1);

return [

    'role_name' => [
        'admin' => 'Administrátor',
        'partner' => 'Partner',
    ],

    'bank_account_format' => [
        'europe_1' => 'Evropa 1 (číslo účtu)',
        'europe_2' => 'Evropa 2 (pouze IBAN)',
        'world' => 'Svět',
    ],

    'document_kind' => [
        'invoice' => 'Faktura',
        'proforma' => 'Zálohová faktura',
        'credit_note' => 'Dobropis',
        'task' => 'Úkol',
    ],

    'vat_mode' => [
        'non_payer' => 'Neplátce DPH',
        'payer' => 'Plátce DPH',
    ],

];
