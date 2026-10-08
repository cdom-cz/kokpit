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

    'activity_source_label' => [
        'web' => 'Web',
        'console' => 'Konzole',
        'job' => 'Úloha na pozadí',
        'webhook' => 'Webhook',
    ],

    'health_status' => [
        'ok' => 'V pořádku',
        'warning' => 'Varování',
        'error' => 'Chyba',
        'not_available' => 'Nedostupné',
    ],

    'health_slot' => [
        'failed_jobs' => 'Selhané úlohy',
        'oldest_pending_job' => 'Nejstarší čekající úloha',
        'scheduler_heartbeat' => 'Tep plánovače',
        'last_rate_date' => 'Datum posledního kurzu',
        'unprocessed_webhooks' => 'Nezpracované webhooky',
        'unsent_invoice_emails' => 'Neodeslané e-maily s fakturou',
    ],

];
