# M16 handoff (2026-11-08)

Base: protected `claude/ecstatic-wozniak-oemyuf` at `c1d5ba4`. No Elliot/infra file touched.

## Branches

| Task | Branch / HEAD | State |
|---|---|---|
| M16.3/A hero a11y + canvas perf | `codex/m16-3-accessibility-motion-audit-20261108` `17cd1f7` | PR #305 open. Only Quality failed: Gitleaks download returned HTTP 504 so the scanner never started. Failed job re-run once GitHub recovers. Needs Integration & QA and M17 AppSec. |
| M16.3/B design tokens | `codex/m16-3-design-tokens-20261108` `074f3a1` | Local; push when the Gitleaks download works. |
| M16.2 three-step How it works | `codex/m16-2-how-it-works-three-steps-20261108` `67ee69b` | Local; open as draft PR. Owner approved the structure; copy adjusted (below). |
| Localization E2E 404 expectation | `codex/localization-e2e-404-expectation-20261108` `cc7bb63` | Local; one-line test fix. |

## Resume

1. Confirm the Gitleaks download works (`curl -sI -L https://github.com/gitleaks/gitleaks/releases/download/v8.30.0/gitleaks_8.30.0_linux_x64.tar.gz` returns 200), then re-run failed Quality jobs. Do not change the workflow or skip the scan.
2. Push each branch, one PR each, link and watch. M16.2 as draft first.
3. Integration & QA and M17 AppSec on the exact HEAD of each PR before any merge.

## M16.2 decisions and copy

Owner approved three steps. Self-service connecting is not available in marketing-only mode, so the copy is contact and agreement based: Explore the plans, Talk it through, Ongoing maintenance ("Once agreed..."); HU: Csomagok megismerése, Egyeztetés, Folyamatos karbantartás. No onboarding, activation or automation is promised as available.

Published-content risk (release prerequisite): production Owner Admin content was not read, because there is no authorised access and none was requested. Mitigated in code: step keys were renamed to `agree` and `maintain`, so rows published under the old keys cannot override the new copy, and a section published without wording for a step now shows the built-in step instead of a blank one (found by a new test, `HowItWorksSectionTest`). Before release, someone with access should still check whether `home.how` is published in either locale; if it is, republish it so the Owner's own wording matches.

## Localization E2E failure (pre-existing, not from M16)

`tests/e2e/localization.spec.ts` "unknown address returns a not-found page without debug details" fails on untouched `c1d5ba4`. Cause: `lang/hu.json` translates "Not Found" to "Az oldal nem található", and after choosing Magyar the 404 shows the Hungarian text, while the test expected English. Reproduced by script; with the one-line expectation fix the test passes. The spec runs under `playwright.connector-e2e.config.ts`, not the marketing workflow. Separate finding, not changed: that 404 page has `lang="en"` while its text is Hungarian.

## Verification done locally

- #305: `npm test` 34/34, build OK; its browser tests have not run yet (CI only).
- Tokens: HU/EN screenshots at 375/768/1440 byte-identical to baseline; computed styles identical except one `0%` vs `0px` serialization of the same value; `npm test` 36/36; marketing browser E2E 18/18.
- M16.2: HowItWorks + Homepage + HungarianContent tests pass, Owner suite 260/261 (1 pre-existing skip), Pint passes, marketing and CTA browser E2E pass.
- Local PHP is 8.4; CI also runs 8.5. The layout-shift check in #305 is a synthetic headless run, not field Core Web Vitals.

## Local environment notes

No `composer`: copy `vendor/` into a worktree (a junction makes Composer resolve `app/` to the main checkout). Windows PHP under a path with `ó` fails to load extensions; use an ASCII-path PHP copy plus php.ini and `PHPRC`. Port 8000 has an unidentified listener here; use another port.
