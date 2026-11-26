# Phase 20: Image Attachments - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-11-26
**Phase:** 20-Image Attachments
**Areas discussed:** Inserting images in the editor, Limits and format, Delivery and display, Lifecycle and orphan cleanup

---

## Inserting images in the editor

| Question | Options | Selected |
|----------|---------|----------|
| How to insert | Button + clipboard + drag and drop / button only / button + clipboard | Button + clipboard + drag and drop |
| New article not yet saved | Upload now, bind on save / images only on saved articles | Upload now, bind on save |
| Markdown reference and alt | `kokpit-attachment:<uuid>`, alt optional / alt required | Alt optional |
| Image ownership | One article only / shared library | One article only |

## Limits and format

| Question | Options | Selected |
|----------|---------|----------|
| Size and dimensions | 10 MB and 2400 px / 5 MB and 1920 px / no scaling | 10 MB, 2400 px |
| Output format | Preserve format / all to WebP | Preserve format |
| Animated GIF | Refuse / first frame / keep animation | Refuse |
| Validation | Sniff content and decode / extension and MIME only | Sniff and decode |

## Delivery and display

| Question | Options | Selected |
|----------|---------|----------|
| Delivery | Stream through the app / short signed S3 link | Stream through the app |
| Cache | private, ETag, short max-age / no-store | private, ETag, 5 min |
| Signed URL | Session and policy only / plus expiring signed URL | Session and policy only |
| CSP | None this phase / CSP on the image route only | CSP on the image route only |
| Versions | Authorise by article / images bound per version | Authorise by article |

## Lifecycle and orphan cleanup

| Question | Options | Selected |
|----------|---------|----------|
| Retention | 7 days after orphaning / next job run / 30 days | 7 days (pending 24 h) |
| Reference tracking | Index written at save / parse bodies in the job | Index written at save |
| Secret on a screenshot | Manual delete action with reason / notice only | Manual delete action (plus upload notice) |

## Claude's Discretion

Image library, table and column names, S3 key layout, front-end script, Czech wording, job schedule.

## Deferred Ideas

Page-wide CSP, animated GIF support, client sharing of images (Phase 23).
