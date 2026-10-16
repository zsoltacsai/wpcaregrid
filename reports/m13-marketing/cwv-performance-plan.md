# Core Web Vitals and Performance Plan

## Baseline status

No field-data report, PageSpeed Insights export, Lighthouse artifact, Web Vitals telemetry, or repeatable lab measurement was found in the repository evidence. This planning pass did not load the live website or run a browser performance audit. **LCP, INP, CLS and TTFB are therefore unmeasured; no performance pass/fail is claimed.**

Source observations: the marketing layout loads the Vite CSS and JS entry; JS starts Alpine and a pricing component; Tailwind builds CSS; public media inventory is currently mostly favicon assets. Font packages are local dependencies (IBM Plex Sans/Mono) but emitted font loading and cache behavior require rendered asset/network review. No analytics third-party payload currently appears in source search.

## Measurement method for implementation

1. Record exact executable SHA, URL, locale, viewport, device profile, cache state and date.
2. Run at least three mobile and desktop lab samples for home/pricing HU/EN; report median and range for LCP/INP proxy/CLS/TTFB and resource waterfalls.
3. Capture field CWV from Search Console/CrUX only after property/URL eligibility exists; do not substitute lab scores for field data.
4. Compare initial and post-change bundle sizes and blocking resources. Run a consent-denied profile and a consent-granted profile if analytics ships.
5. Establish performance budgets after measuring baseline and confirming critical page assets; budget should be realistic for current hosting.

## Candidate budget dimensions (provisional, calibrate after baseline)

- avoid render-blocking third-party scripts; analytics must be consent-gated and non-blocking;
- cap initial JS/CSS transfer and track compressed bundle growth per PR;
- provide width/height or aspect ratio for images; responsive formats and lazy load below fold; do not lazy-load the LCP hero;
- preload only a measured critical font/hero asset; avoid font/layout swaps that shift content;
- record CLS and largest content element on both page families;
- set route-level TTFB targets from current staging/production hosting measurement, not an invented universal number.

No fixed numeric budget is adopted before measurement. M16 retains broader UX/performance polish; M13 addresses regression or obvious marketing-path bottlenecks evidenced by measurement.
