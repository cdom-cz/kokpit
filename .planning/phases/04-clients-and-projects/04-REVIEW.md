---
phase: 04-clients-and-projects
reviewed: 2026-10-08T00:00:00Z
depth: standard
files_reviewed: 145
files_reviewed_list:
  - CONTRIBUTING.md
  - README.md
  - app/Domain/Audit/LogsAllowlistedActivity.php
  - app/Domain/Clients/Actions/AcceptInvitation.php
  - app/Domain/Clients/Actions/ArchiveClient.php
  - app/Domain/Clients/Actions/ClientInput.php
  - app/Domain/Clients/Actions/ContactInput.php
  - app/Domain/Clients/Actions/CreateClient.php
  - app/Domain/Clients/Actions/CreateContact.php
  - app/Domain/Clients/Actions/DeactivatePartnerAccount.php
  - app/Domain/Clients/Actions/DeleteContact.php
  - app/Domain/Clients/Actions/InvitePartner.php
  - app/Domain/Clients/Actions/ReactivatePartnerAccount.php
  - app/Domain/Clients/Actions/ResendInvitation.php
  - app/Domain/Clients/Actions/RestoreClient.php
  - app/Domain/Clients/Actions/RevokeInvitation.php
  - app/Domain/Clients/Actions/SendPartnerPasswordReset.php
  - app/Domain/Clients/Actions/SetPrimaryContact.php
  - app/Domain/Clients/Actions/UpdateClient.php
  - app/Domain/Clients/Actions/UpdateContact.php
  - app/Domain/Clients/Ares/AresClient.php
  - app/Domain/Clients/Ares/AresCompany.php
  - app/Domain/Clients/Ares/AresFailure.php
  - app/Domain/Clients/Ares/AresLookupFailed.php
  - app/Domain/Clients/Ares/CompanyId.php
  - app/Domain/Clients/Enums/ClientStage.php
  - app/Domain/Clients/Enums/InvitationState.php
  - app/Domain/Clients/Enums/InvoiceLanguage.php
  - app/Domain/Clients/InvitationMail.php
  - app/Domain/Clients/InvitationNotAcceptable.php
  - app/Domain/Clients/Models/Client.php
  - app/Domain/Clients/Models/ClientInvitation.php
  - app/Domain/Clients/Models/Contact.php
  - app/Domain/Clients/Notifications/PartnerInvitation.php
  - app/Domain/Clients/Rules/CompanyIdRule.php
  - app/Domain/Clients/Rules/EmailHasNoAccount.php
  - app/Domain/Clients/Rules/EmailHasNoOpenInvitation.php
  - app/Domain/Identity/Models/User.php
  - app/Domain/Identity/Policies/UserPolicy.php
  - app/Domain/Projects/Actions/CreateProject.php
  - app/Domain/Projects/Actions/ProjectInput.php
  - app/Domain/Projects/Actions/UpdateProject.php
  - app/Domain/Projects/Enums/BillingType.php
  - app/Domain/Projects/Enums/ProjectPriority.php
  - app/Domain/Projects/Enums/ProjectStatus.php
  - app/Domain/Projects/EstimateHours.php
  - app/Domain/Projects/Models/Project.php
  - app/Domain/Projects/Models/ProjectBilling.php
  - app/Domain/Projects/Policies/ProjectPolicy.php
  - app/Domain/Projects/ProjectKeySuggester.php
  - app/Domain/Settings/Settings/DefaultsSettings.php
  - app/Domain/Shared/Auth/AccessRules.php
  - app/Domain/Shared/Auth/Audience.php
  - app/Domain/Shared/Database/MorphMap.php
  - app/Domain/Shared/Models/Tag.php
  - app/Domain/Shared/Tags/TagType.php
  - app/Filament/Concerns/RethrowsDomainValidation.php
  - app/Filament/Pages/Auth/AcceptInvitation.php
  - app/Filament/Pages/Auth/RequestPasswordReset.php
  - app/Filament/Pages/SettingsPage.php
  - app/Filament/Partner/Resources/PartnerProjectResource.php
  - app/Filament/Partner/Resources/PartnerProjectResource/Pages/ListPartnerProjects.php
  - app/Filament/Partner/Resources/PartnerProjectResource/Pages/ViewPartnerProject.php
  - app/Filament/RelationManagers/ClientHistoryRelationManager.php
  - app/Filament/RelationManagers/ProjectHistoryRelationManager.php
  - app/Filament/Resources/ClientResource.php
  - app/Filament/Resources/ClientResource/Pages/CreateClient.php
  - app/Filament/Resources/ClientResource/Pages/EditClient.php
  - app/Filament/Resources/ClientResource/Pages/ListClients.php
  - app/Filament/Resources/ClientResource/Pages/ViewClient.php
  - app/Filament/Resources/ClientResource/RelationManagers/ContactsRelationManager.php
  - app/Filament/Resources/ClientResource/RelationManagers/InvitationsRelationManager.php
  - app/Filament/Resources/ClientResource/RelationManagers/PartnerAccountsRelationManager.php
  - app/Filament/Resources/ProjectResource.php
  - app/Filament/Resources/ProjectResource/Pages/CreateProject.php
  - app/Filament/Resources/ProjectResource/Pages/EditProject.php
  - app/Filament/Resources/ProjectResource/Pages/ListProjects.php
  - app/Filament/Resources/ProjectResource/Pages/ViewProject.php
  - app/Filament/Support/ProjectColumns.php
  - app/Http/Middleware/SetNoReferrerPolicy.php
  - app/Providers/AccessServiceProvider.php
  - app/Providers/AppServiceProvider.php
  - app/Providers/Filament/AdminPanelProvider.php
  - bootstrap/app.php
  - composer.json
  - config/kokpit.php
  - config/services.php
  - database/factories/ClientFactory.php
  - database/factories/ProjectFactory.php
  - database/migrations/2026_10_09_000100_create_clients_table.php
  - database/migrations/2026_10_09_000200_add_client_fk_and_deactivation_to_users_table.php
  - database/migrations/2026_10_09_000300_create_projects_table.php
  - database/migrations/2026_10_09_000400_create_project_billing_table.php
  - database/migrations/2026_10_09_000500_create_contacts_table.php
  - database/migrations/2026_10_09_000600_create_client_invitations_table.php
  - database/settings/2026_10_09_000100_add_default_invoice_language.php
  - lang/cs/enums.php
  - lang/cs/kokpit.php
  - tests/Arch/ActivityAllowlistTest.php
  - tests/Arch/ModelDeclarationTest.php
  - tests/Arch/PanelRegistryTest.php
  - tests/Arch/QueryEscapeHatchTest.php
  - tests/Feature/Auth/TwoFactorEnforcementTest.php
  - tests/Feature/Clients/AcceptInvitationTest.php
  - tests/Feature/Clients/AresClientTest.php
  - tests/Feature/Clients/AresFormActionTest.php
  - tests/Feature/Clients/ClientArchiveTest.php
  - tests/Feature/Clients/ClientDefaultsTest.php
  - tests/Feature/Clients/ClientResourceTest.php
  - tests/Feature/Clients/ClientRulesTest.php
  - tests/Feature/Clients/ClientTagsAndHistoryTest.php
  - tests/Feature/Clients/ContactsTest.php
  - tests/Feature/Clients/InvitationManagementTest.php
  - tests/Feature/Clients/PartnerAccountLifecycleTest.php
  - tests/Feature/Clients/PartnerInvitationTest.php
  - tests/Feature/Clients/PartnerPasswordResetTest.php
  - tests/Feature/Operations/SettingsGroupsTest.php
  - tests/Feature/Operations/SettingsPageTest.php
  - tests/Feature/Projects/PartnerProjectResourceTest.php
  - tests/Feature/Projects/ProjectActionsTest.php
  - tests/Feature/Projects/ProjectBillingTest.php
  - tests/Feature/Projects/ProjectKeyTest.php
  - tests/Feature/Projects/ProjectResourceTest.php
  - tests/Feature/Repo/RepositoryFilesTest.php
  - tests/Feature/Schema/ClientTablesTest.php
  - tests/Feature/Schema/ProjectBillingTableTest.php
  - tests/Isolation/CanaryRegistryTest.php
  - tests/Isolation/DeniedModelsTest.php
  - tests/Isolation/PanelAccessTest.php
  - tests/Isolation/PartnerLockoutTest.php
  - tests/Isolation/PartnerProjectVisibilityTest.php
  - tests/Isolation/PartnerSafeColumnsTest.php
  - tests/Isolation/PartnerTagVisibilityTest.php
  - tests/Isolation/RouteWalkTest.php
  - tests/Support/Canary.php
  - tests/Support/CanaryRegistry.php
  - tests/Support/EscapeHatchScanner.php
  - tests/Support/FictionalCompanyId.php
  - tests/Support/Filament/Fixtures/GuestSimplePageFixture.php
  - tests/Support/S3TestDisk.php
  - tests/TestCase.php
  - tests/Unit/Ares/CompanyIdTest.php
  - tests/Unit/Projects/EstimateHoursTest.php
  - tests/Unit/Projects/ProjectKeySuggesterTest.php
findings:
  critical: 0
  warning: 6
  info: 6
  total: 12
status: issues_found
---

# Phase 4: Code Review Report

**Reviewed:** 2026-10-08
**Depth:** standard
**Files Reviewed:** 145 (all application, migration, config and lang files read in full; test files sampled for coverage of claimed behaviour)
**Status:** issues_found

## Summary

The phase is carefully built. The Partner isolation design holds up under adversarial reading: `project_billing`, `clients`, `contacts` and `client_invitations` are all `DeniesPartners` models with Admin-only policies, the Partner resource is built only from the Partner-safe column and entry lists, the projects table column list is pinned by a test, and the Tag Partner scope is derived from the Project scope. The invitation flow is sound: SHA-256 of a 256-bit token, `hash_equals`, row locking on accept, a neutral failure, `Locked` Livewire properties, a signed route with a throttle, a no-referrer header, and `users_email_lower_unique` as the DB backstop. The password reset page does not leak account existence, because the framework broker runs inside a `Timebox` and Filament's reset notification is queued (I verified both in `vendor/`). Money handling is exact (`Money::fromMajor`, integer arithmetic in `EstimateHours`, no float anywhere). The ARES URL is built only from an 8-digit, checksum-valid id plus a config base URL, and the retry predicate correctly retries only connection errors and 5xx. The CHECK constraints, partial unique indexes and RESTRICT foreign keys in the migrations match the stated decisions (D-05 and D-09 to D-16).

No Critical findings. The six warnings are defense-in-depth gaps and integrity races, plus one false-success UI path. In summary:

- The Partner `Project` scope relies on the SoftDeletes scope instead of stating the archived rule itself.
- The "client currency equals project money currency" invariant is checked outside any lock.
- The plaintext invitation token is persisted in queue payloads.
- The Admin "send password reset" action reports success when nothing was sent.
- The project Actions do not own their input validation, although their docblocks say they do.
- E-mail lookups on the reset path are case-sensitive, although the schema treats addresses as case-insensitive.

I could not run the test suite (no PostgreSQL available in the review environment), so every finding below comes from static reading plus checks of the vendored framework code.

## Warnings

### WR-01: Project Partner scope does not enforce "not archived" itself

**File:** `app/Domain/Projects/Models/Project.php:97-108` (docblock claims at lines 28-31)
**Issue:** The class docblock says a Partner sees a project only when it "is not archived". `constrainForPartner()` enforces only own client, `client_visible = true` and an unarchived client. Archived projects are hidden solely because the default `SoftDeletingScope` is also applied. Any code path that calls `withTrashed()` or `onlyTrashed()` as a Partner, or a future Partner-facing resource that removes `SoftDeletingScope` (as `ProjectResource::getEloquentQuery()` and `getRecordRouteBindingEloquentQuery()` already do for the Admin), then exposes archived, client-visible projects of the Partner's own client.

This can happen today, in a small way. A Partner who opens `/admin/projects/{archived-visible-project-id}` on the Admin `ProjectResource` resolves the record through the scope-stripped route-binding query before `authorizeAccess()` returns 403. That leaks the existence of archived projects as 403 versus 404, and it shows that the fail-closed rule is not owned by the scope. The `Tag` Partner scope is derived from `Project::query()`, so it inherits the same gap.
**Fix:**
```php
$query
    ->where("{$table}.client_id", $clientId)
    ->where("{$table}.client_visible", true)
    ->whereNull("{$table}.deleted_at")   // state the archive rule in the scope itself
    ->whereExists(...);
```
Add a test that runs `Project::withTrashed()` as a Partner and expects an archived visible project not to be returned.

### WR-02: Client currency lock and project money currency are checked outside any lock (race)

**File:** `app/Domain/Clients/Actions/UpdateClient.php:43-61`, `app/Domain/Projects/Actions/CreateProject.php:56-110`, `app/Domain/Projects/Actions/UpdateProject.php:69-98`
**Issue:** `ProjectBilling::clientHoldsMoney()` runs before `DB::transaction()` in `UpdateClient`, with no lock on the client row. `CreateProject` and `UpdateProject` read `$client->currency` outside the transaction and build `Money` in that currency, also without a lock. The migration comment for `project_billing` says the invariant "currency of the money equals the client's currency" is enforced "by the domain Actions and the client currency lock, not by a trigger", so the Actions are the only guard.

Interleaving: UpdateClient sees no project money, then a project with a rate in the old currency commits, then UpdateClient commits the new currency. The result is a project rate stored in a currency different from its client's, and the DB cannot catch it. The same applies to a second Admin tab, or to a future import or API writer. It also breaks the contract that billing later resolves "the client rate or the project rate" in a single currency.
**Fix:** Take a row lock on the client in both paths and re-check inside the transaction.
```php
DB::transaction(function () use ($client, $attributes) {
    $current = Client::query()->withTrashed()->whereKey($client->getKey())->lockForUpdate()->firstOrFail();
    if ($attributes['currency'] !== $current->currency && ProjectBilling::clientHoldsMoney($current->id)) {
        throw ValidationException::withMessages(['currency' => __('kokpit.clients.errors.currency_locked')]);
    }
    $current->update($attributes);
    ...
});
```
In `CreateProject` and `UpdateProject`, lock the client row (`lockForUpdate`, or `sharedLock` in the project paths), re-read `currency` and `deleted_at` inside the transaction, and build the `Money` values from the locked row. This also closes the stale `$client->trashed()` check in `CreateProject` (see IN-03).

### WR-03: The plaintext invitation token is persisted in the queue payload and failed_jobs

**File:** `app/Domain/Clients/Notifications/PartnerInvitation.php:26-33`, `app/Domain/Clients/InvitationMail.php:29-47`
**Issue:** The Action docblocks say the plain token is "neither returned nor logged", and only its hash is stored in `client_invitations`. But `InvitationMail::send()` serialises the full signed URL, which contains `token=<plain>`, into the queued `PartnerInvitation` notification (`$acceptUrl` is a public readonly property). The serialised job lands in the `jobs` table (or Redis) and, if mail delivery fails, in `failed_jobs.payload`. Horizon also displays job payloads. `AlertMessageSanitiser` scrubs only the alert text, not `failed_jobs`.

Anyone or anything that can read these stores (a DB backup, a failed-job export, a Horizon screen share) obtains a live, working account-creation link until it expires or is superseded. That defeats the point of hashing the token.
**Fix:** Mark the notification as encrypted at rest (`Illuminate\Contracts\Queue\ShouldBeEncrypted` on the queued job or notification where supported), or build the URL inside the job from an id plus a short-lived token stored encrypted. At minimum, document the exposure, set a short `failed_jobs` retention (`queue:prune-failed --hours=…`), and make `queue:retry` of an expired invitation harmless (the link already fails after expiry or resend). Add a test that the serialised payload does not contain the token if encryption is chosen.

### WR-04: "Send password reset" reports success when nothing was sent

**File:** `app/Domain/Clients/Actions/SendPartnerPasswordReset.php:37-41`, `app/Filament/Resources/ClientResource/RelationManagers/PartnerAccountsRelationManager.php:104-118`
**Issue:** `SendPartnerPasswordReset::handle()` returns silently when `$user->canAccessPanel()` is false. The only reachable case through the UI is a Partner of an archived client, because the action is hidden only for `deactivated_at !== null`. A stale list after a concurrent deactivation reaches the same branch. The relation manager then shows the green notification "reset sent" (`notifications.reset_sent`) although no token was stored and no mail was queued. The Admin believes the Partner has been given a way back in. The test `sends nothing for a Partner of an archived client` pins the no-send behaviour but not the user-visible answer.
**Fix:** Throw a `DomainException` with a clear reason (for example `partner_accounts.errors.cannot_sign_in`) instead of `return;`, so `run()` shows the red "failed" notification. Also hide the action when the owner client is archived, as `InvitationsRelationManager` already does for resend.

### WR-05: Project Actions do not own their input validation; ClientInput skips length checks

**File:** `app/Domain/Projects/Actions/CreateProject.php:56-110`, `app/Domain/Projects/Actions/UpdateProject.php:61-102`, `app/Domain/Projects/Actions/ProjectInput.php`, `app/Domain/Clients/Actions/ClientInput.php:84-146`
**Issue:** The docblocks state that "the Action is the only place that knows the rules" and that the form is a thin adapter. That holds for `ClientInput` and `ContactInput` (every field is validated and mapped to a keyed `ValidationException`), but not for projects. The project Actions validate only money, estimate and the fixed-price requirement. Everything else is left to the Filament form, and a non-form caller (Phase 5 and later code, an import, a seeder, the future API) hits the database instead:
- `key` is not checked against `^[A-Z]{2,6}$`. An empty or invalid key causes a raw `QueryException` (`projects_key_check`), not a field error.
- `name` is not checked. `ProjectResource::actionData()` turns an empty name into `''`, and `projects.name` has no non-empty CHECK, so a blank name is persisted.
- `status` and `priority` strings go straight to the enum cast and the CHECK constraint. `BillingType::from($data['billing_type'])` throws an uncaught `ValueError`.
- A date range with `end_date < start_date` is caught only by `projects_dates_check`, again as a 500.

`ClientInput::attributes()` has the same hole for `company_number`, `tax_number`, `street`, `city` and `postal_code`. Their column limits (32, 32, 255, 255, 20) are enforced in the form via `maxLength`, but not in the Action, so `value too long` surfaces as a 500. Only `name` and `invoice_email` are length-checked.
**Fix:** Add a `ProjectInput::attributes()` that validates name (required, max 255), key (`/^[A-Z]{2,6}$/`), the status, priority and billing type enums (`tryFrom` with a field error), dates (valid, end not before start), and description. Extend `ClientInput` with `mb_strlen` checks for the optional text fields. Reuse them from the Filament forms where possible so the two cannot drift. Add Action-level tests that bypass the form.

### WR-06: Reset and login e-mail lookup is case-sensitive although the schema treats e-mail as case-insensitive

**File:** `app/Filament/Pages/Auth/RequestPasswordReset.php:49-50`, `database/migrations/2026_10_09_000200_add_client_fk_and_deactivation_to_users_table.php:30`
**Issue:** Migration `000200` creates `users_email_lower_unique ON users (lower(email))`, and both `InvitePartner` and `AcceptInvitation` lowercase and compare with `lower(email)`. Partner accounts are therefore stored lowercase. The public forgot-password page, however, passes the typed address unchanged to the password broker, and `EloquentUserProvider::retrieveByCredentials()` compares `email = ?` byte for byte in PostgreSQL. A Partner who types `Jane@Example.com` (or has a phone that capitalises the first letter) gets the neutral "if the account exists" answer and no mail. The Admin sees no failure and the Partner has no clue. The same lookup applies to the login form.
**Fix:** Normalise at the boundary. Override `getCredentialsFromFormData()` in `RequestPasswordReset` (and the login page) to `mb_strtolower(trim($email))`, and guarantee stored e-mails are lowercase (a `User` attribute mutator, plus checking the install command's Admin address). If mixed-case stored addresses must keep working, use a custom user provider that queries `lower(email) = ?`.

## Info

### IN-01: ARES response is not checked against the requested company number; worst-case latency is long

**File:** `app/Domain/Clients/Ares/AresClient.php:64-91`, `app/Domain/Clients/Ares/AresCompany.php:32-52`
**Issue:** `AresCompany::fromResponse()` takes `companyNumber` from the response `ico`, and `formState()` writes it into the form. The code never compares it with the number that was asked for. A malformed or unexpected payload (for example an `ico` without leading zeros or from a different record) silently replaces the Admin's typed company number and is cached for an hour under the requested key. Separately, three tries with a 3 s connect and 5 s total timeout plus a 200 ms pause can hold the Livewire request for roughly 24 s, which is close to common 30 s PHP/FPM limits.
**Fix:** After mapping, `if ($company->companyNumber !== $companyNumber) { throw new AresLookupFailed(AresFailure::Malformed); }`. Consider `->retry(2, ...)` or a lower `timeout`, so the worst case stays well under 30 s.

### IN-02: Hardcoded Prague time zone in the invitation mail

**File:** `app/Domain/Clients/InvitationMail.php:45`
**Issue:** `->timezone('Europe/Prague')` is a magic string in a domain class. This is an open-source product for "a freelancer or small company" and the foreign-client case is explicitly in scope (D-09), so the instance time zone should come from configuration (`config('app.timezone')` or a typed setting), not a literal.
**Fix:** Use `config('app.timezone')` or an injected setting.

### IN-03: `CreateProject` trusts a stale `trashed()` flag while `InvitePartner` locks and re-reads

**File:** `app/Domain/Projects/Actions/CreateProject.php:58-60`
**Issue:** The "never create a project for an archived client" guard reads the in-memory `$client` that the page loaded earlier. A client archived in another tab between page load and submit still gets a project. `InvitePartner` handles the same race correctly with `lockForUpdate()` and a re-read. Folded into the WR-02 fix, but listed separately because it is a pure D-11 correctness gap even without the currency race.
**Fix:** Re-read the client with `withTrashed()->lockForUpdate()` inside the transaction and test `trashed()` on that row.

### IN-04: `ResendInvitation` does not re-check that the e-mail still has no account

**File:** `app/Domain/Clients/Actions/ResendInvitation.php:33-59`
**Issue:** Resend rotates the token and mails a fresh link even if an account for that address appeared since (for example the person was invited through another route). The link then fails neutrally at accept time, so the result is wasted mail and a confusing experience, not a security problem. `InvitePartner` runs `EmailHasNoAccount`; resend does not.
**Fix:** Run `EmailHasNoAccount` (as a system run) inside the locked transaction and throw a `DomainException` with the existing `email_has_account` message.

### IN-05: Account lockout for an archived client is enforced only in `canAccessPanel()`

**File:** `app/Domain/Identity/Models/User.php:82-102`, `app/Domain/Clients/Actions/DeactivatePartnerAccount.php:40`
**Issue:** Deactivation deletes the Sanctum tokens, but archiving a client does not, and both lockouts depend on the panel gate being the only entry point. Today that is true (no API routes). The first Sanctum-authenticated route added later would accept a token of a deactivated-then-reactivated or archived-client Partner without ever calling `canAccessPanel()`. The docblocks promise "blocks login and invalidates sessions and API tokens" without that caveat.
**Fix:** Record in the Phase 4 hand-over notes that every new authenticated route group must apply the same gate (a shared middleware that calls `canAccessPanel()` or an equivalent `isActive()` check), and consider deleting the tokens in `ArchiveClient` too.

### IN-06: Stray divergence between the country field's validation and `isCzech()`

**File:** `app/Filament/Resources/ClientResource.php:159-167,572-575`
**Issue:** The country input requires the regex `^[A-Z]{2}$` and uppercases only after validation (`dehydrateStateUsing`), while `isCzech()` accepts `cz` in any case to decide whether the ARES button and the Czech company-id rule apply. While the field holds `cz` the ARES button and checksum rule are active, but the form cannot be saved. This is harmless (it fails on save with a regex error), just inconsistent and confusing on blur.
**Fix:** Uppercase the state on update (`->afterStateUpdated(fn (Set $set, $state) => $set('country', mb_strtoupper(trim($state))))`) so the regex and `isCzech()` see the same value.

---

_Reviewed: 2026-10-08_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
