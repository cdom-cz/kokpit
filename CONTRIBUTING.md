# Contributing to Kokpit

Kokpit is a public AGPL-3.0 project. This guide currently covers repository hygiene; setup and conventions for the application arrive together with the application.

## Repository hygiene

The rule is: **Fictional data only.** Never put client names, prices, rates, invoice or production data, real e-mail addresses, company IDs, bank accounts, IP addresses, hostnames, tokens or personal absolute paths into code, tests, fixtures, docs, planning docs under `.planning/`, or commit messages.

Use `example.com` addresses, the placeholder company ID `12345678`, paths like `/Users/example/`, and test fakes assembled at runtime from fragments.

Review procedure before every commit:

1. `git status`
2. `git diff --staged`
3. `scripts/check-sensitive.sh`
