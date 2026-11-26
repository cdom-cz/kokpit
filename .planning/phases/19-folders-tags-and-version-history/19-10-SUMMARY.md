---
phase: 19-folders-tags-and-version-history
plan: 10
subsystem: knowledge
tags: [filament, form, classification, tags, prefill, meta-row]
requires:
  - "19-02: copy keys (knowledge.links.*, knowledge.tags.*, knowledge.notifications.classification_changed), KnowledgeFixtures"
  - "19-04: ArticleClassification, ArticleInput classification, SaveOutcome::ClassificationChanged"
  - "19-05: FolderTree::load(), nodes(), pathLabel()"
  - "19-06: the resource file with the versions pages and sub-navigation"
provides:
  - "Zarazeni section (folder, knowledge tags, client, project) in KnowledgeArticleResource::form()"
  - "classification fill and save on the edit screen, classification on the create screen"
  - "validated ?folder=, ?client=, ?project= prefill of the create screen"
  - "second meta row of the view screen (folder path link, client and project links, tag chips)"
affects: [19-07 Knowledge tabs (Pridat clanek link target), 19-08 list (Novy clanek folder link target)]
tech-stack:
  added: []
  patterns:
    - "plain TagsInput bound to form state (not the Spatie plugin input) so tags are written inside the SaveArticle transaction"
    - "only the client select is live; the project select is disabled and cleared through afterStateUpdated"
key-files:
  created:
    - tests/Feature/Knowledge/ArticleClassificationFormTest.php
    - tests/Feature/Knowledge/ArticleLinksTest.php
    - tests/Feature/Knowledge/KnowledgeTagsTest.php
  modified:
    - app/Filament/Resources/KnowledgeArticleResource.php
    - app/Filament/Resources/KnowledgeArticleResource/Pages/EditKnowledgeArticle.php
    - app/Filament/Resources/KnowledgeArticleResource/Pages/CreateKnowledgeArticle.php
    - resources/views/filament/knowledge/article-meta.blade.php
decisions:
  - "R-19-10-1 to R-19-10-4 implemented as planned"
  - "The classification_changed notification mapping on the edit screen already existed from 19-04, so Task 1 reused it instead of adding a second one"
  - "The create prefill reads request()->query() once in mount() and merges the taken values with $this->form->fill([...$this->data, ...$prefill]); a project is considered only after its client was accepted"
  - "The view meta row shows an archived client or project too (their view routes resolve archived records), through loadMissing with withTrashed"
  - "The meta view defaults every classification variable, so a caller that passes none still renders the first line only"
metrics:
  tasks: 3
  completed: 2026-11-26
status: complete
commits: 3
plan_head_before: ed7812d712dcc9ad639da70f88e2294e937678b1
plan_head_after: 47de4400548bf26929ca99694b0359c644cc2f78
actuals:
  tokens: 21000
  tasks: 3
  commits: 3
---

# Phase 19 Plan 10: Classification in the article editor and on the view screen Summary

The Admin can file, tag and link an article where it is written: the create and edit screens have a Zarazeni section that saves with the content through the one SaveArticle path, the create screen takes a validated prefill from the list and the Knowledge tabs, and the view screen shows a second meta row with the folder path, client, project and tag chips.

## What was built

- **Form section (Task 1).** `KnowledgeArticleResource::form()` has a two-column `Section` between the editor tabs and the sensitive toggle with: `Select folder_id` (options from `FolderTree::load()` as path labels, placeholder "Bez slozky"), a plain `TagsInput tags` (suggestions only from `Tag::getWithType('knowledge')`, split keys Enter and comma, 40-character item rule), `Select client_id` (live; archived clients left out, the stored one stays offered) and `Select project_id` (disabled with the helper "Nejdriv vyberte klienta." until a client is chosen, options only the selectable projects of that client as "KEY . name", the stored project stays offered). Changing or clearing the client clears a project that does not belong to it, as `TransactionResource::clientChanged()` does. No other field of the section is live.
- **Edit screen (Task 1).** `mutateFormDataBeforeFill()` fills the folder, client, project and the knowledge tag names. `handleRecordUpdate()` builds `ArticleClassification::from(...)` inside the existing `withFormErrors()` closure and passes it as `classification:`, so a field error lands under its field and every refused save (stale version or redaction, secret guard, write failure) keeps the typed classification. The `ClassificationChanged` notice mapping came from 19-04.
- **Create screen (Task 2).** `handleRecordCreation()` passes the classification the same way. `mount()` reads `folder`, `client` and `project` from the query string once; a value is taken only when it is a UUID (`Str::isUuid`, lowercased) of an existing folder, an existing non-archived client and a selectable project of that client. A project without a valid client, a malformed value, an array and an empty string are ignored silently.
- **View screen (Task 3).** The infolist passes `folderPath`, `folderUrl`, `clientName`, `clientUrl`, `projectLabel`, `projectUrl` and `tags` (knowledge names only) to `filament.knowledge.article-meta`, which renders a second muted row (14px, wrap, 8px gap) with the folder link, client and project links (`x-filament::link`) and gray badges, each only when set and the whole row only when one is set. Escaped output only.

## Tests written (not run in the worktree)

- `tests/Feature/Knowledge/ArticleClassificationFormTest.php`: fill of the four values, folder path labels, classification-only save (notice, no new version, folder and tags written), body plus client in one save, client with project and clearing, project cleared on client clear and change, disabled project with helper and per-client options (archived and foreign projects excluded), forged project of another client as a field error under Projekt, stale version and stale redaction keeping typed values and writing nothing, secret-guard refusal keeping the classification, only the client select live, section order.
- `tests/Feature/Knowledge/ArticleLinksTest.php`: create with folder, client, project and two tags; foreign project refused as a field error; prefill of folder and of client plus project; create with prefilled values; a dataset of ignored values (non-UUID, unknown, list, project without client, empty); archived client; foreign project dropped; capital UUID; the composite foreign key refusing a raw insert with SQLSTATE 23503.
- `tests/Feature/Knowledge/KnowledgeTagsTest.php`: knowledge editor suggests only knowledge tags (and is not the Spatie input); client, project and task forms never suggest a knowledge tag; a tag typed in the editor is stored typed `knowledge` and stays out of client suggestions; case-insensitive merge into the existing tag; second meta row with links and chips; no row when nothing is set; chips only; archived client still shown; escaped tag name.

## Verification status

Worktree only (no vendor, no database): `php -l` clean on all seven files, the plan's greps pass (`SpatieTagsInput::make` 0, `knowledge.links.section` 1, `TagType::Knowledge->value` 2, `classification_changed` 1 on the edit page, `ArticleClassification::from(` 1 on each page, `Str::isUuid` 1, `query('project')` 1, `folderUrl` and `clientUrl` in the meta view, `getSuggestions` 2 in the tags test), `scripts/check-sensitive.sh` and gitleaks clean on every commit. The Pest runs are NOT run in the worktree. They belong after the merge of wave 3, one process at a time and without `--parallel`:

1. `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Knowledge/ArticleClassificationFormTest.php tests/Feature/Knowledge/EditKnowledgeArticleTest.php`
2. `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Knowledge/ArticleLinksTest.php tests/Feature/Knowledge/CreateKnowledgeArticleTest.php`
3. `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Knowledge/KnowledgeTagsTest.php tests/Feature/Knowledge/KnowledgeArticleResourceTest.php tests/Arch/KnowledgeSeamTest.php tests/Isolation/KnowledgeVisibilityTest.php`

Points most likely to need a small adjustment when the tests first run, because the Filament and Livewire testing APIs could not be checked without vendor: `$form->getComponent('project_id')->getHelperText()` and `isLive()`, `Livewire::withQueryParams()` reaching `request()->query()` in `mount()`, and `assertStatus(200)` on a Livewire test.

## Deviations from Plan

None - plan executed as written. The `classification_changed` mapping the plan asked for in `getSavedNotification()` was already added by 19-04 and was reused. The classification meta data (`classificationMeta()` in the resource) landed in the Task 1 commit together with the form section because both live in the same file; the meta view and its tests are in Task 3.

## Known Stubs

None.

## Threat Flags

None. No new endpoint, auth path or schema; T-19-39 to T-19-42 are covered by the UUID and existence checks of the prefill (and the second validation in ArticleClassification and SaveArticle), field errors under their fields, `Tag::getWithType('knowledge')` suggestions with the other forms staying typed, and the single live field.

## Self-Check: PASSED

- Files exist: ArticleClassificationFormTest.php, ArticleLinksTest.php, KnowledgeTagsTest.php (created); the four modified files present.
- Commits exist in HEAD history: 9e0866a2 (edit screen and form section), c455a670 (create screen and prefill), 47de4400 (view meta row and tag separation tests).
