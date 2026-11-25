# Stack Research: Milestone v1.1 Knowledge Center

**Domain:** Markdown knowledge base (folders, tags, versioning, own full-text search, secret guard) inside the existing Kokpit app (Laravel 13.43, Filament 5.12 SPA panel, Livewire 4.4, PHP 8.5, PostgreSQL 18, Redis, S3-compatible private bucket, AGPL-3.0)
**Researched:** 2026-11-25
**Scope:** Only what is NEW for v1.1. The v1 stack (Laravel, Filament, Spatie permission/medialibrary/tags/activitylog/query-builder/data, Horizon, Sanctum, Stripe, dompdf) is validated and not re-researched.
**Confidence:** HIGH for versions, licenses and the "already installed" facts (read from Packagist metadata and `composer.lock`/`vendor/` today). HIGH for Filament MarkdownEditor behaviour (read from `vendor/filament/forms` 5.12.0 source). MEDIUM for the PostgreSQL text-search configuration (PG docs plus established practice; must be proven by a migration test on the real PG 18 image). MEDIUM for Tempest Highlight output details (read upstream source, not executed here). Context7 was not available in this session; versions come from the Packagist API, docs from official PostgreSQL/GitHub pages.

## Headline Decision

The Knowledge Center needs **five small Composer additions, zero npm packages, and two own components** (a CommonMark callout extension and a secret scanner). Everything else is already in `vendor/`:

| Need | Decision |
|------|----------|
| Markdown renderer | `league/commonmark` (already installed transitively as 2.10.7, promote to a direct dependency) |
| Safe HTML | CommonMark `html_input=escape` + `allow_unsafe_links=false`, then a Kokpit-owned `symfony/html-sanitizer` pass (already installed transitively as 8.1.11, promote to direct) |
| Code highlighting | `tempest/highlight` 2.30 (pure PHP, server side, no Node) |
| Callouts `> [!NOTE]` | Own ~60-line CommonMark extension (no maintained package exists) |
| Versioning | Own append-only `knowledge_article_versions` table (full snapshots), no package |
| Folder tree | Adjacency list (`parent_id`) + `staudenmeir/laravel-adjacency-list` 1.26 (recursive CTEs) |
| Search | Own PostgreSQL search: custom `kokpit_cs` text search configuration (`unaccent` + `simple`), generated `tsvector`, extend the existing `kokpit_search_text()` trigram layer; no Scout, no external engine |
| Secret scan | Own `SecretScanner` service (PCRE ruleset + entropy + Luhn/IBAN/mod-11 validators); no mature PHP library exists |
| Version diff view | `jfcherng/php-diff` 7.0 (HTML renderer) |
| AI-friendly export | Plain Markdown + YAML front matter via `symfony/yaml` (promote from require-dev to require) |

## Recommended Stack

### Core Technologies (new or promoted to direct dependency)

| Technology | Version | Purpose | Why Recommended |
|------------|---------|---------|-----------------|
| league/commonmark | `^2.10` (latest 2.10.8, 2026-11-24; lock has 2.10.7) | Markdown to HTML, AST access for callouts, task lists, source line numbers | Already in the app (Laravel 13 requires `^2.8.1`, Filament uses it for `->markdown()`), so zero new transitive weight. Bundles GFM (tables, task lists, strikethrough, autolink, DisallowedRawHtml). Exposes the AST (`Node::getStartLine()`), which both the callout extension and interactive checklist toggling need. BSD-3-Clause, AGPL compatible. Declare it in `composer.json` because the app will now call it directly, not just through Laravel. |
| symfony/html-sanitizer | `^8.1` (lock 8.1.11; 8.2 is still beta) | Defence-in-depth pass over rendered HTML with a Kokpit-owned allowlist | Already installed (Filament requires it). Use our own `HtmlSanitizerConfig` instance, NOT Filament's global one (see Integration notes, Filament's config allows `style` everywhere and silently truncates input over 500000 bytes). MIT. |
| tempest/highlight | `^2.30` (2.30.0, 2026-10-27; requires PHP ^8.4, project is 8.5) | Server-side syntax highlighting for fenced code blocks | Pure PHP, no Node/Shiki process, no npm build (the repo has no `package.json`, no Vite). Ships `hl-*` CSS classes (themeable with plain CSS, no JS). Languages relevant to an ops/dev knowledge base are built in: Bash, Terminal, Dockerfile, DotEnv, Ini, Nginx, Apache, Yaml, Json, Toml, Sql, Http, Diff, Php, JavaScript, TypeScript, Python, Xml, Html, Css, Markdown, Terraform, Go, Blade, Twig. Has an official `Tempest\Highlight\CommonMark\HighlightExtension`. Actively released (82 releases). MIT. |
| staudenmeir/laravel-adjacency-list | `^1.26` (1.26.2, 2026-10-05; the Laravel 13 line is 1.26) | Folder tree: `ancestors`, `descendantsAndSelf`, `depth`, `path`, `toTree()` via recursive CTEs, cycle detection | The folder tree is a classic one-parent-per-node tree. A recursive CTE on a plain `parent_id` is the lowest-risk structure on PostgreSQL (no lft/rgt rebalancing, no path maintenance, trivially UUID-keyed). The package is MIT, tracks each Laravel major within weeks, PHPStan level 9, pulls only `staudenmeir/laravel-cte`. |
| jfcherng/php-diff | `^7.0` (7.0.1, 2026-06-22; PHP >=8.3) | Inline / side-by-side HTML diff between two article versions | Renders word-level highlighted HTML so the version screen needs no custom diff UI. BSD-3-Clause, three tiny helper deps from the same author. Fallback if you want zero new packages: promote `sebastian/diff` 9.0.1 (already in lock via PHPUnit, require-dev) to `require` and render the unified diff yourself. |
| symfony/yaml | `^8.1` (move from require-dev to require) | Write YAML front matter for the AI-friendly export (`Yaml::dump`) | Already in `require-dev`. Needed at runtime only if export/front matter is generated server-side. MIT. |

### Database (PostgreSQL 18, no new extensions)

| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| `pg_trgm` | already installed by migration `2026_10_30_000100` | Trigram GIN index for paths, hostnames, identifiers, inflected Czech forms | Already in place with the schema-qualified, IMMUTABLE wrapper `kokpit_search_text(text)` = `lower(unaccent(...))` and the `App\Domain\Shared\Database\AccentInsensitiveSearch` helper. Reuse both; add `knowledge_*` columns the same way (one GIN `gin_trgm_ops` index per searched expression). |
| `unaccent` | already installed | Diacritics folding inside a custom text search configuration | PG docs show the exact pattern (`ALTER TEXT SEARCH CONFIGURATION ... WITH unaccent, <dict>`). The default `unaccent.rules` covers Czech letters. Trusted extension, no superuser needed. |
| Custom text search configuration `kokpit_cs` | created in a new migration | `to_tsvector`/`to_tsquery` with Czech-friendly normalisation | PostgreSQL ships NO Czech configuration and no Czech Snowball stemmer; a Hunspell `cs_CZ` ispell dictionary needs files copied into the server's `$SHAREDIR/tsearch_data`, which is impossible on a managed PostgreSQL (Zerops) and awkward in DDEV/CI. So: `COPY = simple`, then map word token types to `unaccent, simple`. This gives case- and accent-insensitive whole-word matching; inflection is covered by prefix queries (`term:*`) and trigram similarity. |
| Generated `tsvector` column + GIN | PG 18 `GENERATED ALWAYS AS (...) STORED` | Index of title (weight A) and body (weight B) | Always consistent with the row, no trigger or job to keep in sync, restore-safe. `to_tsvector('public.kokpit_cs'::regconfig, ...)` with a constant regconfig is IMMUTABLE and allowed in a generated column. Use STORED (PG 18 virtual generated columns cannot be indexed). |
| UNIQUE ... NULLS NOT DISTINCT | PG 15+ syntax, fine on 18 | Folder name unique per parent including root level | Single unique index over `(parent_id, kokpit_search_text(name)) NULLS NOT DISTINCT` replaces the two-partial-index workaround for a nullable `parent_id`. |

### Supporting Libraries (already installed, new usage)

| Library | Version (lock) | Purpose | When to Use |
|---------|----------------|---------|-------------|
| spatie/laravel-medialibrary | 11.23.14 (current 11.x) | Image-only attachments on the private S3 bucket | Collection `knowledge-images` with `acceptsMimeTypes([png, jpeg, gif, webp, avif])`, never SVG. Re-encode with a conversion (spatie/image 3.9 + GD, both present) to strip EXIF/GPS and polyglot payloads. Serve through an authorised app route, not a public or long-lived signed URL. |
| spatie/laravel-tags + `filament/spatie-laravel-tags-plugin` | 4.12.0 / 5.10+ | Article tags | Use a dedicated tag `type` (for example `knowledge`) so article tags stay separate from client tags. Morph columns are already UUID in this app. |
| spatie/eloquent-sortable | ^5.0 | Sibling order of folders | `buildSortQuery()` scoped to `parent_id`. |
| spatie/laravel-activitylog | 5.1.5 | Audit of restore, move, visibility change, secret-guard override | Log events and ids only. Never log `body_markdown` or a matched secret value into `properties`. |
| spatie/laravel-query-builder | ^7.3 | List filters (folder, tag, client, project) | Search itself is a custom `AllowedFilter::callback()` that delegates to the own search query class. |
| spatie/laravel-data | present | DTOs for `SecretFinding`, `SearchHit` | Keeps findings typed and maskable. |
| ext-intl (`Normalizer`) | present (Filament needs it) | NFKC normalise + strip zero-width characters before secret scanning | Prevents trivial bypass with homoglyphs or zero-width splits. |

### Development Tools

| Tool | Purpose | Notes |
|------|---------|-------|
| `composer check-licenses` (existing `scripts/check-licenses.php`) | Gate the new packages | MIT/BSD-3 only; run after `composer require`. Likely needs allowlist awareness for BSD-3 `jfcherng/*` if not already allowed. |
| Pest Arch/Feature/Isolation suites | Prove Partner isolation of the new tables and search | The Partner audit matrix in `tests/Support` must get the new routes and Livewire components declared by hand (project rule). |
| `EXPLAIN` test (existing pattern) | Prove the GIN trigram and `tsvector` indexes are used | The accent-insensitive migration already ships such a test; copy it for `search_vector` and the new trigram indexes. |

## Installation

```bash
# New direct dependencies (runs in DDEV: ddev composer ...)
composer require league/commonmark:^2.10 symfony/html-sanitizer:^8.1 tempest/highlight:^2.30 \
  staudenmeir/laravel-adjacency-list:^1.26 jfcherng/php-diff:^7.0

# Promote from require-dev to require (export front matter)
composer require symfony/yaml:^8.1

# No npm. The repository has no package.json / Vite; do not introduce one for this milestone.
# Static CSS (hl-* theme, callouts) is registered with FilamentAsset::register([Css::make(...)]) from a plain file
# and published by the existing `filament:upgrade` post-autoload-dump step.
```

## Alternatives Considered

| Recommended | Alternative | When to Use Alternative |
|-------------|-------------|-------------------------|
| tempest/highlight 2.x | phiki/phiki 2.2.1 (TextMate grammars, MIT, pure PHP, PHP ^8.2) | If highlighting fidelity of exotic languages matters more than a small footprint. v3 is only in beta (3.0.0-beta2, 2026-11-16) and the CommonMark adapter requirement disappeared between 2.1 and 2.2, so wait for v3 stable and wire your own renderer. Both fit behind one `CodeBlockRenderer`, so switching later is cheap. |
| Own callout extension | `pomodocs/commonmark-alert` 0.8 (MIT, 2026-04-02, ~8.7k installs, 4 releases), `amoschou/commonmark-alert-extension` (single dev release) | Only if you accept a pre-1.0 niche dependency. Own code gives Czech labels via `lang/cs`, exact markup control, and a hard allowlist of five types (NOTE, TIP, IMPORTANT, WARNING, CAUTION). |
| Own versions table | `overtrue/laravel-versionable` 6.0.1 (MIT, Laravel 13) or `mpociot/versionable` 4.4.8 | Never for this feature: they store attribute JSON per model, are not built for DB-enforced immutability, UUID v7 morph keys, "restore creates a new version" semantics, or tag and folder snapshots. Fine for a simple CMS, wrong here. |
| Adjacency list + CTE | `kalnoy/nestedset` 7.0.0 (Laravel 13 ready) | Only for a read-heavy tree with very frequent whole-subtree reads at thousands of nodes. It rewrites lft/rgt ranges on every move (concurrency hazard) and is built around integer keys. Folder counts here are tens to low hundreds. |
| Adjacency list + CTE | PostgreSQL `ltree` | Large static hierarchies. Needs a materialised path maintained by app code or triggers on every move and label restrictions on names. Over-engineered here. |
| `simple` + `unaccent` text search config | Hunspell `cs_CZ` ispell dictionary (real Czech morphology) | Only if the operator controls the PostgreSQL filesystem (self-hosted). Keep the configuration name abstract (`kokpit_cs`) so the mapping can be swapped by one migration without touching queries. Mind the dictionary licence before bundling. |
| Own PG search | Laravel Scout + Meilisearch/Typesense | Rejected by the brief (own search in PostgreSQL) and adds a service to deploy on Zerops. |
| Own `SecretScanner` | Shell out to `gitleaks` | Never at request time: binary distribution, process spawning on a web path, and the Go regex dialect has no lookarounds. gitleaks stays in the commit pipeline only. |
| jfcherng/php-diff | `sebastian/diff` (already present) | If you want zero new packages and accept hand-written HTML for a unified diff. |
| Native Filament `Select` with depth-indented labels for the parent folder | `codewithdennis/filament-select-tree` 4.2.4 (Filament ^4/^5) | Only if a true tree-picker UX is required. A flat select with `tree()->get()` and `str_repeat('— ', $depth)` needs no plugin. |
| Own folder navigation (Livewire/Blade) | `solution-forest/filament-tree` 4.0.2 (Filament ^5, drag and drop) | Only if drag-and-drop reordering becomes a requirement. Third-party Filament plugins need a release per Filament minor, and a Partner-visible tree needs scope control the plugin does not provide. |

## What NOT to Use

| Avoid | Why | Use Instead |
|-------|-----|-------------|
| `spatie/shiki-php`, `spatie/commonmark-shiki-highlighter`, Torchlight | `shiki-php` is already in `vendor/` only as a transitive of `ueberdosis/tiptap-php` (Filament RichEditor). It needs a Node binary at runtime and node_modules; Zerops PHP runtime and DDEV have no such contract. | `tempest/highlight` |
| `scrivo/highlight.php` (also in vendor via tiptap-php) and `spatie/commonmark-highlighter` | highlight.php is a 2022 port of highlight.js 9.x; `spatie/commonmark-highlighter` is marked abandoned on Packagist (last release 2021). | `tempest/highlight` |
| Filament `RichEditor` / Tiptap / any WYSIWYG for articles | Canonical storage must be Markdown (AI-readable, diffable, versionable). HTML as source of truth loses all three. | Filament `MarkdownEditor` (EasyMDE) + server-rendered preview |
| `MarkdownEditor::fileAttachments()` default behaviour | In Filament 5.12 it hard-codes visibility `public` and `fileAttachmentsVisibility()` throws a `LogicException`. Images would land world-readable on the bucket, violating "private storage". | `saveUploadedFileAttachmentUsing()` to write a medialibrary item and `getFileAttachmentUrlUsing()` to return a stable authorised app URL |
| `TextEntry::markdown()` / `Str::markdown()` / the disabled `MarkdownEditor` preview for the article view | `Str::markdown()` builds a `GithubFlavoredMarkdownConverter` with CommonMark defaults (`html_input=allow`, `allow_unsafe_links=true`) and relies on Filament's global sanitizer (`style` allowed on all elements, input truncated at 500000 bytes, form controls stripped so task checkboxes vanish, no callouts). | One `KnowledgeMarkdownRenderer` service (Environment + extensions + own sanitizer config), used by view screen, edit preview, version diff preview and export |
| Interactive widgets inside sanitized HTML (copy button, Alpine attributes) | The sanitizer strips `x-*`, `@click` and `button` by design. | Render `<pre data-lang><code>` and add the copy button client-side from a tiny Alpine component in the Blade view (inline, no build step) |
| `mpociot/versionable`, `overtrue/laravel-versionable`, activitylog as version store | See Alternatives. Activitylog would also copy big bodies into `properties` and mix audit with content history. | Own `knowledge_article_versions` |
| Laravel Scout, Meilisearch, Typesense, Elasticsearch, `pgvector` embeddings | Brief demands PostgreSQL FTS; AI/MCP and semantic search are explicitly out of this milestone. | `tsvector` + `pg_trgm` |
| `WHERE body ILIKE '%term%'` without the wrapper | Sequential scan, accent-sensitive. | `kokpit_search_text(col)` with the existing GIN trigram index pattern |
| Passing raw user input to `to_tsquery` / `websearch_to_tsquery` | `to_tsquery` throws on stray operators; `websearch_to_tsquery` cannot do prefix matching, which Czech inflection needs. | Tokenise in PHP, fold with the same rules as the DB, and bind a prefix tsquery (`'term':*` joined by `&`) |
| Third-party "secret scanner" Composer packages | Only brand-new, low-adoption packages exist (63 installs for the closest one); none ports gitleaks rules or validates IBAN/cards. | Own `SecretScanner` with a reviewed ruleset and tests |
| Public bucket URLs or long-lived signed URLs for article images | Partner scoping would be bypassable by URL sharing. | Authorised route that checks the article Policy then streams or issues a short-lived signed redirect |
| `unaccent` via the one-argument form in an index or generated column | STABLE, not IMMUTABLE; will not index. | The existing schema-qualified two-argument wrapper `kokpit_search_text()` and, for tsvector, the unaccent dictionary inside `kokpit_cs` |

## Stack Patterns by Variant

**Markdown rendering pipeline (one service, three callers):**
- `Environment(['html_input' => 'escape', 'allow_unsafe_links' => false, 'max_nesting_level' => 20])` with `CommonMarkCoreExtension`, `GithubFlavoredMarkdownExtension` (tables, task list, strikethrough, autolink), `HighlightExtension` (wrapped in Kokpit's own `FencedCode` renderer so the wrapper markup and language allowlist are ours), and the own `CalloutExtension`.
- Raw HTML in the Markdown is escaped, not stripped, so it stays visible as text. That is right for a technical knowledge base (HTML snippets inside prose are common) and makes the output safe by construction.
- Second layer: `HtmlSanitizer` with `allowSafeElements()`, `allowRelativeLinks()`, link schemes `https|http|mailto`, media schemes none beyond relative (blocks `data:` images that CommonMark lets through), `allowElement('input', ['type','checked','disabled','data-line'])` for task items, `class` allowed only on `pre|code|span|div|p|table|blockquote`, `data-lang` on `pre`, and a raised `withMaxInputLength` matching the DB size cap. Review the `hl-*` hidden token (`<span style="display: none">`) emitted by Tempest for some injections: if `style` stays disallowed, verify no language you enable relies on it.
- Cache the HTML in Redis keyed by `sha256(body) + renderer version`; never store HTML in the database (single canonical source).

**Callouts `> [!NOTE]`:**
- Listener on `DocumentParsedEvent`: find a `BlockQuote` whose first paragraph's first line matches `^\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]$`, remove that line, replace the node by a `Callout` block (type enum + children). Renderer emits `<div class="kk-callout kk-callout--note" role="note"><p class="kk-callout__title">…</p>…</div>` with the title from `lang/cs`.
- Why this syntax: it degrades to a normal blockquote in GitHub, editors and every AI tool, so the canonical Markdown stays portable. Unknown types stay plain blockquotes.

**Task checklists:**
- `TaskListExtension` renders disabled checkboxes. To toggle from the view screen, add a renderer for `TaskListItemMarker` that emits `data-line` from the parent `ListItem::getStartLine()`. A Livewire action flips `[ ]`/`[x]` on exactly that source line after checking a content hash (optimistic lock), then saves through the normal versioned save path. Without this the checklist is read-only in view and editable only in the editor; decide that as a product question, the stack supports both.

**Copyable code blocks:**
- Server emits `<div class="kk-code" data-kk-code><pre data-lang="bash"><code>…</code></pre></div>`; a small inline Alpine component in the Blade view (event delegation on `[data-kk-code]`) adds the button and calls `navigator.clipboard.writeText(pre.innerText)`. Needs HTTPS, which production and DDEV have.

**Versions:**
- `knowledge_article_versions`: UUID v7 PK, `article_id` FK, `version_number` (unique with article, allocated under `SELECT … FOR UPDATE` on the article row so it is gap-free like the existing sequences), `title`, `body_markdown`, `folder_id`, `tags jsonb`, `author_id`, `content_hash` (skip a no-op save), `restored_from_version_id` nullable, `created_at`.
- Full snapshots, not deltas: restore is a plain copy, TOAST compresses text, a single-company instance stays small, and every version is directly readable by AI tools.
- Append-only enforced by a DB trigger (same philosophy as billed time entries). Restore = create a NEW version copying an old one; history is never rewritten.
- Run the secret scan BEFORE the version row is written so a blocked secret never reaches history.

**Folder tree:**
- `knowledge_folders(id uuid v7, parent_id uuid null FK ON DELETE RESTRICT, name, sort order)` with the `NULLS NOT DISTINCT` unique index. Model uses `HasRecursiveRelationships`; override `enableCycleDetection()` only if you also add a cycle guard.
- A cycle (moving a folder into its own descendant) cannot be a CHECK constraint. Add a `BEFORE INSERT OR UPDATE OF parent_id` trigger with a recursive CTE, plus an application validation rule for the friendly message. Depth cap (for example 8) in the same trigger.
- Partner scoping: a folder is visible to a Partner only through a granted article/client link; do not compute inherited grants in PHP loops, use one recursive CTE in a global scope.

**Search (hybrid, one query class `KnowledgeSearch`):**
```sql
-- migration (sketch; names are illustrative)
CREATE TEXT SEARCH CONFIGURATION public.kokpit_cs (COPY = pg_catalog.simple);
ALTER TEXT SEARCH CONFIGURATION public.kokpit_cs
  ALTER MAPPING FOR asciiword, word, numword, asciihword, hword, numhword,
                    hword_asciipart, hword_part, hword_numpart
  WITH public.unaccent, pg_catalog.simple;

ALTER TABLE knowledge_articles ADD COLUMN search_vector tsvector
  GENERATED ALWAYS AS (
    setweight(to_tsvector('public.kokpit_cs'::regconfig, coalesce(title, '')), 'A') ||
    setweight(to_tsvector('public.kokpit_cs'::regconfig, coalesce(body_markdown, '')), 'B')
  ) STORED;
CREATE INDEX knowledge_articles_search_vector_index ON knowledge_articles USING gin (search_vector);
CREATE INDEX knowledge_articles_title_search_index ON knowledge_articles USING gin (kokpit_search_text(title) gin_trgm_ops);
CREATE INDEX knowledge_articles_body_search_index  ON knowledge_articles USING gin (kokpit_search_text(body_markdown) gin_trgm_ops);
```
- Ranking: `ts_rank_cd(search_vector, query)` for word hits UNION trigram `word_similarity` / `LIKE` hits for identifiers, paths and inflected forms; the default PG parser keeps `/etc/nginx/nginx.conf` as ONE `file` token, so a search for `nginx` will not match it through the tsvector. That is exactly why the trigram leg is required, not optional.
- Snippets via `ts_headline('public.kokpit_cs', …)` only for the visible page of results (CPU heavy).
- Sub-pages hidden from the listing but searchable: a boolean on the article, filtered in the listing query only, never in `KnowledgeSearch`. Partner scoping and the search must share one scope class so they cannot diverge.
- Triplet of tests: `EXPLAIN` proves index use; a fixture proves `Příliš žluťoučký kůň` matches `prilis zlutoucky kun`; a Partner test proves a hit from another client never appears.
- Queries under 3 characters cannot use trigram indexes; fall back to prefix FTS only, and say so in the UI.

**Secret / sensitive-data guard (own `SecretScanner`):**
- Pure PHP, PCRE with JIT, synchronous in the save path (articles are small; the user is waiting anyway, consistent with the project rule "synchronous only where the user waits"). Input: title + body + image alt text, after `Normalizer::normalize(NFKC)` and zero-width stripping.
- Rule families, using gitleaks' default rule set (MIT, reuse the idea, port by hand, PCRE allows lookarounds) as the checklist: PEM private-key blocks; AWS key ids; GitHub tokens (`ghp_`, `github_pat_`); Stripe `sk_live_`/`rk_live_`/`whsec_`; Slack tokens; Google API keys; JWTs; URLs with embedded credentials (`scheme://user:pass@host`); `Authorization: Bearer`; `KEY=value` and `password: value` assignments gated by Shannon entropy and a placeholder allowlist (`example`, `changeme`, `<…>`, `${…}`, `xxxx`).
- Validated structured data, which regexes alone over-trigger on: IBAN (ISO 13616 mod 97), Czech bank account `prefix-number/bank` (mod 11 weights), payment cards (Luhn plus IIN ranges), Czech birth numbers (9/10 digits, divisible by 11). All validators are ~10 lines each; do not add a library.
- Output: `SecretFinding` DTO with rule id, line number and a MASKED value (first 2 characters plus length, the same convention as `scripts/check-sensitive.sh`). Never write the matched value to logs, exceptions, activitylog or validation messages.
- Fail closed: if `preg_last_error()` is non-zero (backtrack or JIT limit) block the save with a "scan failed" message. Use possessive quantifiers and bounded repetition in every rule.
- Override path (Admin only, reason recorded in activitylog) is a product decision; the stack needs no extra package for it.

**Image attachments:**
- Editor upload hook returns a stable relative URL such as `/znalosti/obrazky/{media uuid}` that is authorised per request. The Markdown then stays valid after restore, export and AI ingestion.
- The editor uploads before the article exists on the create screen. Create the article draft row on mount (or attach media to a pending bucket) and add a scheduled job that deletes unreferenced media older than a day. Without it the bucket fills with orphans.
- Validate with `finfo`, not the client MIME type; Laravel's `image` rule already excludes SVG, keep it that way.

**AI-friendly format:**
- Canonical = `body_markdown` normalised to LF, no front matter in the column; structured metadata (title, folder path, tags, client/project keys, timestamps) lives in columns.
- Export/serialisation (file, or later API/MCP) = `---\n` + `Yaml::dump(metadata)` + `---\n` + body. Fenced code always carries a language, headings stay a proper h1 to h3 outline, images carry alt text. No embeddings, no HTML cache, no proprietary block format in this milestone.

## Version Compatibility

| Package A | Compatible With | Notes |
|-----------|-----------------|-------|
| league/commonmark 2.10.x | laravel/framework 13.43 (`^2.8.1`), filament/support 5.12, laravel-lang/publisher | Already co-installed; moving the constraint to `^2.10` changes nothing in the lock. |
| symfony/html-sanitizer 8.1.x | filament/support 5.12 (`^7.0\|^8.0`) | Stay on the stable 8.1 line; 8.2.0 is beta (2026-11). Do not mutate Filament's `HtmlSanitizerConfig` singleton, bind your own class. |
| tempest/highlight 2.30 | PHP ^8.4, league/commonmark ^2.4 (optional), no framework | Project PHP 8.5 and DDEV 8.5 satisfy it. Language is taken from the first info word with `\w+`, so use an allowlist in your wrapper renderer. |
| staudenmeir/laravel-adjacency-list 1.26 | illuminate/database ^13.0, staudenmeir/laravel-cte ^1.13, PostgreSQL 9.4+ | Version table in its README maps Laravel 13 to 1.26. UUID v7 string keys: verify the `path` pseudo-column and `depth` with a Pest test in the folder phase (MEDIUM, README does not state UUID explicitly; recursive CTEs cast keys to text). |
| jfcherng/php-diff 7.0.1 | PHP >=8.3 | Pulls `jfcherng/php-color-output`, `php-mb-string`, `php-sequence-matcher`. |
| Filament MarkdownEditor 5.12 | EasyMDE (CodeMirror 5) | Toolbar offers only bold, italic, strike, link, heading, blockquote, codeBlock, bulletList, orderedList, table, attachFiles, undo, redo. Preview toggle and side-by-side shortcuts are disabled in Filament's init, so there is NO built-in preview; the custom server-rendered preview is required, not optional. No toolbar buttons for task lists or callouts; document the syntax in the field helper text. |
| Filament assets without Vite | `FilamentAsset::register` + `Css::make()` | The repo has no JS toolchain. Tailwind utility classes in custom Blade will NOT be compiled; write plain CSS using Filament's CSS variables for callouts and the `hl-*` theme. |
| PostgreSQL 18 text search config | `unaccent` dictionary schema | Create the configuration in `public`, reference it schema-qualified everywhere (same reason the existing wrapper qualifies `public.unaccent`), and keep the migration `down()` dropping the configuration but not the extensions. |

## Sources

- `composer.json`, `composer.lock`, `vendor/filament/forms/src/Components/MarkdownEditor.php`, `vendor/filament/forms/src/Components/Concerns/HasFileAttachments.php`, `vendor/filament/forms/resources/js/components/markdown-editor.js`, `vendor/filament/support/src/SupportServiceProvider.php`, `vendor/laravel/framework/src/Illuminate/Support/Str.php` (local, read 2026-11-25) — installed versions, MarkdownEditor limits, sanitizer config, `Str::markdown` defaults. HIGH.
- Packagist API (`repo.packagist.org/p2/...`, `packagist.org/packages/...json`) — versions, licenses, PHP/Laravel constraints, abandonment, install counts for league/commonmark, tempest/highlight, phiki/phiki, staudenmeir/laravel-adjacency-list, kalnoy/nestedset, overtrue/laravel-versionable, mpociot/versionable, jfcherng/php-diff, sebastian/diff, spatie/commonmark-highlighter, pomodocs/commonmark-alert, amoschou/commonmark-alert-extension, solution-forest/filament-tree, codewithdennis/filament-select-tree, spatie/laravel-medialibrary. HIGH (registry metadata).
- https://github.com/tempestphp/highlight (source: `src/Languages`, `src/CommonMark/HighlightExtension.php`, `CodeBlockRenderer.php`, `Highlighter.php`, `composer.json`) and https://tempestphp.com/2.x/packages/highlight — language list, CommonMark integration, `hl-*` classes, escaping via theme. MEDIUM-HIGH (source read, not executed; an install-and-run check was not possible in this session).
- https://github.com/phikiphp/phiki — TextMate-based alternative, MIT, v3 in beta. MEDIUM.
- https://github.com/staudenmeir/laravel-adjacency-list (README) — Laravel 13 = 1.26, PostgreSQL support, cycle detection, `tree()`/`toTree()`. HIGH, UUID behaviour MEDIUM.
- https://www.postgresql.org/docs/18/textsearch-dictionaries.html and https://www.postgresql.org/docs/18/unaccent.html — Ispell/Hunspell files must live in `$SHAREDIR/tsearch_data`; unaccent as filtering dictionary with `ALTER MAPPING ... WITH unaccent, <dict>`; unaccent is a trusted extension. HIGH.
- WebSearch (Czech FTS in PostgreSQL): confirms there is no built-in Czech configuration; community routes are Hunspell `cs_CZ` files. MEDIUM.
- WebSearch (PHP secret scanning libraries, GitHub alerts extensions for CommonMark): no mature library in either area, only young low-adoption packages. MEDIUM (absence of evidence from search).
- `.gitleaks.toml`, `scripts/check-sensitive.sh`, migration `2026_10_30_000100_add_accent_insensitive_search.php` (local) — existing masking convention and the `kokpit_search_text` trigram infrastructure to reuse. HIGH.

---
*Stack research for: Kokpit v1.1 Knowledge Center (Markdown articles, folders/tags, versioning, PostgreSQL search, secret guard)*
*Researched: 2026-11-25*
