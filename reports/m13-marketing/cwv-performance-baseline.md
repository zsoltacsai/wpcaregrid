# M13.3 Core Web Vitals Lab Baseline and Recheck

## Scope and method

This is reproducible browser lab evidence from the staging marketing pages, not real-user or field Core Web Vitals. Initial run: 2026-10-16, before the M13.3 change, staging SHA `cc8acede6faea076b14a264dccabca75df15a76e`. Final run: after the targeted change, staging SHA `a335162936afa2933e348fe7b04956e98e45c5a3`.

- Browser: Microsoft Edge 155.0.4283.45, headless, Playwright.
- Routes: `/hu/`, `/en/`, `/hu/pricing`, `/en/pricing`.
- Desktop: 1365×900 CSS pixels, DPR 1, unthrottled host network/CPU.
- Mobile: 390×844 CSS pixels, DPR 1, mobile/touch emulation; CDP network 150 ms RTT, 1.6 Mbps down, 768 Kbps up; 4× CPU slowdown.
- Each route/profile used five cold navigations in fresh contexts and five navigations in a shared context (one primer plus four warm samples). Metrics below are medians; values are browser lab observations. CLS on mobile is calculated using the maximum session window (5 s window, 1 s gap). Desktop CLS uses the same cumulative layout-shift observer before and after.
- Collection waited for `load` plus 1.5 seconds. Navigation response-start minus request-start is a TTFB proxy. Resource transfer figures are browser-reported transferred bytes.
- Zero failed requests were recorded in both final passes. Desktop captured zero external host requests. Consent testing also captured zero Google tag/analytics requests.

No reliable field Core Web Vitals baseline is available yet. Search Console/CrUX field evidence was not available to this task. TBT was not measured with a Lighthouse trace. INP is unavailable as field evidence; the browser aggregate long-task duration is not TBT or INP and is not substituted for either.

## Desktop cold-run results

Five samples per route/profile. Before → after values are medians.

| Route | LCP/FCP ms | CLS | TTFB proxy ms | Requests | Transfer bytes |
|---|---:|---:|---:|---:|---:|
| HU home | 660 → 504 | 0.000826 → 0.000826 | 116 → 91 | 15 → 15 | 353,001 → 353,035 |
| EN home | 480 → 460 | 0.000764 → 0.000765 | 94 → 85 | 15 → 15 | 351,660 → 351,705 |
| HU pricing | 324 → 344 | 0.000304 → 0.000379 | 52.5 → 51 | 11 → 11 | 278,000 → 278,045 |
| EN pricing | 420 → 372 | 0.001467 → 0.001467 | 57 → 70 | 11 → 11 | 277,566 → 277,615 |

Desktop LCP/FCP cold-run ranges were variable, especially on EN pricing. The measurements do not support claiming a general speedup; desktop timings remained low in this lab and showed no material regression attributable to the change.

## Mobile cold-run results

Five samples per route/profile. Before → after values are medians.

| Route | LCP/FCP ms | CLS | TTFB proxy ms | Requests | Transfer bytes |
|---|---:|---:|---:|---:|---:|
| HU home | 2,036 → 2,072 | 0.01430 → 0.01430 | 94 → 98 | 15 → 15 | about 353 KB → 353,034 |
| EN home | 1,920 → 1,868 | 0.03566 → 0.03566 | about 92 → 92 | 15 → 15 | about 352 KB → 351,704 |
| HU pricing | 1,512 → 1,536 | 0.000149 → 0.000023 | about 50 → 66 | 11 → 11 | about 278 KB → 278,046 |
| EN pricing | 1,512 → 1,464 | 0.21089 → 0.03220 | about 60 → 65 | 11 → 11 | about 278 KB → 277,614 |

The English pricing cold CLS improvement is approximately 84.7%. Final samples ranged from 0.03220 to 0.03544, compared with the pre-change median of 0.21089. Warm-run English pricing CLS median was 0 after the fix (maximum 0.03220), versus a pre-change median of 0 with an observed maximum of 0.21089. Warm timing varied across routes and is reported in raw temporary run output from the validation session; it is not used as a field claim.

## Bottleneck and change

Before the fix, mobile English pricing controls occupied a wrapping flex row. The localized annual-savings copy and font rendering caused the control row and plan cards below it to reflow. Disabling JavaScript eliminated the high shift in an isolation check; a CSS-only probe that stacked the controls reduced the shift to roughly 0.032 across five cold runs. The source fix in `resources/views/components/pricing/table.blade.php` makes the controls a column at narrow widths and restores the horizontal wrapping row at `md` and above. A feature assertion protects the responsive classes and both billing-period values.

The deployed change was remeasured on the exact executable SHA. It made no changes to Alpine/JavaScript, font assets, analytics or checkout. Home routes retain small lab CLS observations; no evidence from this work justified a broad bundle, font or image rewrite. The measurements identify the pricing-control reflow as the material M13.3 fix.

## Asset baseline and lightweight regression budget

Post-deploy browser resource medians were 15 requests and approximately 353 KB transferred for home pages, and 11 requests and approximately 278 KB for pricing pages. Transferred JavaScript was about 62.9 KB and CSS about 59.4 KB; route font transfer was about 202 KB on home and 145 KB on pricing. The production build output reported 62.59 KB JS (21.60 KB gzip) and 59.11 KB CSS (11.28 KB gzip). No external asset host was contacted.

Use these as review baselines, not noisy timing gates: investigate unexpected third-party requests; review JS/CSS transfer growth greater than 10%; and repeat the same mobile CLS profile after marketing layout changes. Do not make exact lab LCP/TTFB milliseconds a hard CI threshold. A final social image remains absent by design; no asset was fabricated.

## Interpretation and remaining work

- The measured CLS problem on English pricing was isolated, corrected and rechecked on staging.
- The laboratory results do not establish real-user CWV, hosting-wide recovery performance, or production-host performance.
- TTFB values are navigation timing proxies from staging, not server log measurements.
- No Search Console/CrUX field dataset was available. M17 should revisit field CWV after public traffic and Search Console access exist.
- The existing staging security scanner warning remains an M14 AppSec residual; it was not changed or hidden by this performance work.
