# M16 handoff (2026-11-08)

Base: protected `claude/ecstatic-wozniak-oemyuf` at `c1d5ba4`. No Elliot/infra file touched.

## Status

| Task | PR | State |
|---|---|---|
| M16.3/A hero a11y + canvas perf | #305 | Merged as `f3830d32fe45e034e1332b3c8292acb778f96db8`. |
| Localization E2E 404 expectation | #308 | Merged as `c0e0e7bb2f3861a01d99577bf67d2e1859650bfd`. |
| M16.3/B design tokens | #306 | Merged as `1d010e8a0d2fe3857bcb2e6f0e99a126cec04f10`. |
| M16.2 three-step How it works | #307 | Merged as `af6fbaaf5a3cc0f2e09f866dc54fae57ad4a5f4c` (source only; see release prerequisite below). |
| Handoff | #309, #311 | Merged as `9b98f7b2f12d49905665acdb8fb074f02e563acf` and `001dfa28bdb09345d6067e43bdd7c6786ce6dd84`. |
| M16.3 Customer Dashboard: website list header, rows, status chips | #312 | Merged as `74b46b06a7a317429acab723e9f1f32540dcfe14`. Scoped to `.m16-site-list__header/__rows`; the shared status badge only gained a `data-tone` attribute. |
| 404 page `lang` attribute (issue #310) | #313 | Merged as `d665ba0dd83bee2480d6192cb90b8c55f2ff9355`. App-level `resources/views/errors/minimal.blade.php` takes `lang` from the active locale. Issue closed. |
| M16.3 Customer Dashboard home | #317 | Merged as `5ba984039a9e90eefa1e1dca4ac5ff76676e756a`. Hook classes `.m16-dash-header/-panel/-setup`; shared components unchanged. |
| Authenticated dashboard browser E2E | #318 | Merged as `c51097af75eed2cd6093fb8b7cb67fd042e4492a`. Guarded synthetic fixture and 19-test spec, run inside the sandboxed marketing workflow after the cancellation verification (it accepts the current terms document, which that verification fingerprints). |
| M16.2 FAQ (8 approved questions) and contact CTA | #314 | Merged as `0a8494bcafad4c0b06a51825eddf5260bd17aaa2`. Owner-approved content, answers checked against plan definitions; no SLA, uptime, refund or self-service claims. |

Independent QA/AppSec agent reviews are no longer mandatory (Owner, 2026-11-08). Required CI, Gitleaks and the up-to-date-branch rule still gate every merge. Merges were serial: each merge made the other open branches behind, so they were updated and re-run one at a time. Post-merge CI on `af6fbaa` was still running when this was written.

## Open blockers and prerequisites

- The Gitleaks 8.30.0 archive download intermittently times out or returns 504 in the Quality job (seen on #305, #306, #307). The scanner then never starts. It is not bypassed; the failed job is re-run once the workflow run completes.
- Release prerequisite for #307: read production `home.how` content in Owner Admin (both locales) and republish it if present. No production access was used or requested. Nothing was published.
- GitHub reports the protected branch as having no classic branch protection rule (API 404) although required checks clearly gate merging; this is probably rulesets. Not changed.
- Release prerequisite: field Core Web Vitals for the hero have not been measured (the #305 layout-shift test is a synthetic single headless run).
- Release prerequisite for #314: production `home.faq` content, if published, overrides the built-in copy and must be republished; it was not read. The Owner's earlier baseline answers were not available to me, so the answers were written from verified facts; compare them with the baseline. The approved questions and CTA use informal "te" while the rest of the Hungarian site uses "Ön".
- `WebsiteProbeTest::test_http_probe_keeps_failure_candidate_attention_until_confirmation` is time-sensitive (compares `next_check_at` with `now()->addMinutes(5)` without freezing time) and failed once on #317, then passed on re-run. Not changed (monitoring domain); freezing time in that test would remove the flake.
- Possible FAQ accessibility follow-ups (not done): eight `role="region"` panels is above the usual guidance of about six; answers stay hidden until JavaScript runs.

## Integration checkpoints

Checkpoint 1 (after #318, #317, #314): authoritative primary `0a8494bcafad4c0b06a51825eddf5260bd17aaa2`. CI and the marketing browser E2E are green on the two previous primary heads (`c51097a`, `5ba9840`); the run on `0a8494b` was still in progress. Open PRs (#319, #303, #270, #248, #247, #246, #244, #224, #218) are Elliot's G1, Gate 1 and policy work: none touches a file changed in these three merges, and none needs synchronization because of them. No conflicts.

## Next safe M16 task

Responsive sidebar and mobile header of the Customer Dashboard layout (`resources/views/components/layouts/account.blade.php`), in its own PR: return focus to the menu button after Escape, close the menu on navigation and outside click, keep `aria-expanded` and the visible focus outline, Direction 04.1 token styling, with before/after screenshots in HU/EN and the existing dashboard E2E. Then empty states and a reusable status-chip component.

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
