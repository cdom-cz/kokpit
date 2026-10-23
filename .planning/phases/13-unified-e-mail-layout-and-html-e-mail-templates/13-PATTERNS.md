# Phase 13: Unified e-mail layout and HTML e-mail templates - Pattern Map

**Mapped:** 2026-10-23
**Files analyzed:** 27 (new and modified)
**Analogs found:** 24 / 27 (3 rely on framework vendor views as the base, see No Analog Found)

All analog paths are git-tracked application source (`app/`, `database/`, `lang/`, `tests/`). No gitignored mirror paths are used. Fictional data only.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `app/Domain/Invoicing/Mail/InvoiceMail.php` (modify) | mailable | request-response | itself (`Content(text:)` -> `Content(markdown:, text:)`) | exact |
| `app/Domain/Invoicing/Mail/PaymentConfirmationMail.php` (new) | mailable | request-response | `app/Domain/Invoicing/Mail/InvoiceMail.php` | exact |
| `resources/views/mail/invoice.blade.php` (new) | view | transform | `resources/views/mail/invoice-text.blade.php` (+ RESEARCH Pattern 3) | role-match |
| `resources/views/mail/payment-confirmation.blade.php` + `-text.blade.php` (new) | view | transform | `resources/views/mail/invoice-text.blade.php` | role-match |
| `app/Domain/Invoicing/Jobs/SendPaymentConfirmation.php` (new) | job | event-driven | `app/Domain/Invoicing/Jobs/SendInvoiceEmail.php` | exact |
| `app/Domain/Invoicing/Models/PaymentConfirmation.php` (new) | model | CRUD | `app/Domain/Invoicing/Models/InvoiceEmail.php` | exact |
| `app/Domain/Invoicing/Enums/PaymentConfirmationState.php` (new) | enum | - | `app/Domain/Invoicing/Enums/InvoiceEmailState.php` | exact |
| `database/migrations/2026_10_25_000100_create_payment_confirmations_table.php` (new) | migration | CRUD | `database/migrations/2026_10_18_000500_create_card_payments_table.php` | exact |
| `app/Domain/Invoicing/Actions/RecordPayment.php` (modify `recordLocked`) | action | CRUD | itself (constructor collaborator `PaymentLinks`) | exact |
| `app/Domain/Payments/Support/PayLinkBlock.php` (modify, add public URL accessor) | utility | transform | itself | exact |
| `app/Domain/Settings/DocumentImage.php` (modify, add `contents()`) | service | file-I/O | its private `bytes()` / public `dataUri()` | exact |
| `app/Domain/Notifications/Mail/MailBrand.php` (new) | service | request-response | `app/Domain/Operations/Alerts/AdminAlerter.php` (runAsSystem) | role-match |
| `app/Domain/Notifications/Mail/EmbedMailLogo.php` (new) | listener | event-driven | listeners in `app/Providers/OperationsServiceProvider.php` | role-match |
| `app/Domain/Notifications/Mail/MailLogo.php` (new) | utility | file-I/O | `app/Domain/Settings/DocumentImage.php` (GD re-encode) | role-match |
| `app/Domain/Notifications/Mail/PlainTextHtml.php` (new) | utility | transform | RESEARCH Pattern 3 | no analog |
| `app/Domain/Notifications/Mail/MailText.php` (new) | utility | transform | `TaskNotification::escapeMarkdown` | exact (move) |
| `app/Domain/Clients/Notifications/PartnerInvitation.php` (modify) | notification | event-driven | itself | exact |
| `app/Domain/Identity/Notifications/ResetPasswordNotification.php` (new) + 2 call sites | notification | event-driven | `PartnerInvitation` + Filament `ResetPassword` | role-match |
| `app/Domain/Tasks/Notifications/TaskNotification.php` (modify) | notification | event-driven | itself | exact |
| `app/Domain/Operations/Alerts/OperationalAlert.php` (modify) | notification | event-driven | itself + TaskNotification escaping | exact |
| `resources/views/vendor/mail/**`, `vendor/notifications/email.blade.php`, `themes/kokpit.css` (published) | view/theme | transform | framework views (`laravel-mail`, `laravel-notifications` tags) | no tracked analog |
| `config/mail.php` (modify, `markdown` block) | config | - | `config/mail.php` existing keys | exact |
| Provider registration of `EmbedMailLogo` | provider | event-driven | `app/Providers/OperationsServiceProvider.php` | exact |
| `lang/cs/payment_mail.php`, `lang/en/payment_mail.php` (new) | translation | - | `lang/cs/invoice_mail.php`, `lang/en/invoice_mail.php` | exact |
| `lang/cs/kokpit.php` (modify, reviewed texts) | translation | - | itself (`invitations` block ~line 1444) | exact |
| Tests: `MailThemeTest`, `MailBrandTest`, `InvoiceMailHtmlTest`, `PaymentConfirmation{Mail,Trigger,Schema}Test` (new) | test | - | `tests/Feature/Invoicing/SendInvoiceEmailTest.php`, `tests/Isolation/NotificationMarkupTest.php` | role-match |
| Tests updated: `SendInvoiceEmailTest` (lines ~266, 768), `PartnerPasswordResetTest`, `tests/Arch/JobContractTest.php`, `tests/Arch/ModelDeclarationTest.php`, `tests/Isolation/CanaryRegistryTest.php` | test | - | themselves | exact |

## Pattern Assignments

### `app/Domain/Invoicing/Mail/PaymentConfirmationMail.php` and modified `InvoiceMail.php` (mailable)

**Analog:** `app/Domain/Invoicing/Mail/InvoiceMail.php` (lines 1-51)

**Core pattern** (lines 20-50): final Mailable, readonly constructor scalars, `Envelope(subject:)`, `Content`, `attachments()` from bytes.
```php
final class InvoiceMail extends Mailable
{
    public function __construct(
        public readonly string $mailSubject,
        public readonly string $plainText,
        public readonly array $files,
    ) {}

    public function envelope(): Envelope { return new Envelope(subject: $this->mailSubject); }

    public function content(): Content
    {
        return new Content(text: 'mail.invoice-text');   // becomes: new Content(markdown: 'mail.invoice', text: 'mail.invoice-text')
    }
}
```
Changes: add `->locale($language)` in the constructor, add `$payUrl` and `$payButton` constructor values (URL from the new public `PayLinkBlock` accessor), update the class docblock (lines 13-17 say "never HTML"). `PaymentConfirmationMail` copies the shape but has no `attachments()`, no `$files`, and a subject built from `payment_mail` keys. Unqueued: sent inside the job.

**View analog** `resources/views/mail/invoice-text.blade.php` is the single line `{!! $plainText !!}` and stays as the text part. New markdown views follow RESEARCH Pattern 3: flush-left (no 4-space indent), `{!! PlainTextHtml::paragraphs($plainText) !!}`, `<x-mail::button :url>`.

---

### `app/Domain/Invoicing/Jobs/SendPaymentConfirmation.php` (job, event-driven)

**Analog:** `app/Domain/Invoicing/Jobs/SendInvoiceEmail.php`

**Imports and attributes** (lines 18-33, 72-74):
```php
use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

#[Idempotent(how: 'identified by the row id; claimed by a conditional update from queued to sending ...')]
#[Timeout(120)]
final class SendInvoiceEmail extends KokpitJob
```

**UUID guard + claim + send** (lines 87-112):
```php
public function __construct(public readonly string $emailId) {}

public function handle(): void
{
    if (! Str::isUuid($this->emailId)) { return; }
    if (! $this->claim()) { return; }
    $row = InvoiceEmail::query()->findOrFail($this->emailId);
    try {
        Mail::to($row->recipient)->send(new InvoiceMail(...));
        $this->markSentAndLock($row);   // DO NOT copy: payment confirmation must never lock
```

**Conditional claim** (lines 149-157): copy the `where state = queued ... update(['state' => Sending, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()])` idiom, without the invoice-row `lockForUpdate` (the confirmation writes nothing to the invoice).

**Error handling** (lines 114-125): on throw, put the row back to `queued` with a conditional update, and rewrap `TransportExceptionInterface` as a fixed-message `RuntimeException` (provider text can name the recipient, T-10-65). Store only a fixed `failure_code`, never exception text. Add the class to `EXPECTED_APPLICATION_JOBS` in `tests/Arch/JobContractTest.php` (line ~46).

---

### `app/Domain/Invoicing/Models/PaymentConfirmation.php` (model, CRUD) and `PaymentConfirmationState` enum

**Analog:** `app/Domain/Invoicing/Models/InvoiceEmail.php` (lines 7-87) and `Enums/InvoiceEmailState.php`

```php
final class PaymentConfirmation extends KokpitModel implements PartnerIsolated
{
    use DeniesPartners;                       // Admin-only, a Partner reads no row
    protected $table = 'payment_confirmations';
    // nothing fillable: action and job use forceFill
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    protected function casts(): array { return ['state' => PaymentConfirmationState::class, 'attempts' => 'integer', 'sent_at' => 'immutable_datetime']; }
}
```
Rule from the analog docblock (lines 19-22): no `$touches` onto the parent invoice, because a locked invoice refuses even an `updated_at` write. Register in `App\Domain\Shared\Database\MorphMap`, in `tests/Arch/ModelDeclarationTest.php` (list around lines 76-90 and the `toContain` near line 146) and in `tests/Isolation/CanaryRegistryTest.php`.

---

### `database/migrations/2026_10_25_000100_create_payment_confirmations_table.php` (migration)

**Analog:** `database/migrations/2026_10_18_000500_create_card_payments_table.php`

**Header docblock + imports** (lines 5-30): `declare(strict_types=1)`, `return new class extends Migration`, a block comment explaining idempotence-by-constraint.

**Schema conventions** (lines 35-72):
```php
Schema::create('card_payments', function (Blueprint $table) {
    $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
    $table->foreignUuid('invoice_id')->nullable()->constrained('invoices');
    $table->timestampsTz();
    $table->unique('invoice_payment_id', 'card_payments_invoice_payment_unique');
    $table->index('invoice_id', 'card_payments_invoice_id_index');
});
DB::statement('ALTER TABLE card_payments ADD CONSTRAINT card_payments_invoice_payment_fk FOREIGN KEY (invoice_payment_id) REFERENCES invoice_payments (id) ON DELETE SET NULL');
DB::statement("ALTER TABLE card_payments ADD CONSTRAINT card_payments_type_check CHECK (type IN ('matched', ...))");
DB::statement("ALTER TABLE card_payments ADD CONSTRAINT card_payments_currency_check CHECK (currency ~ '^[A-Z]{3}\$')");
```
For the new table: `invoice_payment_id` NOT NULL with a named UNIQUE and FK `ON DELETE CASCADE` (RESEARCH Pattern 5, differs from the analog's SET NULL), `remaining_minor` check `>= 0`, `state` check in (queued, sending, sent, failed, skipped), `language` check in (cs, en). Named constraints as in the analog. A `down()` must drop the table.

---

### `app/Domain/Invoicing/Actions/RecordPayment.php` (modify `recordLocked`, lines 105-145)

**Analog:** itself. Hook after line 142 (`$this->paymentLinks->reconcile($locked);`), before `return $payment->refresh();`. Inject the new collaborator through the constructor like `PaymentLinks` (class is `final readonly`).
```php
// after $payment->save(); bookIncome(...); paymentLinks->reconcile($locked);
$this->confirmations->queueFor($locked, $payment);   // insert row + SendPaymentConfirmation::dispatch($row->id)->afterCommit()
```
Dispatch idiom to copy: `SendInvoiceEmail::dispatch($row->id)->afterCommit()` in `app/Domain/Invoicing/Actions/QueueInvoiceEmail.php`. Remaining amount: `InvoiceBalances::for([$id])` inside the transaction. `carry()` does not pass through `recordLocked()`, so carried proforma payments send nothing. The guard at lines 107-113 (transaction level, actor rule) must stay untouched.

---

### `app/Domain/Payments/Support/PayLinkBlock.php` (modify)

**Analog:** itself (lines 28-50). `applies()` and `forMail()` both call private `url()`. Add one public sibling (e.g. `forButton(Invoice): ?string`) that returns `self::url($invoice)`. Keep `applies()`/`forMail()` as the predicate; the docblock (lines 21-24) forbids reading `pay_token` elsewhere.

---

### `app/Domain/Settings/DocumentImage.php` (modify) and `MailLogo.php`

**Analog:** `DocumentImage` itself: `private function bytes(string $path): ?string` (line 124), `public function dataUri(string $path, string $sha256)` (line 110), `pathPattern(string $kind)` (line 51), GD re-encode in `reencode()` (line 187). Add a public `contents(string $path): ?string` that reuses the same path-pattern check. `MailLogo` downscales with GD (`imagescale`, keep alpha) and returns PNG bytes or null; copy the GD handling style from `reencode()`.

---

### `app/Domain/Notifications/Mail/MailBrand.php` (service) and `EmbedMailLogo.php` (listener)

**Analog for system-context read:** `app/Domain/Operations/Alerts/AdminAlerter.php` (`app(PartnerContext::class)->runAsSystem(...)`; also `RunsAsSystem` job middleware) and `tests/Support/Probes/SettingsReadingProbeJob.php` docblock (settings fail closed outside the system context). Whitelist only name, address lines, company ID, contacts, logo; never bank settings. Memoise per scoped instance.

**Listener registration analog:** `app/Providers/OperationsServiceProvider.php` (imports `Illuminate\Support\Facades\Event`, registers queue-event listeners in `boot()`). Add `Event::listen(MessageSending::class, EmbedMailLogo::class)` in a provider (domain folders are not auto-discovered). The listener returns nothing (a `false` would cancel the send). Code body: RESEARCH "The listener" section.

---

### `app/Domain/Notifications/Mail/MailText.php` (utility)

**Analog (move, do not duplicate):** `app/Domain/Tasks/Notifications/TaskNotification.php` lines 218-223:
```php
private static function escapeMarkdown(string $text): string
{
    $flat = (string) preg_replace('/\s+/u', ' ', $text);
    return (string) preg_replace('/([\\\\`*_\[\]()#|~!])/', '\\\\$1', $flat);
}
```
Usage sites: `$message->line(self::escapeMarkdown($line))` (line 106), `'> '.self::escapeMarkdown($this->excerpt)` (line 110), `array_map(self::escapeMarkdown(...), $values)` in the `TARGET_MAIL` branch (lines 185-191). Keep `TARGET_BELL` using `e($value)`. Existing canaries: `tests/Isolation/NotificationMarkupTest.php`.

---

### `PartnerInvitation.php`, `OperationalAlert.php`, `TaskNotification.php` (MailMessage notifications)

**PartnerInvitation** (lines 41-50): builds `subject/greeting/line/action/line/line`. Wrap `$this->inviteeName` and `$this->clientName` with `MailText::escapeMarkdown()` (Admin-typed). Keep scalars-only constructor, `afterCommit()`, queued. Fix the singular salutation vs plural-polite verb mismatch in `kokpit.invitations.mail.*`.

**OperationalAlert** (lines 50-66): keep `->error()`; the body loop `foreach (explode("\n", $this->body) as $line) { $message->line($line); }` becomes `$message->line(MailText::escapeMarkdown($line))`. It is deliberately not queueable (`sendNow`); do not add `ShouldQueue`. Because it is not queued it runs in the caller's context, but `MailBrand` still reads in system context.

**TaskNotification**: only the escape helper moves; `via()` preference logic stays untouched.

**Password reset:** two call sites build the notification with `app(ResetPasswordNotification::class, ['token' => $token])` and set `$notification->url`:
- `app/Filament/Pages/Auth/RequestPasswordReset.php` lines 56-57
- `app/Domain/Clients/Actions/SendPartnerPasswordReset.php` lines 50-51

Replace the class in both with a subclass of `Filament\Auth\Notifications\ResetPassword` overriding `buildMailMessage($url)`; update `Notification::sent(..., ResetPasswordNotification::class)` lookups in `PartnerPasswordResetTest`. New texts go under `kokpit.password_reset.mail.*`, not `lang/cs.json`.

---

### `lang/cs/payment_mail.php` and `lang/en/payment_mail.php`

**Analog:** `lang/en/invoice_mail.php` (header comment: "The keys are exactly those of lang/cs/invoice_mail.php; a test compares them"), arrays `subject`, `kind`, `body` with `:number`, `:amount`, `:due` placeholders and `"\n\n"` paragraph separators. Parity test: copy the check at `tests/Feature/Invoicing/SendInvoiceEmailTest.php:824`. Add the button label key to `invoice_mail` in both languages (cs "Zaplatit kartou", en "Pay by card"). Language-format helpers: `QueueInvoiceEmail::defaultBody()` (amount via `Money::ofMinor(...)->format($lang)`, dates `j. n. Y` cs, `Y-m-d` en).

---

### Published theme and `config/mail.php`

Publish with `ddev exec php artisan vendor:publish --tag=laravel-mail` and `--tag=laravel-notifications`. Add the explicit `markdown` block (`theme => 'kokpit'`, `paths => [resource_path('views/vendor/mail')]`, `extensions => []`) to `config/mail.php`. Customised views: `message.blade.php` (reads `MailBrand`), `footer.blade.php` (`{!! $slot !!}`, no `Markdown::parse`), `header.blade.php` (drop the "Laravel" branch), `themes/kokpit.css` (amber button, dark text, system fonts), `notifications/email.blade.php` (salutation default).

---

### Tests

**Analogs:** `tests/Feature/Invoicing/SendInvoiceEmailTest.php` (array transport `Mail::getSymfonyTransport()->messages()`, key-parity check at line ~824, assertions at ~266 and ~768 expecting no HTML body must change), `tests/Isolation/NotificationMarkupTest.php` (DOM-parse canaries for escaping), `tests/Support/InvoiceFixtures.php` (`configureSupplier()`, line ~101). Generate the fictional logo PNG at runtime with GD through `DocumentImage::store()`; commit no binary. Suspect tests for extra mail after a payment: `CardPaymentJourneyTest`, `RefundEventTest`, `PaymentAlertsTest`, `CreatePaymentLinkTest`, `SendInvoiceEmailTest`; filter by recipient/subject rather than counting.

## Shared Patterns

### System-context settings read
**Source:** `PartnerContext::runAsSystem()` (as used by `AdminAlerter` and the `RunsAsSystem` job middleware)
**Apply to:** `MailBrand` only; every layout view reads the brand through it, never `SupplierSettings` directly. Queued notifications do not run as system.

### Idempotence by DB constraint plus conditional claim
**Source:** `SendInvoiceEmail::claim()` (lines 136-179) and `CardPayment::recordOnce()` (ON CONFLICT DO NOTHING against unique indexes)
**Apply to:** `payment_confirmations` migration, `SendPaymentConfirmation`.

### Never lock from a non-invoice e-mail
**Source:** `SendInvoiceEmail::markSentAndLock()` (line 189) and `tests/Arch/InvoiceUnlockPathTest.php` (allowlist ~line 63)
**Apply to:** `SendPaymentConfirmation`; add an arch assertion that it references neither `InvoiceLockState` nor `InvoiceAudit::locked`.

### Admin-only models
**Source:** `InvoiceEmail` (`use DeniesPartners`, `implements PartnerIsolated`, `forceFill` only)
**Apply to:** `PaymentConfirmation`.

### Escaping per target
**Source:** `TaskNotification::escapeMarkdown` (Markdown lines) and `e()` (raw HTML blocks)
**Apply to:** `PartnerInvitation`, `OperationalAlert`, `TaskNotification`, `PlainTextHtml`.

### Fixed failure classes, no provider text
**Source:** `SendInvoiceEmail` constants `FAILURE_*` and `TRANSPORT_FAILED` (lines 76-85, 124)
**Apply to:** `SendPaymentConfirmation`.

### Fictional-data rule
**Apply to:** all fixtures, tests, lang examples; use `example.com`, company ID `12345678`; run `scripts/check-sensitive.sh` before commit.

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `app/Domain/Notifications/Mail/PlainTextHtml.php` | utility | transform | Nothing like it exists; use RESEARCH Pattern 3 (verified by running it). |
| Published `resources/views/vendor/mail/**` and `vendor/notifications/email.blade.php` | view | transform | No theme is published yet (`resources/views/mail/` holds only `invoice-text.blade.php`); base on the framework views via `vendor:publish`. |
| `resources/views/vendor/mail/html/themes/kokpit.css` | theme | transform | No CSS analog in mail; start from the framework `default.css`. |

## Metadata

**Analog search scope:** `app/Domain/Invoicing`, `app/Domain/Clients`, `app/Domain/Tasks`, `app/Domain/Operations`, `app/Domain/Settings`, `app/Domain/Payments`, `app/Providers`, `app/Filament/Pages/Auth`, `database/migrations`, `lang/`, `resources/views/mail`, `tests/Arch`, `tests/Isolation`
**Files scanned:** about 25 read in part or full
**Pattern extraction date:** 2026-10-23
