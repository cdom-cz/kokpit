---
phase: 05-tasks-and-kanban
reviewed: 2026-10-09T12:00:00Z
depth: standard
files_reviewed: 12
files_reviewed_list:
  - CONTRIBUTING.md
  - app/Domain/Tasks/Actions/CreateTask.php
  - app/Domain/Tasks/Notifications/TaskChangedNotification.php
  - app/Domain/Tasks/Notifications/TaskCommentedNotification.php
  - app/Domain/Tasks/Notifications/TaskCreatedNotification.php
  - app/Domain/Tasks/Notifications/TaskEscalatedNotification.php
  - app/Domain/Tasks/Notifications/TaskNotification.php
  - app/Filament/Resources/TaskResource.php
  - tests/Feature/Repo/RepositoryFilesTest.php
  - tests/Feature/Tasks/TaskActionsTest.php
  - tests/Feature/Tasks/TaskUpdateTest.php
  - tests/Isolation/NotificationMarkupTest.php
findings:
  critical: 0
  warning: 1
  info: 5
  total: 6
status: issues_found
---

# Phase 5: Code Review Report (gap-closure plans 05-18 and 05-19)

**Reviewed:** 2026-10-09T12:00:00Z
**Depth:** standard
**Files Reviewed:** 12
**Status:** issues_found

## Summary

This run supersedes the earlier review of the phase and covers the two gap-closure plans:

- 05-18: the current person stays selectable on the task edit form (`TaskResource::peopleOptions`), and `CreateTask` drops the `tags` input of a Partner.
- 05-19: one escaping point in the `TaskNotification` base class, with `toMail()` and `toDatabase()` final and the four subclasses reduced to raw scalars.

The security-relevant part holds up under adversarial reading. I checked the following against the installed vendor code:

- The bell (Filament renders title and body through `sanitizeHtml()`) receives `e()`-escaped values and reads as typed.
- The HTML part of the mail is inert. Blade's `{{ }}` encodes the line before CommonMark runs, and the Laravel mail Markdown environment has `html_input => escape` and no autolink extension.
- The mail subject cannot be used for header injection. A value with CRLF plus `Bcc:` is folded into an encoded-word by Symfony Mime and stays inside the Subject header.
- `strtr`-based placeholder replacement in `__()` does not re-scan substituted values, so a title that contains `:actor` is not expanded.
- The Partner tags drop in `CreateTask` runs before `TaskInput::tags()`, and the Partner path in `peopleFor()` returns the defaults. The tests read the tag table in a system run, so they are not vacuous.
- The kept-person option in `peopleOptions` adds exactly the stored value of its own field, only to the Admin-only edit form, and the tests prove it is not offered in the other field.

No critical issue was found. One warning: the escaping is correct for the HTML alternative of the mail but corrupts the plain-text alternative that Laravel sends alongside it, and the new test suite only renders the HTML part, so it cannot see this. The info items are small contract and edge-case gaps.

## Warnings

### WR-01: Markdown backslash escapes show literally in the plain-text alternative of every task mail

**File:** `app/Domain/Tasks/Notifications/TaskNotification.php:218-223` (applied at lines 103, 106, 110); test gap in `tests/Isolation/NotificationMarkupTest.php:479`, `:482` and `:502`
**Issue:** `escapeMarkdown()` inserts a backslash before each of `\ ` * _ [ ] ( ) # | ~ !`. That is correct for the HTML alternative, where CommonMark consumes the backslashes. `Illuminate\Notifications\Channels\MailChannel` also sends a text alternative (`buildMarkdownText()` calls `Markdown::renderText()`). That path renders the same Blade view, strips tags and decodes entities, and never runs the Markdown parser, so the backslashes survive.

I reproduced it with a comment notification whose title is `Fix_the_bug (v2) *now*` and whose actor is `Jane Example`:

- HTML part: `K úkolu ABC-1 Fix_the_bug (v2) *now* přibyl ...` (correct).
- Text part: `K úkolu ABC-1 Fix\_the\_bug \(v2\) \*now\* přibyl ...` (wrong).

Every task title, display name or excerpt with an underscore, parenthesis, asterisk, `#`, `!` or `|` is therefore garbled for a recipient who reads the plain-text part (text-only clients, previews, some accessibility setups). The change lines and the excerpt line are affected the same way. The plan states the invariant "reads exactly as written" and the markup test asserts it, but only for `$mail->render()`, which is the HTML part. The queue round-trip test (`:494-509`) also compares only the HTML.
**Fix:** Make the neutralisation survive both renderers. Return the mail lines as `Htmlable` built from numeric entities. CommonMark treats an entity as literal text, so it can never become syntax, and `renderText()` runs `html_entity_decode`, so the text part gets the plain characters back. `MailMessage::line()` accepts `Htmlable`, and Blade does not encode it a second time:

```php
private static function mailLine(string $text): HtmlString
{
    $flat = (string) preg_replace('/\s+/u', ' ', $text);
    $encoded = e($flat);   // & < > " ' (the same encoding Blade would have applied)

    return new HtmlString(strtr($encoded, [
        '\\' => '&#92;', '`' => '&#96;', '*' => '&#42;', '_' => '&#95;',
        '[' => '&#91;', ']' => '&#93;', '(' => '&#40;', ')' => '&#41;',
        '#' => '&#35;', '|' => '&#124;', '~' => '&#126;', '!' => '&#33;',
    ]));
}
```

The templated `mail_line` is built the same way: escape each value with `e()` plus the entity map, run `__()`, wrap the result in `HtmlString`. The subject stays plain. Add a case to the matrix test that renders the text part, for example `Markdown::renderText($mail->markdown, $mail->data())` or by reading `getTextBody()` from the array transport. It should assert that `notifMarkupFull()` appears verbatim and no backslash precedes a Markdown character.

## Info

### IN-01: The excerpt line is documented as a quote but renders as literal `&gt;` text

**File:** `app/Domain/Tasks/Notifications/TaskNotification.php:109-111`
**Issue:** `'> '.escapeMarkdown($excerpt)` is meant to produce a blockquote. Blade encodes `>` to `&gt;` before CommonMark runs, so the line never starts a block quote. The rendered HTML is `<p>&gt; excerpt</p>`; I confirmed it by rendering. The recipient sees a literal "> " prefix. The same encoding means the `<` and `>` exemption in the `escapeMarkdown` docblock is right but the quote intent is dead. A side effect is that a value cannot open a list, rule or quote at the start of a line, so this is cosmetic and not a safety issue.
**Fix:** Either drop the `'> '` prefix, or render a real quote by building an `HtmlString` such as `<blockquote>…</blockquote>` around the entity-encoded excerpt (this fits the fix for WR-01).

### IN-02: `TaskChangedNotification` with an empty `changedLabels` leaks a raw translation key into the bell

**File:** `app/Domain/Tasks/Notifications/TaskNotification.php:129-131` and `:165-168`; `app/Domain/Tasks/Notifications/TaskChangedNotification.php:24-31`
**Issue:** With no change lines the base class falls back to `bell_body_no_excerpt`, and the `changed` group in `lang/cs/kokpit.php` has no such key. `__()` then returns the key string `kokpit.tasks.notifications.changed.bell_body_no_excerpt` as the bell body. `TaskNotifier` returns early on an empty label list, so this is unreachable today. The constructor does not enforce it, and the base class `bellBodyKey()` contract does not say that every group must define both keys.
**Fix:** Override `bellBodyKey()` in `TaskChangedNotification` to a defined key, or reject an empty list in its constructor (`if ($changedLabels === []) { throw new LogicException(...); }`).

### IN-03: Two silent edge cases in the escaping helpers

**File:** `app/Domain/Tasks/Notifications/TaskNotification.php:204` and `:220-222`
**Issue:**
- `Str::limit()` cuts by display width (`mb_strimwidth`), not by characters. A fullwidth or CJK excerpt is cut at about 60 characters, and `rtrim` can drop a trailing space before the ellipsis. The constant name and the test title speak of 120 characters. This is harmless for Czech text.
- `(string) preg_replace('/\s+/u', ...)` returns `''` on invalid UTF-8, so a value would vanish from the mail without a trace. The database is UTF-8, so this only matters if a non-UTF-8 string ever reaches the constructor.

**Fix:** Use `mb_substr($excerpt, 0, 119).'…'` guarded by `mb_strlen`, and use `mb_convert_encoding($text, 'UTF-8', 'UTF-8')` or `?? ''` with an explicit comment so the behaviour is deliberate.

### IN-04: A kept person with no resolvable name gets an empty option label

**File:** `app/Filament/Resources/TaskResource.php:632-634`
**Issue:** `(string) User::query()->whereKey($current)->value('name')` yields `''` when the row is not found. If the `User` model is soft-deleted or scoped, or the account was hard-deleted, the Select shows a blank option for the stored value. Because the id is still in the list, the form still saves, but the Admin sees an unlabeled choice. Each closure evaluation also runs the project lookup and the options query again, once per field and per Livewire request. This is out of scope as performance, but is cheap to avoid.
**Fix:** Fall back to a visible label such as `__('kokpit.tasks.fields.unknown_person')` when `value('name')` is null, and compute the options once per request.

### IN-05: The `CreateTask` class docblock was edited into over-long, hard-to-read lines

**File:** `app/Domain/Tasks/Actions/CreateTask.php:34-43`
**Issue:** The new Partner sentence leaves lines 38 to 39 and 42 to 43 far longer than the neighbouring wrapped lines. This is purely editorial. The behaviour it describes is correct: tags are Admin-only and are ignored, not rejected, for a Partner.
**Fix:** Re-wrap the paragraph to the same width as the rest of the docblock.

---

_Reviewed: 2026-10-09T12:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
