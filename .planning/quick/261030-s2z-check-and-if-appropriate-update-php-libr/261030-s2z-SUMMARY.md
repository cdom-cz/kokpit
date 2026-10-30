---
status: complete
quick_id: 261030-s2z
---

# Quick Task 261030-s2z: Check/update openspout/openspout and chillerlan/php-qrcode

## Outcome

No update performed. Both libraries are at the newest release allowed by the current constraints, and the newer majors are blocked by Filament. No code change, no commit.

| Library | Locked | New major | Blocked by |
|---|---|---|---|
| openspout/openspout | v4.32.0 (latest 4.x) | v5.13.0 | filament/actions v5.11.0 requires ^4.23 |
| chillerlan/php-qrcode | 5.0.5 (latest 5.x) | 6.0.1 | filament/filament v5.11.0 requires ^5.0 |

Verified 2026-10-30 with `composer why-not` and `composer update <both> --dry-run` ("Nothing to modify in lock file") in DDEV. Filament 5.11.0 is the newest stable release.

## Revisit when

A Filament release allows openspout ^5 or php-qrcode ^6.

## Notes for the future upgrade

- openspout 5: Options, Style, SheetView, Comment, Cell and Row become readonly; Row loses its style parameter, so the bold header in `BuildReportExport::writeXlsx` needs a Style passed via `SpreadsheetCells::text()`.
- php-qrcode 6: `outputType` is removed; `QrPng` should use `outputInterface => QRGdImagePNG::class`.

## Working tree

The user's uncommitted composer.lock bumps (aws/aws-sdk-php, league/commonmark) were left untouched.
