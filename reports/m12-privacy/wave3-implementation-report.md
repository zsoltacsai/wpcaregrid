# M12 Wave 3 — Legal documents, versioning, publication and acceptance

## Scope and safeguards

Wave 3 implements versioned document storage, review-reference capture, publication/effective-date resolution, customer acceptance evidence and Owner controls. It supplies no legal text and makes no legal, tax, lawful-basis, controller/processor, DPA, or statutory-retention conclusion. A recorded reference is an auditable Owner attestation; the application does not validate external advice.

The public catalogue supports Terms of Service, Privacy Notice, Cookie Notice and DPA, with independent locale versions for configured languages. New drafts have empty bodies and acceptance disabled. Public routes resolve only published, effective versions and return an unavailable response when none exists. Historical published versions remain addressable; draft and approved-but-unpublished text is private. Rendering and persistence reuse the established HTML sanitizer. Public legal pages are non-indexable.

Published versions are immutable through model workflows and retained when superseded/retired. Each version stores a UUID, document type, monotonically increasing locale/type version, locale, sanitized content hash, UTC effective/publication/review timestamps, acceptance mode, audience metadata and actor/reference fields. Acceptance is append-only and binds user/customer, exact version, checksum, locale, source and UTC timestamp. It deliberately stores no IP address or user agent.

The Owner Console at `/owner/legal-documents` supports empty draft creation, editing while draft, review-reference recording, publication confirmation and retirement confirmation. It uses the existing Owner settings permission and sensitive-action throttles. Customer account legal history is read-only except where a published, explicitly approved account-use rule requests affirmative acceptance. The checkout flow binds a required checkbox to the exact current Terms UUID and checksum only when that version is published with checkout-required mode; the checkout intent carries evidence and successful paid provisioning appends the acceptance ledger entry. Existing behavior remains unchanged while acceptance is disabled.

The accepted Owner product default W3-O1 is recorded: mandatory acceptance starts OFF; only a reviewed and explicitly published rule may activate it. This does not supply or replace legal approval. Legal wording, DPA role/audience, reacceptance triggers for existing customers, and publication content remain gated on qualified review. No legal document was seeded or published by this implementation.

## Evidence and data boundaries

- Customer privacy export now includes the customer's own legal acceptance facts: type, immutable version reference, locale, checksum, flow source and accepted timestamp. It does not include review notes or other customers' evidence.
- Owner audit records contain version identifiers, event names and safe metadata, not document body text or approval-reference contents.
- Acceptance records are not written through the existing Owner audit helper, which captures request IP; acceptance evidence has no IP/user-agent columns.
- Checkout acceptance is stored on the checkout intent and copied into the immutable acceptance ledger only after verified paid provisioning. No Stripe API behavior or webhook idempotency behavior was changed.
- DPA audience and any non-public access scope must be selected and legally reviewed before any DPA version is published. No DPA copy or audience decision was fabricated.
- Supported document locales are sourced from the existing locale configuration. The catalogue has no silent cross-locale fallback.

## Review gates and unresolved decisions

- **LEGAL REVIEW REQUIRED:** all document wording and business/legal facts; roles and DPA audience; which changes require reacceptance versus notice; effective dates and existing-customer transition; any cookie/consent claims.
- **OWNER PRODUCT DECISION:** W3-O1 accepted as above. W3-O2, W3-O3 and W3-O4 remain pending in `final-decision-register.md`; the app uses conservative behavior (unavailable when no approved locale version exists, DPA publication is blocked unless public audience `all` is explicitly selected and reviewed, and no locale fallback).
- No acceptance enforcement is active in staging until an exact version is intentionally approved and published with an applicable rule. Publishing legal content is a separate human action and was not performed as part of the engineering validation.

## Validation and release evidence

The initial local workflow suite caught an invalid declared return type on the unavailable public-page path. The controller return type was corrected and the same suite passed on rerun. Exact test counts, lint/build/CI results, PR/merge identifiers and staging evidence are to be appended after final release validation.

Adversarial review found that the public DPA route did not enforce its saved audience. DPA drafts now start with no audience; review cannot be recorded unless public audience `all` is explicitly selected, and current/historical public resolution rejects DPA versions without that audience. A regression test proves an unspecified or processor-only audience remains unavailable and that an explicitly approved public audience resolves. This keeps pending W3-O3 from becoming an accidental publication decision.

Local validation on 2026-10-15:

- Wave 3 plus checkout, zero-charge checkout, Stripe webhook inbox, privacy export/foundation and authorization regression: **86 tests, 1266 assertions, 0 failures**.
- Full PHPUnit suite: **1257 tests, 18120 assertions, 0 failures, 25 skips**. The first run had one environment-induced failure in `ConnectorPackageArtifactTest::test_untracked_connector_source_is_rejected_before_packaging`: WSL could not resolve this Windows-created worktree's `.git` pointer, so the subprocess treated Git as unavailable in test mode. The test passed in isolation and the full suite passed after supplying the correct Linux `GIT_DIR` and `GIT_WORK_TREE` for this exact worktree.
- All 25 local skips were confirmed with PHPUnit's skipped-test display: 2 restore concurrency cases (requires explicit loopback MySQL and PCNTL); 6 S3/SFTP cases (local moto_server/OpenSSH services unavailable); 1 platform MySQL recovery drill (isolated MySQL 8.4 CI service); and 16 MySQL/process-concurrency cases (explicit MySQL/independent connections and PCNTL unavailable). They are environment-specific and must run in the required CI jobs.
- Pint passed for all 27 changed PHP files after formatting the new DPA regression test. PHP syntax checks, Composer security audit, Vite production build and `git diff --check` passed in local validation. Gitleaks is not installed in this Windows environment; the mandatory full-history CI secret scan remains required.

Release and staging evidence was completed on 2026-10-15; see the final release section below.

No production change, customer acceptance capture, billing mutation, destructive privacy action, legal publication, or provider-side mutation is authorized by this implementation report.

## Final release and staging evidence — 2026-10-15

- **Primary branch:** `claude/ecstatic-wozniak-oemyuf`
- **Implementation PR:** [#56](https://github.com/zsoltacsai/wpcaregrid/pull/56), merged normally.
- **Exact PR head / implementation SHA:** `fe62381f72d01221f0be1d63a459363500f7ddca`
- **Merge commit / primary SHA:** `8fb5be08108ec81c2de3a28c1ee11890554771a9`
- **Exact-head CI:** GitHub Actions run #220 (`38715011808`) passed all five required jobs at `fe62381f72d01221f0be1d63a459363500f7ddca`: Connector WordPress PHP 8.4 SQLite; Connector WordPress PHP 8.4 MySQL; Server PHP 8.4 SQLite; Server PHP 8.4 MySQL; Quality (Pint, Composer/npm audits, build, full-history Gitleaks).
- **Post-merge CI evidence:** The GitHub commit-workflow-runs and combined-status queries for merge SHA `8fb5be08108ec81c2de3a28c1ee11890554771a9` returned no run/status entries. This is recorded as unavailable evidence, not represented as a post-merge pass. Exact PR-head CI is green.
- **Guarded staging deployment:** `https://staging.wpcaregrid.com` was deployed to exact primary SHA `8fb5be08108ec81c2de3a28c1ee11890554771a9` via `scripts/deploy-staging.sh`, using strict SSH host-key checking and the independently approved Ed25519 fingerprint `SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ`. Pre-deploy platform backup was preserved at `/home/deploy/private-backups/wpcaregrid-pre-deploy-20261015T100027Z.sql.gz`.
- **Migration/runtime:** `2026_10_15_000002_create_legal_document_versions_and_acceptances` is `Ran`. `php artisan system:check` reported application, database, queue, scheduler, Connector API, Stripe, Cloudflare DNS, backup storage, mail, safety switches, privacy-retention safety, and privacy exports `OK`. Security scanner remained `WARNING` because no completed scan was present (residual M10/security finding). `wpcaregrid-default` and `wpcaregrid-backups` Supervisor programs were both `RUNNING`.
- **HTTP/route checks:** `/up`, localized home/pricing routes and public route probes were healthy as appropriate. Terms, Privacy, Cookies and DPA public routes returned `404` because no legal document has been published. `/owner/legal-documents` returned `302` to authentication for a guest. The ten legal-document routes were present. A fresh isolated browser was verified at `staging.wpcaregrid.com` and authenticated by the Owner; the read-only Owner Legal Documents page rendered successfully. It showed 0 version records, an empty catalogue, the warning that legal text is not supplied/approved, acceptance disabled as the new-draft default, and no document published. No Owner UI mutation or synthetic draft was performed. Owner lifecycle behavior is also covered by exact-head feature tests.
- **Local validation:** Full PHPUnit suite: **1,257 tests, 18,120 assertions, 0 failures, 25 skips**. Focused Wave 3/checkout/webhook/privacy/export/access suite: **86 tests, 1,266 assertions, 0 failures**; the final DPA audience and Owner draft regressions passed in the Wave 3 focused run (**9 tests, 45 assertions**). Pint passed for all 27 changed PHP files; PHP syntax, Composer audit, Vite build and `git diff --check` passed. Local skips: 2 restore-concurrency cases require loopback MySQL + PCNTL; 6 S3/SFTP cases require moto_server/OpenSSH; 1 platform DB drill requires isolated MySQL 8.4; 16 MySQL/process-concurrency tests require MySQL connections + PCNTL. CI ran both connector SQLite/MySQL and server SQLite/MySQL jobs successfully. Local Gitleaks was unavailable; the exact-head CI Gitleaks scan passed.
- **Policy and safety state:** W3-O1 remains Owner-approved: mandatory acceptance OFF by default. No legal documents were seeded or published; no acceptance enforcement activated. W3-O2/O3/O4 remain pending with fail-closed behavior. No customer account, billing state, provider, or production system was mutated. M12 destructive retention remains OFF; no deletion, anonymization or provider-deletion path was executed.

This closes the Wave 3 engineering and release scope only. It is not legal approval, publication approval, a DPA determination, or a claim that public legal content is complete. The report-only closure-evidence PR #57 passed exact-head CI run #222 (`38717913446`) and was merged as `e939978f8d97fadb5da4f9827ca2cc07e9e0f0be`. GitHub returned no post-merge workflow run or commit status for that merge SHA when checked. No staging redeploy was needed for the report-only merge.
