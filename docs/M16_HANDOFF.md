# M16 handoff (2026-11-08)

Base: protected `claude/ecstatic-wozniak-oemyuf` at `c1d5ba4`. No Elliot/infra file touched.

## Status

| Task | PR | State |
|---|---|---|
| M16.3/A hero a11y + canvas perf | #305 | Merged as `f3830d32fe45e034e1332b3c8292acb778f96db8`. |
| Localization E2E 404 expectation | #308 | Merged as `c0e0e7bb2f3861a01d99577bf67d2e1859650bfd`. |
| M16.3/B design tokens | #306 | Open. Waiting for a clean required CI run. |
| M16.2 three-step How it works | #307 | Draft. Waiting for a clean required CI run; see release prerequisite below. |
| This handoff | #309 | Merged last. |

Independent QA/AppSec agent reviews are no longer mandatory (Owner, 2026-11-08). Required CI, Gitleaks and the up-to-date-branch rule still gate every merge. Merges are serial: each merge makes the other open branches behind, so they are updated and re-run one at a time.

## Open blockers and prerequisites

- The Gitleaks 8.30.0 archive download intermittently times out or returns 504 in the Quality job (seen on #305, #306, #307). The scanner then never starts. It is not bypassed; the failed job is re-run once the workflow run completes.
- Release prerequisite for #307: read production `home.how` content in Owner Admin (both locales) and republish it if present. No production access was used or requested. Nothing was published.
- GitHub reports the protected branch as having no classic branch protection rule (API 404) although required checks clearly gate merging; this is probably rulesets. Not changed.
- Separate finding, issue #310: the Hungarian 404 page has `lang="en"` with Hungarian text.

## Next safe M16 task

M16.3 follow-up: apply the Direction 04.1 tokens to one Customer Dashboard surface (for example the website list header and status chips), with before/after screenshots and the existing customer-portal tests. Then M16.2 next section (FAQ ordering and wording review) as a draft for the Owner.

## M16.2 decisions and copy

Owner approved three steps. Self-service connecting is not available in marketing-only mode, so the copy is contact and agreement based: Explore the plans, Talk it through (confirm whether and how we can help), Ongoing maintenance ("Where service is available for your site..."); HU: Csomagok megismerése, Egyeztetés, Folyamatos karbantartás. No onboarding, activation or automation is promised as available.

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
