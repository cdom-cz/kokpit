# Quick Task 261015-cv5: Make dashboard widgets collapsible with the state saved per user in the database - Context

**Gathered:** 2026-10-15
**Status:** Ready for planning

<domain>
## Task Boundary

Make every dashboard widget card collapsible to a compact view, independently of the others. The state is saved per user in the database immediately on toggle (no save button).

</domain>

<decisions>
## Implementation Decisions

### Collapsed look
- A collapsed card shows its title plus one key figure (for example the main number or count), so collapsed cards still convey status.

### Partner dashboard
- Partner dashboard widgets get the same behaviour through the same shared wrapper.
- A Partner can only store state for widgets a Partner can see (authorised server side).

### Storage shape
- A dedicated preferences table: user id, widget key, collapsed flag.
- UUID v7 primary key, FK to users, unique (user, widget key), DB check constraint on the allowed widget keys.
- Written immediately on toggle through a Livewire action authorised for the signed-in user only; optimistic UI, silent on success, small error toast only on failure. Default expanded for new users.

### Claude's Discretion
- Exact wrapper markup, which key figure each widget shows, naming of table and class, and the Czech copy.

</decisions>

<specifics>
## Specific Ideas

Follows the todo's suggested direction: shared collapsible wrapper with chevron header and `aria-expanded`.

</specifics>

<canonical_refs>
## Canonical References

.planning/todos/pending/2026-10-15-collapsible-dashboard-widgets-persisted-in-db.md

</canonical_refs>
