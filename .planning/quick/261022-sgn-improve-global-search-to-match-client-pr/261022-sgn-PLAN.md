---
phase: quick-261022-sgn
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Filament/Resources/ClientResource.php
  - app/Filament/Resources/ProjectResource.php
  - app/Filament/Resources/TaskResource.php
  - app/Providers/Filament/AdminPanelProvider.php
  - tests/Feature/Search/GlobalSearchTest.php
  - tests/Feature/Tasks/TaskResourceTest.php
  - tests/Isolation/PanelAccessTest.php
autonomous: true
requirements: [TA-02, AUD-01]

estimate:
  tokens: 55000
  raw_tokens: 55000
  tasks: 2
  confidence: low

must_haves:
  truths:
    - "Typing part of a client name into the existing top-bar global search (Filament's built-in as-you-type dropdown, unchanged in look and behaviour) suggests the client under the 'Klienti' group. The title is the client name and the details are the company number (only when filled) and the stage. Clicking it opens /admin/clients/{id}"
    - "Typing a project key (for example 'nwd' for NWD, any letter case) or a word of a project name suggests the project under 'Projekty'. The title is 'KEY · name' and the details are the client name and the project status. Clicking it opens the project view page"
    - "Tasks are still found by their code KEY-N and by a word of their title under 'Úkoly', exactly as before (title 'KEY-N · title', details project key and status)"
    - "When one term matches records of all three kinds, the groups appear in the order Klienti, Projekty, Úkoly (the sidebar order). Within a group, clients are ordered by name and projects by key"
    - "Archived (soft-deleted) clients, projects and tasks are never suggested"
    - "A Partner gets no suggestion at all. Their own client's name, their client-visible project's key or name, or an own-client task reference all return an empty result. ClientResource, ProjectResource and TaskResource report canGloballySearch() === false for a Partner, and no Partner resource opts in"
    - "Every globally searchable resource of the panel carries #[AccessRule(Audience::AdminOnly)]. The searchable set for the Admin is exactly ClientResource, ProjectResource and TaskResource"
    - "Result details never show a rate, price, currency, payment term or any money"
    - "`ddev composer ci` is green and `scripts/check-sensitive.sh` passes over every touched file"
  artifacts:
    - path: "app/Filament/Resources/ClientResource.php"
      provides: "Admin-only global search opt-in by name, with details, archived exclusion, name ordering and globalSearchSort 10"
      contains: "protected static bool $isGloballySearchable = true;"
    - path: "app/Filament/Resources/ProjectResource.php"
      provides: "Admin-only global search opt-in by key and name, title 'KEY · name', details client and status, archived exclusion, key ordering and globalSearchSort 20"
      contains: "protected static bool $isGloballySearchable = true;"
    - path: "app/Filament/Resources/TaskResource.php"
      provides: "Unchanged search by reference and title, plus globalSearchSort 30 so tasks list after clients and projects"
      contains: "protected static ?int $globalSearchSort = 30;"
    - path: "tests/Feature/Search/GlobalSearchTest.php"
      provides: "Panel-wide global search proofs: client and project matching, details, URLs, archived exclusion, wildcard escaping, group order, the AdminOnly invariant and the Partner empty sweep"
      contains: "getGlobalSearchProvider()"
  key_links:
    - from: "Filament DefaultGlobalSearchProvider::getResults()"
      to: "ClientResource / ProjectResource canGloballySearch()"
      via: "panel globalSearchResourceOptIn(): the class must declare $isGloballySearchable itself, and canGloballySearch() ANDs canAccess(), which EnforcesResourceAccessRule ties to #[AccessRule(Audience::AdminOnly)]"
      pattern: "isGloballySearchable = true"
    - from: "ProjectResource::getGlobalSearchEloquentQuery()"
      to: "Project::client relation"
      via: "eager-loaded with only SoftDeletingScope removed, so the client name detail costs no query per row and the Partner-denying scope stays on"
      pattern: "withoutGlobalScopes\\(\\[SoftDeletingScope::class\\]\\)"
    - from: "DefaultGlobalSearchProvider usort by getGlobalSearchSort()"
      to: "Client 10, Project 20, Task 30"
      via: "static $globalSearchSort on each resource"
      pattern: "globalSearchSort = (10|20|30)"
---

<objective>
Extend the existing global search so the Admin finds clients by name and projects by key (the project "code") or name, in addition to tasks by code (KEY-N) and title, which already work. The as-you-type suggestion dropdown keeps its current look.

What exists today (investigated, not assumed): the search is Filament's built-in panel global search. AdminPanelProvider enables `->globalSearch()->globalSearchResourceOptIn()`, so a resource is searchable only when its own class declares `$isGloballySearchable = true`. TaskResource is the only resource that opts in today: it searches `['reference', 'title']`, shows the title 'KEY-N · title' with the project key and status as details, and leaves archived tasks out. ClientResource and ProjectResource explicitly declare `$isGloballySearchable = false`. The dropdown, its debounce and its grouping by resource label are Filament's own, so the "current style" stays as it is when this plan extends the same mechanism. No custom search component is built, and no view or CSS is touched.

Interpretation (Claude's discretion; this quick task has no CONTEXT.md):
- "Task code" means the task reference KEY-N and "project code" means the project `key`. Task search already matches both the reference and the title (TA-02), so TaskResource only gets a group-order value and a corrected docblock.
- Clients are searched by `name` only, because the request names only names. Searching by company number or contact was not requested and is not added.
- Archived records are left out of the suggestions for clients and projects. This is the same rule TaskResource already applies, and archived records stay reachable from the lists through the trashed filter.
- Group order follows the sidebar order: Klienti (navigationSort 10), Projekty (20), Úkoly (30).
- The Partner keeps getting no global search result. UI-SPEC A-6 (phase 02) says a leakage surface is opened deliberately and only with an access rule. Both newly opened resources already carry `#[AccessRule(Audience::AdminOnly)]`, and the Partner resources (PartnerProjectResource, PartnerTaskResource, PartnerInvoiceResource) stay opted out. Opening search for Partners would be a separate, deliberate decision, and it is out of scope here.
- The ClientResource docblock currently says the client is excluded from global search. That was a phase-3 implementation choice and not a CONTEXT decision. This user request supersedes it. Its intent (client data is Admin-only, and a Partner gets 403 and no result) is kept and proven by tests.

Source coverage audit:
| Source item | Covered by |
|---|---|
| GOAL: match client names | Task 1 (ClientResource opt-in, tests) |
| GOAL: match project names and project codes | Task 1 (ProjectResource opt-in on key and name, tests) |
| GOAL: match task names and task codes | Existing TaskResource search, kept, plus Task 2 regression run of TaskResourceTest |
| GOAL: keep the as-you-type suggestion style | Same Filament mechanism, no view change; Task 2 human check |
| GOAL: Partner restrictions keep applying | Task 1 Partner sweep, AdminOnly invariant test, plus Task 2 Isolation suites |
| REQ TA-02 (keys searchable) | Task 1 (project key), existing task reference search |
| REQ AUD-01 (Partner never sees other data in search) | Task 1 Partner tests, Task 2 InvoiceLeakTest, CardPaymentLeakTest and RouteWalkTest run |

Purpose: one search box gets the Admin to any client, project or task while typing.
Output: three resources opted in or tuned, one new test file, two test files adjusted, and the panel comment corrected.
</objective>

<execution_context>
@~/.claude/gsd-core/workflows/execute-plan.md
@~/.claude/gsd-core/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@.claude/CLAUDE.md
@app/Providers/Filament/AdminPanelProvider.php
@app/Filament/Resources/TaskResource.php
@vendor/filament/filament/src/Resources/Resource/Concerns/HasGlobalSearch.php
@vendor/filament/filament/src/GlobalSearch/Providers/DefaultGlobalSearchProvider.php

<interfaces>
Filament (vendor/filament/filament/src/Resources/Resource/Concerns/HasGlobalSearch.php):
- canGloballySearch(): with resource opt-in, `$isGloballySearchable` must be declared on the class itself, and the result is ANDed with `count(getGloballySearchableAttributes())` and `static::canAccess()`.
- getGloballySearchableAttributes(): array, getGlobalSearchResultTitle(Model): string|Htmlable, getGlobalSearchResultDetails(Model): array<string,string>, getGlobalSearchEloquentQuery(): Builder (default = getEloquentQuery()), getGlobalSearchResultUrl(Model) (view page when the record can be viewed).
- protected static ?int $globalSearchSort (null counts as 0). DefaultGlobalSearchProvider usorts resources by it and groups results under getPluralModelLabel().
- On pgsql the column is wrapped in lower() and the term is lower-cased (case-insensitive). The term is split on whitespace (every word must match some attribute). %, _, [ and ! are escaped with escape '!', and the pattern is a bound parameter.
- GlobalSearchResult has public readonly $title (string|Htmlable), $url (string), $details (array).

Kokpit:
- ClientResource: #[AccessRule(Audience::AdminOnly)], uses EnforcesResourceAccessRule, $recordTitleAttribute = 'name', navigationSort 10. getEloquentQuery() removes SoftDeletingScope and adds select('clients.*') + selectSub primary_contact_name. It already imports Model, Builder and SoftDeletingScope. Pages: index, create, view ('/{record}'), edit.
- ProjectResource: #[AccessRule(Audience::AdminOnly)], $recordTitleAttribute = 'name', navigationSort 20. getEloquentQuery() removes SoftDeletingScope. It already imports Builder and SoftDeletingScope, but NOT Model or Htmlable. Pages include view.
- TaskResource: navigationSort 30, $isGloballySearchable = true, attributes ['reference','title'], query whereNull(tasks.deleted_at) with project eager-loaded withoutGlobalScopes([SoftDeletingScope::class]). This is the pattern to mirror.
- Client: name (string), company_number (?string), stage (ClientStage enum, getLabel()), SoftDeletes, DeniesPartners scope. ClientFactory name = 'Example client ' + 8 random lower-case chars, stage 'active', no company number.
- Project: name, key (2-6 upper-case letters), status (ProjectStatus enum, getLabel()), client(): BelongsTo<Client>, SoftDeletes. Create through app(CreateProject::class)->handle($client, ['name'=>..., 'key'=>..., 'billing_type'=>'hourly', 'client_visible'=>bool]).
- Task: app(CreateTask::class)->handle($admin, $project, ['title' => ...]); reference is KEY-N.
- Labels (lang/cs/kokpit.php): plural 'Klienti', 'Projekty', 'Úkoly'; kokpit.clients.fields.company_number, kokpit.clients.fields.stage, kokpit.clients.fields.hourly_rate, kokpit.projects.fields.client, kokpit.projects.fields.status. No new translation key is needed.
- Test helpers: Tests\Support\Canary::admin(), Canary::partnerFor(?string $clientId). App\Domain\Shared\Auth\AccessRules::for(class)?->audience and App\Domain\Shared\Auth\Audience::AdminOnly. Feature tests get RefreshDatabase from tests/Pest.php. Pest helper functions are global, so new helper names must be unique (the prefixes `gsCategories`/`gsProject` are unused today).
</interfaces>
</context>

<tasks>

<task type="tracer" tdd="true">
  <name>Task 1: Admin finds clients by name and projects by key or name in the global search, Partner still finds nothing</name>
  <files>app/Filament/Resources/ClientResource.php, app/Filament/Resources/ProjectResource.php, tests/Feature/Search/GlobalSearchTest.php, tests/Feature/Tasks/TaskResourceTest.php</files>
  <behavior>
    All tests go through the real panel provider, Filament::getCurrentOrDefaultPanel()->getGlobalSearchProvider()->getResults($term), with the admin panel set current and Canary::admin() signed in (same beforeEach as TaskResourceTest). Every value is fictional ('Example …', keys like NWD and OTH).
    - Client by name: a client named 'Example Northwind client'. The term 'northwind' (lower case) gives the 'Klienti' group with exactly one item. Its title is the client name, its details values are [the stage label] (the factory sets no company number), its details have no key equal to __('kokpit.clients.fields.hourly_rate'), and its url equals ClientResource::getUrl('view', ['record' => $client]).
    - Project by key: a project named 'Example northwind website' with key 'NWD'. The term 'nwd' gives 'Projekty' with one item titled 'NWD · Example northwind website'. Its details values are [client name, status label], and its url equals ProjectResource::getUrl('view', ['record' => $project]).
    - Project by a name word: the term 'website' finds the same project. The term 'northwind website' (two words, every word must match) finds it too.
    - Archived exclusion: a soft-deleted client (->delete()) and a soft-deleted project (->delete(), the same way TaskResourceTest archives a project) are absent. Assert through ClientResource::getGlobalSearchEloquentQuery() and ProjectResource::getGlobalSearchEloquentQuery() id lists (mirrors TaskArchiveTest 'leaves an archived task out of the global search'), and also through the provider (no item for the archived name).
    - Wildcard escaping: with at least one client and one project present, the terms '%' and '_' return no group.
    - Three groups present: one term ('Example') matched by a client, a project and a task yields toHaveKeys(['Klienti', 'Projekty', 'Úkoly']) and not toHaveKey('Aktivita'). The order is asserted in Task 2.
    - Searchable set (moved from TaskResourceTest): for the Admin, the resources in Filament::getResources() whose canGloballySearch() is true equal, canonicalized, [ClientResource::class, ProjectResource::class, TaskResource::class]. Each of them has AccessRules::for($resource)?->audience === Audience::AdminOnly. For a Partner of a client the set is [].
    - Partner sweep: a client with a client_visible project (key 'PVS') and a task in it. The control is that the Admin finds the client name, 'PVS', the project name and the task reference. Then, acting as Canary::partnerFor($client->id), the result for each of those terms and for 'Example' is an empty category list, and ClientResource::canGloballySearch() and ProjectResource::canGloballySearch() are false.
  </behavior>
  <action>
    Open the global search to two more Admin-only resources by mirroring the TaskResource global search block (lines 156-193) exactly. Do not build a custom search component, and do not touch any Blade view or the panel's globalSearch() settings.

    ClientResource:
    - Change `$isGloballySearchable` to true. Above it, write a one-line docblock saying the resource is Admin-only by its AccessRule, is found by name, and that `id` is never searchable.
    - Add `protected static ?int $globalSearchSort = 10;`.
    - Add getGloballySearchableAttributes() returning ['name'].
    - Add getGlobalSearchResultDetails(Model $record). For a non-Client record it returns []. Otherwise it returns, under __('kokpit.clients.fields.company_number'), the company number only when it is filled, then the stage label under __('kokpit.clients.fields.stage'). Give it a docblock saying it never shows a rate, currency, payment term or any money.
    - Add getGlobalSearchEloquentQuery() as parent::getGlobalSearchEloquentQuery(), then whereNull on the qualified clients deleted_at column (built via (new Client)->qualifyColumn('deleted_at'), as TaskResource does), then orderBy the qualified name column. Keep the default title (record title attribute 'name').
    - In the class docblock, rewrite the sentence that excludes the client from global search. It should now say: the resource is globally searchable by name for the Admin only, and a Partner gets 403 on every route and no search result.

    ProjectResource:
    - Change `$isGloballySearchable` to true and add a one-line docblock (Admin-only by its AccessRule, found by key or name, `id` never searchable).
    - Add `protected static ?int $globalSearchSort = 20;`.
    - Add getGloballySearchableAttributes() returning ['key', 'name'].
    - Add getGlobalSearchResultTitle(Model $record): string|Htmlable. For a Project it returns the key, then ' · ', then the name, with the same separator as TaskResource. Otherwise it returns the parent result.
    - Add getGlobalSearchResultDetails(Model $record) returning the client name under __('kokpit.projects.fields.client') (an empty string when the client is missing) and the status label under __('kokpit.projects.fields.status'). It never shows the billing type, a rate, a fixed price, an estimate or the internal note.
    - Add getGlobalSearchEloquentQuery() as parent::getGlobalSearchEloquentQuery(), then whereNull on the qualified projects deleted_at column, then with(['client' => a static closure that calls withoutGlobalScopes([SoftDeletingScope::class])]), the exact eager-load shape TaskResource uses for its project, then orderBy the qualified key column.
    - Add the imports `Illuminate\Database\Eloquent\Model` and `Illuminate\Contracts\Support\Htmlable`.

    Tests:
    - Create tests/Feature/Search/GlobalSearchTest.php with declare(strict_types=1), a short header comment (panel-wide global search: clients, projects and tasks for the Admin, nothing for a Partner, every name fictional), the same beforeEach as TaskResourceTest, and helpers gsCategories(string $term) and gsProject(Client $client, string $key, string $name, bool $visible = false). gsCategories returns label => list of ['title','details','url'], built like taskResSearch, with url read from $item->url. Then write the tests listed in <behavior> first and watch them fail.
    - In tests/Feature/Tasks/TaskResourceTest.php, delete the two panel-wide tests that the new file replaces: the one asserting that no project, client or activity group appears for 'Example', and the one asserting that TaskResource is the single searchable resource. Keep the task key, title-word and Partner-reference tests unchanged. Remove any import that becomes unused.
  </action>
  <verify>
    <automated>ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Search/GlobalSearchTest.php tests/Feature/Tasks/TaskResourceTest.php tests/Feature/Tasks/TaskArchiveTest.php && ddev exec vendor/bin/pint --test app/Filament/Resources/ClientResource.php app/Filament/Resources/ProjectResource.php tests/Feature/Search/GlobalSearchTest.php tests/Feature/Tasks/TaskResourceTest.php && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G app/Filament/Resources/ClientResource.php app/Filament/Resources/ProjectResource.php && test "$(grep -l 'isGloballySearchable = true' app/Filament/Resources/ClientResource.php app/Filament/Resources/ProjectResource.php | wc -l | tr -d ' ')" = 2 && ! grep -q 'not globally searchable' app/Filament/Resources/ClientResource.php && grep -q 'isGloballySearchable = false' app/Filament/Partner/Resources/PartnerProjectResource.php && grep -q 'isGloballySearchable = false' app/Filament/Partner/Resources/PartnerTaskResource.php</automated>
  </verify>
  <done>The Admin finds a client by any part of its name and a project by its key (any letter case) or a name word, through the real panel provider, with the titles, details and view-page URLs above. Archived clients and projects are not suggested. A Partner gets an empty result for every term, and the searchable set is exactly the three AdminOnly resources. Grep baselines at HEAD: zero of the two files opt in (gate expects 2), and the ClientResource docblock still carries the exclusion sentence (the negative gate fails at HEAD, so it proves the rewrite).</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Suggestions grouped Klienti, Projekty, Úkoly in sidebar order, panel comments truthful, full gates green</name>
  <files>app/Filament/Resources/TaskResource.php, app/Providers/Filament/AdminPanelProvider.php, tests/Isolation/PanelAccessTest.php, tests/Feature/Search/GlobalSearchTest.php</files>
  <behavior>
    - Group order: one term ('Example') matched by a client, a project and a task gives array_keys of the categories === ['Klienti', 'Projekty', 'Úkoly'] (toBe, order-sensitive). This fails before TaskResource gets its sort value, because a null sort counts as 0 and puts Úkoly first.
    - In-group order: two clients 'Example Beta client' and 'Example Alpha client' give Alpha before Beta for the term 'Example' in 'Klienti'. Two projects with keys 'OTH' and 'NWD' give NWD before OTH in 'Projekty'.
  </behavior>
  <action>
    - TaskResource: add `protected static ?int $globalSearchSort = 30;` next to `$isGloballySearchable`. Rewrite the one-line docblock above `$isGloballySearchable`, which today describes TaskResource as the panel's single searchable resource. It should say: one of the three Admin-only searchable resources (with ClientResource and ProjectResource), found by KEY-N or title, and `id` is never searchable. Do not change getGloballySearchableAttributes(), the title, the details or the query. Task code and title search already satisfy the request (TA-02).
    - AdminPanelProvider: rewrite the comment block above `->globalSearch()`. Keep the opt-in rule. Name the three searchable resources and what each is found by: ClientResource by name, ProjectResource by key or name, TaskResource by KEY-N or title. Say that each is Admin-only by its #[AccessRule], that canGloballySearch() checks canAccess(), so a Partner sees no result at all (UI-SPEC A-6), and that groups follow the sidebar order through $globalSearchSort. Do not change any method call in the chain.
    - tests/Isolation/PanelAccessTest.php: update only the comment inside the test 'runs the panel with strict authorization and global search as a per-resource opt-in'. It should say the searchable resources are the Admin-only Client, Project and Task resources. The test body stays as it is.
    - tests/Feature/Search/GlobalSearchTest.php: add the order tests from <behavior>.
    - Run the full gate, then `scripts/check-sensitive.sh` over every file this plan touched.
  </action>
  <verify>
    <automated>ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Search/GlobalSearchTest.php tests/Feature/Tasks/TaskResourceTest.php tests/Isolation/PanelAccessTest.php tests/Isolation/InvoiceLeakTest.php tests/Isolation/CardPaymentLeakTest.php tests/Isolation/RouteWalkTest.php tests/Feature/Documents/DocumentResourceTest.php && grep -q 'globalSearchSort = 30' app/Filament/Resources/TaskResource.php && grep -q 'globalSearchSort = 10' app/Filament/Resources/ClientResource.php && grep -q 'globalSearchSort = 20' app/Filament/Resources/ProjectResource.php && ! grep -q 'TaskResource is the only one' app/Providers/Filament/AdminPanelProvider.php && ! grep -q 'The only searchable resource' app/Filament/Resources/TaskResource.php && ddev composer ci && scripts/check-sensitive.sh app/Filament/Resources/ClientResource.php app/Filament/Resources/ProjectResource.php app/Filament/Resources/TaskResource.php app/Providers/Filament/AdminPanelProvider.php tests/Feature/Search/GlobalSearchTest.php tests/Feature/Tasks/TaskResourceTest.php tests/Isolation/PanelAccessTest.php</automated>
    <human-check>Signed in as the Admin at /admin, type part of a client name into the top-bar search: while you type, the same dropdown as before shows a 'Klienti' group, and clicking an item opens the client page. Type a project key in lower case: 'Projekty' shows 'KEY · name' with the client and status. Type a task key such as ABC-1: 'Úkoly' shows it as before. A term matching all three lists the groups Klienti, Projekty, Úkoly in that order. Signed in as a Partner, the same terms show no result.</human-check>
  </verify>
  <done>Groups always appear in the order Klienti, Projekty, Úkoly, with clients sorted by name and projects by key. The panel, TaskResource and PanelAccessTest comments describe the three Admin-only searchable resources. The Isolation suites (InvoiceLeakTest, CardPaymentLeakTest, RouteWalkTest) and DocumentResourceTest stay green. `ddev composer ci` and check-sensitive pass. Grep baselines at HEAD: there is no globalSearchSort in any of the three resources, and both stale comment literals are present, so every gate in this verify fails at HEAD and passes only after the change.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| browser -> Filament global search (Livewire) | The search term is untrusted input and goes into a SQL LIKE predicate on three tables |
| Partner session -> Admin-only resources | A Partner shares the admin panel and its top-bar search with the Admin and must get nothing from the Client, Project or Task resources |

## STRIDE Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation Plan |
|-----------|----------|-----------|----------|-------------|-----------------|
| T-261022-sgn-01 | Information Disclosure | ClientResource / ProjectResource global search | high | mitigate | Both resources keep #[AccessRule(Audience::AdminOnly)] with EnforcesResourceAccessRule::canAccess(). Filament's canGloballySearch() ANDs canAccess(), so a Partner's provider skips them before any query runs. Partner resources stay at `$isGloballySearchable = false` (grep-gated in Task 1). Proven by the Task 1 Partner sweep (own client name, own visible project key and name, own task reference all return empty), the invariant test that every searchable resource is AdminOnly and the Partner set is empty, and the existing InvoiceLeakTest/CardPaymentLeakTest Partner search sweeps (re-run in Task 2) |
| T-261022-sgn-02 | Tampering | Search term into the LIKE predicate | medium | mitigate | Filament binds the pattern as a query parameter and escapes %, _, [ and ! with escape '!'. No raw SQL is added. Proven by the Task 1 test that '%' and '_' return no group on a populated database |
| T-261022-sgn-03 | Information Disclosure | Result details shown in the dropdown | low | mitigate | The details are limited to company number and stage (client) and client name and status (project), never a rate, price, currency, payment term or money. The test asserts that the hourly-rate label key is absent |
| T-261022-sgn-04 | Information Disclosure | Archived clients and projects | low | mitigate | getGlobalSearchEloquentQuery() adds whereNull on the qualified deleted_at, the same as TaskResource. Proven by the archived tests (query id list and provider result) |
| T-261022-sgn-05 | Denial of Service | Leading-wildcard LIKE on each keystroke | low | accept | One company's small tables, Filament's per-resource limit of 50 rows and its debounced input. No index work is warranted for a single-tenant instance |
| T-261022-sgn-06 | Information Disclosure | Test fixtures and planning files | low | mitigate | Fictional 'Example …' names, fictional keys and Canary accounts only. `scripts/check-sensitive.sh` runs over every touched file, plus the lefthook gitleaks hook |
| T-261022-sgn-SC | Tampering | npm/pip/cargo/composer installs | low | accept | This plan installs no package |
</threat_model>

<verification>
- `ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Search/GlobalSearchTest.php tests/Feature/Tasks/TaskResourceTest.php tests/Isolation/PanelAccessTest.php` is green
- `ddev composer ci` is green (pest, pint, phpstan, licenses)
- `scripts/check-sensitive.sh` passes over every touched file
- Human check (end of phase, not blocking): see the Task 2 human-check. The dropdown looks and behaves as before, with the Klienti and Projekty groups added ahead of Úkoly, and a Partner sees nothing
</verification>

<success_criteria>
- The Admin finds clients by name, projects by key or name, and tasks by KEY-N or title, in the existing as-you-type dropdown, grouped Klienti, Projekty, Úkoly
- Archived records are not suggested, and no detail shows money
- A Partner gets no result for any term. This is enforced by the AccessRule and canAccess() inside canGloballySearch(), not by hiding, and it is proven by tests
- The full CI gate and the sensitive-data check are green
</success_criteria>

<output>
Create `.planning/quick/261022-sgn-improve-global-search-to-match-client-pr/261022-sgn-SUMMARY.md` when done
</output>
