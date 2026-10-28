---
quick_id: 261028-kr9
status: complete
commit: 84cb780
---

# Summary

`Spayd::sanitiseMessage()` now uses `Str::ascii()` instead of `iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE')`
and the `Normalizer` workaround. musl rejects the `//TRANSLIT` suffix, glibc answers `?` under the C locale;
`Str::ascii()` behaves the same on both. Added a test for `ß`, `Ł`, `Æ`, `ø` and an unmappable emoji.
Unit invoicing tests (99), PHPStan and Pint pass.
