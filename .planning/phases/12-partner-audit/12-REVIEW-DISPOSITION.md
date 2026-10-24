---
phase: 12-partner-audit
review: 12-REVIEW.md
critical: 0
warning: 5
info: 6
---

# Phase 12 code review disposition

Advisory ledger: every finding of 12-REVIEW.md starts as `open`. Nothing was fixed or dismissed by the orchestrator.

| ID | Severity | Summary | Disposition |
|----|----------|---------|-------------|
| WR-01 | warning | Policy completeness is checked against CanaryRegistry, not Gate::policies() (User, PersonalAccessToken, DashboardWidgetPreference have no row) | open |
| WR-02 | warning | Only five abilities are probed; deleteAny, restore, forceDelete, reorder, replicate and others are not declarable | open |
| WR-03 | warning | FORBIDDEN_FIELD_PATTERN misses names such as billed_at, amount, fee, margin, total and over-matches "rate" inside other words | open |
| WR-04 | warning | ChannelProbe::apiBlob silently skips API routes with unknown or multiple path parameters | open |
| WR-05 | warning | Route binders for export/import apply to any route with such a parameter name | open |
| IN-01 | info | Closed routes are looked up by name though RouteRules allows unnamed keys | open |
| IN-02 | info | RouteInventory fails on closure middleware; only the first HTTP verb is probed | open |
| IN-03 | info | The "Partner audit" CI step is skipped when the Pest step fails (needs `if: ${{ !cancelled() }}`) | open |
| IN-04 | info | Unused `partner` flag in walkResourceMap; class-directory scanner duplicated four times | open |
| IN-05 | info | README and CONTRIBUTING claim is stronger than WR-01 to WR-04 allow | open |
| IN-06 | info | `composer partner-audit` takes about 153 s locally; Composer's 300 s timeout may be hit on slower machines | open |
