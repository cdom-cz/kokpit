# Deferred items, phase 07-rest-api

## From plan 07-11

- **Untranslated rule sentences in the public API docs.** Scribe appends a sentence for each validation rule it can describe (`min`, `max`, `uuid`, `integer` ranges) to the documented field, and with the app locale `cs` the sentence is the raw Laravel text with an unreplaced `:Attribute` placeholder (for example `:Attribute nemůže být delší než 200 znaků.`). It shows on the lookup and list parameters, on `duration_seconds` of the single create and the update, and elsewhere in the generated spec. Fix options for plan 07-12 or later: replace such rules by closure rules (as done for the batch `entries` limit), or configure Scribe to describe rules in English. Found while checking the batch spec; not caused by plan 07-11.
- **Empty `examples` on the batch `entries` property in the OpenAPI spec.** Scribe renders `examples: [[]]` for the array property although the curl example is correct. Cosmetic.
