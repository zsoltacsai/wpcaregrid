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

Remote exact-SHA CI, staging deployment/validation, and final merge evidence remain pending.

No production change, customer acceptance capture, billing mutation, destructive privacy action, legal publication, or provider-side mutation is authorized by this implementation report.
