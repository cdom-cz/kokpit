# Phase 1: Repository Hygiene - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-10-06
**Phase:** 1-Repository Hygiene
**Areas discussed:** Sensitive-content script, Local denylist, Pre-commit hook, gitleaks and CI, Documentation and GitHub settings, Existing tracked content

---

Run in `--auto` mode: all gray areas selected and the recommended option chosen without user prompts.

## Sensitive-content script
[auto] Implementation language → Selected: plain Bash (recommended; no runtime exists yet)
[auto] Pattern set and allowlist → Selected: generic patterns + committed allowlist file (recommended; reviewable in PRs)

## Local denylist
[auto] Behaviour when absent → Selected: notice + generic patterns only (recommended; CI path)

## Pre-commit hook
[auto] Hook mechanism → Selected: `.githooks/` + `core.hooksPath` via install script (recommended)
[auto] Missing gitleaks locally → Selected: fail with install hint (recommended; fail-closed)

## gitleaks and CI
[auto] gitleaks invocation → Selected: pinned binary, not gitleaks-action (recommended; avoids licence key)
[auto] Scan scope → Selected: full history, `fetch-depth: 0`, push + pull_request only (recommended)

## Documentation and GitHub settings
[auto] Where the rule lives → Selected: `.claude/CLAUDE.md` + CONTRIBUTING (recommended)
[auto] GitHub secret scanning / push protection → Selected: manual checklist, human-verify (recommended)

## Existing tracked content
[auto] `.planning/codebase/` → Selected: scan, sanitize or untrack; no history rewrite unless a real secret is found (recommended)

## Claude's Discretion

Regex tuning, `scripts/` layout, test harness choice, doc wording, zizmor.

## Deferred Ideas

SECURITY.md runbook, licence allowlist CI, deploy hardening, `.ddev/` hygiene.
