# Content terminology (customer-facing text)

Owner-approved Hungarian terminology (2026-11-08). The Hungarian tone is informal (tegeződés), natural and professional.

| Use | Avoid in customer-facing marketing copy |
|---|---|
| weboldal | honlap |
| karbantartási csomag | előfizetés |
| biztonsági mentés | backup |
| irányítópult | ügyfélfelület, vezérlőpult |

## Where "előfizetés" stays

Use "előfizetés" only where the context is recurring billing, cancellation, payment periods or contractual subscription terms: the Subscription and Billing pages, Stripe status messages, the cancellation FAQ, billing emails. Choosing or describing what the customer gets (plan choice, "no plan yet" empty states, package descriptions) is "karbantartási csomag". In English, "plan" is the package and "subscription" the recurring contract.

## Applied so far

FAQ, marketing homepage, pricing feature labels, dashboard (menu and page title, demo texts, plan-choice and empty-state messages), the restore notice in the backups pages and the privacy account-access texts.

## Not renamed

Database fields, API contracts, routes, CSS hooks, translation keys, internal identifiers and the owner/operator consoles. Customer emails (`lang/hu/emails.php`) and the Stripe billing wording in `account.php` and `checkout.php` were left, because they are billing context.

## Known follow-ups (not part of the terminology change)

- Much of the Hungarian account area, checkout and emails still addresses the customer formally ("Ön", "kérjük, lépjen…"); only the FAQ was moved to informal address. Converting the rest is a separate content pass.
- The pricing table and the update-process section still say "webhely" in places; the FAQ uses "weboldal".
- "mentés" on its own remains in many UI labels; "biztonsági mentés" is the full term used in marketing copy and package descriptions.

## Guards

`tests/Feature/HungarianTerminologyTest.php` fails if a retired term (honlap, vezérlőpult, ügyfélfelület, the English word backup) appears in the customer-facing Hungarian string values; `tests/Feature/FaqSectionTest.php` also keeps "előfizetés" out of the FAQ except the cancellation answer.

Marketing-only production: do not describe the customer dashboard, online ordering or self-service connecting as available.
