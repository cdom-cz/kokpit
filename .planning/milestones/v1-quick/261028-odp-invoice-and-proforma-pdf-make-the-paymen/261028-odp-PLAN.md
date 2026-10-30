---
phase: quick-261028-odp
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - resources/views/pdf/invoice.blade.php
  - tests/Feature/Invoicing/InvoicePdfTest.php
  - app/Domain/Invoicing/Pdf/PdfParts.php
  - app/Domain/Invoicing/Pdf/InvoicePdfData.php
  - app/Domain/Invoicing/Pdf/CashReceiptPdfData.php
  - tests/Feature/Invoicing/PdfEngineTest.php
  - resources/views/pdf/cash-receipt.blade.php
  - lang/cs/kokpit.php
  - tests/Feature/Invoicing/CashReceiptPdfTest.php
autonomous: true
requirements: [IN-04, P15-08]

estimate:
  tokens: 55000
  raw_tokens: 55000
  tasks: 3
  confidence: low

must_haves:
  truths:
    - "On an issued invoice and an issued proforma paid by transfer, the payment box still prints all the payment details: the sentence (amount, account, variable symbol, due date), every fact row (account number, IBAN, BIC, variable symbol, payment method, amount due, each only when present as today), the SPAYD QR when supported, and the card lead, button and printed URL when card payment is on. The facts are laid out two to a row, the QR is smaller and the spacing tighter, so the box with the card block is about 135 pt tall instead of about 230 pt. That leaves room for about three more line rows on the page"
    - "The QR prints as a 72 pt (about 25 mm) white square that includes the PNG's own 4-module quiet zone. It still decodes to the same SPAYD string (error correction M is unchanged)"
    - "An online or a cash document prints its two facts (payment method, amount due) on one row. A credit note still prints no payment box"
    - "The invoice, proforma and credit-note PDF (one template, one party builder) no longer prints the supplier's e-mail, phone or website. Name, address, company ID, VAT ID, registration note and the not-a-VAT-payer note still print, and the issued supplier snapshot still holds the e-mail, phone and website"
    - "The cash receipt (PPD) PDF prints neither the supplier's e-mail, phone or website nor the document footer lines. The issuer line, the signature and every Accounting Act item stay, and the receipt's supplier and appearance snapshots still hold the contact values and the footer"
    - "The invoice and proforma PDF still prints the document footer, and the settings hint for the document footer says it is printed on invoices, proformas and credit notes and not on the cash receipt"
  artifacts:
    - path: "resources/views/pdf/invoice.blade.php"
      provides: "Compact payment box: facts two per row, 72 pt QR, 10/12 pt padding, 12 pt top margin, card lead and button on one row"
      contains: "array_chunk($data->payment['facts'], 2)"
    - path: "app/Domain/Invoicing/Pdf/PdfParts.php"
      provides: "party() with four parameters; notes hold only the registration note"
    - path: "app/Domain/Invoicing/Pdf/CashReceiptPdfData.php"
      provides: "Receipt data without a footer property"
    - path: "resources/views/pdf/cash-receipt.blade.php"
      provides: "Receipt template without the footer block"
  key_links:
    - from: "InvoicePdfData::payment() facts list (unchanged flat list)"
      to: "resources/views/pdf/invoice.blade.php facts table"
      via: "array_chunk in Blade, two key/value pairs per <tr>"
      pattern: "array_chunk\\(\\$data->payment\\['facts'\\], 2\\)"
    - from: "InvoicePdfData::fromInvoice and CashReceiptPdfData::fromReceipt"
      to: "PdfParts::party()"
      via: "four-argument call, no contact switch"
      pattern: "PdfParts::party\\("
---

<objective>
Make the payment details block of the invoice and proforma PDF compact, so more line items fit on a page. Stop
printing the supplier's contact lines (e-mail, phone, website) on the invoice, proforma and cash receipt PDFs. Stop
printing the document footer on the cash receipt PDF.

Purpose: today the tinted payment box (88 pt QR in a 96 pt column, 16 pt padding, 24 pt top margin, six stacked fact
rows, then the card lead, button and URL stacked on three lines) takes about 230 pt of an A4 page. That pushes line
items onto a second page early. The supplier's contact lines take up to three more lines in the party block, and the
user no longer wants them, or the receipt footer, on the documents.

Output: a compacted payment box in `resources/views/pdf/invoice.blade.php`, `PdfParts::party()` without the contact
lines, the receipt without its footer, a corrected settings hint, and Pest tests for each change.

Source coverage (quick task, no CONTEXT.md or RESEARCH.md; user requests relayed by the orchestrator):
- GOAL "compact payment block, both invoice and proforma, all information needed to pay kept": Task 1.
- USER "no supplier e-mail/phone/website on the invoice/proforma PDF, snapshot and other places untouched": Task 2.
- USER "same contact lines removed from the cash receipt PDF": Task 2 (shared party builder), tested in Task 3.
- USER "no footer lines on the cash receipt PDF, invoice/proforma keep their footer": Task 3.
There are no D-XX decisions to honour. Prior UI-SPEC values (Phase 10 Surface H payment box: 24 pt margin, 16 pt
padding, 96 pt QR column with 4 pt padding, one-column facts; Phase 11: 12 pt gap, lead, button and URL stacked) are
superseded by this user request for spacing and layout only. The content of the box (Phase 10 fact list, Phase 14
D-01, Phase 15 D-02) is kept in full, so no fact row is dropped. Record this in the SUMMARY.

Planner choices (discretion):
- Condense, do not drop. The facts repeat parts of the sentence, but earlier phases fixed which facts print and many
  tests assert them. Two facts per row halves their height and keeps every label and value.
- The QR goes from 88 pt to 72 pt (about 25 mm) and the 4 pt white padding is removed, because the PNG from
  `QrPng` already has a white 4-module quiet zone (`quietzoneSize` 4, opaque). Do not go below 72 pt. A SPAYD
  payload is QR version 6 or 7 (41 to 45 modules), so a module stays at about 0.45 mm, which phone cameras read
  reliably.
- The credit note uses the same template and the same supplier party builder, so it also loses the contact lines.
  This is intended: one document family, one supplier block.
- The `notes` list of a party block keeps its shape (now zero or one entry, the registration note), so neither
  template and no PHPStan array shape needs a change for it.
</objective>

<execution_context>
@~/.claude/gsd-core/workflows/execute-plan.md
@~/.claude/gsd-core/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@.claude/CLAUDE.md
@resources/views/pdf/invoice.blade.php
@app/Domain/Invoicing/Pdf/PdfParts.php

Facts from the codebase that shape the plan:
- `InvoicePdfData::payment()` returns `facts` as a flat `list<array{key, value}>`. Transfer: account (when an
  account number exists), IBAN, BIC (when present), variable symbol, payment method, amount due. Online and cash:
  payment method and amount due. The data layer stays as it is. Only the template groups the facts into rows.
- `PdfParts::party()` is called in `InvoicePdfData::fromInvoice()` (supplier with the contact switch on, customer
  with it off) and in `CashReceiptPdfData::fromReceipt()` (received-by supplier on, received-from customer off).
  After this change neither PDF prints contacts, so the boolean contact parameter is removed. Its other caller is
  `tests/Feature/Invoicing/PdfEngineTest.php` (5 calls, plus the test "prints the contact notes only when asked").
- The receipt copies the invoice's `supplier_snapshot` and `appearance_snapshot` (`IssueCashReceipt`), and the
  appearance snapshot carries `footer` from `InvoicingSettings::document_footer`. Nothing in the snapshots changes.
- Existing assertions this plan must keep green, and does keep green because every label and value still prints:
  `tests/Feature/Invoicing/InvoicePdfTest.php`, `tests/Feature/Invoicing/InvoicePdfVariantsTest.php` ('Variabilní
  symbol', 'Způsob úhrady', 'Převodem', 'K úhradě', 'IBAN', the grouped IBAN, card texts),
  `tests/Feature/Payments/PayButtonOutputsTest.php` (lead, button label, URL, a `/URI` link annotation, and no
  `class="card-lead"` when the card is off), `tests/Feature/Invoicing/NonVatPayerTest.php`,
  `tests/Feature/Invoicing/CashReceiptPdfTest.php`. No existing test asserts the supplier e-mail, phone or website,
  or the receipt footer, in a rendered PDF.
- Fixture values (tests/Support/InvoiceFixtures.php::configureSupplier): supplier e-mail `exampleEmail('billing')`,
  website `https://www.example.com`, phone null, registration note 'Registered in the example register.'.
  `pdfSettings()` in InvoicePdfTest writes settings as the system and forgets scoped instances. CashReceiptPdfTest
  uses the same pattern inline (see `cashReceiptPdfConfigureSignature()`).

Project rules (CLAUDE.md):
- Fictional data only. Never copy anything from a real invoice (including the sample PDF the user mentioned, or the
  user's real footer wording) into code, tests, comments or the SUMMARY. Build phone-like values at runtime from
  fragments.
- No migration in this plan. Inside a worktree, run only `php -l` and the grep gates (first `<automated>` of each
  task). The Pest, Pint and PHPStan commands (second `<automated>`) run only after the merge into the HEAD branch, in
  DDEV. Their result is the verdict.
</context>

<tasks>

<task type="tracer">
  <name>Task 1: Tracer, a compact payment box rendered end-to-end for an issued invoice and proforma</name>
  <files>resources/views/pdf/invoice.blade.php, tests/Feature/Invoicing/InvoicePdfTest.php</files>
  <read_first>resources/views/pdf/invoice.blade.php (style block lines 63-74, payment markup lines 195-231), app/Domain/Invoicing/Pdf/QrPng.php, tests/Feature/Invoicing/InvoicePdfTest.php (helpers pdfImages and pdfSettings, the kind dataset at lines 94-112)</read_first>
  <behavior>
    - New test in tests/Feature/Invoicing/InvoicePdfTest.php named 'lays the payment facts out two to a row in a compact box', with a dataset 'invoice' => [InvoiceKind::Invoice], 'proforma' => [InvoiceKind::Proforma]. Issue `InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '1000.00')], $kind)` (CZK transfer, QR supported). Read `$facts = InvoicePdfData::fromInvoice($issued)->payment['facts']`, then extract the facts table from the HTML with a non-greedy `#<table class="facts">(.*?)</table>#s` match.
    - Expect `count($facts) >= 4`. The table holds exactly `intdiv(count($facts) + 1, 2)` rows (count of '<tr' in the match), which is fewer rows than facts. Every fact key and value appears inside the matched table.
    - Expect exactly one QR image (`pdfImages($html)` has count 1), the HTML contains 'width: 72pt; height: 72pt', and `QrDecode::payload` of the image starts with 'SPD*1.0*ACC:CZ'.
    - Add `use App\Domain\Invoicing\Pdf\InvoicePdfData;` to the imports.
  </behavior>
  <action>
    Rework only the payment box of resources/views/pdf/invoice.blade.php. Keep the data layer, the lang files and every printed string unchanged. Values (user request: compact block, all payment information kept):

    1. CSS `table.payment`: top margin 12pt (was 24). Keep width 100%, the tint background, the 2pt accent top border and `page-break-inside: avoid`. `table.payment td`: padding `10pt 12pt` (was 16pt), vertical-align top. `table.payment td.qr`: width 72pt, right padding 0.
    2. QR: `.qr-box` becomes a 72pt square with a white background and no padding. The PNG's own white 4-module quiet zone gives the margin, so the old 4pt padding is redundant. The `<img>` inline style becomes `width: 72pt; height: 72pt;`. Remove every trace of the 88-point square (box and image). Do not change `app/Domain/Invoicing/Pdf/QrPng.php`.
    3. `.sentence`: margin-bottom 4pt (was 8), line-height 1.4.
    4. Facts as a grid: `table.facts` gets width 100%. Replace the one-fact-per-row loop with a loop over `array_chunk($data->payment['facts'], 2)`. Each row prints, for every fact of the pair, a `td.key` (keep class `key`, width 64pt, padding-right 4pt, muted, 8pt) and a `td` with class `value` (white-space nowrap, so a grouped IBAN never breaks inside the value). Give the value cell of the first fact in a row the extra class `gap` via `@class(['value', 'gap' => $loop->first])`, with CSS `table.facts td.gap { padding-right: 12pt; }`. When a pair has only one fact, close the row with one empty cell spanning two columns (`colspan="2"`). Keep font 8pt and line-height 1.4. Keep every value escaped with double-brace Blade echo. Never use a raw (unescaped) echo.
    5. Card block on one row: replace the stacked `div.card-lead` and the separate `table.card-button` with one `table.card-row` (margin-top 6pt, cells vertical-align middle, padding 0). Its first cell is `<td class="card-lead">` (exactly this class attribute, 8pt, line-height 1.4, ink color, white-space nowrap, padding-right 8pt) holding the lead text. Its second cell is `<td class="card-button">` (accent background, border-radius 3pt, padding `4pt 12pt`) holding the same `<a href="{{ $data->cardPayment['url'] }}">` with the label (onAccent color, 9pt bold, no underline). The `<a href>` must stay, because it produces the `/URI` link annotation the tests check. Below the table, keep `div.card-url` with margin-top 2pt, font-size 7.5pt, line-height 1.3, muted, nowrap. Drop the old `table.card-button` CSS rules and add the `table.card-row` ones.
    6. Leave everything outside the payment box untouched: header, parties, dates, items, summary bar, printed note, credit notice and closing footer.

    Then add the test described in `<behavior>` to tests/Feature/Invoicing/InvoicePdfTest.php, placed after the existing kind-dataset test. Use only fixture data (fictional).

    Vertical budget for reference: the old box with the card block was 24 + 2 + 32 + about 174 = about 232 pt. The new one is about 12 + 2 + 20 + max(72, about 102) = about 136 pt, so about three more 29.5 pt line rows fit on the page.
  </action>
  <verify>
    Verification order (CLAUDE.md): the first `<automated>` (php -l and grep gates) is safe in a worktree. The second needs the shared database, so run it only after the merge into the HEAD branch, never inside a worktree.
    <automated>php -l tests/Feature/Invoicing/InvoicePdfTest.php && grep -qF "array_chunk(\$data->payment['facts'], 2)" resources/views/pdf/invoice.blade.php && grep -qF 'width: 72pt; height: 72pt' resources/views/pdf/invoice.blade.php && ! grep -qF 'height: 88pt' resources/views/pdf/invoice.blade.php && grep -qE 'table\.payment \{[^}]*margin-top: 12pt' resources/views/pdf/invoice.blade.php && grep -qE 'table\.payment td \{[^}]*padding: 10pt 12pt' resources/views/pdf/invoice.blade.php && grep -qF '<td class="card-lead">' resources/views/pdf/invoice.blade.php && grep -qF '<a href="{{ $data->cardPayment' resources/views/pdf/invoice.blade.php && ! grep -qF '{!!' resources/views/pdf/invoice.blade.php && grep -qF 'lays the payment facts out two to a row in a compact box' tests/Feature/Invoicing/InvoicePdfTest.php</automated>
    <automated>ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Invoicing/InvoicePdfTest.php tests/Feature/Invoicing/InvoicePdfVariantsTest.php tests/Feature/Payments/PayButtonOutputsTest.php tests/Feature/Invoicing/NonVatPayerTest.php</automated>
    <human-check>After the merge, in DDEV with fictional dev data, issue a CZK transfer invoice with card payment on and about 15 lines. Open its PDF and confirm three things: the payment box is visibly shorter (facts in two columns, lead and button on one line), more lines fit on page 1 than before, and the QR scans in a banking app with the right amount and variable symbol.</human-check>
  </verify>
  <done>The payment box of an invoice and a proforma prints all facts two to a row, a 72 pt QR that decodes to the SPAYD string, and the card lead and button on one row with the printed URL below. Every existing PDF assertion still passes after the merge, and the new test passes for both kinds.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Drop the supplier contact lines from every PDF party block</name>
  <files>app/Domain/Invoicing/Pdf/PdfParts.php, app/Domain/Invoicing/Pdf/InvoicePdfData.php, app/Domain/Invoicing/Pdf/CashReceiptPdfData.php, tests/Feature/Invoicing/PdfEngineTest.php, tests/Feature/Invoicing/InvoicePdfTest.php</files>
  <read_first>app/Domain/Invoicing/Pdf/PdfParts.php (party(), lines 19-72), tests/Feature/Invoicing/PdfEngineTest.php (lines 98-164 and the pdfEngineSnapshot() helper above them), app/Domain/Invoicing/Pdf/CashReceiptPdfData.php (lines 92-110)</read_first>
  <behavior>
    - PdfEngineTest: rename 'prints the contact notes only when asked' to 'prints the registration note and never the contact lines of a snapshot'. First assert that `pdfEngineSnapshot()` itself carries a non-null e-mail, phone and website, so the test is not vacuous. Then assert that `PdfParts::party('Supplier', pdfEngineSnapshot(), pdfEngineTranslator(), 'Not a VAT payer')` and `PdfParts::party('Customer', pdfEngineSnapshot(), pdfEngineTranslator(), null)` both give `notes === ['Registered at the example court']`, and that the supplier block keeps `vatNote === 'Not a VAT payer'`. The other four PdfEngineTest calls drop their trailing boolean argument, and their expectations stay unchanged.
    - InvoicePdfTest: new test 'prints no contact line of the supplier, but its name, address, ids and registration note' with the invoice/proforma kind dataset. Before issuing, set a supplier phone through `pdfSettings()`, assembled at runtime from fragments (for example `implode(' ', ['+420', '000', '000', '000'])`). Issue a one-line document of `$kind`. Expect the issued `supplier_snapshot` to hold non-null `email`, `phone` and `website` (the snapshot is untouched). Expect the HTML not to contain any of those three snapshot values. Expect the HTML to still contain 'Example Supplier s.r.o.', 'Sample Street 10', the snapshot's `company_id`, 'Registered in the example register.' and 'Dodavatel není plátce DPH.'.
  </behavior>
  <action>
    User request: the supplier's e-mail, phone and website must no longer print in the supplier block of the invoice and proforma PDF, nor of the cash receipt PDF. The snapshots, the settings, the e-mails and every other screen keep them.

    1. app/Domain/Invoicing/Pdf/PdfParts.php, `party()`: remove the fifth (boolean) parameter and the branch that appends the supplier's e-mail, phone and website keys to the notes. The notes loop reads only `registration_note`, so `notes` is a list of zero or one string. Keep the return array shape exactly (`label`, `name`, `lines`, `ids`, `notes`, `vatNote`). Update the method docblock: the notes hold the registration note only, and the e-mail, phone and website of a snapshot are never printed on a PDF, though they stay in the snapshot (user decision 2026-10-28). Update the class docblock if it mentions contacts.
    2. app/Domain/Invoicing/Pdf/InvoicePdfData.php: call `PdfParts::party()` with four arguments for the supplier and the customer, and change nothing else. This covers the invoice, the proforma and the credit note, which share the template.
    3. app/Domain/Invoicing/Pdf/CashReceiptPdfData.php: call `PdfParts::party()` with four arguments for received-from and received-by. Task 3 adds the receipt test and the footer change, so this task changes only these two calls in this file.
    4. Update tests/Feature/Invoicing/PdfEngineTest.php and add the InvoicePdfTest test exactly as in `<behavior>`. Use fictional values only.

    Do not touch the snapshot builders (`app/Domain/Invoicing/Support/InvoiceSnapshot.php`), the settings, the Filament supplier form or any e-mail template.
  </action>
  <verify>
    Verification order (CLAUDE.md): the first `<automated>` is safe in a worktree. Run the second only after the merge into the HEAD branch.
    <automated>php -l app/Domain/Invoicing/Pdf/PdfParts.php && php -l app/Domain/Invoicing/Pdf/InvoicePdfData.php && php -l app/Domain/Invoicing/Pdf/CashReceiptPdfData.php && php -l tests/Feature/Invoicing/PdfEngineTest.php && php -l tests/Feature/Invoicing/InvoicePdfTest.php && ! grep -qF 'withContact' app/Domain/Invoicing/Pdf/PdfParts.php && ! grep -qF "'email', 'phone', 'website'" app/Domain/Invoicing/Pdf/PdfParts.php && ! grep -rqE 'PdfParts::party\(.*, (true|false)\)' app tests/Feature/Invoicing/PdfEngineTest.php && grep -qF "registration_note" app/Domain/Invoicing/Pdf/PdfParts.php && grep -qF 'prints no contact line of the supplier' tests/Feature/Invoicing/InvoicePdfTest.php && grep -qF 'never the contact lines of a snapshot' tests/Feature/Invoicing/PdfEngineTest.php</automated>
    <automated>ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Invoicing/PdfEngineTest.php tests/Feature/Invoicing/InvoicePdfTest.php tests/Feature/Invoicing/InvoicePdfVariantsTest.php tests/Feature/Invoicing/IssuedHeaderEditTest.php</automated>
  </verify>
  <done>No PDF party block prints an e-mail, phone or website. The supplier block keeps its name, address, IDs, registration note and VAT note. The snapshots still hold the contact values. PdfEngineTest and the new InvoicePdfTest test pass after the merge.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: Cash receipt PDF without the document footer, and its tests</name>
  <files>app/Domain/Invoicing/Pdf/CashReceiptPdfData.php, resources/views/pdf/cash-receipt.blade.php, lang/cs/kokpit.php, tests/Feature/Invoicing/CashReceiptPdfTest.php</files>
  <read_first>app/Domain/Invoicing/Pdf/CashReceiptPdfData.php (class docblock lines 15-30, constructor lines 41-71, fromReceipt lines 92-115), resources/views/pdf/cash-receipt.blade.php (style rule at line 58, closing table lines 154-172), tests/Feature/Invoicing/CashReceiptPdfTest.php (imports, beforeEach, cashReceiptPdfConfigureSignature and cashReceiptPdfPay helpers, lines 1-104), lang/cs/kokpit.php (footer and footer_hint at lines 344-345)</read_first>
  <behavior>
    - New test in tests/Feature/Invoicing/CashReceiptPdfTest.php named 'prints neither the supplier contact lines nor the document footer, which stay in the snapshots'. Before paying, write the settings as the system with `app(PartnerContext::class)->runAsSystem(...)` followed by `app()->forgetScopedInstances()`, the way `cashReceiptPdfConfigureSignature()` does. Set `SupplierSettings::phone` to a fictional value assembled from fragments, and `InvoicingSettings::document_footer` to the fictional 'Example footer line of the receipt test.'. Then `[, $invoice, $receipt] = cashReceiptPdfPay();`.
    - Expect the receipt's `supplier_snapshot` to hold non-null `email`, `phone` and `website`, and `appearance_snapshot['footer']` to equal the footer text (data kept).
    - Expect `app(CashReceiptPdf::class)->html($receipt)` not to contain the e-mail, the phone, the website, the footer text or the string 'class="footer"'. Expect it still to contain 'Example Supplier s.r.o.', 'Registered in the example register.', 'Dodavatel není plátce DPH.' and `$receipt->number`.
    - Expect `app(InvoicePdf::class)->html($invoice)` to still contain the footer text (the invoice keeps its footer) and not to contain the supplier e-mail.
    - Add the imports `use App\Domain\Invoicing\Pdf\InvoicePdf;` and `use App\Domain\Settings\Settings\InvoicingSettings;`.
  </behavior>
  <action>
    User request: the cash receipt (PPD) PDF no longer prints the document footer lines. The invoice and proforma keep theirs. The footer stays in the receipt's appearance snapshot and in the settings. The Accounting Act content of the receipt (P15-08) does not include the footer, so the receipt stays complete.

    1. app/Domain/Invoicing/Pdf/CashReceiptPdfData.php: remove the footer constructor property, its `@param list<string>` docblock line and the named argument that builds it in `fromReceipt()`. Keep the shared footer splitter method in PdfParts, because the invoice still uses it. Extend the class docblock with one sentence: the receipt prints neither the supplier's e-mail, phone and website nor the document footer of the appearance snapshot, though both stay in the snapshots (user decision 2026-10-28).
    2. resources/views/pdf/cash-receipt.blade.php: in the closing table, delete the conditional block that prints the footer lines, and keep the issuer line and the signature cell as they are. Delete the now-unused footer CSS rule. Keep the `.note` and `.vat-note` rules, because the registration note and the VAT note still use them.
    3. lang/cs/kokpit.php: change `settings.invoicing.appearance.footer_hint` (line 345) to 'Tiskne se na konec faktur, zálohových faktur a dobropisů, například údaj o zápisu v rejstříku. Příjmový pokladní doklad ji netiskne.'. Change no other key.
    4. Add the CashReceiptPdfTest test exactly as in `<behavior>`. This test also covers the receipt half of Task 2 (contact lines). Use fictional values only, and do not reuse any real footer wording.
  </action>
  <verify>
    Verification order (CLAUDE.md): the first `<automated>` is safe in a worktree. The second (Pest, Pint and PHPStan in DDEV) runs only after the merge into the HEAD branch.
    <automated>php -l app/Domain/Invoicing/Pdf/CashReceiptPdfData.php && php -l lang/cs/kokpit.php && php -l tests/Feature/Invoicing/CashReceiptPdfTest.php && ! grep -qE 'public array \$footer|PdfParts::footer' app/Domain/Invoicing/Pdf/CashReceiptPdfData.php && ! grep -qF 'class="footer"' resources/views/pdf/cash-receipt.blade.php && ! grep -qF '$data->footer' resources/views/pdf/cash-receipt.blade.php && grep -qF 'PdfParts::footer' app/Domain/Invoicing/Pdf/InvoicePdfData.php && grep -qF 'class="footer"' resources/views/pdf/invoice.blade.php && grep -qF 'Příjmový pokladní doklad ji netiskne.' lang/cs/kokpit.php && ! grep -qF '{!!' resources/views/pdf/cash-receipt.blade.php && grep -qF 'prints neither the supplier contact lines nor the document footer' tests/Feature/Invoicing/CashReceiptPdfTest.php</automated>
    <automated>ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Invoicing/CashReceiptPdfTest.php tests/Feature/Invoicing/InvoicePdfTest.php tests/Feature/Invoicing/InvoicePdfVariantsTest.php tests/Feature/Invoicing/PdfEngineTest.php tests/Feature/Payments/PayButtonOutputsTest.php tests/Feature/Invoicing/NonVatPayerTest.php tests/Feature/Invoicing/UpdateIssuedDocumentTest.php tests/Feature/Invoicing/IssueDocumentTest.php tests/Feature/Operations/DocumentAppearanceSettingsTest.php && ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G</automated>
    <human-check>After the merge, record a fictional cash payment in DDEV and open the receipt PDF. Confirm that no supplier e-mail, phone or website and no footer lines appear, and that the issuer line and signature still print.</human-check>
  </verify>
  <done>The receipt PDF prints no footer lines and no supplier contact lines. The receipt snapshots still hold both, and the invoice PDF still prints its footer. The settings hint says where the footer prints. The listed Pest files, Pint and PHPStan are green after the merge.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| snapshot data -> PDF template | Admin-entered snapshot strings (supplier, customer, bank, footer) are placed into Blade templates and rendered by Dompdf |
| PDF -> recipient | The PDF leaves the system by e-mail and download; its payment details direct real money |

## STRIDE Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation Plan |
|-----------|----------|-----------|----------|-------------|-----------------|
| T-261028-odp-01 | Tampering | resources/views/pdf/invoice.blade.php, resources/views/pdf/cash-receipt.blade.php (new markup) | medium | mitigate | Every new cell prints with escaped double-brace Blade echo. The gates fail if a raw echo appears in either template, and the existing escaping test ('prints markup and a file reference in a line description as escaped text') stays green |
| T-261028-odp-02 | Denial of Service | QR in the payment box | low | mitigate | The QR keeps its own quiet zone and error correction M, and is not shrunk below 72 pt (about 25 mm). The Task 1 test decodes the rendered QR, and the human check scans it with a banking app |
| T-261028-odp-03 | Information Disclosure | PDF party blocks and receipt footer | low | accept | The change only removes printed data (contacts, receipt footer). No new field reaches a PDF, and the data sources (`InvoicePdfData`, `CashReceiptPdfData`) read the same snapshots as before |
| T-261028-odp-04 | Repudiation | Already issued documents re-rendered after the change | low | accept | Snapshots are untouched. A regenerated PDF of an older document gets the new layout and omits the contact lines and receipt footer, but its payment instructions, amounts, numbers and parties are unchanged |
| T-261028-odp-SC | Tampering | npm/pip/cargo installs | low | accept | This plan installs no package |
</threat_model>

<verification>
- Worktree (cheap, no DB): the first `<automated>` command of each task (php -l and grep gates) passes.
- After the merge into the HEAD branch (no migration needed for this plan):
  `ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Invoicing/InvoicePdfTest.php tests/Feature/Invoicing/InvoicePdfVariantsTest.php tests/Feature/Invoicing/PdfEngineTest.php tests/Feature/Invoicing/CashReceiptPdfTest.php tests/Feature/Payments/PayButtonOutputsTest.php tests/Feature/Invoicing/NonVatPayerTest.php tests/Feature/Invoicing/UpdateIssuedDocumentTest.php tests/Feature/Invoicing/IssueDocumentTest.php tests/Feature/Invoicing/IssuedHeaderEditTest.php tests/Feature/Operations/DocumentAppearanceSettingsTest.php` is green.
- After the merge: `ddev exec vendor/bin/pint --test` and `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` are green, and `ddev composer ci` is green.
- Before committing: `scripts/check-sensitive.sh` is clean (fictional data only).
- Human checks (end of the task): the shorter payment box and more lines on page 1, the QR scans, and the receipt without contacts and footer.
</verification>

<success_criteria>
- The invoice and proforma payment box is about 95 pt shorter with the card block (about 60 pt without it). It keeps the sentence, every fact, the QR and the card block.
- No invoice, proforma, credit-note or cash-receipt PDF prints the supplier's e-mail, phone or website. The snapshots still hold them.
- The cash receipt PDF prints no document footer. The invoice and proforma still do.
- All listed tests, Pint and PHPStan pass after the merge into the HEAD branch.
</success_criteria>

<output>
Create `.planning/quick/261028-odp-invoice-and-proforma-pdf-make-the-paymen/261028-odp-SUMMARY.md` when done. Record
there which Phase 10 and Phase 11 UI-SPEC layout values the compact box supersedes (24 pt margin, 16 pt padding, 96 pt
QR column with 4 pt padding, one-column facts, stacked card lead and button), and that the credit note also loses the
supplier contact lines because it shares the template.
</output>
