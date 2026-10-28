<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/*
 * The projects table is readable by every Partner of the client, so its column
 * list is pinned (D-05, D-07, research Pitfall 8). A new column fails this test
 * until a reviewer has decided that every Partner may read it.
 */

it('pins the Partner-safe column list of the projects table', function (): void {
    $columns = Schema::getColumnListing('projects');
    sort($columns, SORT_STRING);

    expect($columns)->toBe([
        'client_id',
        'client_visible',
        'created_at',
        'deleted_at',
        'description',
        'end_date',
        'id',
        'key',
        'name',
        'priority',
        'start_date',
        'status',
        'updated_at',
    ], 'The projects table is readable by Partners. Put Admin-only attributes (rate, price, estimate, billing type, internal note) into project_billing or another Admin-only table; extend this list only after reviewing that every Partner may read the new column.');
});

it('has no projects column whose name looks like money, an estimate, billing or an internal note', function (): void {
    $suspicious = array_values(array_filter(
        Schema::getColumnListing('projects'),
        static fn (string $column): bool => preg_match('/rate|price|estimate|billing|note|cost|budget/i', $column) === 1,
    ));

    expect($suspicious)->toBe([], 'Admin-only attributes belong in project_billing, not in the Partner-readable projects table.');
});

/*
 * The tasks table is readable by every Partner of the client for the tasks of the
 * client-visible projects (TA-01, D-13), so its column list is pinned in
 * migration order as well. A new column fails this test until a reviewer has
 * decided that every Partner may read it.
 */

it('pins the Partner-safe column list of the tasks table', function (): void {
    expect(Schema::getColumnListing('tasks'))->toBe([
        'id',
        'project_id',
        'parent_id',
        'depth',
        'parent_depth',
        'number',
        'reference',
        'title',
        'description',
        'status',
        'priority',
        'position',
        'start_date',
        'due_date',
        'completed_at',
        'assignee_id',
        'requester_id',
        'escalated_at',
        'escalated_by_id',
        'deleted_at',
        'created_at',
        'updated_at',
    ], 'The tasks table is readable by Partners. Put Admin-only attributes (billing terms, the private checklist, internal comments) into task_billing, task_checklist_items or an internal comment; extend this list only after reviewing that every Partner may read the new column.');
});

it('has no tasks column whose name looks like money, a rate, an estimate, billing or an internal note', function (): void {
    $suspicious = array_values(array_filter(
        Schema::getColumnListing('tasks'),
        static fn (string $column): bool => preg_match('/money|rate|price|estimate|billing|internal|note|cost|budget/i', $column) === 1,
    ));

    expect($suspicious)->toBe([], 'Admin-only attributes belong in task_billing, task_checklist_items or an internal comment, not in the Partner-readable tasks table.');
});

/*
 * The task_comments table is readable by every Partner of the client for the
 * non-internal comments of the visible tasks (TA-07, D-08), so its column list is
 * pinned in migration order as well. The internal flag is a column of this table:
 * the model scope drops every internal row for a Partner, and a new column fails
 * this test until a reviewer has decided that every Partner may read it.
 */

it('pins the Partner-safe column list of the task_comments table', function (): void {
    expect(Schema::getColumnListing('task_comments'))->toBe([
        'id',
        'task_id',
        'author_id',
        'body',
        'is_internal',
        'is_escalation',
        'created_at',
        'updated_at',
    ], 'The task_comments table is readable by Partners for non-internal rows. Put Admin-only attributes into an Admin-only table; extend this list only after reviewing that every Partner may read the new column.');
});

it('has no task_comments column whose name looks like money, a rate, a price, an estimate or billing', function (): void {
    $suspicious = array_values(array_filter(
        Schema::getColumnListing('task_comments'),
        static fn (string $column): bool => preg_match('/money|rate|price|estimate|billing|cost|budget/i', $column) === 1,
    ));

    expect($suspicious)->toBe([], 'Admin-only attributes do not belong in the Partner-readable task_comments table.');
});

/*
 * The documents table is readable by every Partner of the client for the files shared with them (DO-01, D-03,
 * D-04): every column of a shared file, the name and the description included, is shown to the client. Its column
 * list is pinned in migration order as well. A new column fails this test until a reviewer has decided that every
 * Partner may read it.
 */

it('pins the Partner-safe column list of the documents table', function (): void {
    expect(Schema::getColumnListing('documents'))->toBe([
        'id',
        'name',
        'description',
        'visible_to_client',
        'owner_type',
        'owner_id',
        'client_id',
        'project_id',
        'uploaded_by_id',
        'extension',
        'mime_type',
        'size_bytes',
        'created_at',
        'updated_at',
    ], 'The documents table is readable by Partners for the files shared with them, and the description is shown to the client when the file is shared. Put Admin-only attributes (an internal note, a price, a rate, a cost, billing data) into an Admin-only table; extend this list only after reviewing that every Partner may read the new column.');
});

it('has no documents column whose name looks like money, a rate, a price, an estimate, billing, a cost or an internal note', function (): void {
    $suspicious = array_values(array_filter(
        Schema::getColumnListing('documents'),
        static fn (string $column): bool => preg_match('/money|rate|price|estimate|billing|internal|note|cost|budget/i', $column) === 1,
    ));

    expect($suspicious)->toBe([], 'Admin-only attributes do not belong in the Partner-readable documents table.');
});

/*
 * The invoices table is readable by every Partner of the client for the issued documents of the client (IN-09,
 * D-13), and so is invoice_items: both hold only what the document prints and its links to other issued documents.
 * The lists are pinned in migration order as well. A new column fails this test until a reviewer has decided that
 * every Partner may read it; the rate, the CNB and CZK figures, the internal note, the payments, the PDF link and
 * the billing sources belong in Admin-only tables.
 */

it('pins the Partner-safe column list of the invoices table', function (): void {
    expect(Schema::getColumnListing('invoices'))->toBe([
        'id',
        'client_id',
        'kind',
        'state',
        'number',
        'variable_symbol',
        'currency',
        'language',
        'vat_mode',
        'issue_date',
        'taxable_supply_date',
        'due_date',
        'subtotal_minor',
        'vat_minor',
        'total_minor',
        'note',
        'supplier_snapshot',
        'customer_snapshot',
        'bank_snapshot',
        'appearance_snapshot',
        'proforma_id',
        'credited_invoice_id',
        'cancels_invoice',
        'issued_at',
        'created_at',
        'updated_at',
        'lock_state',
        'payment_method',
    ], 'The invoices table is readable by Partners for issued documents. Put Admin-only attributes (an internal note, a CNB rate, a CZK total, a payment, the PDF link) into invoice_admin, invoice_item_sources or another Admin-only table; extend this list only after reviewing that every Partner may read the new column. lock_state (open or locked) was reviewed in Phase 10.1: it carries no reason, sender or time of the lock, which live only in the Admin-only activity log. payment_method (transfer or online) was reviewed in Phase 14: every Partner may read how the own document is paid; it carries no account, price, rate or token.');
});

it('pins the Partner-safe column list of the invoice_items table', function (): void {
    expect(Schema::getColumnListing('invoice_items'))->toBe([
        'id',
        'invoice_id',
        'position',
        'description',
        'quantity',
        'unit',
        'unit_price_minor',
        'line_total_minor',
        'price_converted',
        'vat_rate',
        'vat_minor',
        'credited_item_id',
        'created_at',
        'updated_at',
    ], 'The invoice_items table is readable by Partners for the lines of issued documents. Put Admin-only attributes (the source hourly rate, the from-time flag, the linked time) into invoice_item_sources or another Admin-only table; extend this list only after reviewing that every Partner may read the new column.');
});

it('has no invoices or invoice_items column whose name looks like a rate, an internal note, a CNB or CZK figure, a payment, a source or a billing attribute', function (): void {
    // `invoices.payment_method` is the one reviewed exception (Phase 14, pinned in the list above): it says only how the
    // own document is paid and carries no account, price, rate or token. Any other `payment` column still fails here.
    $reviewed = ['invoices' => ['payment_method'], 'invoice_items' => []];

    foreach (['invoices', 'invoice_items'] as $table) {
        $suspicious = array_values(array_filter(
            Schema::getColumnListing($table),
            static fn (string $column): bool => ! in_array($column, $reviewed[$table], true)
                && preg_match('/hourly|internal|cnb|czk|exchange|payment|source|cost|budget|estimate|billing/i', $column) === 1,
        ));

        expect($suspicious)->toBe([], "Admin-only attributes belong in invoice_admin, invoice_item_sources or another Admin-only table, not in the Partner-readable {$table} table.");
    }
});

/*
 * The cash receipts table (PPD, Phase 15, plan 15-02; D-13) is readable by every Partner of the client for the client's
 * own receipts. It holds what the receipt prints (the number of the paid document included, which is the number of the
 * client's own document), its lock and cancel state and three opaque links (the invoice, `invoice_payment_id`,
 * `pdf_document_id`). The payment link and the PDF link name Admin-only rows a Partner cannot read; they are reviewed on
 * purpose and carry no figure of their own. The cancel reason, the unlock reason and the person who acted are free text
 * or identities of the Admin and live only in the Admin-only activity log, never on this row.
 */

it('pins the Partner-safe column list of the cash_receipts table', function (): void {
    expect(Schema::getColumnListing('cash_receipts'))->toBe([
        'id',
        'invoice_id',
        'client_id',
        'invoice_payment_id',
        'number',
        'document_number',
        'issued_on',
        'received_on',
        'amount_minor',
        'currency',
        'purpose',
        'language',
        'supplier_snapshot',
        'customer_snapshot',
        'appearance_snapshot',
        'lock_state',
        'cancelled_at',
        'pdf_document_id',
        'created_at',
        'updated_at',
    ], 'The cash_receipts table is readable by Partners for their own receipts. It holds what the receipt prints (the paid document number included, which is the number of the client\'s own document), its lock and cancel state and three opaque links (the invoice, invoice_payment_id, pdf_document_id); the payment link names an Admin-only row a Partner cannot read and is reviewed on purpose. Reasons and causers live in the Admin-only activity log. Extend this list only after reviewing that every Partner may read the new column.');
});

it('has no cash_receipts column whose name looks like a rate, an internal note, a reason, a cost or a billing attribute, apart from the reviewed payment link', function (): void {
    // `invoice_payment_id` is the one reviewed exception (Phase 15, pinned in the list above): an opaque id of an
    // Admin-only row, no figure. Any other `payment` column still fails here.
    $reviewed = ['invoice_payment_id'];

    $suspicious = array_values(array_filter(
        Schema::getColumnListing('cash_receipts'),
        static fn (string $column): bool => ! in_array($column, $reviewed, true)
            && preg_match('/hourly|internal|cnb|czk|exchange|payment|source|cost|budget|estimate|billing|reason|note|created_by|cancelled_by/i', $column) === 1,
    ));

    expect($suspicious)->toBe([], 'Admin-only attributes (a reason, a causer, an internal note) belong in the Admin-only activity log or an Admin-only table, not in the Partner-readable cash_receipts table.');
});
