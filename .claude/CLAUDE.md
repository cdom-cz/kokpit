<!-- GSD:project-start source:PROJECT.md -->

## Project

**Kokpit**

Kokpit is an open-source (AGPL-3.0) web CRM/ERP for a freelancer or small company, replacing a hosted tool such as Caflou. It covers the whole flow: client -> project -> task -> tracked time -> billing -> invoice -> payment -> income. One instance serves one company; the primary user is a single admin, secondary users are client accounts (role Partner) with a restricted view in the same panel.

**Core Value:** Tracked time turns into an issued, payable invoice in one pass, with no unbilled time or unpaid invoice ever slipping through unnoticed.

### Constraints

- **Tech stack**: PHP 8.5, current stable Laravel and Filament (SPA mode), PostgreSQL, S3-compatible private bucket, Sanctum, Stripe Payment Links, queue (DB or Redis, decided in phase 1) — fixed by the brief
- **License**: AGPL-3.0; all dependencies must be AGPL-compatible, no paid or closed packages
- **Security**: Partner must never see measured time, rates, prices or finance, nor another client's data — enforced by Policies and global query scopes, not only UI hiding
- **Data integrity**: UUID v7 keys everywhere incl. package morph columns; FK, unique, partial indexes and check constraints enforced in DB
- **Repository hygiene**: no secrets, real data or instance-specific values in git; enforced by tooling from step 0 (local denylist outside repo, pre-commit hook, gitleaks, CI)
- **Concurrency**: number sequences (tasks, invoices) must be gap-free and duplicate-free under concurrent creation
- **Immutability**: an issued invoice or proforma stays editable until a successful e-mail send locks it; Admin may unlock a sent document with a recorded reason; number and kind never change; credit notes and billed time entries are immutable; snapshots for supplier, customer, rates, exchange rates

<!-- GSD:project-end -->

## Repository Hygiene

Kokpit is a public AGPL-3.0 repository. The rule is: **Fictional data only.** Never put client names, prices, rates, invoice or production data, real e-mail addresses, company IDs, bank accounts, IP addresses, hostnames, tokens or personal absolute paths into code, tests, fixtures, docs, planning docs under `.planning/`, or commit messages.

Use instead:

- `example.com` addresses (`jane@example.com`)
- the placeholder company ID `12345678`
- paths like `/Users/example/`
- test fakes assembled at runtime from fragments, so no single line of a test file looks like a real value

Review procedure before every commit:

1. `git status`
2. `git diff --staged`
3. `scripts/check-sensitive.sh`

The lefthook pre-commit hook runs the checker and gitleaks. Never bypass it (`--no-verify`, `LEFTHOOK=0`); CI rescans everything.

After any regeneration of the GSD-managed blocks in this file, rerun `scripts/check-sensitive.sh .claude/CLAUDE.md`.

If something sensitive was committed: stop, rotate or revoke it first, and tell the owner. Do not rewrite history on your own. The full procedure is in `CONTRIBUTING.md`.

## Worktrees, Tests and Migrations

Development is done in git worktrees. The final test run and the database migrations happen only after the work is merged into the HEAD branch (the branch the worktrees were merged into), not inside a worktree.

- In a worktree: write code and tests, and run only cheap checks that need no shared state (formatting, static analysis where it works without the DB).
- Do not run migrations from a worktree. They would change the shared development database and diverge between parallel worktrees.
- After the merge into the HEAD branch: run the migrations there, then the full test suite and static analysis (in DDEV, see the memory note), and treat that result as the final verdict.
- Run the suite the way CI does: `ddev exec php -d memory_limit=1G vendor/bin/pest --testsuite=Unit,Arch,Feature,Isolation,Concurrency` (CI shards it with `--shard`). Do not use `pest --parallel`: paratest turns the "use statement has no effect" warning of a `use Closure;` in a global-namespace test into an `ErrorException` and aborts the whole run, although CI is green. Such a failure is not a repository bug, so do not "fix" the tests; rerun without `--parallel`.

<!-- GSD:skills-start source:skills/ -->

## Project Skills

No project skills found. Add skills to any of: `.claude/skills/`, `.agents/skills/`, `.cursor/skills/`, `.github/skills/`, or `.codex/skills/` with a `SKILL.md` index file.
<!-- GSD:skills-end -->

<!-- GSD:workflow-start source:GSD defaults -->

## GSD Workflow Enforcement

Before using Edit, Write, or other file-changing tools, start work through a GSD command so planning artifacts and execution context stay in sync.

Use these entry points:
- `/gsd-fast` for a trivial task inline, with no subagents and no PLAN.md
- `/gsd-quick` for small fixes, doc updates, and ad-hoc tasks
- `/gsd-debug` for investigation and bug fixing
- `/gsd-execute-phase` for planned phase work

Do not make direct repo edits outside a GSD workflow unless the user explicitly asks to bypass it.
<!-- GSD:workflow-end -->

<!-- GSD:profile-start -->

## Developer Profile

> Profile not yet configured. Run `/gsd-profile-user` to generate your developer profile.
> This section is managed by `generate-claude-profile` -- do not edit manually.
<!-- GSD:profile-end -->
