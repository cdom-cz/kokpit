---
phase: 18-knowledge-articles-and-secret-guard
source: 18-REVIEW.md
---

# Code review disposition

One row per finding. `open` means seen and not yet triaged.

| Finding | Severity | Disposition |
|---------|----------|-------------|
| CR-01 PEM private key bypass for indented, quoted or escaped keys | critical | open |
| WR-01 scanner reads Markdown source, not the rendered text | warning | open |
| WR-02 task-list button lost in SPA mode | warning | open |
| IN-01 random runs over 256 characters and quoted passwords with spaces | info | open |
| IN-02 normaliser keeps the final newline after a code fence only | info | open |
| IN-03 rejected link can leave an anchor without href | info | open |
| IN-04 write failures and concurrent archive reach the Admin as a raw error | info | open |
| IN-05 body length limit defined in four places | info | open |
| IN-06 editor preview loads remote images in the write tab | info | open |
