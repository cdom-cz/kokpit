# Phase 14: Switchable payment method and independent invoice currency - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-10-23
**Phase:** 14-Switchable payment method and independent invoice currency
**Areas discussed:** Payment method model, Bank account selection, Free invoice currency, Changes after issue

---

## Payment method model

| Option | Description | Selected |
|--------|-------------|----------|
| Exclusive transfer / online | New column replaces the boolean | ✓ |
| Keep boolean | Only change the outputs | |
| Three values transfer / online / both | Keeps today's combination | |

**User's choice:** Exclusive transfer / online.
**Notes:** Default is always transfer, online chosen by hand (owner message). Then clarified twice that a transfer invoice can also be paid online, i.e. bank details plus the card button; the card switch for transfer is kept (recorded as an assumption in D-03).

## Bank account selection

| Option | Description | Selected |
|--------|-------------|----------|
| Multiple accounts per currency | Drop UniqueCurrencies | ✓ |
| Keep one per currency | Account choice free, settings unchanged | |

**User's choice:** Multiple accounts per currency; a currency/account mismatch is allowed without a warning.
**Notes:** Blocking the issue on a mismatch was rejected as contrary to the roadmap.

## Free invoice currency

| Option | Description | Selected |
|--------|-------------|----------|
| Any ISO 4217 | Whole list | |
| CNB-rate currencies plus account and client currencies | Reliable conversion | ✓ |

**User's choice:** CNB-rate currencies plus account and client currencies; leave Stripe-unsupported currencies to Stripe (existing failure path).

## Changes after issue

**User's choice:** Same regime as currency (open until send, then unlock with reason); changing method or currency deactivates the Stripe link and recreates it per the new state; the currency is locked once a payment exists.

## Claude's Discretion

Names, account identifier, form layout, wording.

## Deferred Ideas

None.
