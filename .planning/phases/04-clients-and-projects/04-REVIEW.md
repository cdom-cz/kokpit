---
phase: 04-clients-and-projects
reviewed: 2026-10-09T00:00:00Z
depth: standard
files_reviewed: 25
files_reviewed_list:
  - CONTRIBUTING.md
  - README.md
  - app/Domain/Identity/Models/User.php
  - app/Domain/Projects/Actions/UpdateProject.php
  - app/Domain/Projects/Models/Project.php
  - app/Domain/Shared/Database/MorphMap.php
  - app/Domain/Shared/Tags/TagType.php
  - app/Filament/Resources/ProjectResource.php
  - app/Filament/Resources/ProjectResource/Pages/EditProject.php
  - app/Filament/Resources/ProjectResource/Pages/ViewProject.php
  - app/Providers/AccessServiceProvider.php
  - app/Providers/Filament/AdminPanelProvider.php
  - composer.json
  - composer.lock
  - config/kokpit.php
  - lang/cs/enums.php
  - lang/cs/kokpit.php
  - tests/Arch/ActivityAllowlistTest.php
  - tests/Arch/ModelDeclarationTest.php
  - tests/Feature/Repo/RepositoryFilesTest.php
  - tests/Isolation/CanaryRegistryTest.php
  - tests/Isolation/PanelAccessTest.php
  - tests/Isolation/PartnerSafeColumnsTest.php
  - tests/Isolation/RouteWalkTest.php
  - tests/Support/CanaryRegistry.php
findings:
  critical: 0
  warning: 9
  info: 8
  total: 17
status: issues_found
---

# Phase 4: Code Review Report

**Reviewed:** 2026-10-09
**Depth:** standard
**Files Reviewed:** 25
**Status:** issues_found

## Summary

This pass covers the files that changed after the previous review (commit 487fd4b). The changes are Phase 5 edits to Phase 4 code:

- the project-key freeze (`UpdateProject`, `ProjectResource`, `EditProject`)
- the `Project::tasks()` relation
- the project board link
- the opt-in global search and the all-users bell in `AdminPanelProvider`
- the new morph-map, policy and canary entries
- the hand-over documentation
- the pinned-column tests

I checked the Filament options against `vendor/` (`globalSearchResourceOptIn`, `Heroicon::OutlinedViewColumns`), the language keys the new code uses, and `scripts/check-sensitive.sh` on the docs and lang files.

- **Repository hygiene:** no real-looking client names, prices, e-mails, hostnames, company IDs or personal paths in the changed files. The canary amount `95000` minor units is a fictional fixture value.
- **Global search:** `canGloballySearch()` requires the resource to declare the flag itself and `canAccess()`. Only the Admin-only `TaskResource` qualifies, and a Partner gets no result.
- **Key freeze:** the three layers (form field disabled, Action precheck, `KP002` trigger translated to a field error) are consistent. Lock order with `CreateTask` (board lock, project `FOR SHARE`) cannot deadlock with `UpdateProject`, which takes only the project row.

No Critical findings. The three new warnings concern the actor-dependent precheck in `UpdateProject`/`ProjectResource` and two weak spots in the new repository test. The prior findings WR-01, WR-02, WR-05 and IN-05 still apply to files in this scope. WR-03, WR-04, WR-06, IN-01 to IN-04 and IN-06 concern files outside this scope; `git diff 487fd4b..HEAD` shows those files unchanged, so they are carried forward unchanged and were not re-read.

I could not run the test suite (no PostgreSQL in the review environment), so everything below comes from static reading.

## Warnings

### WR-01: Project Partner scope does not enforce "not archived" itself (carried forward, still applies)

**File:** `app/Domain/Projects/Models/Project.php:99-110` (docblock claim at lines 30-33)
**Issue:** The class docblock says a Partner sees a project only when it "is not archived". `constrainForPartner()` enforces own client, `client_visible = true` and an unarchived client. Archived projects are hidden only because the default `SoftDeletingScope` is also applied. `ProjectResource::getEloquentQuery()` and `getRecordRouteBindingEloquentQuery()` already remove that scope for the Admin resource. A Partner who opens `/admin/projects/{archived-visible-id}` therefore resolves the record before `authorizeAccess()` returns 403 (403 versus 404 leaks existence). The new Phase 5 `Task::constrainForPartner()` (`whereIn project_id, Project::query()`) inherits the same dependence on the soft-delete scope of `Project`.
**Fix:**
```php
$query
    ->where("{$table}.client_id", $clientId)
    ->where("{$table}.client_visible", true)
    ->whereNull("{$table}.deleted_at")
    ->whereExists(...);
```
Add a test that runs `Project::withTrashed()` as a Partner and expects an archived visible project not to be returned.

### WR-02: Client currency and project money currency are checked outside any lock (carried forward, still applies)

**File:** `app/Domain/Projects/Actions/UpdateProject.php:80-94` (plus `app/Domain/Clients/Actions/UpdateClient.php:43-61` and `app/Domain/Projects/Actions/CreateProject.php:56-110`, unchanged)
**Issue:** `UpdateProject` reads `$client->currency` and builds `Money` before `DB::transaction()` and takes no lock on the client row. Interleaving: `UpdateClient` sees no project money, a project rate in the old currency commits, `UpdateClient` commits the new currency. The result is a project rate in a currency different from the client's. The DB does not guard this invariant (the migration says it is the Actions' job).
**Fix:** In both paths, lock the client row (`lockForUpdate` in `UpdateClient`, `sharedLock` in the project Actions) inside the transaction. Re-read `currency` and `deleted_at` from that locked row and build the `Money` values from it.

### WR-03: Plaintext invitation token persisted in queue payload and failed_jobs (carried forward, file unchanged)

**File:** `app/Domain/Clients/Notifications/PartnerInvitation.php:26-33`, `app/Domain/Clients/InvitationMail.php:29-47`
**Issue:** The signed accept URL, which contains the plain token, is a public property of the queued notification. It lands in `jobs` and, on failure, in `failed_jobs.payload`. Anyone who can read these stores or a backup obtains a live account-creation link.
**Fix:** Make the notification `ShouldBeEncrypted`, or build the URL inside the job from an id plus a short-lived encrypted token. At minimum, prune `failed_jobs` quickly and document the exposure.

### WR-04: "Send password reset" reports success when nothing was sent (carried forward, file unchanged)

**File:** `app/Domain/Clients/Actions/SendPartnerPasswordReset.php:37-41`, `app/Filament/Resources/ClientResource/RelationManagers/PartnerAccountsRelationManager.php:104-118`
**Issue:** The Action returns silently when `canAccessPanel()` is false (for example a Partner of an archived client). The UI then shows the green "reset sent" notification.
**Fix:** Throw a `DomainException` with a clear reason so the red failure notification appears. Also hide the action when the owner client is archived.

### WR-05: Project Actions do not own their input validation (carried forward, still applies to UpdateProject)

**File:** `app/Domain/Projects/Actions/UpdateProject.php:139-164`, `app/Domain/Projects/Actions/ProjectInput.php`, `app/Domain/Clients/Actions/ClientInput.php`
**Issue:** The Action validates money, estimate, fixed price, client immutability and (new) the key freeze, and nothing else. A non-form caller gets a raw `QueryException` or `ValueError` instead of a field error. Examples:
- `key` is not matched against `^[A-Z]{2,6}$`. With the new freeze, `key => ''` on a project with no tasks passes `isset()` and is written as `''`.
- `name` is not checked.
- `status` and `priority` strings are not checked.
- `end_date < start_date` is not checked.

`ClientInput` has no length checks for the optional text columns.
**Fix:** Add a `ProjectInput::attributes()` that validates name, key pattern, enum values and the date order, each as a keyed `ValidationException`. Add `mb_strlen` checks to `ClientInput`. Add Action-level tests that bypass the form.

### WR-06: Reset and login e-mail lookup is case-sensitive although the schema is case-insensitive (carried forward, file unchanged)

**File:** `app/Filament/Pages/Auth/RequestPasswordReset.php:49-50`
**Issue:** `users_email_lower_unique` treats e-mail as case-insensitive and invitations store lowercase addresses. The forgot-password page passes the typed address unchanged, so `Jane@Example.com` gets the neutral answer and no mail.
**Fix:** Normalise with `mb_strtolower(trim($email))` at the boundary, or use a user provider that queries `lower(email)`.

### WR-07: The project-key freeze precheck reads tasks through the actor-dependent Partner scope

**File:** `app/Domain/Projects/Actions/UpdateProject.php:76` and `app/Filament/Resources/ProjectResource.php:122-125`
**Issue:** `$project->tasks()->withTrashed()->exists()` goes through the `Task` model, whose `PartnerScope` is fail-closed. `PartnerScope` returns `whereRaw('1 = 0')` for a guest and for any state that is not Admin, system or a Partner with a client. In a context with no signed-in user (an artisan command, a queue job, a seeder or an import calling `UpdateProject` outside `runAsSystem`), the precheck is always false. The domain-level `key_frozen` check never fires. Only the `projects_key_frozen_guard` trigger remains, reached after `$project->update()` has run and been rolled back. The Action's docblock presents the precheck as an equal guard, and `CONTRIBUTING.md` says "`UpdateProject` and the edit form refuse it earlier". The same call in `keyIsFrozen()` would also under-report if the form were ever rendered for a non-Admin.
**Fix:** Make the precheck independent of the actor, for example:
```php
app(PartnerContext::class)->runAsSystem(
    static fn (): bool => $project->tasks()->withTrashed()->exists(),
)
```
Or use `DB::table('tasks')->where('project_id', $project->getKey())->exists()`, which `QueryEscapeHatchTest` flags unless the file is allowlisted, so prefer `runAsSystem`. Add a test that calls `UpdateProject` with no signed-in user and a project that has an archived task.

### WR-08: Repository test depends on an untracked-by-contract planning file and reads it unguarded

**File:** `tests/Feature/Repo/RepositoryFilesTest.php:474-481`
**Issue:** `file_get_contents(base_path('.planning/REQUIREMENTS.md'))` returns `false` plus an E_WARNING if the file is missing (a trimmed checkout, a release export, a Docker context that ignores `.planning/`). The warning makes Pest fail with an unrelated error, and `expect(false)->not->toContain(...)` is meaningless. The test also pins the wording `task:<project uuid>` of a planning document, so editing the requirement text breaks the product test suite.
**Fix:** Skip when the file is absent and assert on stable facts:
```php
$path = base_path('.planning/REQUIREMENTS.md');
if (! is_file($path)) {
    $this->markTestSkipped('.planning is not part of this checkout');
}
```
Or drop the wording assertion and test that the `projects` table has no counter column (already covered by `PartnerSafeColumnsTest` and `TasksTableTest`).

### WR-09: The "only names Phase 5 classes that exist" test is vacuous for several names

**File:** `tests/Feature/Repo/RepositoryFilesTest.php:406-433`
**Issue:** The loop asserts `expect($contributing)->toContain($short)` with a plain substring. `TaskBilling` is contained in `TaskBillingResolver` and `TaskBillingResolverTest`. `TaskComment` is contained in `TaskCommentsTest`. `TaskBoard` is contained in `TaskBoardTest`. `TaskNotification` is contained in `TaskNotifier`'s sibling `TaskNotificationsTest` (the author fixed this for one name only, with the backticked check at the end). So the documentation can drop a mechanism and the test still passes for those names. Only the `class_exists` half of the loop is real.
**Fix:** Match whole words, for example `expect(preg_match('/\b'.preg_quote($short, '/').'\b/', $contributing))->toBe(1)`, or match the backticked form (`` "`{$short}`" ``) for every name and make sure the document backticks each one.

## Info

### IN-01: ARES response is not checked against the requested company number; worst-case latency is long (carried forward, file unchanged)

**File:** `app/Domain/Clients/Ares/AresClient.php:64-91`, `app/Domain/Clients/Ares/AresCompany.php:32-52`
**Issue:** The mapped `ico` of the response replaces the typed number without a comparison, and is cached under the requested key. Three tries at 3 s connect / 5 s total plus pauses can hold the request for about 24 s.
**Fix:** After mapping, throw `AresLookupFailed(AresFailure::Malformed)` when the number differs. Reduce the retries or the timeout.

### IN-02: Hardcoded Prague time zone in the invitation mail (carried forward, file unchanged)

**File:** `app/Domain/Clients/InvitationMail.php:45`
**Issue:** `->timezone('Europe/Prague')` is a magic string in a domain class of an open-source product.
**Fix:** Use `config('app.timezone')` or a typed setting.

### IN-03: `CreateProject` trusts a stale `trashed()` flag (carried forward, file unchanged)

**File:** `app/Domain/Projects/Actions/CreateProject.php:58-60`
**Issue:** A client archived in another tab between page load and submit still gets a project. `InvitePartner` locks and re-reads.
**Fix:** Re-read the client with `withTrashed()->lockForUpdate()` inside the transaction and test `trashed()` there (folds into WR-02).

### IN-04: `ResendInvitation` does not re-check that the e-mail still has no account (carried forward, file unchanged)

**File:** `app/Domain/Clients/Actions/ResendInvitation.php:33-59`
**Issue:** A fresh link is mailed for an address that has since got an account; the link then fails neutrally.
**Fix:** Run `EmailHasNoAccount` inside the locked transaction.

### IN-05: Account lockout for an archived client is enforced only in `canAccessPanel()` (carried forward, still applies)

**File:** `app/Domain/Identity/Models/User.php:82-102`, `app/Domain/Clients/Actions/DeactivatePartnerAccount.php:40`
**Issue:** Archiving a client does not delete Sanctum tokens, and both lockouts rely on the panel gate being the only entry point. The Phase 12 hand-over note covers deactivated users only, not Partners of an archived client. `User` also gained the `notification_preferences` array cast; the attribute is correctly not mass-assignable.
**Fix:** Extend the Phase 12 hand-over note to "every authenticated route group applies `canAccessPanel()`", and consider deleting tokens in `ArchiveClient`.

### IN-06: Country field validation diverges from `isCzech()` (carried forward, file unchanged)

**File:** `app/Filament/Resources/ClientResource.php:159-167,572-575`
**Issue:** The regex requires upper-case, and the value is upper-cased only after validation, while `isCzech()` accepts `cz`. The ARES button is active for a value that cannot be saved.
**Fix:** Upper-case in `afterStateUpdated` so both see the same value.

### IN-07: `keyIsFrozen()` runs a query on every evaluation

**File:** `app/Filament/Resources/ProjectResource.php:122-125,173-177`
**Issue:** The key field evaluates it in both `helperText()` and `disabled()`, so the edit form issues at least two identical `EXISTS` queries on every render and every Livewire round trip. Behaviourally correct, but the result can differ between the two closures within one render if a task is created in between, producing a disabled field with the "not frozen" hint.
**Fix:** Memoise per record for the request, for example a `private static array $frozen = []` keyed by `$record->getKey()`, or compute once in `mount` and keep it in a form-state flag.

### IN-08: Hand-over notes in `CONTRIBUTING.md` are out of phase order

**File:** `CONTRIBUTING.md:26-30`
**Issue:** The notes now run Phase 6, 7, 9, 10, then 8, then 12. The Phase 8 (exports) note is left after Phase 10.
**Fix:** Move the Phase 8 bullet between Phase 7 and Phase 9. `RepositoryFilesTest` only checks that the entries exist, so it will not complain.

---

_Reviewed: 2026-10-09_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
