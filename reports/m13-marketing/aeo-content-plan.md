# AEO and Answer-Oriented Content Plan

## Existing foundation

The home page is composed from stable Blade sections and the Owner content schema. It contains a localized FAQ list. `ContentRepository` serves a complete locale section, falling back as a whole; `MarketingTranslationPolicy` omits alternates when required translations are missing. This gives a practical reusable source for page facts, but there is no separate content/FAQ route or FAQ schema today.

## Answer template

For each high-priority page, answer directly before deeper detail:

1. What is the service or feature?
2. Who is it for?
3. What does the current plan include?
4. How does the workflow operate?
5. What limits or exclusions apply?
6. What happens when a check fails?
7. What differs by product/plan?
8. What is the next step?

Use concise factual blocks with a linked explanation and a clear CTA. Keep one source for feature facts and plan comparisons; reuse typed content and catalogue values rather than separately transcribing FAQs, schema and pricing copy.

## Prioritized content gaps

| Priority | Topic | Customer value/search value | Evidence required | Suggested destination |
|---|---|---|---|---|
| P0 | Explain exactly what WordPress maintenance includes and how plan scope differs | high | current catalogue and service runbooks | improve home/pricing sections |
| P0 | Pricing/plan differences for WordPress vs WooCommerce | high commercial | current active catalogue and translated features | pricing; preserve crawlable HTML |
| P1 | Backup verification and recovery limits | high trust | current storage, verification and restore-readiness facts | home section first; dedicated page only if deep content exists |
| P1 | Update and post-update verification | high commercial | actual selection/approval and test coverage | home/process section |
| P1 | Security scans and what “malware cleanup” includes | high risk/high trust | scanner/plan cadence, capability, limits, M14 review | home/plan FAQ after review |
| P1 | WooCommerce operations workflow | high commercial | actual tests/checks in each WooCommerce plan | home now; dedicated page if substantive unique detail |
| P2 | Secure administrative access | moderate trust | access scope, time limits, customer approval model | feature explanation |
| P2 | FAQ content clusters | moderate informational | support questions and product truth | maintain current FAQ or future help center |
| P3 | Articles/how-to library | unknown until demand evidence | Search Console queries and support demand | defer; no volume-driven blog calendar |

## FAQ subjects to review

What does maintenance include? Are backups stored off-site? How often are backups created by plan? Can CareGrid restore a site and what affects readiness? Which updates are managed? What happens after an update check fails? What WooCommerce flow is checked? Is a connector required? What is monitored? How does admin access work? What happens after cancellation to backups? Is the product an AI service?

Answers must match actual plan/configuration, mention limits, and avoid legal conclusions. “What happens to backups after cancellation?” must retain M12 policy gates and not imply physical erasure. Do not publish draft/unapproved legal language to answer privacy questions.
