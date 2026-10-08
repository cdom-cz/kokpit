# Phase 4: Code Review Disposition

**Source review:** `.planning/phases/04-clients-and-projects/04-REVIEW.md` (12 findings: 0 critical, 6 warnings, 6 info; standard depth, 145 files)
**State:** recorded by the unattended `--chain` run; no finding has been triaged yet, so every row is `open`. Fix with `/gsd-code-review 4 --fix` (Critical and Warning) or by hand, then update this table.
**Review limit:** the reviewer could not run the test suite (no PostgreSQL reachable from its sandbox); the findings come from reading the code. The suite itself ran green in DDEV after every plan (1547 tests at the end of the phase).

| ID | Severity | Disposition | Commit | Rationale |
|----|----------|-------------|--------|-----------|
| WR-01 | Warning | open | - | Partner scope on `Project` relies on the SoftDeletes scope for "not archived"; add `deleted_at IS NULL` to `constrainForPartner` so a scope-stripping path cannot expose an archived visible project. Security-relevant (Partner isolation). |
| WR-02 | Warning | open | - | Client currency lock and project money currency are read outside a row lock; a concurrent project save and currency change can store money in a different currency than the client's. Lock the client row and re-check inside the transaction. |
| WR-03 | Warning | open | - | The queued invitation notification carries the signed URL with the plaintext token into `jobs`, `failed_jobs.payload` and Horizon, which weakens the token hashing. Encrypt the payload or shorten retention. Security-relevant. |
| WR-04 | Warning | open | - | The Admin "send reset" action reports success when nothing was sent (for example a Partner of an archived client). Throw a `DomainException` and hide the action for archived clients. |
| WR-05 | Warning | open | - | Project Actions and parts of `ClientInput` do not validate key format, name, status, priority, billing type, date order or field lengths; non-form callers get raw database errors and a blank name can be persisted. |
| WR-06 | Warning | open | - | Reset and login e-mail lookup is case-sensitive although e-mails are stored lowercase; a Partner who types a capital letter gets no reset mail. Normalise at the boundary. |
| IN-01 | Info | open | - | ARES response `ico` is not compared with the requested number; worst-case latency about 24 s. |
| IN-02 | Info | open | - | `Europe/Prague` is hardcoded in `InvitationMail`. |
| IN-03 | Info | open | - | `CreateProject` trusts a stale `trashed()` flag; `InvitePartner` locks and re-reads. |
| IN-04 | Info | open | - | `ResendInvitation` does not re-check that the e-mail still has no account. |
| IN-05 | Info | open | - | Archived-client lockout is enforced only by `canAccessPanel()`; future API routes (Phase 7, Phase 12) need the same gate. |
| IN-06 | Info | open | - | Country field validation and `isCzech()` disagree on lowercase input. |
