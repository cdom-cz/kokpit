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

    'document_owner_type' => [
        'client' => 'Klient',
        'project' => 'Projekt',
        'task' => 'Úkol',
        'task_comment' => 'Komentář',
        'transaction' => 'Transakce',
        'invoice' => 'Doklad',
    ],

    // DocumentTypeGroup: the file-type groups of the central documents listing (plan 09-14).
    'document_type_group' => [
        'image' => 'Obrázky',
        'pdf' => 'PDF',
        'text' => 'Textové dokumenty',
        'spreadsheet' => 'Tabulky',
        'presentation' => 'Prezentace',
        'archive' => 'Archivy',
        'other' => 'Ostatní',
    ],

    'vat_mode' => [
        'non_payer' => 'Neplátce DPH',
        'payer' => 'Plátce DPH',
    ],

    'invitation_state' => [
        'pending' => 'Čeká',
        'accepted' => 'Přijata',
        'revoked' => 'Zrušena',
        'expired' => 'Vypršela',
    ],

    'client_stage' => [
        'lead' => 'Zájemce',
        'active' => 'Aktivní',
        'paused' => 'Pozastavený',
        'ended' => 'Ukončený',
    ],

    'invoice_language' => [
        'cs' => 'Čeština',
        'en' => 'Angličtina',
    ],

    'project_status' => [
        'planned' => 'Plánovaný',
        'to_clarify' => 'K upřesnění',
        'in_progress' => 'V realizaci',
        'in_review' => 'Ke kontrole',
        'ready_to_release' => 'K vypuštění',
        'done' => 'Dokončeno',
    ],

    'project_priority' => [
        'low' => 'Nízká',
        'normal' => 'Normální',
        'high' => 'Vysoká',
        'urgent' => 'Naléhavá',
    ],

    'billing_type' => [
        'hourly' => 'Hodinová sazba',
        'fixed_price' => 'Pevná cena',
    ],

    'task_billing_type' => [
        'inherit' => 'Podle projektu',
        'hourly' => 'Hodinová sazba',
        'fixed_price' => 'Pevná cena',
        'non_billable' => 'Nefakturovat',
    ],

    'billing_source' => [
        'task' => 'Tento úkol',
        'parent_task' => 'Nadřazený úkol',
        'project' => 'Projekt',
        'client' => 'Klient',
    ],

    'time_billing_state' => [
        'unbilled' => 'Nevyfakturováno',
        'billed' => 'Vyfakturováno',
        'non_billable' => 'Nefakturovatelné',
    ],

    'activity_source_label' => [
        'web' => 'Web',
        'console' => 'Konzole',
        'job' => 'Úloha na pozadí',
        'webhook' => 'Webhook',
        'api' => 'API',
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

    'notification_event' => [
        'task_created' => 'Nový úkol od klienta',
        'comment' => 'Nový komentář',
        'escalation' => 'Eskalace úkolu',
        'assignment_change' => 'Změna úkolu',
    ],

    'notification_channel' => [
        'mail' => 'E-mail',
        'database' => 'Zvonek',
    ],

    'signal_category' => [
        'main' => 'Hlavní',
        'medium' => 'Střední',
        'other' => 'Ostatní',
        'extra' => 'Dodatečný',
    ],

    'report_export_status' => [
        'pending' => 'Připravuje se',
        'ready' => 'Hotovo',
        'failed' => 'Selhalo',
    ],

    'document_archive_status' => [
        'pending' => 'Připravuje se',
        'ready' => 'Hotovo',
        'failed' => 'Selhalo',
    ],

    'report_export_format' => [
        'csv' => 'CSV',
        'xlsx' => 'XLSX',
    ],

    'transaction_type' => [
        'income' => 'Příjem',
        'expense' => 'Výdaj',
    ],

    'czk_source' => [
        'identity' => 'Kč',
        'cnb' => 'Kurz ČNB',
        'manual' => 'Zadáno ručně',
        'custom' => 'Vlastní kurz',
    ],

    // Invoicing (plan 10-33): the document kinds, the stored state and the derived display status.
    'invoice_kind' => [
        'invoice' => 'Faktura',
        'proforma' => 'Zálohová faktura',
        'credit_note' => 'Dobropis',
    ],

    'invoice_state' => [
        'draft' => 'Koncept',
        'issued' => 'Vystaveno',
    ],

    'invoice_status' => [
        'draft' => 'Koncept',
        'unpaid' => 'Nezaplaceno',
        'partially_paid' => 'Částečně zaplaceno',
        'overdue' => 'Po splatnosti',
        'paid' => 'Zaplaceno',
        'cancelled' => 'Stornováno',
        'converted' => 'Převedeno na fakturu',
        'awaiting_invoice' => 'Zaplacená záloha bez faktury',
        'credit_note' => 'Dobropis',
    ],

    // The Partner has no draft; a paid proforma reads as paid whether or not its invoice exists.
    'invoice_status_partner' => [
        'unpaid' => 'Čeká na úhradu',
        'partially_paid' => 'Částečně zaplaceno',
        'overdue' => 'Po splatnosti',
        'paid' => 'Zaplaceno',
        'cancelled' => 'Stornováno',
        'converted' => 'Zaplaceno',
        'awaiting_invoice' => 'Zaplaceno',
        'credit_note' => 'Dobropis',
    ],

    // The tabs of the invoice list (plan 10-19); the last one is the paid proforma still waiting for its invoice.
    'invoice_list_tab' => [
        'all' => 'Všechny',
        'drafts' => 'Koncepty',
        'unpaid' => 'Nezaplacené',
        'overdue' => 'Po splatnosti',
        'paid' => 'Zaplacené',
        'cancelled' => 'Stornované',
        'awaiting_invoice' => 'Zálohy k vyfakturování',
    ],

    'invoice_item_origin' => [
        'time' => 'Z času',
        'fixed_amount' => 'Pevná částka',
        'manual' => 'Ruční položka',
    ],

    'item_grouping' => [
        'project' => 'Podle projektu',
        'task' => 'Podle úkolu',
    ],

    'payment_method' => [
        'transfer' => 'Převodem',
        'cash' => 'Hotově',
        'card' => 'Kartou',
    ],

    'invoice_email_state' => [
        'queued' => 'Ve frontě',
        'sending' => 'Odesílá se',
        'sent' => 'Odesláno',
        'failed' => 'Odeslání selhalo',
    ],

];
