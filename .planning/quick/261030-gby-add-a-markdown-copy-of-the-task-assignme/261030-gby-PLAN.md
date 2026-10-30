---
phase: quick-261030-gby
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - composer.json
  - composer.lock
  - app/Domain/Shared/Text/RichTextMarkdown.php
  - app/Domain/Shared/Text/StrikethroughConverter.php
  - app/Filament/Resources/TaskResource/Pages/ViewTask.php
  - lang/cs/kokpit.php
  - tests/Feature/Tasks/TaskMarkdownCopyTest.php
  - tests/Unit/Text/RichTextMarkdownTest.php
autonomous: true
requirements: [TA-01]

estimate:
  tokens: 80000
  raw_tokens: 80000
  tasks: 3
  confidence: low

must_haves:
  truths:
    - "On the Admin task page /admin/tasks/KEY-N (ViewTask) there is a gray header action 'Kopírovat jako Markdown' (kokpit.tasks.markdown.action). Clicking it opens a modal 'Zadání úkolu v Markdownu' with a read-only monospace textarea that holds the Markdown (the preview), a 'Kopírovat' button that writes exactly that Markdown to the clipboard and shows the toast 'Markdown je zkopírovaný do schránky', and a 'Zavřít' button. The modal has no submit and writes nothing. Clicking or focusing the textarea selects all of it, so a manual copy also works"
    - "The Markdown starts with '# ' and the task title only, with its whitespace collapsed to single spaces. The KEY-N reference never appears in it. After one blank line comes the task description converted from HTML to Markdown, and the text ends with one newline. A task with no description gives only the H1 line"
    - "Every format the description editor offers survives the conversion: h2 and h3 become '##' and '###', bold '**', italic '*', strike '~~', links '[text](url)', bullet, ordered and nested lists, a blockquote '>', a table as a GFM pipe table with a separator row, and a line break. Underline, which Markdown has no syntax for, stays as plain text with no leftover tag. Czech diacritics come through intact. The output has no whitespace-only lines and no run of three or more newlines"
    - "The description is cleaned again through RichText::render() before it is converted. Hostile markup written around the Actions straight into the row gives no script, event handler, image, inline style or javascript: link in the Markdown"
    - "A Partner gains nothing. /admin/tasks/KEY-N stays 403 for a Partner. On an Admin-mounted page after the session switched to a Partner, the action is hidden, and the modal fill re-checks TaskResource::canAccess(). The Partner page /admin/my-tasks/KEY-N has no copyMarkdown action and keeps its pinned header actions. TaskPolicy, every Partner class and TaskColumns stay unchanged"
    - "league/html-to-markdown (MIT, thephpleague) is the only new direct dependency. It is locked in composer.lock, and the licence gate `composer licenses --locked --format=json | php scripts/check-licenses.php` passes"
    - "After the merge into the HEAD branch, the full suite run the CI way, pint, phpstan and `scripts/check-sensitive.sh` over every touched file are green"
  artifacts:
    - path: "app/Domain/Shared/Text/RichTextMarkdown.php"
      provides: "convert(?string $html): string (RichText::render, then league HtmlConverter with ATX headings, stripped unknown tags, the table and strikethrough converters, and normalised newlines) and document(string $title, ?string $html): string (the '# title' H1 plus the converted body)"
      contains: "final class RichTextMarkdown"
    - path: "app/Domain/Shared/Text/StrikethroughConverter.php"
      provides: "A league ConverterInterface for del, s and strike that emits GFM '~~text~~', with surrounding whitespace kept outside the markers"
      contains: "implements ConverterInterface"
    - path: "app/Filament/Resources/TaskResource/Pages/ViewTask.php"
      provides: "The private copyMarkdownAction() header action: a read-only preview modal filled from RichTextMarkdown::document() and a clipboard button wired through alpineClickHandler"
      contains: "Action::make('copyMarkdown')"
    - path: "lang/cs/kokpit.php"
      provides: "The kokpit.tasks.markdown group: action, heading, description, label, copy, copied, close"
      contains: "'markdown' => ["
    - path: "composer.json"
      provides: "The league/html-to-markdown caret requirement"
      contains: "\"league/html-to-markdown\""
    - path: "tests/Feature/Tasks/TaskMarkdownCopyTest.php"
      provides: "End-to-end proofs on ViewTask: the exact Markdown in the modal, the copy wiring, the empty, archived and forged-description cases, and the Partner refusals"
      contains: "copyMarkdown"
    - path: "tests/Unit/Text/RichTextMarkdownTest.php"
      provides: "Converter fidelity: every editor format, nested lists, line break, hostile input, empty input, title whitespace, diacritics, newline normalisation"
      contains: "RichTextMarkdown::"
  key_links:
    - from: "app/Filament/Resources/TaskResource/Pages/ViewTask.php copyMarkdownAction()"
      to: "app/Domain/Shared/Text/RichTextMarkdown.php"
      via: "fillForm returns ['markdown' => RichTextMarkdown::document($task->title, $task->description)] after abort_unless(TaskResource::canAccess(), 403)"
      pattern: "RichTextMarkdown::document\\("
    - from: "app/Domain/Shared/Text/RichTextMarkdown.php convert()"
      to: "app/Domain/Shared/Text/RichText.php render()"
      via: "the stored HTML is cleaned again through the one sanitiser before the converter sees it"
      pattern: "RichText::render\\("
    - from: "the 'Kopírovat' button in the modal schema"
      to: "the modal's markdown state"
      via: "alpineClickHandler calls navigator.clipboard?.writeText($wire.mountedActions[0].data.markdown) and then sends a FilamentNotification, the same pattern as ListApiTokens::showTokenAction()"
      pattern: "mountedActions\\[0\\]\\.data\\.markdown"
---

<objective>
Let the Admin copy a task's assignment as Markdown from the task page /admin/tasks/KEY-N. The Markdown has an H1 with the task name only (no KEY-N code), and its body is the task description converted from HTML to Markdown. A modal shows the Markdown as a preview, and it is copied from that modal.

Interpretation of the request (Claude's discretion, since this quick task has no CONTEXT.md). The owner wrote, in Czech, "an option to copy the task assignment as MD; possibly show an MD preview in a popup and copy from it; H1 = name without the code; content = Popis (Description) converted from HTML to MD". This plan does both in one flow. A header action on ViewTask opens a preview modal. The modal has a read-only textarea with the Markdown, a one-click 'Kopírovat' button, and select-all on focus as a fallback.
- "Name without the code": a task's address is `reference` (KEY-N, e.g. ABC-12) and its name is `title`. The page title is "reference · title". The H1 is `# ` plus `title` only.
- "Popis" is the `description` column. It stores sanitised HTML from the RichEditor, whose toolbar offers bold, italic, underline, strike, link, h2, h3, blockquote, bullet list, ordered list and table. Images are dropped by the sanitiser.
- Scope: only the Admin task page (ViewTask). EditTask, the board preview slide-over, the Partner task page and the API are not changed. The Partner surfaces stay pinned on purpose, so the Partner gains no new affordance.
- The action is shown on an archived task too, because it is read-only and writes nothing.
- The title goes into the H1 as written (Markdown characters are not escaped), with whitespace collapsed so the H1 stays on one line.

Purpose: the Admin hands a task's assignment to an issue tracker, a chat or an AI coding agent without retyping or reformatting it.
Output: one new dependency (league/html-to-markdown), two small classes in app/Domain/Shared/Text, one header action on ViewTask, seven Czech strings, one Feature test file and one Unit test file.

Design decisions:
- Converter: league/html-to-markdown. composer.json and vendor have no HTML-to-Markdown converter today. league/commonmark and parsedown go the other way (Markdown to HTML), and tiptap-php has no Markdown serializer. The package is MIT and already on the licence allowlist, and it is verified below. Hand-rolling a DOM walker was rejected, because the library already handles escaping, nested lists and GFM tables.
- Sanitise first: convert() passes the stored HTML through RichText::render(), the one sanitiser that every read surface uses. Text written around the Actions is then harmless here as well.
- Converter options: `header_style` 'atx', so editor h2 and h3 keep '##' and '###' under the title H1 (the library default is setext). `strip_tags` true, so underline and any other tag without a Markdown form collapse to their text. Then the library's TableConverter, and an own StrikethroughConverter for del, s and strike, because the library has none.
- Clipboard: the house pattern of ListApiTokens::showTokenAction(). A readOnly, non-dehydrated Textarea filled through fillForm, and a `Filament\Schemas\Components\Actions` row with an action whose alpineClickHandler writes `$wire.mountedActions[0].data.markdown` and sends a FilamentNotification. No server round trip happens for the copy, and the Markdown is never printed as HTML.
- Access: TaskResource carries AccessRule(Audience::AdminOnly), so a Partner gets 403 on /admin/tasks/*. The action's visible() also requires TaskResource::canAccess(). The fillForm closure starts with abort_unless(TaskResource::canAccess(), 403), because /livewire/update is public and Filament authorizes the page only on mount.
</objective>

<execution_context>
@~/.claude/gsd-core/workflows/execute-plan.md
@~/.claude/gsd-core/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@.claude/CLAUDE.md
@app/Filament/Resources/TaskResource/Pages/ViewTask.php
@app/Filament/Resources/ApiTokenResource/Pages/ListApiTokens.php
@app/Domain/Shared/Text/RichText.php
@app/Filament/Support/TaskColumns.php
@tests/Feature/Tasks/RichTextRenderingTest.php
@tests/Feature/Tasks/TaskEscalationTest.php

Facts the executor can rely on (verified while planning, at commit 6f1ad6f):
- Laravel 13, Filament ^5.0, PHP 8.5, Pest 5. Host PHP 8.5 and host composer exist, but the host has no database. Tests run only in DDEV.
- Worktree rule (CLAUDE.md): in a git worktree, run only cheap checks (php -l, grep, pint where it works, the licence gate). Pest, PHPStan through DDEV, and the full suite run only after the merge into the HEAD branch. Each task's first `<automated>` command is worktree-safe. The second runs after the merge.
- DDEV points at the main checkout, not at a worktree. In the main checkout, add the package with `ddev composer require league/html-to-markdown:^5.2`. In a git worktree, run the host `composer require league/html-to-markdown:^5.2` from the worktree root instead, so nothing changes in the main checkout. Either way, `git status --short` must afterwards show composer.json and composer.lock as the only changes from the install. public/js/filament, public/css/filament and public/fonts/filament are gitignored. Revert anything else the post-autoload-dump scripts touched.
- No HTML-to-Markdown converter is installed. composer.lock mentions league/html-to-markdown only inside symfony/mime's require-dev, and it has no `"name": "league/html-to-markdown"` package entry.
- league/html-to-markdown 5.x API: `new HtmlConverter(array $options)`, `->convert(string $html): string`, and `->getEnvironment()->addConverter(ConverterInterface $converter)`. The options include 'header_style' (default 'setext'), 'strip_tags' (default false), 'hard_break' (default false, so a br becomes two spaces and a newline), 'bold_style' '**', 'italic_style' '*', 'list_item_style' '-', 'use_autolinks' true and 'table_pipe_escape' '\|'. `League\HTMLToMarkdown\Converter\TableConverter` is NOT registered by default and must be added. It collapses newlines inside a cell to spaces, so a tiptap `<th><p>x</p></th>` gives one cell. After the first row it emits a separator row such as `|---|---|`. `League\HTMLToMarkdown\Converter\ConverterInterface` declares `convert(League\HTMLToMarkdown\ElementInterface $element): string` and `getSupportedTags(): array`. `ElementInterface::getValue()` returns the already converted children. The library has no strikethrough converter.
- RichText (app/Domain/Shared/Text/RichText.php) is a final class with static clean() and render(). render(?string): HtmlString returns an empty HtmlString for null or an empty string. It allows safe elements, drops img, keeps https, http and mailto links only, and forces rel and target on every link. The library's LinkConverter ignores those extra attributes.
- ViewTask::getHeaderActions() returns: TaskResource::timerAction(), $this->addTimeAction(), EditAction (hidden when trashed), archiveAction, restoreAction, $this->clearEscalationAction(). Private action builders in this file assert `$this->getRecord() instanceof Task` and call `abort_unless(TaskResource::canAccess(), 403)` in their bodies.
- The copy pattern to mirror is ListApiTokens::showTokenAction(), lines 173-237. It uses `Textarea::make('token')->readOnly()->dehydrated(false)->rows(3)->extraInputAttributes(['class' => 'font-mono', 'x-on:focus' => '$el.select()', 'x-on:click' => '$el.select()'])`. It is followed by `Actions::make([Action::make('copyToken')->label(...)->icon(Heroicon::OutlinedClipboard)->color('gray')->alpineClickHandler('navigator.clipboard?.writeText($wire.mountedActions[0].data.token).then(() => new FilamentNotification().title(' . json_encode(__(...), JSON_THROW_ON_ERROR | JSON_HEX_APOS | JSON_HEX_QUOT) . ').success().send())')])`. Its imports are Filament\Forms\Components\Textarea, Filament\Schemas\Components\Actions and Filament\Support\Enums\Width.
- Filament testing helpers that exist: mountAction, assertActionMounted, assertActionVisible, assertActionHidden, assertActionDoesNotExist, assertActionDataSet (an alias of assertSchemaStateSet), assertMountedActionModalSee and assertMountedActionModalSeeHtml.
- Domain signatures: `CreateProject::handle(Client $client, array $data)` (see richRenderTask() in RichTextRenderingTest for the fields name, key, billing_type and client_visible), `CreateTask::handle(User $actor, Project $project, array $data, ?Task $parent = null)`, `UpdateTask::handle(User $actor, Task $task, array $data)`, `ArchiveTask::handle(User $actor, Task $task): void`. Canary helpers: `Canary::admin()`, `Canary::partnerFor(?string $clientId)` and `Canary::twoClients()`. Feature tests use RefreshDatabase. `Filament::setCurrentPanel(Filament::getPanel('admin'))` goes in beforeEach. The Partner task page is ViewPartnerTask at /admin/my-tasks/KEY-N, on the same panel.
- tests/Pest.php: Unit tests are plain PHPUnit cases WITHOUT the application, so no `__()`, config() or database. Feature tests extend TestCase with RefreshDatabase. Pest helper functions are global, so new helpers use the prefixes `taskMd` (Feature) and `richMd` (Unit). Neither prefix is used anywhere in tests/ today.
- Only lang/cs has kokpit.php (lang/en has no kokpit.php), and LangEncodingTest requires NFC UTF-8. The `kokpit.tasks` group starts at about line 1185, and its `'actions' => [...]` subgroup ends with 'change_status_submit' => 'Uložit stav'. No `markdown` key exists in lang/cs/kokpit.php today.
- Pinned Partner header actions: tests/Feature/Tasks/TaskEscalationTest.php (around line 332) and tests/Isolation/PartnerTaskVisibilityTest.php (line 240) expect ViewPartnerTask's header actions to be exactly ['editDescription', 'escalate', 'clearEscalation']. Both must pass unchanged.
- Repository hygiene: fictional 'Example ...' data only, example.com links, hostile payloads assembled from fragments at runtime (see richRenderForged() in RichTextRenderingTest), and no maintainer or personal names in code or docs.

## Package Legitimacy Audit (planner-verified; quick mode has no RESEARCH.md)

| Package | Registry | Verdict | Evidence |
|---------|----------|---------|----------|
| league/html-to-markdown | packagist.org/packages/league/html-to-markdown | [VERIFIED] | Official thephpleague package (source github.com/thephpleague/html-to-markdown), MIT, about 39 million installs, not abandoned, latest stable 5.2.3, requires php ^7.2.5 or ^8.0 with ext-dom and ext-xml. symfony/mime, already locked here, lists it in its require-dev. |

Verdict: approved, no legitimacy checkpoint needed (the package is not [ASSUMED] or [SUS]). The executor still checks the resolved package before committing: `composer show league/html-to-markdown` must name the thephpleague source and the MIT licence, and composer.lock must gain no package other than league/html-to-markdown. List the resolved version in the SUMMARY. Run `composer audit` for information: report any advisory, and block only if it names league/html-to-markdown.
</context>

<tasks>

<task type="tracer" tdd="true">
  <name>Task 1: End-to-end "Admin opens the Markdown preview of a task and copies it" — dependency, converter core, modal action, Czech labels, one exact-output test</name>
  <files>composer.json, composer.lock, app/Domain/Shared/Text/RichTextMarkdown.php, app/Filament/Resources/TaskResource/Pages/ViewTask.php, lang/cs/kokpit.php, tests/Feature/Tasks/TaskMarkdownCopyTest.php</files>
  <behavior>
    - As the Admin, on the ViewTask page of a task titled 'Example markdown task' whose description was saved through UpdateTask as an h2 'Example heading' followed by a paragraph with a strong 'Example bold' and the words ' and plain words': assertActionVisible('copyMarkdown'); mountAction('copyMarkdown') gives assertActionMounted('copyMarkdown') and assertActionDataSet with markdown exactly equal to the four lines '# Example markdown task', '', '## Example heading', '', '**Example bold** and plain words' joined by newlines, plus one trailing newline
    - The same Markdown does not contain the task's reference (KEY-N)
  </behavior>
  <action>
Step 1, dependency. Install league/html-to-markdown with a caret constraint on 5.2 as described in the context facts: in the main checkout through `ddev composer require`, in a worktree through the host `composer require` from the worktree root. Then run the legitimacy and licence checks from the Package Legitimacy Audit and confirm with `git status --short` that only composer.json and composer.lock changed.

Step 2, RED. Create tests/Feature/Tasks/TaskMarkdownCopyTest.php with declare(strict_types=1). Add a header comment saying what the file proves and that every value is fictional. The beforeEach sets the admin panel, creates `$this->admin = Canary::admin()` and signs in with actingAs. Add a helper `taskMdTask(string $title, ?string $description): Task` that creates a project through CreateProject for `Client::factory()->create()` (name 'Example markdown project', key 'MDC', billing_type 'hourly', client_visible true) and a task through CreateTask with the title, and that saves the description through UpdateTask when it is not null. It takes an optional project argument so later tasks can reuse it. Add the behavior cases as `it()` blocks and drive the page with `Livewire::test(ViewTask::class, ['record' => $task->reference])`. Run the file and see it fail.

Step 3, GREEN.
(a) Create app/Domain/Shared/Text/RichTextMarkdown.php: declare(strict_types=1), `final class RichTextMarkdown` in namespace App\Domain\Shared\Text. Give the class a docblock that explains the why: it turns the stored rich text into Markdown for the clipboard; the HTML is cleaned again through RichText::render() first, so text written around the Actions is harmless; ATX headings keep the editor's h2 and h3 under the one H1 the title gets; tags with no Markdown form (underline) collapse to their text; the result is plain text that no caller prints as HTML. It holds a lazily built `private static ?HtmlConverter $converter`, as RichText caches its sanitiser, created with the options 'header_style' => 'atx' and 'strip_tags' => true.
`public static function convert(?string $html): string` returns '' for null or ''. Otherwise it converts `RichText::render($html)->toHtml()`, then normalises the result: CRLF and CR become LF, whitespace-only lines become empty, any run of three or more newlines becomes exactly two, and the whole string is trimmed.
`public static function document(string $title, ?string $html): string` builds the heading '# ' plus the title, with every whitespace run (preg_replace with the u modifier) collapsed to one space and the ends trimmed. It returns the heading plus "\n" when convert() gives '', and otherwise the heading, "\n\n", the body and "\n". Cast preg_replace results to string for PHPStan level 8.
(b) lang/cs/kokpit.php: inside the `kokpit.tasks` group, right after the `'actions' => [...]` subgroup, add a `'markdown' => [...]` subgroup with these keys (NFC UTF-8):
- 'action' => 'Kopírovat jako Markdown'
- 'heading' => 'Zadání úkolu v Markdownu'
- 'description' => 'Název úkolu jako nadpis a jeho popis převedený do Markdownu.'
- 'label' => 'Markdown'
- 'copy' => 'Kopírovat'
- 'copied' => 'Markdown je zkopírovaný do schránky'
- 'close' => 'Zavřít'
(c) app/Filament/Resources/TaskResource/Pages/ViewTask.php: add a private `copyMarkdownAction(): Action`. Its docblock says: the assignment as Markdown (the title as the one H1, never the KEY-N reference, then the description) in a read-only preview with a copy button; nothing is written; the fill re-checks TaskResource::canAccess() because /livewire/update is public; the copy runs in the browser from the modal state, the same pattern as the API token modal.
Build it as `Action::make('copyMarkdown')` with the label kokpit.tasks.markdown.action, `Heroicon::OutlinedClipboardDocument`, color 'gray', modalHeading kokpit.tasks.markdown.heading, modalDescription kokpit.tasks.markdown.description, `modalWidth(Width::ThreeExtraLarge)`, `modalSubmitAction(false)` and modalCancelActionLabel kokpit.tasks.markdown.close. It is visible only when `$this->getRecord() instanceof Task && TaskResource::canAccess()`; archived tasks stay included on purpose.
The `fillForm(function (): array {...})` body calls `abort_unless(TaskResource::canAccess(), 403)`, asserts the record is a Task and returns `['markdown' => RichTextMarkdown::document($task->title, $task->description)]`.
The `schema([...])` holds `Textarea::make('markdown')`, configured with the label kokpit.tasks.markdown.label, readOnly, dehydrated(false), rows(16), and extraInputAttributes with class 'font-mono' and `x-on:focus` and `x-on:click` set to '$el.select()'. After it comes an `Actions::make([...])` with `Action::make('copyMarkdownToClipboard')`: label kokpit.tasks.markdown.copy, `Heroicon::OutlinedClipboard`, color 'primary', and an alpineClickHandler built exactly like ListApiTokens' copyToken. It writes `$wire.mountedActions[0].data.markdown` with `navigator.clipboard?.writeText(...)`, then sends `new FilamentNotification().title(...)` with the kokpit.tasks.markdown.copied text, json_encoded with JSON_THROW_ON_ERROR | JSON_HEX_APOS | JSON_HEX_QUOT, and `.success().send()`.
Register it in getHeaderActions() right after `$this->addTimeAction()` and before the EditAction, with a one-line comment in the style of the neighbours. Add the imports App\Domain\Shared\Text\RichTextMarkdown, Filament\Forms\Components\Textarea, Filament\Schemas\Components\Actions and Filament\Support\Enums\Width. Add no public property, because everything public travels in the Livewire snapshot. Do not touch TaskResource, TaskColumns, EditTask, TaskPolicy or any Partner class.

Run the test file again and make it pass (after the merge, if you are in a worktree).
  </action>
  <verify>
    <automated>php -l app/Domain/Shared/Text/RichTextMarkdown.php && php -l app/Filament/Resources/TaskResource/Pages/ViewTask.php && php -l lang/cs/kokpit.php && php -l tests/Feature/Tasks/TaskMarkdownCopyTest.php && grep -q '"league/html-to-markdown"' composer.json && grep -q '"name": "league/html-to-markdown"' composer.lock && grep -q "Action::make('copyMarkdown')" app/Filament/Resources/TaskResource/Pages/ViewTask.php && grep -q 'RichTextMarkdown::document(' app/Filament/Resources/TaskResource/Pages/ViewTask.php && grep -q 'mountedActions\[0\]\.data\.markdown' app/Filament/Resources/TaskResource/Pages/ViewTask.php && grep -q 'RichText::render(' app/Domain/Shared/Text/RichTextMarkdown.php && grep -q "'header_style' => 'atx'" app/Domain/Shared/Text/RichTextMarkdown.php && grep -q "'markdown' => \[" lang/cs/kokpit.php && composer licenses --locked --format=json | php scripts/check-licenses.php</automated>
    <automated>ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Tasks/TaskMarkdownCopyTest.php</automated>
  </verify>
  <done>league/html-to-markdown is required and locked, and the licence gate passes. On /admin/tasks/KEY-N, 'Kopírovat jako Markdown' opens the preview modal, whose state is exactly the H1 title, a blank line, the converted description and a trailing newline, with no reference code. The 'Kopírovat' button is wired to the clipboard. The Feature test is green after the merge.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 2: Converter fidelity — tables, strikethrough, lists, hostile and empty input, title whitespace, diacritics</name>
  <files>app/Domain/Shared/Text/RichTextMarkdown.php, app/Domain/Shared/Text/StrikethroughConverter.php, tests/Unit/Text/RichTextMarkdownTest.php</files>
  <behavior>
    - convert() of an HTML string holding every editor format: h2 'Example heading', h3 'Example subheading', a paragraph with strong 'Example bold', em 'Example italic', u 'Example underline', s 'Example strike' and a link 'Example link' to https://example.com/page, a ul with li p 'Example bullet', an ol with two li p 'Example first' and 'Example second', a blockquote p 'Example quote', and a table with a tbody tr of th p 'Example head' and td p 'Example cell'. The output contains the lines '## Example heading' and '### Example subheading' (regex with the m modifier), contains '**Example bold**', '*Example italic*', '~~Example strike~~' and '[Example link](https://example.com/page)', contains 'Example underline' but not '<u>', and contains the lines '- Example bullet', '1. Example first', '2. Example second', '> Example quote' and '| Example head | Example cell |', plus a separator line matching the pattern of pipe-delimited dashes with optional colons
    - A nested list (ul > li > p 'Example parent' + ul > li > p 'Example child') gives the line '- Example parent' and a line of one or more spaces followed by '- Example child'
    - A paragraph 'Example line one' br 'Example line two' puts the two texts on different lines
    - A hostile string assembled from fragments at runtime (a script calling markerOne, an img with an onerror handler calling markerTwo, a javascript: link with the text 'Example link text', and a p with an inline style mentioning markerFour and the text 'Benign words') gives Markdown with none of the markers, no '<script', no '<img', no 'style=' and no 'javascript:', but with 'Benign words' and 'Example link text'
    - convert(null), convert('') and convert('<p></p>') are all ''. document('Example title', null) and document('Example title', '<p></p>') are both exactly '# Example title' plus one newline
    - document() with a title made of two spaces, 'Example', a newline, two spaces, 'spaced', a tab, 'title' and two spaces, and a null description, is exactly '# Example spaced title' plus one newline
    - '<p>Příklad úkolu s háčky</p>' converts to text that contains 'Příklad úkolu s háčky'
    - For the every-format input, the output has no whitespace-only line, no run of three newlines, and no leading or trailing whitespace
  </behavior>
  <action>
RED first. Create tests/Unit/Text/RichTextMarkdownTest.php with declare(strict_types=1) and a header comment saying the file pins the HTML-to-Markdown contract of the task assignment copy, and that every value is fictional with hostile parts assembled from fragments. Use the `richMd` prefix for any helper, for example `richMdFormatted(): string` and `richMdForged(): string`. Build the forged string from fragments the way richRenderForged() does in tests/Feature/Tasks/RichTextRenderingTest.php, so no single line looks like a payload. Remember that Unit tests have no application: call RichTextMarkdown statically and never use `__()`, factories or the database. Write the behavior cases as `it()` blocks. Use regex expectations with the m modifier for line-level checks rather than one exact string for the big input, so the test pins each format and not the library's incidental spacing. Run the file. The table, strike and diacritics cases are expected to fail before the change.

GREEN.
(a) Create app/Domain/Shared/Text/StrikethroughConverter.php: declare(strict_types=1), `final class StrikethroughConverter implements ConverterInterface` (League\HTMLToMarkdown\Converter\ConverterInterface). Give it a docblock saying the library has no strikethrough converter and that the editor's strike is written as GFM '~~'. convert(ElementInterface $element): string takes `$element->getValue()`. When the value is empty after trimming, it returns the value unchanged. Otherwise it keeps the leading and trailing whitespace outside the markers, because '~~ x ~~' is not strikethrough in GFM, and returns that whitespace around '~~' + the trimmed value + '~~'. getSupportedTags(): array returns ['del', 's', 'strike'], documented as `@return list<string>`.
(b) In RichTextMarkdown's converter factory, register `new TableConverter` (League\HTMLToMarkdown\Converter\TableConverter) and `new StrikethroughConverter` through `getEnvironment()->addConverter(...)`. Extend the class docblock with one line on each: the editor offers tables, the library leaves them out by default, and strike has no default converter.
(c) Run the Unit file. If a format check fails because of the library's output, fix it in RichTextMarkdown (options, a converter, or the normalisation), never by dropping or weakening the check. If the library's real output for a case differs only in spacing from the line patterns above (for example the separator row's exact dash count), adjust the regex to accept that spacing and keep the format assertion. Then rerun tests/Feature/Tasks/TaskMarkdownCopyTest.php: its exact string from Task 1 must still hold.
  </action>
  <verify>
    <automated>php -l app/Domain/Shared/Text/StrikethroughConverter.php && php -l app/Domain/Shared/Text/RichTextMarkdown.php && php -l tests/Unit/Text/RichTextMarkdownTest.php && grep -q 'implements ConverterInterface' app/Domain/Shared/Text/StrikethroughConverter.php && grep -q 'new TableConverter' app/Domain/Shared/Text/RichTextMarkdown.php && grep -q 'new StrikethroughConverter' app/Domain/Shared/Text/RichTextMarkdown.php && grep -q 'RichTextMarkdown::' tests/Unit/Text/RichTextMarkdownTest.php</automated>
    <automated>ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Unit/Text/RichTextMarkdownTest.php tests/Feature/Tasks/TaskMarkdownCopyTest.php</automated>
  </verify>
  <done>Every format the description editor offers becomes the matching Markdown. That covers headings, emphasis, strike as '~~', links, bullet, ordered and nested lists, blockquote, GFM table and line break. Underline becomes plain text, hostile markup leaves nothing executable, and empty input gives an H1-only document. The title whitespace is collapsed, Czech diacritics survive, and the output is newline-normalised. The Unit and Feature files are green after the merge.</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: Surface proofs — modal wiring, empty, archived and forged descriptions, Partner refusals — and the full gate</name>
  <files>tests/Feature/Tasks/TaskMarkdownCopyTest.php, app/Filament/Resources/TaskResource/Pages/ViewTask.php</files>
  <behavior>
    - None of the seven keys kokpit.tasks.markdown.{action,heading,description,label,copy,copied,close} comes back from `__()` as its raw key
    - After mountAction('copyMarkdown'): assertMountedActionModalSee shows the heading text and the copy label, and assertMountedActionModalSeeHtml finds 'navigator.clipboard' and 'mountedActions[0].data.markdown'
    - A task 'Example empty task' with no description: the modal state markdown is exactly '# Example empty task' plus one newline
    - After `app(ArchiveTask::class)->handle($this->admin, $task)`, the ViewTask page still opens, the action is visible, and the modal state equals the Markdown it had before the archive
    - A description forged straight into the row around the Actions (DB::table('tasks')->where('id', ...)->update(['description' => <fragments>]), with a script calling markerOne, an onerror handler calling markerTwo and a p 'Benign words'): the modal state contains 'Benign words' and neither marker, nor '<script'
    - Partner of the task's client (Canary::partnerFor on the project's client id, project client_visible true): GET of TaskResource::getUrl('view', ['record' => $task]) as the Partner is forbidden (403)
    - A ViewTask page mounted as the Admin, then `$this->actingAs($partner)`: assertActionHidden('copyMarkdown')
    - As the Partner, Livewire::test(ViewPartnerTask::class, ['record' => $task->reference]) gives assertActionDoesNotExist('copyMarkdown')
  </behavior>
  <action>
RED first. Add the behavior cases as `it()` blocks to tests/Feature/Tasks/TaskMarkdownCopyTest.php, reusing taskMdTask(). Each case that needs its own project passes a distinct project key, for example 'MDA' and 'MDP', to stay clear of the per-project number. Import App\Domain\Tasks\Actions\ArchiveTask, App\Filament\Partner\Resources\PartnerTaskResource\Pages\ViewPartnerTask, App\Filament\Resources\TaskResource, Illuminate\Support\Facades\DB and Tests\Support\Canary as needed. Assemble the forged description from fragments at runtime, as in Task 2. Assert on rendered text through `__('kokpit.tasks.markdown.*')`, never through a hard-coded copy of the Czech strings, and keep only fictional 'Example ...' data. Run the file. A case that passes right away is expected, because Tasks 1 and 2 built the guards. A case that fails is a defect in the earlier code: fix it in ViewTask or RichTextMarkdown, never by weakening the test. The rules stay as Task 1 wrote them: visibility needs TaskResource::canAccess(), the fill aborts with 403 without it, nothing is written, and the Partner classes stay untouched.

Then run the gates listed in verify, in order. The neighbour suites must pass unchanged. TaskEscalationTest and PartnerTaskVisibilityTest pin the Partner page's header actions. TaskAddTimeTest and TaskResourceTest drive the same ViewTask header. RichTextRenderingTest and RichTextSanitiserTest pin the sanitiser that convert() reuses. LicenceCheckTest pins the licence script. Finish with `scripts/check-sensitive.sh` over every file in files_modified, and with the invariant that the Partner resources, TaskColumns and the task policies did not change.
  </action>
  <verify>
    <automated>php -l tests/Feature/Tasks/TaskMarkdownCopyTest.php && grep -q "assertActionHidden('copyMarkdown')" tests/Feature/Tasks/TaskMarkdownCopyTest.php && grep -q "assertActionDoesNotExist('copyMarkdown')" tests/Feature/Tasks/TaskMarkdownCopyTest.php && grep -q 'ArchiveTask::class' tests/Feature/Tasks/TaskMarkdownCopyTest.php && git diff --quiet 6f1ad6f -- app/Filament/Partner app/Filament/Support/TaskColumns.php app/Domain/Tasks/Policies app/Filament/Resources/TaskResource.php && scripts/check-sensitive.sh composer.json composer.lock app/Domain/Shared/Text/RichTextMarkdown.php app/Domain/Shared/Text/StrikethroughConverter.php app/Filament/Resources/TaskResource/Pages/ViewTask.php lang/cs/kokpit.php tests/Feature/Tasks/TaskMarkdownCopyTest.php tests/Unit/Text/RichTextMarkdownTest.php</automated>
    <automated>ddev exec php -d memory_limit=1G vendor/bin/pest --compact tests/Feature/Tasks/TaskMarkdownCopyTest.php tests/Unit/Text/RichTextMarkdownTest.php tests/Feature/Tasks/TaskResourceTest.php tests/Feature/Tasks/TaskEscalationTest.php tests/Feature/Tasks/RichTextRenderingTest.php tests/Feature/Tasks/RichTextSanitiserTest.php tests/Feature/TimeTracking/TaskAddTimeTest.php tests/Isolation/PartnerTaskVisibilityTest.php tests/Unit/LicenceCheckTest.php && ddev exec php -d memory_limit=1G vendor/bin/pest --compact --testsuite=Arch && ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G && ddev composer check-licenses</automated>
    <human-check>In the browser (https on DDEV, which the Clipboard API needs), as the Admin, open a task with a formatted description: headings, bold, a list and a table. Click 'Kopírovat jako Markdown'. The modal shows '# ' and the task name without the KEY-N code, then the description as Markdown. Click 'Kopírovat': the toast 'Markdown je zkopírovaný do schránky' appears, and pasting into a text editor gives exactly the previewed text. Clicking into the textarea selects all of it. 'Zavřít' closes the modal and nothing on the task changed.</human-check>
  </verify>
  <done>The modal wiring, the empty, archived and forged-description cases and every Partner refusal are proven. The neighbour suites, Arch, pint, phpstan and the licence gate are green after the merge. check-sensitive passes over every touched file, and the Partner, policy and TaskColumns files are unchanged.</done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| browser -> /livewire/update | Public endpoint. Mounting copyMarkdown runs the fillForm closure server-side for whoever holds the page snapshot |
| stored description -> Markdown output | The stored HTML may have been written around the Actions. It crosses into a text the Admin pastes elsewhere |
| Composer registry -> vendor | A new third-party package enters the build |

## STRIDE Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation Plan |
|-----------|----------|-----------|----------|-------------|-----------------|
| T-261030-gby-01 | Information Disclosure | ViewTask::copyMarkdownAction (task title and description) | high | mitigate | ViewTask belongs to the AdminOnly TaskResource (403 for a Partner). The action is visible only while TaskResource::canAccess() holds. fillForm calls abort_unless(TaskResource::canAccess(), 403) before it reads the record. Proven by the Task 3 tests: 403 on GET, action hidden after a session switch, no copyMarkdown on ViewPartnerTask |
| T-261030-gby-02 | Information Disclosure | Partner task surfaces | medium | mitigate | PartnerTaskResource, ViewPartnerTask, TaskColumns and the task policies are untouched (git diff gate in Task 3). The pinned Partner header actions in TaskEscalationTest and PartnerTaskVisibilityTest pass unchanged |
| T-261030-gby-03 | Tampering (stored XSS) | Description HTML converted to Markdown and shown in the modal | medium | mitigate | RichTextMarkdown::convert() cleans the HTML through RichText::render() before converting. The Markdown is bound to a Filament Textarea (escaped value, never an HtmlString or raw Blade). The copy reads it through $wire state, not through HTML injection. The toast title is json_encoded with JSON_HEX_APOS and JSON_HEX_QUOT. Proven by the hostile Unit case and the forged-row Feature case |
| T-261030-gby-04 | Information Disclosure | Content of the Markdown | low | mitigate | The Markdown contains only the title and the description: no reference, people, checklist, billing, internal note or time. Proven by the exact-equality assertion in Task 1 |
| T-261030-gby-05 | Denial of Service | Conversion of a long description | low | accept | Descriptions are capped at RichText::MAX_LENGTH (100000 bytes) and converted once per modal open, for the single Admin only |
| T-261030-gby-06 | Information Disclosure | Test fixtures and planning files | low | mitigate | Fictional 'Example ...' data, example.com links, payloads assembled from fragments. scripts/check-sensitive.sh runs over every touched file, plus the lefthook gitleaks hook |
| T-261030-gby-SC | Tampering | composer install of league/html-to-markdown | high | mitigate | Planner-verified legitimacy (packagist, thephpleague source, MIT, about 39 million installs, not abandoned). Caret constraint and committed composer.lock. The executor checks `composer show` source and licence, that the lock gains no other package, the licence gate and `composer audit` (blocking only on an advisory for this package) |
</threat_model>

<verification>
- Worktree-safe gates of all three tasks pass: php -l, the grep gates, the licence gate, check-sensitive, and the unchanged-Partner git diff gate
- After the merge into the HEAD branch: every second `<automated>` command passes, then the full suite the CI way: `ddev exec php -d memory_limit=1G vendor/bin/pest --testsuite=Unit,Arch,Feature,Isolation,Concurrency` (not `--parallel`)
- `ddev exec vendor/bin/pint --test`, `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` and `ddev composer check-licenses` are green
- Human check (end of phase, not blocking): the browser flow in Task 3's human-check
</verification>

<success_criteria>
- The Admin copies a task's assignment as Markdown from the task page in two clicks (open the preview, then Kopírovat). The H1 is the task name without its code, and the body is the description converted from HTML
- Every editor format converts faithfully, and hostile stored markup leaves nothing executable
- A Partner gains no new surface or data, as enforced by the AdminOnly resource, canAccess() on visibility and on fill, and the untouched, pinned Partner pages
- One verified, MIT-licensed dependency is added and locked. The full CI-style suite and the sensitive-data check are green
</success_criteria>

<output>
Create `.planning/quick/261030-gby-add-a-markdown-copy-of-the-task-assignme/261030-gby-SUMMARY.md` when done
</output>
