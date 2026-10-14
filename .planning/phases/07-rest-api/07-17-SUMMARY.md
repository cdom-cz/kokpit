---
phase: 07-rest-api
plan: 17
subsystem: api
tags: [rest-api, gap-closure, reference-resolver, docs, pest]

requires:
  - phase: 07-rest-api
    provides: "07-16 cursor validation on the lookup controllers and LookupTest, 07-08 lookup lists, 07-10 public docs"
provides:
  - "ReferenceResolver::PROJECT_KEY and TASK_REFERENCE match the whole string (D modifier)"
  - "CONTRIBUTING Ordering bullet and the three lookup sort descriptions state that the REST API lookup lists do not sort in Czech order"
  - "Documented-limits test: the public intro and every per_page description equal config/kokpit.php, with a failing self-check"
  - "ApiDocs helper docblock names the docs-output side effect and the --parallel restriction"
affects: [07-18]

status: complete
requirements-completed: [AP-04, AP-05]

actuals:
  tokens: 6200
  tasks: 3
  commits: 3

plan_head_before: 145204a1787466ab1805e21626596a6517f99aec
plan_head_after: 20bc5540230bc64503e77a734d8b4fdd950ccbfd

tech-stack:
  added: []
  patterns:
    - "A pure helper compares public docs text with configuration and returns problem strings; a second test feeds it changed config and reworded text so the check is proven able to fail"

key-files:
  created: []
  modified:
    - app/Domain/Api/ReferenceResolver.php
    - tests/Feature/Api/LookupTest.php
    - CONTRIBUTING.md
    - app/Http/Controllers/Api/V1/ClientController.php
    - app/Http/Controllers/Api/V1/ProjectController.php
    - app/Http/Controllers/Api/V1/TaskController.php
    - tests/Feature/Api/ApiDocumentationTest.php
    - tests/Support/ApiDocs.php

key-decisions:
  - "IN-02 is fixed with a test, not by building intro_text from config(): config/scribe.php cannot rely on another config file and the QueryParam descriptions are constant expressions (as planned)"
  - "IN-04 is fixed by documentation in the helper, not by generating into a scratch directory (as planned)"
  - "The IN-03 HTTP 422 test switches TrimStrings off for its one request, because the middleware already strips a trailing line feed from a query value before validation; the D modifier is the second line of defence"

patterns-established:
  - "Per-finding evidence: each review finding closed by a change in the tree and, where behaviour changed, a test"

coverage:
  - id: IN-03
    description: "A key or reference with a trailing line feed is malformed: 422 under filter.project when the value reaches validation, 404 on the detail routes"
    requirement: AP-04
    verification:
      - kind: integration
        ref: "tests/Feature/Api/LookupTest.php#it answers 422 for a project key with a trailing line feed instead of an empty list (IN-03)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/LookupTest.php#it matches a project key and a task reference as a whole string, a trailing line feed included (IN-03)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/LookupTest.php#it answers 404 for a task or project reference with a trailing line feed (IN-03)"
        status: pass
    human_judgment: false
  - id: IN-01
    description: "The ordering convention and the three sort descriptions say the lookup lists sort by the plain column, not in Czech order"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Feature/Api/ApiDocumentationTest.php (suite regenerates the spec with the new descriptions)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Repo/RepositoryFilesTest.php"
        status: pass
    human_judgment: false
  - id: IN-02
    description: "A limit stated in the public docs that differs from config/kokpit.php fails the suite, and the check is proven able to fail"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Feature/Api/ApiDocumentationTest.php#it states exactly the limits of config/kokpit.php in the intro and in every per_page description (IN-02)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/ApiDocumentationTest.php#it reports a documented limit that differs from the configuration (the limits check can fail)"
        status: pass
    human_judgment: false
  - id: IN-04
    description: "The ApiDocs helper documents that the suite overwrites the local docs output and must not run with --parallel"
    requirement: AP-05
    verification:
      - kind: other
        ref: "grep -q -- '--parallel' tests/Support/ApiDocs.php"
        status: pass
    human_judgment: false
---

# Phase 7 Plan 17: Info findings IN-01 to IN-04 Summary

**Whole-string key patterns, an honest ordering convention for the lookup lists, documented limits pinned to config/kokpit.php by a test that can fail, and a documented side effect of the docs helper.**

## Performance

- **Tasks:** 3 (1 tracer, 2 auto, one with tdd)
- **Files:** 8 modified
- **Verification:** `pest tests/Feature/Api tests/Feature/Repo/RepositoryFilesTest.php` 348 passed; Pint clean; `scripts/check-sensitive.sh` clean

## Evidence per finding

| Finding | What changed | Proof |
|---|---|---|
| IN-03 | `PROJECT_KEY` and `TASK_REFERENCE` end with `$/D`; class docblock says the patterns match the whole string | three tests in `LookupTest.php`: the pattern constants, 422 under `filter.project`, 404 on `/tasks/DEMO-1%0A` and `/projects/DEMO%0A`. RED confirmed with the old patterns (the pattern and 422 tests failed), GREEN with `/D` |
| IN-01 | CONTRIBUTING "Ordering" bullet names the REST API lookup lists as the exception, with the keyset reason; the `sort` description of `/clients`, `/projects`, `/tasks` ends with "Names (Titles) sort in the plain database order with an id tiebreaker, not in Czech alphabetical order." | documentation and repository files suites regenerate and pass; the generated spec carries the sentence |
| IN-02 | `documentedLimitProblems()` in `ApiDocumentationTest.php` compares the read and write rate limits, default page size, page maximum, batch limit (two sentences) and key lifetime of the intro, and the `1 to N` / `Default N` of every `per_page` description (4 list operations), with `config('kokpit.api')` | the pass test, and a self-check test with `rate_limit.read` 121, `page_size.max` 201, `batch_max_items` 101 and a reworded intro, each reported by name |
| IN-04 | `tests/Support/ApiDocs.php` class docblock: the suite overwrites the real gitignored docs output with the example.com base URL (regenerate with `ddev exec php artisan scribe:generate`) and must not run with `--parallel` | acceptance grep |

## Task Commits

1. **Task 1 (tracer): whole-string key and reference patterns** - `f283b2b`
2. **Task 2: ordering convention and lookup sort descriptions** - `d4e1697`
3. **Task 3: documented limits pinned to the configuration, helper side effects documented** - `20bc554`

The tracer feedback gate ran in the automated form: the Task 1 verify (lookup, list and write suites, Pint, PHPStan) passed before the expansion tasks started.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug in the plan's premise] The 422 for `filter[project]=DEMO%0A` is not reachable through the default HTTP stack**
- **Found during:** Task 1 (first GREEN run)
- **Issue:** The plan expected `GET /api/v1/tasks?filter[project]=DEMO%0A` to answer 422 once the pattern had the `D` modifier. Laravel's `TrimStrings` middleware strips the trailing line feed from the query value before validation, so the request reaches validation as `DEMO` and answers 200 (and did so before the change, with the project's tasks, not an empty list). The two other claims of the plan hold: the pattern constants and the path-segment 404 (route parameters are not trimmed; the 404 held before and after).
- **Fix:** the HTTP test switches `TrimStrings` off for its one request (`TrimStrings::skipWhen`, `flushState` in `finally`), which reaches the pattern; with the old pattern that test is RED (200), with `/D` it is GREEN (422). The change is therefore defence in depth for a stack where the value is not trimmed, which is what the review finding asked for.
- **Files modified:** tests/Feature/Api/LookupTest.php
- **Commit:** f283b2b

Otherwise none - plan executed as written.

## Known Stubs

None.

## Threat Mitigation

| Threat | Status | Evidence |
|---|---|---|
| T-07-71 (trailing line feed accepted as a key) | mitigated | `/D` on both patterns; the three IN-03 tests |
| T-07-72 (docs state limits that differ from the enforced config) | mitigated | the limits-equal-config test and its failing self-check |
| T-07-SC | accepted | no package added |

## Issues Encountered

`phpstan analyse` reports 2 pre-existing errors (`cast.string` in `app/Console/Commands/ResetAdminTwoFactorCommand.php` and `app/Console/Commands/StorageCheckCommand.php`), out of scope and untouched. The plan's files add no PHPStan error.

## Self-Check: PASSED

- `.planning/phases/07-rest-api/07-17-SUMMARY.md` written; commits `f283b2b`, `d4e1697` and `20bc554` are ancestors of HEAD.
- Acceptance greps of all three tasks re-run and pass; `pest tests/Feature/Api tests/Feature/Repo/RepositoryFilesTest.php` passes.
