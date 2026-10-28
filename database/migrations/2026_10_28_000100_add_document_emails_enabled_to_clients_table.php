<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The per-client switch "send documents by e-mail" (Phase 16, D-01, D-05). When it is off, no invoice, proforma,
 * credit note or payment confirmation is e-mailed to the client; the Admin hands the document over by hand.
 *
 * The column is NOT NULL with the default true, so every existing client and every new row sends documents as
 * before: PostgreSQL fills the default into the existing rows when the column is added and there is no UPDATE
 * and no backfill to write. Only the Admin sets it to false.
 *
 * The column is Admin-only data: `clients` is a `DeniesPartners` table, so a Partner reads it through no model,
 * relation or policy. A change is audited through the allowlist of the Client model, not through a trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->boolean('document_emails_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('document_emails_enabled');
        });
    }
};
