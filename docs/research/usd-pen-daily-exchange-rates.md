# USD/PEN Daily Exchange Rate sources

_Researched 2026-07-23 for the single-user, self-hosted Money Assistant MVP._

## Decision

Use the Banco Central de Reserva del Peru (BCRP) BCRPData JSON API, series `PD04638PD`, as the sole automatic seed source:

```text
GET https://estadisticas.bcrp.gob.pe/estadisticas/series/api/PD04638PD/json/{from}/{to}/esp
```

The series is explicitly named **"TC Interbancario (S/ por US$) - Venta"**. Its direction therefore already matches the domain value: PEN per one USD. It is a daily interbank **sell** quote, not a midpoint, tax rate, card rate, or the rate actually applied to a Transaction. Multiplying a USD amount by it produces a PEN reporting value; inversion is required only when expressing PEN in USD. The series identity and semantic must be stored as provenance and shown to the owner as "BCRP interbank sell" rather than as an unspecified official rate. [BCRPData `PD04638PD` JSON result](https://estadisticas.bcrp.gob.pe/estadisticas/series/api/PD04638PD/json/2026-07-01/2026-07-23/esp) [BCRP exchange-rate glossary](https://www.bcrp.gob.pe/publicaciones/glosario/t.html)

BCRPData is the lowest-friction authoritative source reviewed. BCRP documents a public GET API with date-range and JSON output, says API access does not require first logging in, and permits up to ten same-frequency series per request. No API key or account is required for this one-series request. BCRP does not publish a request quota or service-level guarantee in the API documentation, so the specification must use caching, bounded retries, and timeouts rather than interpreting undocumented limits as unlimited service. [BCRPData developer API](https://estadisticas.bcrp.gob.pe/estadisticas/series/ayuda/api)

BCRP expressly allows full or partial reproduction of portal content without prior authorization when the source is cited. It disclaims liability, may change the portal without notice, and places responsibility for externally sourced statistics on the original provider. The app and owner-facing rate detail should attribute the value to "Banco Central de Reserva del Peru, BCRPData series PD04638PD." [BCRPData conditions of use](https://estadisticas.bcrp.gob.pe/estadisticas/series/ayuda/condiciones-de-uso)

## Required seed and fallback policy

For a Transaction occurrence date `D` in the owner's local Lima calendar:

1. Request the inclusive range `D - 7 calendar days` through `D` from `PD04638PD`. Do not request a "latest" rate and attach it to `D`; the explicit range is what establishes the observation date.
2. Parse each `periods[].name` as the **source observation date** and its corresponding decimal string as the source value. Discard `n.d.`, malformed, non-positive, future-dated, or wrong-series responses. Never use the HTTP request date, response arrival time, or job run date as the rate's source date.
3. Prefer a valid observation exactly on `D`. If `D` is a Saturday, Sunday, Peruvian market holiday, or another definitively missing historical day, choose the greatest valid BCRP observation date earlier than `D`, but only when `D - source_date <= 7` calendar days. Retain `D` as the Daily Exchange Rate's applicable date and retain the earlier BCRP observation date separately.
4. Do not treat a recent `n.d.` as proof of a holiday. BCRP says BCRPData is updated periodically, not at a guaranteed hour, and the payload exposes no publication timestamp. A same-day or recent-business-day absence can be publication lag. Retry with capped backoff and again on the next Lima business day before classifying a business date as missing. [About BCRPData](https://estadisticas.bcrp.gob.pe/estadisticas/series/ayuda/bcrpdata) [BCRPData developer API](https://estadisticas.bcrp.gob.pe/estadisticas/series/ayuda/api)
5. Once an exact-date observation or permitted carry-forward value seeds `D`, preserve the existing domain rule: do not automatically overwrite it later. Record `retrieved_at` separately from `source_date`; retrieval proves when the application saw a quote, not when BCRP published or the market observed it.
6. On timeout, HTTP error, invalid schema, or `n.d.`, retry only the same BCRP series. Do not silently substitute BCRP's SBS banking sell series, SUNAT, SBS accounting, a buy quote, a midpoint, or an aggregator because those values have different populations and semantics.
7. If no valid `PD04638PD` observation exists within seven calendar days after bounded retries, leave combined reporting unavailable for affected Transactions and require an owner-entered Daily Exchange Rate. Separate original-currency reporting remains available. SUNAT/SBS may be displayed as a manually consulted reference, but not imported automatically under the BCRP provenance label.

The missing-day behavior is observable rather than inferred: a request spanning a weekend omits Saturday and Sunday periods, while the 28 and 29 July 2025 Peruvian holidays are present as `n.d.` and 30 July resumes with a value. This justifies the application's bounded prior-observation rule, but BCRP does not itself instruct consumers to carry a quote forward. The seven-day carry is Money Assistant policy, not BCRP semantics. [BCRP `PD04638PD`, 18-23 July 2026](https://estadisticas.bcrp.gob.pe/estadisticas/series/api/PD04638PD/json/2026-07-18/2026-07-23/esp) [BCRP `PD04638PD`, 24 July-1 August 2025](https://estadisticas.bcrp.gob.pe/estadisticas/series/api/PD04638PD/json/2025-07-24/2025-08-01/esp)

## Date semantics

Three dates must remain distinct:

| Date | Meaning | Source |
| --- | --- | --- |
| Applicable date | The Transaction's local occurrence date whose combined report needs a Daily Exchange Rate. | Money Assistant domain rule |
| Source date | The BCRP `periods[].name` date on which the interbank sell observation applies. It may precede the applicable date under the seven-day carry policy. | BCRP payload |
| Retrieval date/time | When Money Assistant successfully received the payload. It is operational metadata only. | Money Assistant clock |

The API response does **not** expose a `published_at`, revision timestamp, or guaranteed daily release hour. Calling `periods[].name` a "publication date" would overstate the contract; it is the dated statistical observation. The current/retrieval date must never be substituted for it. BCRP only states that the database is updated periodically and is available every day of the year. [About BCRPData](https://estadisticas.bcrp.gob.pe/estadisticas/series/ayuda/bcrpdata) [BCRPData developer API](https://estadisticas.bcrp.gob.pe/estadisticas/series/ayuda/api)

For a recent business date with no value, keep the distinction visible: "not yet available from BCRP" is not the same state as "no market observation for a weekend/holiday." For an old date bracketed by later valid observations, an omitted period or `n.d.` can be treated as a definitive missing observation and the carry policy can run immediately.

## Precision finding

The six-decimal requirement is a **storage and deterministic-calculation capability**, not six guaranteed decimal places of market information.

`PD04638PD` sometimes returns long decimal strings, such as `3.40378571428571`, so an API consumer can receive and round more than six decimal places on those observations. Other dates return only `3.545` or `3.5485`, and the same response's series metadata declares `"dec":"3"`. BCRP therefore does not contractually or consistently provide six meaningful decimal places even though some calculated daily values are serialized with many more. [BCRP `PD04638PD`, July 2026](https://estadisticas.bcrp.gob.pe/estadisticas/series/api/PD04638PD/json/2026-07-01/2026-07-23/esp) [BCRP `PD04638PD`, July-August 2025](https://estadisticas.bcrp.gob.pe/estadisticas/series/api/PD04638PD/json/2025-07-24/2025-08-01/esp)

The specification should consequently require:

- parse the API value as a base-10 decimal string, never binary floating point;
- round a value with more than six fractional digits to the app's fixed six-decimal scaled integer using an explicit decimal rule, then preserve it;
- pad a shorter source value for storage (`3.545` becomes `3.545000`) without claiming that the appended zeros are source precision;
- keep the exact source string or equivalent source-precision metadata for provenance;
- allow the owner to replace the seed with a six-decimal value.

If "six-decimal precision" is interpreted as six independently significant decimal places guaranteed by the publisher, **none of the viable official Peruvian sources reviewed qualifies**. Making that a hard provider gate would leave the MVP without an official low-friction source and would provide false precision relative to cent-denominated Transactions. Six-place local storage still prevents repeated conversion drift and accommodates owner-entered values.

## Candidate comparison

| Candidate | Authority and exact semantics | Date and non-business-day behavior | Genuine precision | Seven-day history | Access, limits, and self-hosting friction | Licensing / redistribution | Decision |
| --- | --- | --- | --- | --- | --- | --- | --- |
| **BCRPData `PD04638PD`** | BCRP-published `TC Interbancario (S/ por US$) - Venta`; direct PEN per USD sell series. | Dated observations; weekends omitted; holidays can be `n.d.`; no publication timestamp or release SLA. | Metadata says 3 decimals; payload sometimes contains more than 6 and sometimes only 3-4. Six-place source accuracy is not guaranteed. | Explicit arbitrary `from`/`to` range easily covers seven days and older history. | Public HTTPS GET/JSON, no key or login; no published quota/SLA. One small request per missing applicable date can be cached permanently. | Reproduction allowed with source citation. | **Primary automatic seed.** |
| **BCRPData `PD04640PD`** | BCRP republishes `TC Sistema bancario SBS (S/ por US$) - Venta`; still PEN per USD sell, but a banking-system/SBS population rather than interbank. | Same convenient BCRP date-range API and missing markers. | Declared and returned at 3 decimals in reviewed periods. | Yes. | Same low-friction API. | Same BCRP portal terms, while BCRP notes responsibility for externally sourced statistics remains with the original provider. | Do not silently fall back: it changes the statistical series and fails genuine six-decimal precision. [BCRP `PD04640PD`](https://estadisticas.bcrp.gob.pe/estadisticas/series/api/PD04640PD/json/2026-07-01/2026-07-23/esp) [BCRP conditions](https://estadisticas.bcrp.gob.pe/estadisticas/series/ayuda/condiciones-de-uso) |
| **SBS Tipo de Cambio Promedio / Contable** | Official SBS pages expose USD buy and sell quotations, a professional-market weighted average, and a singular accounting rate. These are distinct purposes; the accounting rate is for company accounting, not a general personal-spending midpoint. | Date-picker pages expose a dated value, but the reviewed pages do not document a machine contract, publication timestamp, or weekend carry rule. | USD buy/sell is shown at 3 decimals and USD professional/accounting values at 4 decimals; other currencies having 6 decimals does not make USD six-decimal. | Human date lookup exists. | Legacy ASP.NET page/postback rather than a documented stable JSON API; materially more brittle for unattended self-hosting. | The reviewed SBS rate pages show copyright but no BCRP-equivalent explicit reproduction grant. | Authoritative human cross-check only; not the MVP adapter. [SBS weighted-average rates](https://www.sbs.gob.pe/app/pp/SISTIP_PORTAL/Paginas/Publicacion/TipoCambioPromedio.aspx) [SBS accounting rates](https://www.sbs.gob.pe/app/pp/SISTIP_PORTAL/Paginas/Publicacion/TipoCambioContable.aspx) [BCRP glossary definitions](https://www.bcrp.gob.pe/publicaciones/glosario/t.html) |
| **SUNAT official exchange-rate publication** | Official tax publication of SBS closing buy/sell quotations. SUNAT explicitly says the rate published on a date is the SBS close from the **previous day**, so publication date and market date are not interchangeable. | SUNAT explicitly instructs users to take the immediately preceding published rate when a day has no publication. | Current text feed and UI use 3 decimals for USD buy/sell; padding is not added information. | Human monthly history exists, but the current text endpoint is only the current publication. | `/a/txt/tipoCambio.txt` is simple but undocumented as an API; the official historical UI's own JavaScript calls a reCAPTCHA-protected POST endpoint. That is unsuitable for unattended recovery after downtime. | The reviewed page asserts SUNAT copyright and provides no explicit redistribution grant comparable to BCRP's. | Good manual fallback and an official statement of tax carry behavior; reject as the automatic adapter because dates are shifted, precision is lower, and historical automation is gated. [SUNAT monthly publication and notes](https://www.sunat.gob.pe/cl-at-ittipcam/tcS01Alias) [SUNAT current text](https://www.sunat.gob.pe/a/txt/tipoCambio.txt) [SUNAT official client code](https://www.sunat.gob.pe/cl-at-ittipcam/js/papeleta/papeleta.js?t=291120202213) |
| **Frankfurter v2** | Open-source aggregator. It has no BCRP provider; USD/PEN defaults to a blend of cross-rates from multiple foreign institutions, not a Peruvian official USD/PEN series. Provider dates can differ inside one blended result. | Daily time series and provider expansion exist, but a result can include stale provider dates; default last digits can change as new provider data arrives. | Reviewed USD/PEN output is 4 decimals; no six-decimal guarantee. | Yes. | No API key or monthly quota; abuse rate limiting exists. Easy Docker self-hosting, but that adds an unnecessary second service and still does not add BCRP. | Code is MIT; underlying data remains subject to each provider's terms. | Reject for primary/fallback financial provenance despite excellent access. [Frankfurter API and FAQ](https://frankfurter.dev/) [Frankfurter providers](https://frankfurter.dev/providers/) [Frankfurter USD/PEN with providers](https://api.frankfurter.dev/v2/rates?base=USD&quotes=PEN&from=2026-07-20&to=2026-07-23&expand=providers) [Frankfurter deployment](https://frankfurter.dev/deploy/) [Frankfurter MIT license](https://github.com/lineofflight/frankfurter/blob/main/LICENSE) |

## Why the sell series

Money Assistant needs one reproducible directional reporting rate, not an attempt to reconstruct the owner's card issuer spread. `PD04638PD` directly matches PEN per USD and avoids a locally invented midpoint. The BCRP glossary defines a nominal exchange rate as units of domestic currency for one foreign currency, while the series itself identifies the selected side as `Venta`. [BCRP exchange-rate glossary](https://www.bcrp.gob.pe/publicaciones/glosario/t.html) [BCRP `PD04638PD`](https://estadisticas.bcrp.gob.pe/estadisticas/series/api/PD04638PD/json/2026-07-01/2026-07-23/esp)

The choice is conventional rather than legally mandatory. A buy quote would systematically answer a different question, and averaging buy/sell would create a derived midpoint not published as this series. Owner editability is the correct escape hatch when a bank statement, card network, tax rule, or personal policy requires another value.

## Operational acceptance criteria for the later specification

1. The configured automatic source is exactly `BCRPData:PD04638PD`, direction `PEN/USD`, side `sell`, with BCRP attribution.
2. The request always supplies an inclusive seven-calendar-day date range ending on the applicable date.
3. The adapter validates the returned series name/code expectation, date, positive decimal value, and `n.d.` marker before seeding.
4. Applicable date, source observation date, and retrieval timestamp are separate fields/concepts; the UI does not call retrieval time the publication date.
5. Weekend/holiday carry chooses only the latest prior valid BCRP observation and never exceeds seven calendar days.
6. Recent business-day absence is retried because the API gives no guaranteed publication hour; it is not immediately mislabeled as a holiday.
7. Decimal ingestion uses exact decimal arithmetic. Six-place storage does not imply six-place source accuracy.
8. A seeded value is owner-editable and is never automatically overwritten after storage.
9. Provider failures or an exhausted lookback leave combined reporting unavailable and actionable; they never trigger an unlabelled cross-provider or buy/sell semantic change.
10. Requests are cached, bounded, and observable, and the implementation assumes neither unlimited rate capacity nor an uptime SLA.

## Primary sources

- [BCRPData developer API](https://estadisticas.bcrp.gob.pe/estadisticas/series/ayuda/api)
- [BCRPData conditions of use](https://estadisticas.bcrp.gob.pe/estadisticas/series/ayuda/condiciones-de-uso)
- [About BCRPData](https://estadisticas.bcrp.gob.pe/estadisticas/series/ayuda/bcrpdata)
- [BCRP `PD04638PD` dated JSON](https://estadisticas.bcrp.gob.pe/estadisticas/series/api/PD04638PD/json/2026-07-01/2026-07-23/esp)
- [BCRP `PD04638PD` holiday window](https://estadisticas.bcrp.gob.pe/estadisticas/series/api/PD04638PD/json/2025-07-24/2025-08-01/esp)
- [BCRP economic glossary](https://www.bcrp.gob.pe/publicaciones/glosario/t.html)
- [SBS weighted-average exchange rates](https://www.sbs.gob.pe/app/pp/SISTIP_PORTAL/Paginas/Publicacion/TipoCambioPromedio.aspx)
- [SBS accounting exchange rates](https://www.sbs.gob.pe/app/pp/SISTIP_PORTAL/Paginas/Publicacion/TipoCambioContable.aspx)
- [SUNAT official monthly exchange-rate publication](https://www.sunat.gob.pe/cl-at-ittipcam/tcS01Alias)
- [SUNAT current exchange-rate text](https://www.sunat.gob.pe/a/txt/tipoCambio.txt)
- [SUNAT official historical-UI client code](https://www.sunat.gob.pe/cl-at-ittipcam/js/papeleta/papeleta.js?t=291120202213)
- [Frankfurter official API documentation](https://frankfurter.dev/)
- [Frankfurter official provider catalog](https://frankfurter.dev/providers/)
- [Frankfurter source repository and license](https://github.com/lineofflight/frankfurter)
