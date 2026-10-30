# API Coverage — Stripe

> Full coverage by default. Opt-outs are explicit, reasoned decisions.

Scope: the Stripe API surface the card-payment flow of Phase 11 touches (Payment Links, webhook events and their signature, and the neighbouring capabilities a payment integration usually reaches for). Plans named in brackets implement each integrated capability.

| capability | decision | reason |
|---|---|---|
| payment_links.create (inline price, single use, metadata, messages) | INTEGRATE | |
| payment_links.update active=false (deactivation) | INTEGRATE | |
| webhook signature verification (Stripe-Signature, 300 s tolerance) | INTEGRATE | |
| event checkout.session.completed | INTEGRATE | |
| event checkout.session.async_payment_succeeded | INTEGRATE | |
| event refund.created | INTEGRATE | |
| idempotent requests (Idempotency-Key per link row) | INTEGRATE | |
| API version pinning (2026-09-30.endive) | INTEGRATE | |
| payment_links.retrieve | OPT-OUT | not needed: Kokpit stores the id and URL returned at creation and never reads the link back |
| payment_links.list | OPT-OUT | not needed: payment_links is Kokpit's own record of every link it created |
| payment_links.line_items.list | OPT-OUT | not needed: one inline line per link, known at creation |
| products and prices create/update | OPT-OUT | not needed: the link uses inline price_data, so no product or price objects are managed |
| checkout.sessions.retrieve / list | OPT-OUT | not needed: the session fields used for matching arrive in the signed event payload |
| payment_intents.retrieve | OPT-OUT | not needed: matching uses the session's amount_total and currency, never the payment intent |
| event checkout.session.async_payment_failed | OPT-OUT | not needed: links accept card payments only, which settle synchronously; an unpaid session records nothing |
| event checkout.session.expired | OPT-OUT | not needed: an expired session moves no money and changes no Kokpit state |
| event charge.refunded | OPT-OUT | not needed: refund.created already reports every refund with its own unique id |
| refunds.create | OPT-OUT | explicitly out of scope: refunds are made in the Stripe dashboard (PAY-01, D-13 "no Stripe API call and no refund automation") |
| disputes (charge.dispute.* events, disputes API) | OPT-OUT | explicitly out of scope: disputes are handled in the Stripe dashboard (PAY-01, deferred to v2) |
| balance transactions and fees | OPT-OUT | explicitly out of scope: fee tracking is v2 (PAY-01) |
| customers create/update | OPT-OUT | not needed: a payment link needs no customer object, and the payer's personal data is not kept (stored payloads are trimmed) |
| events.list / events.retrieve (replay from the API) | OPT-OUT | not needed: redelivery is Stripe's own retry or a manual resend from the Stripe dashboard; stored events are re-dispatched when unprocessed |
| webhook endpoint management API | OPT-OUT | not needed: the owner registers the event destination once in the Stripe dashboard (documented in README by plan 11-20) |
| thin events / event destinations v2 payloads | OPT-OUT | not needed yet: the checkout and refund events used here are delivered as snapshot events, which the SDK's verifier handles |
| Stripe Connect (connected accounts) | OPT-OUT | explicitly out of scope: one instance serves one company with one Stripe account |
