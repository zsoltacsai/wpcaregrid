# M8 fixture fingerprint v2

This is a read-only, fixture-specific verification tool. It does not run a
backup or restore. Its only allowed live target is the paired M8 synthetic
WordPress site identified in `scripts/e2e/m8-fixture-intent-v2.json`. The
collector also pins the URL, site UUID, and `m8_` database prefix in code.

## Contract and use

- `scripts/e2e/m8-fingerprint-v2.php` is the canonical collector and
  comparator for the pairing baseline, backup preflight/post-check, restore
  validation, and future failure-matrix snapshots.
- `scripts/e2e/m8-fixture-intent-v2.json` is the source-controlled statement
  of intended fixture content, media, persistent options, plugin, theme, and
  table identity. It was derived from the approved paired baseline and M8
  setup reports, then checked against the live fixture. It is not a copy of
  the current database.
- The output schema is `wpcg-m8-fixture/2`, generator `2.0.0`, intent
  version `1`. The historical paired JSON is schema 1 evidence only.
  Comparison across schema versions fails with
  `NON-COMPARABLE FINGERPRINT SCHEMA`.
- A candidate becomes an approved backup preflight baseline only after a
  separate owner decision. Until then, backup remains blocked.

The CLI runs with PHP on the WordPress host (outside the webroot) using
`--wp-root=/path/to/public --intent=/path/to/m8-fixture-intent-v2.json`.
It prints JSON to stdout. A future guarded task should capture two independent
outputs, require byte equality, and compare that output with the approved
schema-v2 baseline before and after backup/restore. The tool is in repository
scripts; it is not installed as a WordPress plugin or automatically invoked by
the backup engine. No staging deployment is needed to use it.

## Stable fields

Content includes all ordinary posts/pages in publish, draft, private,
pending, future, and trash states. Auto-drafts and revision records are
excluded as ephemeral/secondary records. Each included object has ID, type,
status, parent, slug, and title/content/excerpt hashes. The intentional
Privacy Policy draft (ID 3) is required by the fixture intent.

The persistent-option allowlist is fixed in code and in the intent file:
`active_plugins`, `blog_public`, `blogdescription`, `blogname`,
`gmt_offset`, `home`, `page_for_posts`, `page_on_front`,
`permalink_structure`, `show_on_front`, `siteurl`, `stylesheet`,
`template`, `timezone_string`, and `wp_page_for_privacy_policy`.
Output records each name, normalized autoload class, and SHA-256 of its value.
Only `active_plugins` is safely decoded (classes prohibited), sorted, and
encoded as JSON before hashing. Other values are hashed as raw stored bytes.
The title, tagline, and privacy-page target are also checked against explicit
non-secret fixture intent.

Transient/site-transient values, update caches, cron timestamps, locks,
Connector credential, sessions, and other non-allowlisted options are
excluded. Exclusion is based on the fixture contract and volatility/secret
classification, not the fact that an option once changed. No secret-bearing
option value or hash enters the fingerprint. Connector state contributes only
safe flags, version, and the pinned synthetic site identity. The total
`wp_options` row count is not a strict field.

Intentional upload files are hashed by relative path. The collector scans all
upload files, detects extras and missing expected files, and refuses symlinks.
It records active plugin slugs/versions, active theme/version, table names,
prefix, and count of included content. It does not hash WordPress core.

Integrity checks cover postmeta, term relationships, nonzero comment post
IDs, nonzero post parents (including attachments/revisions), post-type menu
targets, and configured privacy/front/posts page references. Any dangling
reference appears in `integrity.failures`; the expected count is zero.

## Determinism and limitations

The JSON has stable key and array order. It contains no capture timestamp,
random value, absolute local path, credential, or raw post content. The
fixture intent and normalized output must both be reviewed before approving
the candidate. The selected strict option values can still change through an
authorized WordPress setting change, correctly causing a new fingerprint.

The historical schema-1 baseline recorded 159 options; current WordPress
records 158. It did not preserve option names, so the one-row difference is
non-reconstructable from that artifact alone. A read-only replay of the
retained fixture MySQL ROW binlogs reconstructed the baseline and current
option-name sets; the sole difference was WordPress's
`_transient_doing_cron`. The reconstructed current set exactly matched a
fresh SQL option-name query. The old hash must not be
compared byte-for-byte with schema 2, nor should an option be fabricated to
make counts equal.

Standalone contract tests run with:

```sh
php scripts/e2e/test-fingerprint-v2.php
```

CI runs this test in its quality job. A separate disposable WordPress
7.1.3/MySQL experiment confirmed transient creation/deletion and ordinary
homepage/cron requests do not change the selected strict options, while
changing a persistent option or privacy target does.
