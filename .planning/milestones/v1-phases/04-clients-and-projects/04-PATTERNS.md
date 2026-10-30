# Phase 4: Clients and Projects - Pattern Map

**Mapped:** 2026-10-08
**Files analyzed:** 78 new/modified files (grouped into 12 slices)
**Analogs found:** 62 exact or role-match / 78 (16 are greenfield or covered only by RESEARCH.md patterns, listed at the end)

All analog paths are git-tracked (listed by `git ls-files`). Paths are repo-relative. Line numbers refer to the state at the start of Phase 4. Section references such as "RESEARCH Pattern 3" point to `04-RESEARCH.md`.

Important repository facts the planner must not miss:

- There is **no model with real columns yet** other than `User`. All "model" analogs for Money pairs, SoftDeletes, LoggedAttributes and policies are either the test probes (`tests/Support/Probes/*`) or the package-model subclasses. The probes are the closest tracked analogs; copy their shape, not their `$guarded = []` (Pitfall 9 in RESEARCH: use `#[Fillable]`).
- There is **no `database/factories/` entry besides `UserFactory`**. `Client`, `Project` (and optionally `Contact`) factories follow `UserFactory`'s `protected $model` + model `newFactory()` override style.
- `app/Filament/Resources/` today holds only `ActivityResource` (read-only). There is **no CRUD resource analog**; `SettingsPage` (form + save) and `ActivityResource` (table, filters, access trait) are the partial analogs.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `database/migrations/2026_10_08_*_create_clients_table.php` | migration | CRUD | `database/migrations/2026_10_07_000100_create_number_sequences_table.php` | exact |
| `database/migrations/*_create_contacts_table.php` | migration | CRUD | same | exact |
| `database/migrations/*_create_projects_table.php` | migration | CRUD | same | exact |
| `database/migrations/*_create_project_billing_table.php` | migration | CRUD | same + `Tests/Support/Probes/MoneyProbe.php` lines 45-52 (Money pair) | exact |
| `database/migrations/*_create_client_invitations_table.php` | migration | CRUD | same | exact |
| `database/migrations/*_add_client_fk_and_deactivation_to_users.php` | migration (ALTER) | CRUD | `database/migrations/2026_10_08_000200_add_source_to_activity_log_table.php` | exact |
| `database/settings/*_add_default_invoice_language.php` | settings migration | CRUD | `database/settings/2026_10_08_000120_create_defaults_settings.php` | exact |
| `app/Domain/Clients/Models/Client.php` | model | CRUD | `tests/Support/Probes/ActivityProbe.php` + `MoneyProbe.php` + `app/Domain/Shared/Models/Tag.php` | role-match |
| `app/Domain/Clients/Models/Contact.php` | model | CRUD | same | role-match |
| `app/Domain/Clients/Models/ClientInvitation.php` | model | CRUD | same | role-match |
| `app/Domain/Projects/Models/Project.php` | model | CRUD | same (plus RESEARCH Pattern 1 scope) | role-match |
| `app/Domain/Projects/Models/ProjectBilling.php` | model | CRUD | `MoneyProbe.php` (casts) + `DeniesPartners` | role-match |
| `app/Domain/Clients/Enums/{ClientStage,InvoiceLanguage,InvitationState}.php`, `app/Domain/Projects/Enums/{ProjectStatus,ProjectPriority,BillingType}.php`, shared `TagType` enum | enum | transform | `app/Domain/Settings/VatMode.php` | exact |
| `app/Domain/Clients/Policies` / `app/Domain/Projects/Policies/ProjectPolicy.php` | policy | request-response | `app/Domain/Shared/Policies/AdminOnlyPolicy.php` + `KokpitPolicy.php` | exact |
| `app/Domain/Identity/Policies/UserPolicy.php` (or `AdminOnlyPolicy` registration for `User`) | policy | request-response | `AdminOnlyPolicy.php` + `AccessServiceProvider.php` lines 31-38 | exact |
| `app/Domain/Shared/Models/Tag.php` (scope change) | model (modified) | CRUD | itself (lines 21-24) + RESEARCH Pattern 2 | exact |
| `app/Domain/Shared/Database/MorphMap.php` (5 lines) | config (modified) | n/a | itself (lines 26-35) | exact |
| `app/Domain/Shared/Auth/Audience.php` (`Guest`) + `AccessRules.php` | enum/service (modified) | request-response | itself (`AccessRules.php` lines 47-50 `match`) | exact |
| `app/Domain/Identity/Models/User.php` (`canAccessPanel`, `deactivated_at` cast) | model (modified) | request-response | itself (lines 52-67) | exact |
| `app/Domain/Clients/Ares/{AresClient,AresCompany,AresFailure,AresLookupFailed}.php` | service (HTTP adapter) | request-response | none for HTTP; `app/Domain/Settings/Banking/Iban.php` for a pure-validator + value-object style | partial |
| `app/Domain/Clients/Ares/CompanyId.php` + `CompanyIdRule` | utility / rule | transform | `app/Domain/Settings/Banking/Iban.php`, `app/Domain/Settings/Rules/IbanRule.php` | role-match |
| `app/Domain/Projects/ProjectKeySuggester.php` | service (pure) | transform | `app/Domain/Settings/Numbering/NumberPattern.php` | role-match |
| `app/Domain/Clients/Actions/{Create,Update,Archive,Restore}Client`, `SetPrimaryContact`, `Create/UpdateProject` | action | CRUD | `app/Domain/Shared/Sequences/SequenceAllocator.php` (transaction style) + `app/Console/Commands/InstallCommand.php` (create user in `runAsSystem`) | partial |
| `app/Domain/Clients/Actions/{InvitePartner,ResendInvitation,RevokeInvitation,AcceptInvitation}` | action | request-response | `InstallCommand.php` lines 33-110 | role-match |
| `app/Domain/Clients/Actions/{Deactivate,Reactivate}PartnerAccount`, `SendPartnerPasswordReset` | action | request-response | none (RESEARCH Pattern 4 code) | no analog |
| `app/Domain/Clients/Notifications/PartnerInvitation.php` | notification (queued) | event-driven | `app/Domain/Operations/Alerts/OperationalAlert.php` | role-match |
| `app/Filament/Resources/ClientResource.php` (+ `Pages/*`) | resource | CRUD | `app/Filament/Resources/ActivityResource.php` + `app/Filament/Pages/SettingsPage.php` | partial |
| `app/Filament/Resources/ClientResource/RelationManagers/{Contacts,PartnerAccounts,Invitations}RelationManager.php` | relation manager | CRUD | `tests/Support/Filament/Fixtures/AdminOnlyRelationManager.php` + `EnforcesRelationManagerAccessRule.php` | partial |
| `app/Filament/RelationManagers/{Client,Project}HistoryRelationManager.php` | relation manager | CRUD (read-only) | docblock sample in `ActivityHistoryRelationManager.php` lines 19-23; fixture `tests/Support/Filament/Fixtures/ProbeActivityHistoryRelationManager.php` | exact |
| `app/Filament/Resources/ProjectResource.php` (+ `Pages/*`) | resource | CRUD | same as `ClientResource` | partial |
| `app/Filament/Partner/Resources/PartnerProjectResource.php` (+ index, view) | resource (read-only) | CRUD | `tests/Support/Filament/CanaryRecordResource.php` (+ `Pages/ListCanaryRecords.php`, `ViewCanaryRecord.php`) | role-match |
| `app/Filament/Pages/Auth/AcceptInvitation.php` | page (guest SimplePage) | request-response | `app/Filament/Pages/SettingsPage.php` for the form shape; `vendor` `Register` per RESEARCH | partial |
| `app/Providers/Filament/AdminPanelProvider.php` (second `discoverResources`, `routes()` hook) | provider (modified) | request-response | itself lines 70-71 | exact |
| `app/Providers/AccessServiceProvider.php` (register policies) | provider (modified) | n/a | itself lines 31-38 | exact |
| `app/Providers/AppServiceProvider.php` or a new provider (RateLimiter `invitation`) | provider (modified) | n/a | no analog | no analog |
| `config/kokpit.php` (`invitations.ttl_days`) | config (modified) | n/a | itself lines 50-53 (`alerts`) | exact |
| `config/services.php` (`ares.base_url`) | config (modified) | n/a | itself (tail of file) | exact |
| `app/Domain/Settings/Settings/DefaultsSettings.php` (+ `default_invoice_language`) | settings (modified) | CRUD | itself | exact |
| `app/Filament/Pages/SettingsPage.php` `defaultsTab()` (+ field) | page (modified) | request-response | itself lines 440-472 | exact |
| `lang/cs/kokpit.php`, `lang/cs/enums.php` | config (modified) | n/a | themselves | exact |
| `database/factories/{Client,Project,Contact}Factory.php` | factory | CRUD | `database/factories/UserFactory.php` | exact |
| `tests/Support/Canary.php` (`twoClients()` real clients) | test support (modified) | CRUD | itself lines 37-45 | exact |
| `tests/Support/CanaryRegistry.php` (5 fixtures, `Tag` re-point) | test support (modified) | CRUD | itself lines 59-108 | exact |
| `tests/Arch/ModelDeclarationTest.php`, `tests/Isolation/CanaryRegistryTest.php`, `tests/Arch/ActivityAllowlistTest.php` (list updates) | test (modified) | transform | themselves | exact |
| `tests/Isolation/DeniedModelsTest.php`, `RouteWalkTest.php`, `PanelAccessTest.php` (wording / Partner walk) | test (modified) | request-response | themselves | exact |
| `tests/Arch/PanelRegistryTest.php` (Guest assertion) | test (modified) | transform | itself lines 102-171 | exact |
| `tests/Feature/Schema/*` (new `ClientTablesTest`, column allowlist) | test | transform | `tests/Feature/Schema/KeysAndTimestampsTest.php`, `SchemaConventionsTest.php` | exact |
| `tests/Feature/Clients/*`, `tests/Feature/Projects/*`, `tests/Unit/Ares/*`, `tests/Unit/Projects/*` | test | mixed | `tests/Feature/Operations/SettingsPageTest.php`, `tests/Unit/Settings/IbanTest.php` | role-match |
| `tests/Isolation/PartnerProjectVisibilityTest.php`, `PartnerSafeColumnsTest.php` | test | request-response | `tests/Isolation/FailClosedScopeTest.php`, `CanaryRegistryTest.php` | role-match |
| `tests/Feature/Operations/SettingsGroupsTest.php`, `SettingsPageTest.php` (invoice language) | test (modified) | CRUD | themselves lines 36-53 / 181-197 | exact |

## Pattern Assignments

### Migrations (all new tables)

**Analog:** `database/migrations/2026_10_07_000100_create_number_sequences_table.php` (lines 19-34) and the users migration.

UUID v7 default key, `timestampsTz()`, raw-SQL CHECKs, no `down()` logic beyond a drop:
```php
Schema::create('number_sequences', function (Blueprint $table) {
    $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
    $table->string('scope_key', 191)->unique();
    $table->bigInteger('next_value')->default(1);
    $table->timestampsTz();
});
DB::statement('ALTER TABLE number_sequences ADD CONSTRAINT number_sequences_next_value_check CHECK (next_value >= 1)');
```
Money column pair convention (`tests/Support/Probes/MoneyProbe.php` lines 45-52):
```php
$table->bigInteger('amount_minor')->nullable();
$table->char('amount_currency', 3)->nullable();
DB::statement("ALTER TABLE money_probes ADD CONSTRAINT money_probes_amount_currency_check CHECK (amount_currency ~ '^[A-Z]{3}$')");
```
Apply: `clients.hourly_rate_minor/_currency` (NOT NULL, plus `CHECK (hourly_rate_currency = currency)`), `project_billing.hourly_rate_*`, `fixed_price_*` (both-null-or-both-set CHECK). Enum columns are `string` + `CHECK (col IN (...))` (no precedent yet; first enum columns). Partial unique indexes via `DB::statement('CREATE UNIQUE INDEX ... WHERE ...')` (for example `contacts_one_primary_per_client`, `clients (country, company_id) WHERE company_id IS NOT NULL` without a `deleted_at` predicate, `client_invitations (email) WHERE accepted_at IS NULL AND revoked_at IS NULL`). Foreign keys: `foreignUuid(...)->constrained()->restrictOnDelete()`.

Schema-rule constraints the migrations must satisfy (`tests/Support/PgSchema.php` rules R1-R6, R9): every `id`/`*_id` column is `uuid`, no identity/`nextval`, `timestamptz` only (use `timestampTz` for `deleted_at`, `expires_at`, `accepted_at`, `revoked_at`, `last_sent_at`, `deactivated_at`), single-column uuid PK defaults to `uuidv7()` (hence `project_billing` has its own `id`, not `project_id` as PK).

**`users` ALTER migration.** Analog `2026_10_08_000200_add_source_to_activity_log_table.php` (`ALTER TABLE ... ADD CONSTRAINT` style). The `client_id` column already exists with a comment "No foreign key until the clients table exists in Phase 4" (`0001_01_01_000000_create_users_table.php` lines 24-26). Add: `deactivated_at timestamptz null`, `ALTER TABLE users ADD CONSTRAINT users_client_id_foreign FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE RESTRICT`, `CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))`. Migration timestamp must sort after the `clients` migration. Note `sessions.user_id` and other `*_id` columns are unaffected.

---

### `app/Domain/Clients/Models/Client.php` and siblings (model, CRUD)

**Analogs (no real model exists yet):**
- Isolation declaration: `app/Domain/Shared/Models/Tag.php` lines 5-24
- Allowlist: `tests/Support/Probes/ActivityProbe.php` lines 7-33
- Money cast: `tests/Support/Probes/MoneyProbe.php` lines 29-38
- Base class: `app/Domain/Shared/Models/KokpitModel.php` (HasUuids; docblock lines 10-22 states the conventions)

**Isolation declaration** (`Tag.php`):
```php
class Tag extends BaseTag implements PartnerIsolated
{
    use DeniesPartners, HasUuids;
}
```
For Admin-only models (`Client`, `Contact`, `ProjectBilling`, `ClientInvitation`): `final class Client extends KokpitModel implements PartnerIsolated { use DeniesPartners, SoftDeletes, LogsAllowlistedActivity; }`. For `Project`: `implements PartnerIsolated` + `use IsolatesPartners` with a real `constrainForPartner` (RESEARCH Pattern 1 code block: `client_id`, `client_visible = true`, whereExists non-archived client). `DeniesPartners` already `use`s `IsolatesPartners` (`DeniesPartners.php` line 21), so never use both.

**Allowlist declaration** (`ActivityProbe.php` lines 30-33):
```php
#[LoggedAttributes(['title', 'status'])]
final class ActivityProbe extends KokpitModel
{
    use LogsAllowlistedActivity;
```
Apply with the lists in RESEARCH Pattern 6 "Activity log allowlists". Rules enforced by `tests/Support/AuditDeclaration.php` (a-e; sensitive pattern at line 30 `SENSITIVE_NAME`): names must be real columns, none matching password/secret/token patterns, never `token_hash`. Attributes are not inherited, so declare on every concrete model. The log name equals the morph alias (`LogsAllowlistedActivity.php` line 41), so each alias also needs `kokpit.activity.subjects.*` Czech labels (`lang/cs/kokpit.php` line 203 is an empty array today).

**Money cast** (`MoneyProbe.php` lines 29-38): `protected function casts(): array { return ['hourly_rate' => MoneyCast::class]; }` over the `hourly_rate_minor` + `hourly_rate_currency` pair (`MoneyCast.php` lines 25-47 reads `{key}_minor`/`{key}_currency`; one null and one set throws). Beware (RESEARCH Pattern 7): the virtual `hourly_rate` attribute is not in `attributesToArray()`, so do not use Filament `->relationship()` for it; use the Actions.

**Mass assignment** (`User.php` lines 39-41, Pitfall 9):
```php
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
#[NotPartnerScoped(reason: '...')]
```
Use `#[Fillable([...])]` listing only user-editable columns. `client_id`, `deactivated_at`, `accepted_*`, `token_hash` are set with `forceFill` or the relation. `ModelConventionsServiceProvider.php` line 40 silently discards in production but throws in tests.

**Policy registration:** `#[UsePolicy(AdminOnlyPolicy::class)]` on the model (RESEARCH Pattern 1 table) or `Gate::policy` in `AccessServiceProvider::boot()` (lines 35-37 loop). `Project` gets `ProjectPolicy extends KokpitPolicy`.

**Soft-delete tag guard** (RESEARCH Pattern 2): `Client` and `Project` use `HasTags { detachTags as private detachTagsFromTrait; }` plus the `trashed() && ! isForceDeleting()` override. No analog in repo; copy the RESEARCH block. Needs a test (archive keeps `taggables` rows).

**Tag model change** (`Tag.php`): replace `use DeniesPartners, HasUuids;` by `use IsolatesPartners, HasUuids;` and add `constrainForPartner` from RESEARCH Pattern 2. Update the class docblock (lines 18-19 currently say Admin-only). Keep `#[AdminOnlyPolicy]` registration (`AccessServiceProvider.php` line 35).

**Morph map** (`MorphMap.php` lines 26-35): append `'client' => Client::class, 'contact' => Contact::class, 'project' => Project::class, 'project_billing' => ProjectBilling::class, 'client_invitation' => ClientInvitation::class,` and the five `use` imports (alphabetical). Enforced map: R9 and `tests/Feature/Schema/MorphMapTest.php`.

---

### Enums (`ClientStage`, `InvoiceLanguage`, `InvitationState`, `ProjectStatus`, `ProjectPriority`, `BillingType`, `TagType`)

**Analog:** `app/Domain/Settings/VatMode.php` lines 14-28
```php
enum VatMode: string implements HasLabel
{
    case NonPayer = 'non_payer';
    case Payer = 'payer';

    public function getLabel(): string
    {
        return __('enums.vat_mode.'.$this->value);
    }
}
```
Add one group per enum in `lang/cs/enums.php` (group name = snake_case enum, as in `lang/cs/enums.php` lines 7-29: `role_name`, `vat_mode`, ...). `tests/Feature/Localisation/EnumLabelsTest.php` scans every `HasLabel` enum under `app/` and fails on a missing label. New groups: `client_stage`, `invoice_language`, `invitation_state`, `project_status`, `project_priority`, `billing_type`. D-16 status labels: Plánovaný, K upřesnění, V realizaci, Ke kontrole, K vypuštění, Dokončeno. DB CHECK values must equal enum values.

---

### `app/Domain/Shared/Auth/Audience.php` + `AccessRules.php` (add `Guest`)

**Analog:** itself. `Audience.php` lines 11-15 (two cases) and `AccessRules.php` lines 47-50:
```php
return match ($rule->audience) {
    Audience::AdminOnly => $context->isAdmin(),
    Audience::PartnerAllowed => $context->isAdmin() || $context->partnerClientId() !== null,
};
```
Add `case Guest = 'guest';` and `Audience::Guest => false,` (fail-closed; the page uses no `Enforces*` trait). The `match` is exhaustive, so PHPStan flags a missing arm. Add one arch assertion to `tests/Arch/PanelRegistryTest.php` (near lines 167-171): `Guest` appears only on `SimplePage` subclasses not in `$panel->getPages()`.

---

### `app/Domain/Identity/Models/User.php` (modify `canAccessPanel`, `deactivated_at`)

**Analog:** itself, lines 52-67. Current code:
```php
protected function casts(): array
{
    return ['email_verified_at' => 'datetime', 'password' => 'hashed'];
}
public function canAccessPanel(Panel $panel): bool
{
    return $this->hasAnyRole([RoleName::Admin->value, RoleName::Partner->value]);
}
```
Add `'deactivated_at' => 'datetime'` and the extended method from RESEARCH "Code Examples". The Partner branch must wrap the `Client` read in `app(PartnerContext::class)->runAsSystem(...)` (`PartnerContext.php` line 72) because `Client` is `DeniesPartners`. Add `@property \Carbon\CarbonImmutable|null $deactivated_at`. `deactivated_at` is deliberately not fillable (docblock on lines 28-30 states the rule for `client_id`).

---

### `app/Domain/Clients/Actions/*` and `app/Domain/Projects/Actions/*` (action, CRUD / request-response)

No Actions directory exists; first Actions in the repo. Closest analogs:
- Explicit system run + transaction + no default secrets: `app/Console/Commands/InstallCommand.php` lines 33-45 (`return app(PartnerContext::class)->runAsSystem(fn (): int => $this->install());`) and the password rule lines 36 / 88: `private const int PASSWORD_MAX_BYTES = 72;` and `Password::min(12)`.
- Transaction/lock style: `app/Domain/Shared/Sequences/SequenceAllocator.php` (row lock, no reliance on Filament callbacks).

Conventions to copy:
```php
// AcceptInvitation is the one sanctioned guest system run (RESEARCH Pattern 3)
app(PartnerContext::class)->runAsSystem(fn () => DB::transaction(function () use (...) {
    $invitation = ClientInvitation::query()->whereKey($id)->lockForUpdate()->first();
    // re-check pending, not expired, hash_equals(token_hash, hash('sha256', $token))
    $user = User::query()->create(['name' => $name, 'email' => $invitation->email, 'password' => $password]);
    $user->forceFill(['client_id' => $invitation->client_id, 'email_verified_at' => now()])->save();
    $user->assignRole(RoleName::Partner->value);
    $invitation->forceFill(['accepted_at' => now(), 'accepted_user_id' => $user->id])->save();
}));
```
`RoleName::Partner` is `'partner'` (`app/Domain/Identity/RoleName.php` line 17). Catch `Illuminate\Database\UniqueConstraintViolationException` and rethrow as `ValidationException::withMessages(['data.key' => ...])` for the project key and company id (RESEARCH Pattern 8). Business rules live here, never in Filament callbacks or observers. Deactivation Action code: RESEARCH "Deactivation Action" (`forceFill` + `$user->tokens()->delete()`).

**Analog gap:** no Actions exist; `DeactivatePartnerAccount`, `ReactivatePartnerAccount`, `SendPartnerPasswordReset` use RESEARCH Pattern 4 code only.

---

### `app/Domain/Clients/Notifications/PartnerInvitation.php` (notification, event-driven)

**Analog:** `app/Domain/Operations/Alerts/OperationalAlert.php` lines 5-50: scalar constructor, `via()`, `toMail()` with `MailMessage`:
```php
final class OperationalAlert extends Notification
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $actionUrl = null,
    ) {}
    public function via(object $notifiable): array { return ['mail', 'database']; }
    public function toMail(object $notifiable): MailMessage { $message = (new MailMessage)->error()->subject($this->title) ...
```
Differences: this one is `implements ShouldQueue` (OperationalAlert deliberately is not, docblock lines 14-18), `via()` returns `['mail']`, sent with `Notification::route('mail', $email)->notify(...)` to an on-demand address, constructor takes only strings and a timestamp (a queued job re-fetches models under a fail-closed scope). Not `Dispatchable`, so `tests/Arch/JobContractTest.php` does not apply. Test probe for notification shape: `tests/Support/Probes/ProbeNotification.php`.

---

### `app/Domain/Clients/Ares/*` (service, request-response)

**Analog gap for the HTTP adapter:** no HTTP client exists in the repo. Use RESEARCH "ARES HTTP call skeleton" (with the mandatory `retry(..., when:)` callback) and `config('services.ares.base_url')` (add to `config/services.php`; the file's pattern is a flat array of provider arrays).

Style analogs for pure validators and value objects: `app/Domain/Settings/Banking/Iban.php` and `app/Domain/Settings/Rules/IbanRule.php` (static `isValid`, rule class wrapping it, unit-tested by `tests/Unit/Settings/IbanTest.php`). Copy that split for `CompanyId::isValid()` (RESEARCH "Mod-11 company id check") and `CompanyIdRule`. The DTO is a `final readonly class AresCompany` with `fromResponse(array)`; no `spatie/laravel-data`.

Test constraints: `Http::fake` plus `Http::preventStrayRequests()` in a Feature `beforeEach` (add to `tests/Pest.php` or a Feature `beforeEach`; `tests/Pest.php` lines 17-22 is where suites are wired). Checksum-valid fictional ids must be built at runtime from fragments; the placeholder `12345678` is checksum-invalid and serves as the "fails locally, no HTTP call" fixture. Never paste a recorded real ARES response.

---

### `app/Domain/Projects/ProjectKeySuggester.php` (service, pure transform)

**Analog:** `app/Domain/Settings/Numbering/NumberPattern.php` (pure, no framework dependency, unit-tested in `tests/Unit/Numbering/NumberPatternTest.php`). Signature `suggest(string $name, Closure $isTaken): string` per RESEARCH Pattern 8; `Str::ascii` for diacritics; the `$isTaken` closure uses `Project::withTrashed()` (argument-free `withoutGlobalScopes()` is flagged by `QueryEscapeHatchTest`).

---

### `app/Filament/Resources/ClientResource.php`, `ProjectResource.php` (resource, CRUD)

**Analogs:** `app/Filament/Resources/ActivityResource.php` (declaration + trait + navigation + table; lines 34-87) and `app/Filament/Pages/SettingsPage.php` (form components, `Money::fromMajor` parsing, imports lines 7-50).

**Declaration + access trait** (`ActivityResource.php` lines 34-39):
```php
#[AccessRule(Audience::AdminOnly, reason: 'The audit trail shows who changed what; a Partner never sees it.')]
final class ActivityResource extends Resource
{
    use EnforcesResourceAccessRule;

    protected static ?string $model = Activity::class;
```
`EnforcesResourceAccessRule::canAccess()` = `AccessRules::allows(static::class) && parent::canAccess()` (`app/Filament/Concerns/EnforcesResourceAccessRule.php` line 21). A resource that overrides `canAccess()` must still call it. Navigation methods use `__('kokpit.<area>.navigation_label')`, `getNavigationGroup()`, `getNavigationIcon(): Heroicon` (lines 48-61). Set `protected static bool $isGloballySearchable = false;` (panel also has `->globalSearch(false)`, `AdminPanelProvider.php` line 61). No `ForceDeleteAction`/bulk. Archived-record route binding override uses `withoutGlobalScopes([SoftDeletingScope::class])` (argument form is allowed).

**Form Money field** (`SettingsPage.php` lines 440-472, copy this for the client rate and project rate/price inputs):
```php
TextInput::make('default_hourly_rate')
    ->inputMode('decimal')
    ->suffix(fn (Get $get): string => (string) $get('default_currency').' / '.__('kokpit.settings.defaults.per_hour'))
    ->required()
    ->rules(fn (Get $get): array => [
        ...$rules['default_hourly_rate'],
        static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
            try { Money::fromMajor(is_string($value) ? $value : '', (string) $get('default_currency')); }
            catch (InvalidArgumentException|OverflowException) { $fail(__('kokpit.settings.defaults.rate_invalid')); }
        },
    ]),
```
Currency select: `->options(array_combine(Money::isoCurrencyCodes(), Money::isoCurrencyCodes()))->searchable()->live()` (same file lines 446-452). Display text via `str_replace('.', ',', $money->toMajor())` as `DefaultsSettings::toFormState()` does (line 59).

**Defaults prefill (D-13):** read `app(DefaultsSettings::class)` (`default_currency`, `default_hourly_rate`), `app(InvoicingSettings::class)->payment_due_days`, `PaymentSettings->online_payments_enabled`, `SupplierSettings->country` in `->default(fn () => ...)` closures on create; copy into the row, never re-read. Settings are Admin context only (`SettingsProperty` is `DeniesPartners`).

**ARES `suffixAction`:** no analog; use RESEARCH Pattern 5 "Filament 5 action wiring" (throw `ValidationException::withMessages([$component->getStatePath() => ...])`).

**Analog gap:** there is no CRUD resource (Create/Edit/View pages) in the repo; `ActivityResource` is read-only and has only `Pages/ListActivities.php`. Page classes under `Resources/*/Pages` are exempt from `#[AccessRule]` (`PanelRegistryTest.php` lines 102-119 `panelRegistryIsGoverned`). Relation-manager classes are not exempt.

---

### Relation managers (`ContactsRelationManager`, `PartnerAccountsRelationManager`, `InvitationsRelationManager`, `ClientHistoryRelationManager`, `ProjectHistoryRelationManager`)

**History analog (exact):** `app/Filament/RelationManagers/ActivityHistoryRelationManager.php` docblock lines 19-23:
```php
#[AccessRule(Audience::AdminOnly, reason: 'The history shows who changed what.')]
final class TaskHistoryRelationManager extends ActivityHistoryRelationManager {}
```
List it in the resource's `getRelations()` so the registry test sees it. The model must use `LogsAllowlistedActivity` (provides `activitiesAsSubject`).

**CRUD relation managers (role match):** `tests/Support/Filament/Fixtures/AdminOnlyRelationManager.php` plus the trait `app/Filament/Concerns/EnforcesRelationManagerAccessRule.php` lines 22-30:
```php
public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
{
    return AccessRules::allows(static::class) && parent::canViewForRecord($ownerRecord, $pageClass);
}
public function bootEnforcesRelationManagerAccessRule(): void
{
    abort_unless(AccessRules::allows(static::class), 403);
}
```
Every relation manager: `#[AccessRule(Audience::AdminOnly, reason: ...)]` + `use EnforcesRelationManagerAccessRule;`. Every related model needs a policy method (strict authorization, `AdminPanelProvider.php` line 58): `ContactPolicy`, `ClientInvitationPolicy`, `UserPolicy` can all be `AdminOnlyPolicy`. Contacts are not a repeater (partial unique index). The accounts manager has no delete action; test that.

---

### `app/Filament/Partner/Resources/PartnerProjectResource.php` (+ `Pages/ListPartnerProjects`, `ViewPartnerProject`)

**Analog:** `tests/Support/Filament/CanaryRecordResource.php` with its `Pages/ListCanaryRecords.php` and `ViewCanaryRecord.php` (read-only list + view of a Partner-scoped model; used by `RouteWalkTest`). Read the file when implementing; its shape (no create/edit pages, policy-driven) is the model. Declaration is `#[AccessRule(Audience::PartnerAllowed, reason: '...')]` + `use EnforcesResourceAccessRule;` as `app/Filament/Pages/Dashboard.php` lines 21-24 do for a page:
```php
#[AccessRule(Audience::PartnerAllowed, reason: 'Empty landing page for both roles; the Partner text names no time, rate, price or finance.')]
class Dashboard extends BaseDashboard { use EnforcesPageAccessRule;
```
Specifics: slug `my-projects`; `canAccess()` additionally requires a Partner (call the trait's logic too); no relation managers, no actions, no client column; table search only on `name` and `key`. Register the directory in `AdminPanelProvider.php` after line 70:
```php
->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
->discoverResources(in: app_path('Filament/Partner/Resources'), for: 'App\Filament\Partner\Resources')
```
`PanelRegistryTest` scans all of `app/Filament`, so the new directory is covered automatically.

---

### `app/Filament/Pages/Auth/AcceptInvitation.php` (guest SimplePage)

**Analogs:** `app/Filament/Pages/SettingsPage.php` for the `#[AccessRule]` + `Enforces*` + Livewire form conventions (lines 52-66 imports `CanUseDatabaseTransactions`, `HasUnsavedDataChangesAlert`), and `Dashboard.php` for the declaration. The guest page is **different**: `#[AccessRule(Audience::Guest, reason: '...')]`, no `EnforcesPageAccessRule` (that trait would deny a guest), base class `Filament\Pages\SimplePage`, `#[Locked]` `$invitationId` / `$token`, password rules from `InstallCommand.php` (min 12, 72-byte cap), a call into the `AcceptInvitation` Action for every read/write (each Livewire request gets a fresh `PartnerContext`).

Route: `AdminPanelProvider::configure()` gets `->routes(fn () => Route::get('/invitation', AcceptInvitation::class)->middleware(['signed', 'throttle:invitation'])->name('invitation.accept'))` (RESEARCH Pattern 3); no path parameters (`RouteWalkTest.php` `walkedUrl()` lines 75-88 throws on any parameter other than `{record}`). `RateLimiter::for('invitation', ...)` has no analog; put it in a service provider `boot()`.

**Analog gap:** no guest page, no signed route, no rate limiter definition exists in the repo.

---

### Settings: `DefaultsSettings` invoice language (D-13, Open Question 1)

**Analogs:** `app/Domain/Settings/Settings/DefaultsSettings.php` (lines 19-107: public typed properties, `rules()`, `toFormState()`, `fillFromFormState()`), `database/settings/2026_10_08_000120_create_defaults_settings.php` (lines 9-13):
```php
protected function migrate(): void
{
    $this->migrator->add('defaults.default_currency', 'CZK');
    $this->migrator->add('defaults.default_hourly_rate', ['minor' => 0, 'currency' => 'CZK']);
}
```
Add `public string $default_invoice_language;` (rule `in:cs,en` or the `InvoiceLanguage` enum values), a NEW settings migration file (never edit the existing one) `database/settings/2026_10_09_*_add_default_invoice_language.php` with `$this->migrator->add('defaults.default_invoice_language', 'cs');`, extend `toFormState()` / `fillFromFormState()` / the `@return array{...}` shape, and a `Select` in `SettingsPage::defaultsTab()` (lines 440-472) inside the existing `Group::make([...])->statePath(DefaultsSettings::group())`. Update `tests/Feature/Operations/SettingsGroupsTest.php` (lines 36-53) and `SettingsPageTest.php` (lines 181-197, form-state arrays assert the exact keys) and add the `kokpit.settings.defaults.*` Czech strings.

---

### Policies

**Analog:** `app/Domain/Shared/Policies/AdminOnlyPolicy.php` (`final class AdminOnlyPolicy extends KokpitPolicy {}`) and `KokpitPolicy.php` lines 25-42 (`before()`: Admin true, Partner-with-client null, everyone else false; all abilities default false).

`ProjectPolicy extends KokpitPolicy` overrides only (types are `Model $record`, check inside):
```php
public function viewAny(User $user): bool { return true; }
public function view(User $user, Model $record): bool
{
    return $record instanceof Project && $record->client_id === $user->client_id && $record->client_visible;
}
```
Everything else stays false (create/update/delete/restore/forceDelete/replicate/reorder). Admin passes every ability via `before()`, including `forceDelete`, so "no hard delete" is enforced by not exposing the action and `ON DELETE RESTRICT`. Register in `AccessServiceProvider::boot()` (the loop at line 35 lists package models; add `Gate::policy` lines or `#[UsePolicy]`). `tests/Isolation/PolicyBaseTest.php` exercises the base.

---

### Test support: `tests/Support/Canary.php`

**Change `twoClients()`** (lines 37-45). Current:
```php
public static function twoClients(): array
{
    return [Str::uuid7()->toString(), Str::uuid7()->toString()];
}
```
New (RESEARCH "Canary helper change"): create two `Client::factory()` rows inside `runAsSystem` and return their ids; keep the signature so ~15 callers do not change. Also `Canary::partnerFor()` (lines 60-90) keeps working because the FK is now satisfied. Add `Canary::projectKey()` helper if wanted (unique runtime 2-6 letter key). Where a test needs a Partner whose client does not exist, build it explicitly (FK forbids it, so such a test must now bypass via a raw state or be redefined).

### Test support: `tests/Support/CanaryRegistry.php`

**Analog:** itself, `fixtures()` lines 56-108. One entry per `PartnerIsolated` model, closure signature `static function (string $clientId, string $canary): void`, writes wrapped in `app(PartnerContext::class)->runAsSystem(...)`:
```php
SettingsProperty::class => static function (string $clientId, string $canary): void {
    app(PartnerContext::class)->runAsSystem(static function () use ($clientId, $canary): void {
        SettingsProperty::query()->create([ 'group' => 'canary', 'name' => $canary, ... ]);
```
Add: `Client::class` (canary in `name`), `Contact::class` (`name`), `Project::class` (`name`, `client_visible = true`), `ProjectBilling::class` (`internal_note`, needs a project row), `ClientInvitation::class` (`name`). Order matters: `Project` before `ProjectBilling`, `Project` before `Tag`. Re-point `Tag::class` (currently `self::host()->attachTag($canary)`, lines 74-78) to create a visible `Project` of that client and attach a `project`-typed tag carrying the canary (`attachTag($canary, 'project')` on the real `Project`, not the probe host). Each registered model must show its canary to the Admin and, if not `DeniesPartners`, exactly one own row to the Partner (`CanaryRegistryTest.php` lines 108-160).

### Test list updates (hard-coded model lists)

- `tests/Arch/ModelDeclarationTest.php` lines 31-41: add `Client`, `ClientInvitation`, `Contact`, `Project`, `ProjectBilling` to the `toEqualCanonicalizing` list.
- `tests/Isolation/CanaryRegistryTest.php` lines 83-90 ("does not pass vacuously"): add the same five (all five implement `PartnerIsolated`).
- `tests/Arch/ActivityAllowlistTest.php` lines 39-42: replace `toBe([])` with the explicit list `[Client::class, ClientInvitation::class (if logged), Contact::class, Project::class, ProjectBilling::class]` sorted (the helper `AuditDeclaration::loggingModels()` sorts).
- `tests/Isolation/DeniedModelsTest.php` (lines 27-44, 68-73): still passes (probe tag has no type, no project); update docblock wording ("four package models") since `Tag` is no longer deny-all.
- `tests/Isolation/RouteWalkTest.php`: Partner A requests the new Admin resource routes (expects 403/404, never the canary of client B) and the Partner project routes (walk expects own canary visible).
- `tests/Feature/Schema/*`: new `ClientTablesTest.php` modelled on `KeysAndTimestampsTest.php` (raw SQL inserts, `QueryException` `errorInfo[0]` SQLSTATE asserts such as `'23502'`, `'23505'`, `'23514'`, `'23503'`); the `Tag` morph test (`PackageModelsTest.php` lines 100-113) keeps working. `SchemaConventionsTest.php` R1-R9 runs automatically over the new tables.

### New test files

**Feature tests (Livewire/Filament):** analog `tests/Feature/Operations/SettingsPageTest.php` (lines 139-237: `Livewire::test(...)`, `assertSet('data....')`, `assertHasFormErrors([...])`). Helpers: `Canary::admin()`, `Canary::partnerFor($clientId)`, `exampleEmail()` from `tests/Pest.php` lines 33-36 (assembled `example.com` address). Feature and Isolation suites get `RefreshDatabase` (`tests/Pest.php` lines 17-22); Arch does not touch the DB.

**Unit tests:** analog `tests/Unit/Settings/IbanTest.php` (pure validator) for `CompanyIdTest`, `ProjectKeySuggesterTest`.

**Isolation tests:** analogs `tests/Isolation/FailClosedScopeTest.php` and `PanelAccessTest.php`. `PartnerProjectVisibilityTest`: own visible project seen; hidden project, other client, archived project, archived client invisible; policy denies writes; Partner tag display (visible project tag yes; hidden project tag no; other client tag no; `client`-type tag no). `PartnerSafeColumnsTest`: pin `Schema::getColumnListing('projects')` to an explicit Partner-safe allowlist.

**Escape hatch rule:** `tests/Arch/QueryEscapeHatchTest.php` + `tests/Support/EscapeHatchScanner.php` lines 44-60 flag no-argument `withoutGlobalScopes()` and `DB::table(`. Use `withoutGlobalScopes([SoftDeletingScope::class])`, `Project::withTrashed()`, and `runAsSystem`, never `DB::table` in app code.

---

## Shared Patterns

### Partner isolation declaration
**Source:** `app/Domain/Shared/Auth/DeniesPartners.php` lines 19-29, `PartnerScope.php` lines 24-41, `ModelDeclaration` rules in `tests/Support/ModelDeclaration.php` (lines 34, 72-78).
**Apply to:** every new model. Exactly one of: `implements PartnerIsolated` (+ `DeniesPartners` or `IsolatesPartners`) or `#[NotPartnerScoped(reason)]`, never both. `PartnerScope` fails closed on every state except Admin, system run, or Partner-with-client on a `PartnerIsolated` model.
```php
$builder->whereRaw('1 = 0');   // PartnerScope fallback and DeniesPartners::constrainForPartner
```

### Access rule on every Filament class
**Source:** `app/Domain/Shared/Auth/AccessRule.php` (constructor `Audience $audience, string $reason = ''`), `AccessRules::allows()`.
**Apply to:** every concrete Resource, Page, Widget, RelationManager, Cluster under `app/Filament` (resource pages exempt). Missing attribute = denied to everybody; `PanelRegistryTest` fails the build.
- Resource: `use EnforcesResourceAccessRule;`
- Page: `use EnforcesPageAccessRule;` (guest accept page: `Audience::Guest`, no trait)
- Relation manager: `use EnforcesRelationManagerAccessRule;` (boot hook aborts 403)

### Explicit system run
**Source:** `app/Domain/Shared/Auth/PartnerContext.php::runAsSystem()` (line 72); usage `InstallCommand.php` line 38 and `tests/Support/CanaryRegistry.php` line 64.
**Apply to:** `AcceptInvitation` Action, `User::canAccessPanel` client lookup, all test fixtures that write `DeniesPartners` models, and queued jobs (jobs extend `KokpitJob` with `RunsAsSystem`; the invitation notification is not a job and carries scalars only).

### Money
**Source:** `app/Domain/Shared/Money/MoneyCast.php` (pair `{key}_minor`/`{key}_currency`), `Money::fromMajor` (`Money.php` line 128), `Money::isoCurrencyCodes()` (line 162), `Money::toMajor()` (line 152). No floats (`tests/Arch/MoneyBoundaryTest.php` guards the boundary).
**Apply to:** `clients.hourly_rate`, `project_billing.hourly_rate`, `fixed_price`, forms, defaults prefill.

### Activity log allowlist
**Source:** `app/Domain/Audit/LogsAllowlistedActivity.php` lines 35-69 and `LoggedAttributes` attribute.
**Apply to:** `Client`, `Contact`, `Project`, `ProjectBilling`, optionally `ClientInvitation`. Lists in RESEARCH Pattern 6. A model using the trait without the attribute throws on its first logged event. Czech subject labels under `kokpit.activity.subjects.*`.

### UUID v7 and timestamptz
**Source:** `KokpitModel.php` docblock lines 10-22; migrations above. Enforced by `PgSchema` rules R1-R9.
**Apply to:** all five new tables and the `users` alteration.

### Czech localisation
**Source:** `lang/cs/enums.php` (enum groups), `lang/cs/kokpit.php` (areas: `settings`, `activity`, `install`...). New sections in `kokpit.php`: `clients`, `contacts`, `projects`, `invitations`, `partner_accounts`, `ares`, `partner_projects`; navigation labels `kokpit.<area>.navigation_label/navigation_group`. Code, tests, docs in English.

### Repository hygiene in tests
**Source:** `tests/Pest.php` `exampleEmail()`, `Canary::canary()` (`implode('_', ['CANARY', $label, bin2hex(random_bytes(4))])`), `CanaryRegistry.php` line 93 (`'https://'.implode('.', ['example', 'com']).'/hook'`).
**Apply to:** every new test: addresses, URLs, company IDs, tokens built at runtime from fragments; fictional placeholder `12345678` is checksum-invalid by design.

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `app/Domain/Clients/Ares/AresClient.php` | service | request-response (HTTP) | No outbound HTTP client in the repo; use RESEARCH Pattern 5 skeleton (`retry(..., when:)` is mandatory) |
| `app/Domain/Clients/Actions/*` (all) | action | CRUD / request-response | The repo has no `Actions` directory yet; first Actions. Style from `InstallCommand.php` and `SequenceAllocator.php` only |
| `DeactivatePartnerAccount`, `ReactivatePartnerAccount`, `SendPartnerPasswordReset` | action | request-response | No account lifecycle code exists; RESEARCH Pattern 4 |
| `app/Filament/Resources/ClientResource.php`, `ProjectResource.php` Create/Edit/View pages | resource pages | CRUD | No CRUD resource exists (`ActivityResource` is read-only); `SettingsPage` shows the form conventions only |
| ARES `suffixAction` form wiring | component | request-response | No form action analog; RESEARCH Pattern 5 |
| `AcceptInvitation` guest SimplePage + signed route | page | request-response | No guest page, `routes()` hook or signed URL in the repo; plus `Audience::Guest` is a new primitive |
| `RateLimiter::for('invitation', ...)` | provider | request-response | No named limiter defined anywhere |
| Soft-delete model + `HasTags` `detachTags` guard | model | CRUD | No `SoftDeletes` model exists; RESEARCH Pattern 2 block |
| `Tag::constrainForPartner` real constraint | model | CRUD | First non-deny Partner constraint; only `CanaryRecord` (`tests/Support/CanaryRecord.php`) shows a `where('client_id', ...)` constraint style |
| Contact / project factories with real columns | factory | CRUD | Only `UserFactory`; copy its shape (`$model`, `definition()`, fictional `fake()` data; set `client_id` through the relation) |
| Partial unique index / deferred constraint trigger (optional) | migration | CRUD | No partial index exists yet; raw `DB::statement` in the `number_sequences` migration is the closest style |
| `tests/Support/EscapeHatchScanner.php` extension for `withoutGlobalScope(PartnerScope::class)` (optional hardening) | test support | transform | Extends itself (lines 44-60) |

Analog gaps: 11 greenfield rows above; the other mapped files have an exact or role-match analog.

## Metadata

**Analog search scope:** `app/`, `database/`, `config/`, `lang/`, `tests/` (tracked files only, via `git ls-files`).
**Files scanned:** about 260 tracked files listed; about 35 read in full or in ranges.
**Pattern extraction date:** 2026-10-08
