# Phase 8: CNB API Coverage Matrix

**External API:** Czech National Bank public API (`https://api.cnb.cz/cnbapi`), Swagger 2.0 description at `/cnbapi/api-docs` (read 2026-10-14, 22 GET operations, no authentication).
**Integrated by:** `App\Domain\ExchangeRates\Cnb\CnbClient` (plans 08-01, 08-06, 08-08).
**Rule:** full coverage by default; every capability that Kokpit does not call carries an explicit opt-out reason.

Kokpit needs exactly one data set from the CNB: the daily exchange-rate fixing of the main currencies (EX-01, D-02), today and history. Everything else the API offers is a different data set (interest rates, money-market operations, forward rates, averages, other-currency monthly rates) with no consumer in Kokpit's flow client -> time -> billing -> invoice -> payment.

| capability | decision | reason |
|---|---|---|
| exrates/daily (date, lang): the daily fixing list valid for a day | INTEGRATE | |
| exrates/daily-year (year): every daily fixing list of one year | INTEGRATE | |
| exrates/daily-currency-month (currency, yearMonth): one currency for one month | OPT-OUT | D-02 stores the whole list; daily-year already returns every currency of a year in one request, so a per-currency month call would add requests the CNB asks to avoid |
| exrates/monthly-averages-currency (currency) | OPT-OUT | averages are not used: D-01 converts each entry at the rate of its own day and documents snapshot the rate of the issue day |
| exrates/monthly-averages-year (year) | OPT-OUT | averages are not used (D-01, EX-03 snapshots) |
| exrates/monthly-cumulative-averages-currency (currency) | OPT-OUT | cumulative averages are a tax-accounting figure; full accounting is out of scope (REQUIREMENTS Out of Scope) |
| exrates/monthly-cumulative-averages-year (year) | OPT-OUT | cumulative averages are a tax-accounting figure; full accounting is out of scope (REQUIREMENTS Out of Scope) |
| exrates/quarterly-averages-currency (currency) | OPT-OUT | averages are not used (D-01, EX-03 snapshots) |
| exrates/quarterly-averages-year (year) | OPT-OUT | averages are not used (D-01, EX-03 snapshots) |
| fxrates/daily-month (other currencies, monthly list) | OPT-OUT | not a daily fixing; a client currency outside the daily list stays visibly unconverted ("chybí kurz") instead of mixing two rate sources; revisit when a client bills in such a currency |
| fxrates/daily-range-currency (currency, lang, yearMonthFrom, yearMonthTo) | OPT-OUT | other-currency monthly list, same reason as fxrates/daily-month |
| fxrates/daily-year (lang, year) | OPT-OUT | other-currency monthly list, same reason as fxrates/daily-month |
| czeonia/daily (date): CZEONIA overnight rate | OPT-OUT | interest-rate benchmark, no Kokpit consumer |
| czeonia/daily-year (year) | OPT-OUT | interest-rate benchmark, no Kokpit consumer |
| pribor/daily (date): PRIBOR fixing | OPT-OUT | interest-rate benchmark, no Kokpit consumer |
| pribor/daily-year (year) | OPT-OUT | interest-rate benchmark, no Kokpit consumer |
| pribor/daily-year-term (period, year) | OPT-OUT | interest-rate benchmark, no Kokpit consumer |
| omo/daily (date): open market operations | OPT-OUT | central-bank operations data, no Kokpit consumer |
| omo/daily-year (year) | OPT-OUT | central-bank operations data, no Kokpit consumer |
| forward/daily (date): forward rates | OPT-OUT | forward points are a trading figure; invoices use the published fixing (IN-07) |
| forward/daily-range-currency-pair-maturity (pair, range, maturity) | OPT-OUT | forward points are a trading figure; invoices use the published fixing (IN-07) |
| skd/daily (date): SKD money-market data | OPT-OUT | money-market statistics, no Kokpit consumer |

## How the two integrated capabilities are used

- **exrates/daily** (`CnbClient::daily()`, plan 08-01): the scheduled run (plan 08-03, 15:00 to 17:00 Prague plus a final 18:00 run) and the manual "download today" action (plan 08-08). One request per run, skipped entirely when the day's list is already stored. The row date is the payload's `validFor`, never the requested day.
- **exrates/daily-year** (`CnbClient::year()`, plan 08-06): the gap self-heal of the scheduled run (only when a Czech working day inside the lookback window has no stored list) and the queued history backfill (plan 08-08), one request per year.

Request discipline: the CNB FAQ asks clients not to access the service excessively. Kokpit makes at most four daily requests on a working day (fewer once the list is stored), none on weekends and Czech public holidays, and one request per affected year for history.
