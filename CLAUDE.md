# WP CareGrid — Claude Code Project Instructions

> Persistent project memory and operating contract for Claude Code.
> Read this file before making changes.
>
> This repository is intentionally developed across multiple Claude Pro accounts/sessions.
> Never assume a previous chat is available. Repository state + this file + project docs are the source of truth.

## 1. Project Identity

**Product:** WP CareGrid  
**Domain:** https://wpcaregrid.com  
**Business:** WordPress and WooCommerce maintenance, backups, security, monitoring, functional verification, support and custom development.

Primary positioning:

> WordPress maintained. Website working.

This is a managed service + software platform, not merely a plugin updater.

## 2. Core Product

WP CareGrid provides recurring maintenance subscriptions with:

- WordPress maintenance
- WooCommerce maintenance
- remote backups
- backup verification
- restore/recovery
- plugin/theme/core updates
- functional verification after important updates
- vulnerability monitoring
- malware/integrity scanning
- controlled remediation
- temporary secure admin access
- customer portal
- internal operator console
- owner/admin console
- content management
- billing
- multilingual/international pricing
- later lead generation and outreach automation

## 3. Brand / Design

The brand must look like an experienced technical service, not an AI startup.

### Tone

Prefer:
- direct
- factual
- calm
- professional
- technical
- trustworthy
- practical

Avoid:
- AI buzzwords
- "AI-powered"
- "next-generation"
- "revolutionary"
- exaggerated claims
- generic SaaS marketing language
- fake testimonials
- fake customer statistics

### Visual identity

Primary: `#17212B`  
Background: `#F6F6F2`  
Surface: `#FFFFFF`  
Text: `#27313A`  
Muted: `#66717A`  
Border: `#D9DDD8`  
Accent: `#5B8C72`  
Warning: `#C58A32`  
Danger: `#C84735`

Typography:
- IBM Plex Sans
- IBM Plex Mono for technical metadata only

Avoid:
- gradients
- glassmorphism
- neon
- excessive rounded UI
- 3D illustrations
- decorative blobs
- excessive animation
- generic stock photography

Prefer:
- realistic UI
- dashboards
- status indicators
- technical data
- simple line icons
- restrained cards/borders

Brand assets are under `design/`. Inspect `design/BRAND.md`, `design/DESIGN_TOKENS.css`, `design/brand-board.png` and logo assets before visual changes.

## 4. Public Homepage

The homepage is already implemented. Do not casually redesign it.

Main sections:

1. Header
2. Hero
3. Services
4. Why maintenance
5. Update verification
6. Pricing
7. Dashboard
8. License management
9. WooCommerce
10. Custom development
11. How it works
12. Activity log
13. FAQ
14. Final CTA
15. Footer

Key message:

> We don't just update. We check.

Update flow:

`Backup → Update → Check → Functional test → Done`

Public dashboard previews are demo-only. Never put real customer/project domains in public demo UI.

## 5. Pricing — Current Commercial Baseline

### WordPress

| Plan | Monthly | Annual |
|---|---:|---:|
| Basic | 8,990 HUF | 89,900 HUF |
| Business | 18,990 HUF | 189,900 HUF |
| Secure | 28,990 HUF | 289,900 HUF |

### WooCommerce

| Plan | Monthly | Annual |
|---|---:|---:|
| Basic | 14,900 HUF | 149,000 HUF |
| Business | 24,900 HUF | 249,000 HUF |
| Secure | 39,900 HUF | 399,000 HUF |

Annual = 10 monthly payments, communicated as **2 months free**.

### Backup frequency

WordPress:
- Basic: monthly
- Business: weekly
- Secure: daily

WooCommerce:
- Basic: weekly
- Business: daily
- Secure: daily

### Pricing rules

- Hungarian pricing is final customer pricing with no VAT added under the current AAM business model.
- Do not hardcode tax assumptions globally.
- Billing country determines the market.
- Do not use IP address as pricing authority.
- Do not use exchange-rate conversion.
- Do not invent international amounts.
- Server resolves the actual price.
- Never trust browser-submitted amount, currency or Stripe Price ID.
- Homepage, pricing and checkout use one pricing source.

## 6. Plan Boundaries

Basic = automated essential maintenance.

Business = active maintenance, security checks and functional verification.

Secure = stronger security monitoring, malware handling and priority support.

Custom development is separate from maintenance plans.

Separate paid work includes:
- custom plugins
- API integrations
- WooCommerce development
- automation
- larger bug fixes
- new features
- design changes
- SEO/Ads/content

Do not promise unlimited development in maintenance plans.

## 7. Third-Party Licenses

Third-party plugin/theme licenses are the customer's responsibility.

States:
- valid
- expired
- unknown
- not_available

Never infer `expired` from `unknown` or `not_available`.

When an expired/invalid license is actually detected:
- record it
- show it in dashboard
- notify the customer

Do not silently renew licenses.

## 8. Functional Verification

A successful update is not merely a successful updater API call.

Preferred flow:

`Backup → Update → Health check → Functional verification → Log`

For WooCommerce, important checks may include:

`Product → Cart → Checkout → Payment → Order → Email`

Never claim a functional check happened if only a health check ran.

## 9. Application Architecture

High level:

```text
Public Website
      ↓
Pricing / Checkout
      ↓
Stripe
      ↓
Customer Account
      ↓
Customer Portal
      ↓
Website Onboarding
      ↓
WP CareGrid Connector
      ↓
Central API
      ↓
Jobs / Queues
      ↓
WordPress Site
```

Core domains/services:

- Connector
- Pricing
- Billing
- Subscriptions
- Websites
- Backups
- Security
- Site Health
- Admin Access
- Audit / Activity
- Operator Console
- Owner Admin
- Content

## 10. Technology

Current direction:

- Laravel
- Blade
- Tailwind CSS
- Alpine.js where appropriate
- PHP
- relational database
- queues
- Stripe
- S3-compatible storage
- SFTP
- WordPress Connector plugin

Do not introduce React without a concrete architectural reason.

## 11. Security Principles

Non-negotiable:

### Never request/store customer WordPress passwords

Do not request/store:
- WordPress password
- hosting password
- FTP password
- database password
- Stripe secret
- unrelated credentials

### No arbitrary remote execution

These must never exist:
- RUN_PHP
- RUN_COMMAND
- EXECUTE_CODE
- EVAL
- arbitrary SQL execution
- arbitrary shell execution

Every remote operation must be a fixed, explicitly whitelisted job type.

### Tenant isolation

Customers must never access another customer's:
- website
- subscription
- backup
- security findings
- incidents
- billing
- activity

Cross-tenant tests are required for tenant-sensitive paths.

### Secrets

Never log or expose:
- connector credentials or hints
- pairing codes
- access tokens
- session cookies
- Stripe secrets
- storage credentials
- passwords/password hashes
- license keys

## 12. Customer Architecture

- `User` = login/authentication
- `Customer` = paying commercial entity
- `Subscription` = Stripe/local subscription mirror
- `Website` = managed site
- `Connector` = technical connection to one website

One login per customer for now.

## 13. Subscription Lifecycle

Stripe is authoritative for payment/subscription state.

Important:
- browser success is not proof of payment
- signed webhooks activate paid service
- webhook processing is idempotent
- replayed events do not duplicate subscriptions
- webhook order must not corrupt newer state

Important events:
- `checkout.session.completed`
- `checkout.session.async_payment_succeeded`
- `checkout.session.async_payment_failed`
- `checkout.session.expired`
- `customer.subscription.created`
- `customer.subscription.updated`
- `customer.subscription.deleted`
- `invoice.paid`
- `invoice.payment_failed`

Unknown future Stripe statuses are logged and not guessed.

## 14. WordPress Connector

Current Connector version: **0.4.1**

Communication:
- outbound HTTPS only
- no arbitrary REST/AJAX surface
- heartbeat
- job polling
- admin settings

Major domains:
- Core
- Api
- Auth
- Pairing
- Heartbeat
- Inventory
- Licenses
- Health
- Jobs
- Updates
- Backups
- Security
- Admin

Pairing:
- one-time code
- short lifetime
- single use
- hashed server-side
- rate limited
- not in URL
- tied to website

Connector credential:
- high entropy
- bearer-style currently
- hash stored on server
- encrypted on WordPress
- revocable
- never logged

## 15. Job System

Remote operations use the existing job system.

Important job types currently include:
- `GET_INVENTORY`
- `SITE_HEALTH`
- `LICENSE_CHECK`
- `UPDATE_PLUGIN`
- `UPDATE_THEME`
- `UPDATE_CORE`
- `BACKUP_FILES`
- `BACKUP_DATABASE`
- `BACKUP_FULL`
- `RESTORE`
- `CREATE_ADMIN_SESSION`

Rules:
- server authorizes
- connector validates again
- payload strictly validated
- UUID idempotency
- interrupted jobs are not blindly replayed
- results are separate from execution
- unsupported jobs are rejected

## 16. Backup Engine

Backup types:
- FILES
- DATABASE
- FULL

States include:
- queued
- running
- uploading
- uploaded
- verifying
- verified
- failed
- corrupt
- expired
- deleted

Only `verified` counts as successful.

Storage:
- S3-compatible
- SFTP
- local development storage only

Object identity:
`{website_uuid}/{backup_uuid}/...`

Never use customer names/URLs as storage identifiers.

Backups are streamed/chunked. Never load a full multi-GB backup into PHP memory.

Storage credentials remain server-side and are never sent to the Connector.

### Pre-update

When policy requires:

`Backup required → Backup verified → Update released`

If backup fails:

`BACKUP_REQUIRED_FAILED`

and the update does not proceed.

### Restore

Requires:
- verified backup
- correct website/customer
- active connector
- explicit confirmation
- safety backup first
- post-restore health checks
- functional verification

Do not enable live restore casually.

## 17. Secure Admin Access

Two levels:

### Maintenance Access

Restricted role:

`wp_caregrid_maintenance`

Important capabilities include:
- read
- update_plugins
- update_themes
- update_core
- update_languages
- activate_plugins
- view_site_health_checks

No unrestricted:
- user management
- plugin/theme installation
- file editor
- manage_options
- unfiltered_html

### Temporary Full Admin

- explicit confirmation
- short-lived
- revocable
- audited
- never default

Operator full admin remains disabled by default.

Do not bypass `AdminAccessService`.

## 18. Unified Site Health

Five current dimensions:
- connector
- WordPress
- backups
- updates
- security

Overall:
- healthy
- attention
- critical
- unknown

Rules:
- worst known dimension wins
- unknown never becomes healthy automatically
- open critical security incident keeps security critical
- scanner unavailable is not healthy

Do not create parallel health calculations.

## 19. Security & Malware

Security concepts remain separate:
- vulnerability
- integrity issue
- suspicious activity
- malware

Vulnerability:
- trusted/configured source only
- no source = "Vulnerability scanner not configured"
- no invented CVEs/severity

Integrity:
- verified
- modified
- missing
- extra
- unavailable

Modified != malware.

Malware scanning:
- heuristic/rule based
- versioned rules
- severity and confidence separate
- scanners do not auto-confirm malware
- false positives must be tested
- destructive remediation is controlled

Remediation:
1. verified FULL backup
2. authorization
3. controlled action
4. rescan
5. health check
6. functional verification

Keep `SECURITY_REMEDIATION_ENABLED=false` until real-site rehearsal and explicit approval.

## 20. Operator Console

Operator authentication is separate from customers.

Operator permissions include:
- websites.view
- websites.manage
- backups.view
- backups.run
- backups.restore
- updates.view
- updates.run
- security.view
- security.scan
- security.manage
- security.remediate
- admin_access.request
- admin_access.full
- licenses.view
- audit.view

Do not grant permissions implicitly.

Do not implement customer impersonation.

Sensitive operations must call existing services.

Known commit:
`ba2d1db` — Internal Operator Console

## 21. Owner Admin — Next Product Area

The owner/admin area is separate from Operator Console.

Owner responsibilities:
- business dashboard
- customers
- websites
- operators
- permissions
- pricing administration
- public content
- translations
- SEO
- navigation
- system status

Do not build a generic page builder.

## 22. Content Management Direction

Public content should eventually be editable from Owner Admin.

Expected content:
- hero
- services
- why maintenance
- update process
- pricing introduction
- dashboard
- licenses
- WooCommerce
- custom development
- how it works
- activity
- FAQ
- final CTA
- footer
- navigation
- SEO

Locales:
- `en`
- `hu`

Future locales should be possible.

Do not auto-translate.

Draft/published states should exist.

Draft content must never leak publicly.

### Display language and currency

Details: `docs/LOCALIZATION.md`.

- The public site, customer login and customer portal are bilingual (`en`, `hu`), in Laravel's `lang/en`, `lang/hu` (UI texts), separate from owner-published content. Owner Admin, Operator Console and staff invitations stay English; customer emails are English until the language is stored at checkout.
- A language or a displayed currency never decides a price. Hungarian is always HUF. English offers only currencies that have real prices in the pricing source (today HUF only): no conversion, no exchange rates, no IP-based choice, no invented prices. Checkout resolves billing country → market → price on the server; browser-supplied amounts, currencies, Price IDs and markets are never read.
- A customer's language is stored on their own login (`users.locale`). No request may name another user; only supported codes are accepted.

## 23. Billing / Invoicing

Stripe handles payments/subscriptions.

Invoice provider should be replaceable:

```text
InvoiceProvider
 ├── BillingoProvider
 └── SzamlazzProvider
```

Do not hardwire the whole application to one invoice provider.

Tax logic remains separate from payment logic.

## 24. Lead Generation — Future

Future components:

Scout:
- finds WordPress sites
- identifies company
- analyzes public technical information
- scores opportunities

Outreach:
- Hungarian/English
- evidence-based
- initially human-approved

Never fabricate security problems.

This is future scope.

## 25. Git Milestones

Project branch:

`claude/ecstatic-wozniak-oemyuf`

Important commits:
- `ba2d1db` — Internal Operator Console
- `4076684` — Security & Malware Engine
- `2f7d0b4` — Owner Admin & Content Management

Do not rewrite history.

Do not force-push.

Do not squash milestone commits without explicit instruction.

When asked to push:
1. `git status`
2. `git log --oneline -12`
3. inspect diff/stat
4. check secrets/local/generated files
5. push normally
6. report final HEAD and status

Do not merge into main/master unless explicitly requested.

## 26. Development Method

Use small, isolated milestones.

Preferred workflow:

`Inspect → Plan → Implement → Test → E2E → Review → Commit → Push only when requested`

Do not start the next milestone automatically.

Every completion report must contain:
- files changed
- migrations
- tests
- E2E status
- deployment/config needs
- known limitations
- commit hash

## 27. Multi-Claude / Multiple Pro Accounts

This repository is deliberately continued across multiple Claude Pro accounts.

A new Claude must assume it has **no chat history**.

### Start of every session

Read:
- `CLAUDE.md`
- relevant `docs/*.md`

Then run:
- `git status`
- `git log --oneline -12`

Inspect the current branch and recent milestone state.

Do not guess missing context.

### Before implementation

Find the existing domain/service first.

Ask internally:
1. Does this belong to an existing domain?
2. Can an existing service be reused?
3. Does this create duplicate business logic?
4. Does it affect tenant isolation?
5. Does it affect secrets?
6. Does it affect backup/recovery?
7. Does it change a commercial promise?
8. Does it require migration?
9. What tests prove it?
10. What does the next Claude need to know?

### After every milestone

Create/update:

`docs/PROJECT_STATUS.md`

It should contain:
- completed milestones
- current branch
- current HEAD
- test baseline
- current in-progress work
- next milestone
- blockers
- pending manual configuration
- important decisions

Update `CLAUDE.md` only when permanent architecture/business rules change.

### End of every session

Report:
- current HEAD
- working tree status
- tests
- unfinished work
- anything the next Claude must know

Never say a milestone is complete while it is uncommitted/incomplete.

## 28. Test Discipline

Unit tests are not enough.

For security/workflow-critical features, prefer real E2E validation with:
- Laravel
- queue worker when needed
- WordPress
- real database
- real storage where relevant
- Playwright/browser where relevant

Past milestones have found bugs that unit tests missed.

Do not weaken production code to make environment-specific tests green.

Known environment-specific issues have included:
- Windows Unix-permission semantics
- Windows symlink behavior
- optional S3/SFTP test services
- WordPress test environment requirements
- MySQL/MariaDB integration requirements

Linux CI should be authoritative for Linux/production-like filesystem behavior.

## 29. Current Strategic Roadmap

### Completed

- public homepage
- pricing engine
- Stripe Checkout MVP
- customer accounts
- subscription lifecycle
- website onboarding foundation
- WordPress Connector
- Backup Engine
- Secure WordPress Admin Access
- Internal Operator Console
- Unified Site Health
- Security & Malware Engine
- Owner Admin + CMS

### Next / planned

1. ~~Owner Admin + CMS~~ (done)
2. Production hardening / CI / deployment (in progress: docs/PROJECT_STATUS.md)
3. ~~Real Stripe test-mode configuration~~ (validated locally: docs/STRIPE_TEST_MODE.md)
4. Real backup storage setup
5. Transactional email
6. Controlled pilot onboarding
7. Security remediation rehearsal
8. Production readiness review
9. Lead generation agent
10. Outreach automation
11. Agency/scale features

Do not skip directly from development to uncontrolled production automation.

## 30. Production Safety

Conservative defaults remain required:

`SECURITY_REMEDIATION_ENABLED=false`

`ADMIN_ACCESS_OPERATOR_FULL_ADMIN=false`

Live restore only after staging rehearsal.

Production sites must use HTTPS.

Destructive automation requires explicit rollout approval.

## 31. Business Workflow

Preferred customer lifecycle:

```text
Lead
  ↓
Website analysis
  ↓
Offer
  ↓
Checkout
  ↓
Payment
  ↓
Customer account
  ↓
Add website
  ↓
Connector pairing
  ↓
Initial audit
  ↓
Initial backup
  ↓
Maintenance
  ↓
Monitoring
  ↓
Security
  ↓
Support / Development
```

Operational lifecycle:

```text
Detect
  ↓
Assess
  ↓
Backup
  ↓
Change
  ↓
Verify
  ↓
Log
  ↓
Notify if needed
```

For security incidents:

```text
Detection
  ↓
Verified backup
  ↓
Investigation
  ↓
Controlled remediation
  ↓
Rescan
  ↓
Health check
  ↓
Functional check
  ↓
Resolution
```

## 32. Content / Communication Rules

Customer-facing text must be concrete and honest.

Prefer:
- Backup verified
- Update successful
- Functional check passed
- License expired
- Security scan unavailable
- Customer action required
- Backup required
- Site attention required

Avoid claiming:
- 100% secure
- guaranteed protection
- malware-free
- fully automated recovery

unless evidence and scope genuinely support the claim.

## 33. Final Rule

This is a long-running production-oriented project.

Never rely on previous chat history.

Use:
- repository
- `CLAUDE.md`
- `docs/`
- tests
- git history

as persistent memory.

Preserve existing architecture.

Do not invent prices.

Do not invent security findings.

Do not bypass authorization.

Do not expose secrets.

Do not duplicate business logic.

Prefer explicit state machines.

Prefer reversible operations.

Prefer real E2E validation for critical workflows.

Never silently change an agreed business rule.

When a requirement conflicts with the architecture, surface the conflict before implementing a hidden workaround.
