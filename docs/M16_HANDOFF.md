# M16 handoff (2026-11-08)

Base: protected `claude/ecstatic-wozniak-oemyuf` at `c1d5ba4`. No Elliot/infra file touched. GitHub was returning 502-504, so only #305 was pushed; the rest is local.

## Branches

| Task | Branch | State |
|---|---|---|
| M16.3/A hero a11y + canvas perf | `codex/m16-3-accessibility-motion-audit-20261108` (`17cd1f7`) | PR #305 open. Quality failed only on Gitleaks download HTTP 504 (scanner never started). Re-run that job once GitHub is stable; do not change the workflow for this. Still needs Integration & QA and M17 AppSec. |
| M16.3/B design tokens | `codex/m16-3-design-tokens-20261108` (`074f3a1`) | Local only. Not pushed, no PR. |
| M16.2 three-step "How it works" | `codex/m16-2-how-it-works-three-steps-20261108` (`284da6c`) | Local only. **Draft, needs Owner decision before PR is final.** |

## Resume (when GitHub is stable)

1. `gh run rerun 42468705471 --failed`; confirm Gitleaks actually runs.
2. Push each local branch normally, open one PR per branch against the protected branch, link and watch it.
3. Request Integration & QA and M17 AppSec per PR on the exact HEAD. Nothing may merge without them and green required checks.
4. Open the M16.2 PR as draft until the Owner answers the questions below.

## Verification already done locally

- #305: `npm test` 34/34, build OK. Browser tests for it have NOT run yet (CI only).
- Tokens: HU/EN full-page screenshots at 375/768/1440 byte-identical to the pre-refactor baseline; computed styles identical except one serialization difference (`0%` vs `0px` in a radial-gradient position, same value). `npm test` 36/36; marketing browser E2E 18/18.
- M16.2: PHPUnit Homepage/HungarianContent/LocalizedMarketingRoutes/SEO 53/53, Owner suite 260/261 (1 pre-existing skip); marketing + CTA browser E2E pass.
- Known local-only failure, also failing on untouched `c1d5ba4`: `tests/e2e/localization.spec.ts` "unknown address returns a not-found page" (not part of the marketing CI paths). Not investigated further.
- Local PHP is 8.4; CI uses 8.4/8.5 lanes.
- Synthetic layout-shift check in #305 is a single headless run, not field Core Web Vitals.

## Owner decisions needed (M16.2)

1. Issue #280 asks for three steps; the section had four. Draft merges "Connect your website" and "Initial check" into "Connect and check your site". Approve, or keep four?
2. Copy honesty: steps 2-3 describe flows that are not open in marketing-only production (no self-service connect). Current wording mirrors the existing approved copy; should it say explicitly that onboarding starts after contact?
3. Content contract: the step list is part of the owner-editable schema. If production already holds published `home.how` rows, the dropped `audit` row is ignored by the loader, but any published `connect` text would still override the new merged copy. Check Owner Admin content before release.
4. Account onboarding still says "Four steps" (`lang/*/account.php`); that is a separate flow and was left alone.

## Environment notes for local runs

No `composer` on this machine. A worktree needs a copied `vendor/` (a junction makes Composer resolve `app/` to the main checkout). Windows PHP in an `ó` path fails to load extensions; use an ASCII-path PHP copy with a php.ini and set `PHPRC`. Port 8000 is held by an unidentified listener here; use another port.
