---
status: testing
phase: 20-image-attachments
source: [20-VERIFICATION.md]
started: 2026-11-27T12:13:39Z
updated: 2026-11-27T12:13:39Z
---

## Current Test

number: 1
name: Editor image insertion in the browser
expected: |
  Button, paste and drop insert a reference at the cursor, the status line shows progress, the SVG is refused with its Czech message, and the secret notice and dark-mode PNG border render.
awaiting: user response

## Tests

### 1. Editor image insertion in the browser
expected: After filament:assets in DDEV, in Znalosti create an article; the Vložit obrázek button, clipboard paste and dropping two files (one SVG) work as described in 20-VERIFICATION.md human_verification item 1 and the 20-08 plan human-check.
result: [pending]

### 2. First Zerops deploy rehearsal prints the GD check
expected: kokpit:deploy:verify output contains "Knihovna obrázků GD: v pořádku" and "všech pět kontrol prošlo", and apk add reports no unknown package php85-gd.
result: [pending]

## Summary

total: 2
passed: 0
issues: 0
pending: 2
skipped: 0
blocked: 0

## Gaps
