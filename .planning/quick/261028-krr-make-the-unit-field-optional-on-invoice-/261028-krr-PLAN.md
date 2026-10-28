---
phase: quick-261028-krr
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - database/migrations/2026_10_28_000400_make_invoice_item_unit_optional.php
  - app/Domain/Invoicing/Support/InvoiceItemInput.php
  - app/Domain/Invoicing/Models/InvoiceItem.php
  - lang/cs/invoices.php
  - tests/Feature/Invoicing/DraftLifecycleTest.php
  - tests/Feature/Schema/InvoiceTablesTest.php
  - app/Domain/Invoicing/Pdf/InvoicePdfData.php
  - app/Filament/Support/InvoiceColumns.php
  - app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php
  - tests/Feature/Invoicing/InvoicePdfTest.php
autonomous: true
requirements: [IN-01, IN-02]

estimate:
  tokens: 45000
  raw_tokens: 45000
  tasks: 2
  confidence: low

must_haves:
  truths:
    - "The Admin can save a line of a draft invoice or a draft proforma with the Unit field left empty (blank text, whitespace only, null, or the key missing). No field error is raised, and the stored `invoice_items.unit` is NULL, never an empty string"
    - "A unit that is given is trimmed and stored as before, and a unit longer than 16 characters is still refused with the field error `items.<n>.unit`. The reworded Czech copy of `invoices.errors.item_unit` no longer asks the user to enter a unit"
    - "The database accepts a NULL unit and still refuses an empty or whitespace-only unit through the `invoice_items_unit_check` constraint (SQLSTATE 23514)"
    - "On an issued invoice or proforma, the PDF prints the quantity alone (for example `3`) for a line without a unit, with no trailing space. A line with a unit prints exactly as before (for example `2 h`)"
    - "The Admin detail page, the credit-note dialog and the Partner detail all print the quantity of a no-unit line with no stray separator, because all four display sites use the single `InvoiceItem::printedQuantity()` helper"
    - "Nothing else changes: lines generated from time keep `h`, fixed amounts keep their unit, the editor still pre-fills `ks` on a new manual line (the Admin may clear it), and credit notes and proforma conversion copy a NULL unit as is"
  artifacts:
    - path: "database/migrations/2026_10_28_000400_make_invoice_item_unit_optional.php"
      provides: "unit column made nullable and its check relaxed to NULL-or-non-blank; down() refuses while a NULL unit exists"
      contains: "unit IS NULL OR btrim(unit)"
    - path: "app/Domain/Invoicing/Support/InvoiceItemInput.php"
      provides: "Optional unit: blank or absent becomes null, a given unit is trimmed and capped at 16 characters"
      contains: "public ?string $unit"
    - path: "app/Domain/Invoicing/Models/InvoiceItem.php"
      provides: "Nullable unit property and the printedQuantity(locale) display helper"
      contains: "function printedQuantity("
  key_links:
    - from: "EditInvoice repeater field `unit` (Livewire state null or empty string)"
      to: "InvoiceItemInput::list()"
      via: "ItemListWriter for drafts, and UpdateIssuedDocument for open issued documents"
      pattern: "InvoiceItemInput::list"
    - from: "InvoiceItemInput unit normalisation (blank to null)"
      to: "UpdateIssuedDocument credited-line freeze, which compares the input unit with the stored unit strictly"
      via: "null on both sides for a credited line without a unit, so the line is not falsely reported as changed"
      pattern: "input->unit !== \\$stored->unit"
    - from: "InvoicePdfData / InvoiceColumns / ViewInvoice"
      to: "InvoiceItem::printedQuantity()"
      via: "one helper for every place a quantity is shown with its unit"
      pattern: "printedQuantity\\("
---

<objective>
Make the Unit of an invoice or proforma line optional. A line can be saved without a unit, stored as NULL, and
shown everywhere (PDF, Admin detail, credit-note dialog, Partner detail) as the quantity alone, with no stray
separator.

Purpose: some lines (a flat fee, a licence) have no meaningful unit, and today the editor refuses to save them
until something is typed into Unit.

Output: one migration, an optional unit in `InvoiceItemInput`, a nullable `InvoiceItem::$unit` with a
`printedQuantity()` helper used by every display site, and updated or new Pest tests.

Source coverage (quick task, no CONTEXT.md or RESEARCH.md): GOAL "Unit optional on invoice and proforma lines,
empty unit accepted and rendered gracefully, with a Pest test" is COVERED by Task 1 (accept and store) and
Task 2 (render). There are no D-XX decisions to honour.

Findings that shape the plan:
- The Filament field has no `required()`. The requirement is enforced in `InvoiceItemInput::unit()` (the
  `item_unit` error) and in the DB (`string('unit', 16)` NOT NULL plus `invoice_items_unit_check`). The form
  itself needs no change.
- There is no ISDOC export and no API endpoint for invoice lines, so nothing to change there.
- The PDF already trims. `InvoiceColumns::lines()`, `ViewInvoice::lines()` and the credit-dialog row
  concatenate the quantity, a space and the unit with no trim, which leaves a trailing space.
</objective>

<execution_context>
@~/.claude/gsd-core/workflows/execute-plan.md
@~/.claude/gsd-core/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@.claude/CLAUDE.md
@app/Domain/Invoicing/Support/InvoiceItemInput.php
@database/migrations/2026_10_28_000300_relax_payment_confirmation_recipient_check.php

Pattern to follow for the migration: `2026_10_28_000300_relax_payment_confirmation_recipient_check.php` uses raw
`DB::statement` DDL, a header comment explaining why, and a `down()` that refuses with a `RuntimeException` when
rows exist that the old constraint cannot hold. The original constraint lives in
`database/migrations/2026_10_17_000200_create_invoices_tables.php` (the column at line 138 and the check at
line 158).

Project rules (CLAUDE.md):
- Fictional data only in tests ("Example ..." descriptions, no real units or prices beyond the existing fixture
  style).
- Do not run migrations or the DB test suite inside a worktree. In a worktree, run only `php -l` and the grep
  gates. After the merge into the HEAD branch, run `ddev exec php artisan migrate --no-interaction`, then the
  ddev Pest, Pint and PHPStan commands below. Their result is the verdict.
- Tests and linters run through `ddev exec` (the DB host resolves only inside DDEV). The test suite uses its own
  `kokpit_test` database with RefreshDatabase, so the new migration is applied there automatically.
</context>

<tasks>

<task type="tracer">
  <name>Task 1: Tracer, a draft line without a unit saves end-to-end (input, model, DB)</name>
  <files>database/migrations/2026_10_28_000400_make_invoice_item_unit_optional.php, app/Domain/Invoicing/Support/InvoiceItemInput.php, app/Domain/Invoicing/Models/InvoiceItem.php, lang/cs/invoices.php, tests/Feature/Invoicing/DraftLifecycleTest.php, tests/Feature/Schema/InvoiceTablesTest.php</files>
  <behavior>
    - Saving a draft invoice and a draft proforma (dataset over kind `invoice` and `proforma` via `draftCreate($admin, $client, ['kind' => ...])`) with four lines succeeds. The lines have unit `''`, unit `'   '`, unit `null`, and no `unit` key at all. Every stored line has `unit === null`, and the draft total is the sum of the lines.
    - A line with unit `' h '` is stored as `'h'` (trimming unchanged).
    - In the existing test "refuses bad lines as field errors in Czech and stores nothing", the blank-unit entry at index 4 is replaced by an over-long unit (`str_repeat('u', 17)`), so the indices and the other expectations stay unchanged. `items.4.unit` now carries the reworded message.
    - Raw SQL in InvoiceTablesTest: a line with `'unit' => null` is allowed (`RawSql::expectAllowed`), and the refused dataset keeps `'blank unit' => ''` and gains `'whitespace unit' => '   '` (both SQLSTATE 23514).
  </behavior>
  <action>
    Wire the optional unit through every layer in one pass:

    1. Migration (new file `database/migrations/2026_10_28_000400_make_invoice_item_unit_optional.php`), modelled on the 2026_10_28_000300 relax migration. Use raw `DB::statement`, not the schema builder `change()`. up() drops `invoice_items_unit_check` and runs `ALTER TABLE invoice_items ALTER COLUMN unit DROP NOT NULL`. It then re-adds `invoice_items_unit_check` as `CHECK (unit IS NULL OR btrim(unit) <> '')`. NULL means "no unit", and a blank string stays forbidden, the same convention as the payment-confirmation recipient. Every existing row already satisfies the relaxed check.

       down() counts rows with a NULL unit and throws a `RuntimeException` when any exist. Backfilling a unit would rewrite printed documents, and the child guard triggers block writes to locked documents anyway. Otherwise down() restores NOT NULL and the original check verbatim from 2026_10_17_000200 (`CHECK (btrim(unit) <> '')`).

       Add a header comment that explains the why: lines such as a flat fee have no meaningful unit, and the editor refused to save them. Do not run this migration in a worktree.

    2. `InvoiceItemInput`: the constructor property becomes `public ?string $unit`. Replace the "unit" bullet of the class docblock: the unit is optional, blank or absent means no unit (null), and a given unit is trimmed and at most 16 characters.

       New unit semantics:
       - null, or a string that trims to empty, gives a valid null.
       - A string of 1 to `UNIT_MAX_LENGTH` characters after trimming gives the trimmed string.
       - A string longer than that, or any non-string non-null value (int, array, bool), records the `item_unit` problem.

       Separate "absent" from "invalid", for example with a small validity check beside the normaliser. Remove `$unit === null` from the early-return condition in `line()`, so a valid null unit no longer blocks the line. Normalise blank to null and never keep `''`: the credited-line freeze in `UpdateIssuedDocument` compares the input unit with the stored unit strictly, and a NULL stored unit must equal the normalised input.

    3. `InvoiceItem`: change the docblock to `@property string|null $unit`. Leave `#[Fillable]` unchanged.

    4. `lang/cs/invoices.php`: reword `errors.item_unit` in Czech so it only states the 16-character maximum for the unit and no longer asks the user to enter one. Match the tone of the neighbouring messages.

    5. Tests as in `<behavior>`. Update the expected `items.4.unit` message in DraftLifecycleTest to the new Czech copy. Add the new "saves a line without a unit" test next to the zero-price test. For the missing-key case, build the line array without the `unit` key; for the null case, spread `InvoiceFixtures::item(...)` and override `'unit' => null`. Use fictional "Example ..." descriptions only.

    Leave the EditInvoice form as it is (it has no `required()`), including its `ks` default. Keeping the pre-fill is a deliberate discretion choice: it changes nothing for the usual case, and the Admin can now clear it.

    Verification order (CLAUDE.md): the first `<automated>` command (php -l and grep gates) is safe in a worktree. The second needs the shared database, so run it only after the merge into the HEAD branch, never inside a worktree.
  </action>
  <verify>
    <automated>php -l database/migrations/2026_10_28_000400_make_invoice_item_unit_optional.php && php -l app/Domain/Invoicing/Support/InvoiceItemInput.php && php -l app/Domain/Invoicing/Models/InvoiceItem.php && grep -qF "unit IS NULL OR btrim(unit)" database/migrations/2026_10_28_000400_make_invoice_item_unit_optional.php && grep -q "DROP NOT NULL" database/migrations/2026_10_28_000400_make_invoice_item_unit_optional.php && grep -qF 'public ?string $unit' app/Domain/Invoicing/Support/InvoiceItemInput.php && grep -qF '@property string|null $unit' app/Domain/Invoicing/Models/InvoiceItem.php && ! grep -qF '1 to 16 characters after trimming' app/Domain/Invoicing/Support/InvoiceItemInput.php</automated>
    <automated>ddev exec php artisan migrate --no-interaction && ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Invoicing/DraftLifecycleTest.php tests/Feature/Schema/InvoiceTablesTest.php tests/Feature/Invoicing/UpdateIssuedDocumentTest.php tests/Feature/Invoicing/DraftEditorTest.php tests/Feature/Invoicing/IssuedEditorTest.php</automated>
  </verify>
  <done>
    A draft invoice and a draft proforma save lines with a blank, whitespace-only, null or missing unit, and those lines are stored as NULL. A unit over 16 characters is still refused with the reworded message. The DB allows NULL and refuses '' and '   '. The listed Pest files are green after the merge, and the existing credited-line freeze dataset (unit changed to `pcs`) is still refused.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Print a line without a unit as the quantity alone, everywhere</name>
  <files>app/Domain/Invoicing/Models/InvoiceItem.php, app/Domain/Invoicing/Pdf/InvoicePdfData.php, app/Filament/Support/InvoiceColumns.php, app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php, tests/Feature/Invoicing/InvoicePdfTest.php</files>
  <behavior>
    - New test in InvoicePdfTest, with a dataset over `InvoiceKind::Invoice` and `InvoiceKind::Proforma`. Issue a document via `InvoiceFixtures::issued($client, [InvoiceFixtures::item('Example flat fee', '3', '100.00', ''), InvoiceFixtures::item('Example consulting', '2', '500.00')], $kind)`. The HTML from `app(InvoicePdf::class)->html($issued)` contains `<td class="quantity">3</td>` and `<td class="quantity">2 h</td>`.
    - In the same test, `printedQuantity('cs')` of the first stored line returns `'3'` and of the second `'2 h'`. `printedQuantity('en')` of a line stored with quantity `1.5000` and no unit returns `'1.5'`.
  </behavior>
  <action>
    1. Add `InvoiceItem::printedQuantity(string $locale): string`. It returns the quantity formatted by `InvoiceQuantity::format($locale)`, followed by a single space and the unit when the unit is not null, or the formatted quantity alone when it is null. The docblock says it is the one place a line's quantity is shown with its unit, and it inherits `@throws InvalidArgumentException` for a locale other than cs or en.

    2. Replace the hand-built quantity-plus-unit strings with this helper. The `'quantity'` entry becomes `$item->printedQuantity($locale)` in `InvoicePdfData::items()` (drop the now-unneeded local and trim), and `$item->printedQuantity('cs')` in `InvoiceColumns::lines()` (Partner detail) and `ViewInvoice::lines()` (Admin detail). After the change, none of these three files reads the raw unit property of an item directly, including the credit-row builder in step 3.

    3. ViewInvoice credit-note dialog: in the credit-row shape used by `creditRows()`, `creditRow()` and the `$creditRows` property docblock, replace the `original` and `unit` keys with one `printed` string key filled from `$item->printedQuantity('cs')` (fallback row: `''`). The display `Text` then shows that key. First grep `ViewInvoice.php` for other readers of `['original']`. If there is one, keep `original` and only replace `unit`.

    4. Write the InvoicePdfTest test from `<behavior>` first, then make it pass. Import `App\Domain\Invoicing\Enums\InvoiceKind`. For the `en` case, read a stored line from the issued document, or build one in memory with `forceFill(['quantity' => '1.5000', 'unit' => null])`. Use fictional "Example ..." descriptions only.

    Run `ddev exec vendor/bin/phpstan` over the touched files after the merge. The nullable unit property must leave no string-only type assumption behind; the credit-row shape is the known one.

    Verification order (CLAUDE.md): the first `<automated>` command (php -l and grep gates) is safe in a worktree. The second (Pest, Pint, PHPStan in DDEV) runs only after the merge into the HEAD branch, once the migration has been applied there.
  </action>
  <verify>
    <automated>php -l app/Domain/Invoicing/Models/InvoiceItem.php && php -l app/Domain/Invoicing/Pdf/InvoicePdfData.php && php -l app/Filament/Support/InvoiceColumns.php && php -l app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php && test "$(grep -l 'printedQuantity(' app/Domain/Invoicing/Models/InvoiceItem.php app/Domain/Invoicing/Pdf/InvoicePdfData.php app/Filament/Support/InvoiceColumns.php app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php | wc -l | tr -d ' ')" = 4 && ! grep -qE 'item->unit([^_a-zA-Z]|$)' app/Domain/Invoicing/Pdf/InvoicePdfData.php app/Filament/Support/InvoiceColumns.php app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php && ! grep -qF "['unit']" app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php</automated>
    <automated>ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Invoicing/InvoicePdfTest.php tests/Feature/Invoicing/InvoiceDetailTest.php tests/Feature/Invoicing/PartnerInvoiceResourceTest.php tests/Feature/Invoicing/InvoicePdfVariantsTest.php tests/Feature/Invoicing/DraftLifecycleTest.php && ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G</automated>
    <human-check>As the Admin, open a draft invoice and a draft proforma, clear the Unit of a manual line and save. No error appears. Issue the document and open its PDF: the quantity column shows only the number for that line. On the issued detail page and in the Partner view of the same document, the line shows the number with no trailing gap. Lines with a unit (for example from tracked time, "h") look exactly as before.</human-check>
  </verify>
  <done>
    `InvoiceItem::printedQuantity()` exists and is the only way the PDF, the Admin detail, the credit dialog and the Partner detail build "quantity unit". A no-unit line prints as the bare quantity on an invoice and on a proforma. Pint, PHPStan and the listed Pest files are green after the merge.
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| Admin browser -> Livewire editor -> InvoiceItemInput | The unit text of a line is untrusted input from the form state (string, null or forged type) |
| Application -> PostgreSQL `invoice_items` | The DB constraint is the last guard on what a stored unit may be |
| Issued document -> Partner view / PDF | The unit is printed on the document the client receives |

## STRIDE Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation Plan |
|-----------|----------|-----------|----------|-------------|-----------------|
| T-261028-krr-01 | Tampering | InvoiceItemInput unit normalisation | medium | mitigate | A given unit is still trimmed and capped at 16 characters, and a non-string non-null value is refused with `items.<n>.unit`. Blank becomes NULL, never `''`. Proven by the DraftLifecycleTest refused and accepted cases |
| T-261028-krr-02 | Tampering | `invoice_items_unit_check` | medium | mitigate | The relaxed check `unit IS NULL OR btrim(unit) <> ''` still refuses blank and whitespace-only units written by any path, including raw SQL. Proven by the InvoiceTablesTest dataset. The column length of 16 is unchanged |
| T-261028-krr-03 | Tampering | Credited-line freeze in UpdateIssuedDocument | medium | mitigate | Normalising to NULL keeps the strict input-versus-stored comparison exact for credited lines without a unit. The existing frozen-unit dataset in UpdateIssuedDocumentTest is re-run |
| T-261028-krr-04 | Information Disclosure | Unit printed to the Partner and in the PDF | low | accept | The unit was already Partner-readable (PartnerSafeColumnsTest lists it), and a NULL unit reveals nothing new. Output is still Blade-escaped |
| T-261028-krr-05 | Repudiation | InvoiceAudit of issued edits | low | accept | The audit already records `items.<n>.unit` as `string|int|null`, so clearing a unit is recorded as a change to null |
| T-261028-krr-06 | Information Disclosure | Test fixtures and this plan | low | mitigate | Fictional "Example ..." descriptions only. `scripts/check-sensitive.sh` runs over every touched file before commit, plus the lefthook gitleaks hook |
| T-261028-krr-SC | Tampering | composer/npm installs | low | accept | This plan installs no package |
</threat_model>

<verification>
- In the worktree: both tasks' `php -l` and grep gates pass
- After the merge into the HEAD branch: `ddev exec php artisan migrate --no-interaction` applies `2026_10_28_000400_make_invoice_item_unit_optional`
- After the merge: `ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Invoicing/DraftLifecycleTest.php tests/Feature/Schema/InvoiceTablesTest.php tests/Feature/Invoicing/UpdateIssuedDocumentTest.php tests/Feature/Invoicing/InvoicePdfTest.php tests/Feature/Invoicing/InvoiceDetailTest.php tests/Feature/Invoicing/PartnerInvoiceResourceTest.php` is green
- After the merge: `ddev composer ci` is green (pest, pint, phpstan, licenses)
- `scripts/check-sensitive.sh` passes over every touched file
- Human check (end of phase, not blocking): see the Task 2 human-check
</verification>

<success_criteria>
- An invoice or proforma line can be saved and issued without a unit, and it is stored as NULL
- A blank unit cannot reach the database, and an over-long unit is still refused
- Every place that shows a line prints a no-unit line as the bare quantity, with no stray separator
- Lines with a unit, time-generated lines and the editor default behave exactly as before
- The full CI gate and the sensitive-data check are green after the merge
</success_criteria>

<output>
Create `.planning/quick/261028-krr-make-the-unit-field-optional-on-invoice-/261028-krr-SUMMARY.md` when done
</output>
