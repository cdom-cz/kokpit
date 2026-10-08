# Phase 3: Code Review Disposition

**Source review:** `.planning/phases/03-operations-foundation/03-REVIEW.md` (13 findings: 1 critical, 8 warnings, 4 info)
**Gate after the fixes:** `ddev composer ci` green (950 Pest tests, Pint, Larastan level 8, licence check). Workflows and scripts were not touched, so `scripts/tests/run.sh` was not needed.
**Edited in:** the main checkout (not an isolated worktree), because the gate runs in DDEV against the main checkout.

| ID | Severity | Disposition | Commit | Rationale |
|----|----------|-------------|--------|-----------|
| CR-01 | Critical | fixed | `821cf25` | The alert delivery is deferred (`defer(..., always: true)`) to `JobAttempted`, which the worker raises after every `JobFailed` listener, so the `failed_jobs` row is written first. Still synchronous and queue-independent (D-11). SMTP socket timeout bounded to 10 s (`MAIL_TIMEOUT`). |
| WR-01 | Warning | fixed | `9013abf` | The page boot hook asks the `#[AccessRule]` declaration directly and then `canAccess()`, like the relation manager and widget traits. |
| WR-02 | Warning | fixed | `26d0162` | New `AlertMessageSanitiser` removes hosts, IPs, ports, URLs, DSN fragments, quoted values, e-mail addresses, paths and SQL tails before the cut. Docblock and config comment corrected. |
| WR-03 | Warning | fixed | `fda21c0` | `ProductionConfigGuard` refuses `MAIL_MAILER` of `log`, `array` or missing, and an `APP_URL` that is not a public https URL. Deploy checklist in `CONTRIBUTING.md` states that `APP_URL` and the mail settings are project-level variables. |
| WR-04 | Warning | fixed | `8b95cb5` | `RecordWorkerHeartbeat` is `ShouldBeUnique` (900 s). |
| WR-05 | Warning | deferred | - | Deploy workflow dispatch ref and green-CI policy is a maintainer decision (see the recommendation below). |
| WR-06 | Warning | deferred | - | Real fix is a unique constraint on the issued-number string, planned for Phase 10 (already noted in `03-08-SUMMARY.md`). |
| WR-07 | Warning | fixed | `1a842d1` | `trustProxies` trusts only `X-Forwarded-For`, `-Port` and `-Proto`; the forwarded host is ignored. |
| WR-08 | Warning | fixed | `6d7019b` | `Money::convert` rejects zero and negative rates (non-finite values were already refused by the pattern). |
| IN-01 | Info | open | - | Supplier rules hardening (`D` modifier, `url:http,https`, `max`, trimming); not in scope of this pass. |
| IN-02 | Info | open | - | Fallback alert recipient and throttle release on failed delivery; needs a design decision. |
| IN-03 | Info | open | - | One business time zone definition and moving the display format constant; refactor for a later pass. |
| IN-04 | Info | open | - | Readiness check should cover `session.connection` and treat a falsy Redis ping as failure; not in scope of this pass. |

## Decisions and residual risk

- **CR-01, timeout kill.** When the worker kills a job on timeout (`TimeoutExceededException`), the process exits right after `JobFailed` and no later hook runs, so that one alert is still delivered inline. It is bounded by the SMTP timeout, but in that path the alert still runs before the framework writes the `failed_jobs` row. Another mailer than SMTP is not bounded by `MAIL_TIMEOUT`; set the timeout of its client in `config/mail.php` if one is used.
- **CR-01, other failures.** A hanging transport in the deferred step can no longer cost the `failed_jobs` row (tests prove the row exists when the alert is sent and when every channel throws). The deferred send can still be cut by the job timeout alarm, which would lose only the alert, not the failure record.
- **WR-02.** The sanitising is best effort and errs on removing too much. The unfiltered message stays in `failed_jobs` and the log.
- **WR-04.** No `retryUntil`, deliberately: the worker marks an expired job as failed, which would write `failed_jobs` rows and raise Admin alerts after every worker outage. A lost unique lock (for example a cache flush) delays the next heartbeat by at most 900 s.
- **WR-03.** The `APP_URL` check refuses non-https URLs and `localhost`, loopback, `*.localhost` and `*.test` hosts. It does not try to recognise other private or development hosts.
- **WR-07.** `URL::forceRootUrl(config('app.url'))` from the review was not added; ignoring the forwarded host already removes the spoofing path, and `APP_URL` is now guaranteed in production.

## Deferred, with recommendations

- **WR-05 (deploy dispatch ref and CI policy).** Recommendation: in the `verify` job refuse a `workflow_dispatch` unless `github.ref` is `refs/heads/main` or a `refs/tags/v*` tag, and require (with `checks: read`) that the `CI Passed` check concluded `success` for `GITHUB_SHA`. Until then the reviewer of the `production` environment is the only gate. This touches the workflow, the workflow tests and the repository settings, so it needs the maintainer.
- **WR-06 (number scope key versus pattern collisions).** Recommendation: add a unique constraint on the issued-number string per document kind in Phase 10, and make issuing check that the formatted number does not exist before the allocator increments, so a collision fails with a clear error. Until invoices exist there is nothing to collide with.
