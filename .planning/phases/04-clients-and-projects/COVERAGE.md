# API Coverage — ARES (Czech register of economic subjects, public REST API)

> Full coverage by default. Opt-outs are explicit, reasoned decisions.

Scope: the client form's ARES button (CL-04, D-08, D-09), implemented by plan 04-15 (`AresClient`, `AresCompany`; the company number checksum it relies on is plan 04-14). The API is public, keyless and read-only; there are no write capabilities. Capability ids follow the API's resource groups.

| capability | decision | reason |
|---|---|---|
| ekonomicke-subjekty lookup by company number (GET basic record) | INTEGRATE | |
| ekonomicke-subjekty search (POST vyhledat by name or criteria) | OPT-OUT | not needed yet: CL-04 asks for a lookup by company ID only; a name search is a separate feature to decide later |
| business register record (ekonomicke-subjekty-vr) | OPT-OUT | not needed: the basic record already holds name, company number, tax number and registered address, the only fields D-08 lets ARES fill |
| statistical register record (ekonomicke-subjekty-res) | OPT-OUT | not needed: no statistical classification is stored on a client |
| trade licence register record (ekonomicke-subjekty-rzp) | OPT-OUT | not needed: trade licences are not part of the client model |
| other source registers (health, parties, churches, farmers, schools, insolvency) | OPT-OUT | explicitly out of scope: none of their data is part of the client model, and D-08 limits ARES to registry identity and address fields |
| standardised address search (standardizovane-adresy) | OPT-OUT | not needed: the address comes from the basic record and is stored as typed; no address validation feature is planned |
| code lists (ciselniky-nazevniky) | OPT-OUT | not needed: legal form and other coded values are not stored on a client |
