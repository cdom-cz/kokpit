# Phase 4: Clients and Projects - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-10-08
**Phase:** 4-Clients and Projects
**Areas discussed:** Partner invitation, Hiding prices from Partner, Client form/ARES/archival, Project key and fields

---

## Partner invitation

| Option | Description | Selected |
|--------|-------------|----------|
| Signed link, account created on acceptance | Invitation record; user created when password is set | ✓ |
| Account created immediately, link is a password reset | Simpler, leaves unconfirmed accounts | |

**User's choice:** Signed link, account on acceptance; 7-day validity with resend and revoke; reject existing e-mails; deactivate/reactivate/reset password.

---

## Hiding prices from Partner

| Option | Description | Selected |
|--------|-------------|----------|
| Separate `project_billing` table | Admin-only 1:1 model | ✓ |
| Columns on projects, hidden | Relies on every query omitting them | |
| DB view for Partner | Strong but complex | |

**User's choice:** Separate table; Partner sees no clients at all; Partner sees name, key, status, description, dates, priority.
**Notes:** User chose that Partner also sees project tags (against the recommendation to hide them).

---

## Client form, ARES, archival

**User's choice:** ARES overwrites only ARES fields; one billing address, ID and tax ID; fixed stage enum; archival keeps projects, blocks Partner login; one primary contact, separate invoice e-mail; defaults prefilled and stored.
**Notes:** User raised mid-discussion that they have foreign clients → company ID and tax ID optional, ARES only for CZ.

---

## Project key and fields

**User's choice:** Initials-based key suggestion with collision variants; billing types hourly and fixed only; statuses Planned, To clarify, In progress, In review, Ready to release, Done; priorities low, normal, high, urgent; dates optional.

---

## Claude's Discretion

Class names, e-mail wording, ARES DTO, tag component, form layout, deactivation storage.

## Deferred Ideas

None.
