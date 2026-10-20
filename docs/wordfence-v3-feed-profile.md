# Wordfence v3 feed schema profile

## Findings

- **Retained real feed found: No in the locations searched.** No downloaded feed was present in the repository's local storage tree or in the top level of the local temporary directory. The only filename match there was a Wordfence-related PR note, not a feed; its contents were not opened. The tracked Wordfence fixtures inspected are explicitly synthetic. No staging connection or Wordfence request was made.
- **Real records/ranges analyzed: 0 / 0.** The rejected provider version is still unknown, so real-feed categories and a rejection count cannot be measured.
- `SyncVulnerabilities::handle()` stages the HTTPS response in a `wpcg-feed-*` temporary file under the configured feed directory, with mode `0600`, and streams the response into it. Download failures delete the staged file in `stageWordfenceFeed()`'s catch path. After a successful download, `handle()` imports it inside a `try` and calls `File::delete($download)` in `finally`, whether import succeeds or fails. The temporary feed is therefore not expected to remain after the reported staging failure; the previous real payload is not available for this profiling task.
- The v3 parser reads a top-level object one record at a time. Config sets a 160 MiB feed ceiling and the JSON record reader has a 2 MiB record ceiling. A successful import publishes the vulnerability index separately; the feed file is still deleted afterward.
- Current v3 schema validation expects each vulnerability record to contain a software list; software entries use an `affected_versions` object whose values are range objects with string `from_version`/`to_version` and boolean `from_inclusive`/`to_inclusive` fields. The only wildcard token defined by the parser is the standalone `*`.
- **Previous validator baseline (before PR #138):** the token check used the ASCII-oriented regex `^[A-Za-z0-9][A-Za-z0-9.+_-]{0,63}$`, allowing at most 64 ASCII characters. The reported `software.version_token` failure came from this earlier rule; it does not reveal the rejected value or its format category.
- **Revised validator (introduced by PR #138):** version tokens are limited to 128 bytes, must be valid UTF-8, and must be non-empty (and non-blank) without control or format characters. The standalone `*` is accepted only for affected-version range endpoints; installed and patched versions cannot use it.
- Existing v3 fixtures inspected are marked synthetic and use fabricated identifiers, software, and references. Existing coverage includes numeric object keys, a prefixed version, an exclusive upper boundary, and invalid boundary types. The previous v2-style feature fixture is not evidence of real v3 feed content.

## Offline synthetic fixture coverage

The new fixture covers numeric dotted versions, standalone wildcard, surrounding whitespace, a prerelease-like suffix, a build suffix, a `v` prefix, Unicode punctuation, an escaped control character, empty and over-length strings, null and numeric endpoints, an unexpected `affected_versions` list, and true/false/non-boolean/missing boundary semantics. These examples are synthetic and are not asserted to be Wordfence values.

The profiler emits counts, JSON types, and short hashes of object key shapes. It does not emit identifiers, labels, titles, references, or version values. It applies the repository's 160 MiB feed ceiling by default, caps each record at 2 MiB, refuses symlink inputs, and reports parse failures without including source text or paths. Categories can overlap. The `over_64_bytes`, `outside_previous_token_alphabet`, and `rejected_by_previous_version_token_regex` metrics are explicitly historical comparisons against the **OLD pre-PR #138 regex** above; they do not describe rejection by the revised validator. The `version_compare_semantics_need_review` count marks values outside numeric dotted notation for review; it does not prove whether `version_compare()` gives the intended provider ordering.

Run against an already authorized local capture only:

```sh
php scripts/profile-wordfence-v3-feed.php /secure/local/path/feed.json
```

The optional `--max-bytes=N` argument can lower the 160 MiB ceiling. The utility makes no network calls and does not write to the input or vulnerability index.

## Safe one-time staging profile workflow

The normal synchronization still downloads once, imports once, and deletes its temporary `wpcg-feed-*` file in `finally`. A one-shot profile can now be requested on that same invocation with `--profile-feed`. The switch is not part of the scheduler invocation and is off by default. Before any network request, the command requires all three established deployment identity values to match exactly: `CAREGRID_DEPLOYMENT_ENV=staging`, `CAREGRID_DEPLOYMENT_HOSTNAME=stg1.wpcaregrid.com`, and `APP_URL=https://staging.wpcaregrid.com`. Staging intentionally uses `APP_ENV=production`; `APP_ENV` alone is not treated as a staging identity. The profile option is refused for local `--file` imports.

After the ordinary secured download succeeds, the command runs `scripts/profile-wordfence-v3-feed.php` against that local temporary file before starting the importer. It makes no additional request. The child process has a 64 MiB PHP memory limit, a configurable timeout hard-capped at 120 seconds (default 120), the normal configured download ceiling (at most 160 MiB), the existing 2 MiB per-record ceiling, and a 32 KiB combined stdout/stderr ceiling. A local Windows PHP 8.4.25 benchmark of a disposable 128,886,579-byte synthetic feed (86,968 records) completed in 70.9 seconds under the 64 MiB limit; the 15-second limit did not complete. The benchmark measured 2 MiB peak PHP allocator usage. These results justify the two-minute cap for this feed size on that environment; slower staging hardware can still hit the timeout, in which case profiling remains best-effort and import continues. Only the profiler's aggregate stdout is persisted; process diagnostics and exception messages are discarded. A profiling failure logs only a fixed `feed_profile_failed` class and one of the fixed subcodes, then normal import and cleanup proceed unchanged.

Successful reports are written beneath `storage/app/private/wordfence-profiles/`, which is inside the non-public local private storage root. The profiler refuses symlinked path components, creates the report directory with mode `0700`, and creates collision-resistant report files with mode `0600`. Reports contain aggregate counts and key-shape hashes only. They do not contain the feed, API key, authorization header, provider values, vulnerability IDs, response body, or feed/report paths. Do not copy reports into a public directory, commit them, attach them to a ticket, or add their contents to application logs.

For a separately authorized staging run, first confirm the reviewed deployment SHA, verify the three deployment identity values, ensure scheduled synchronization remains disabled, check that the Wordfence cooldown has expired, and arrange for one operator to run:

```sh
php artisan security:vulnerabilities:sync --profile-feed
```

The normal feed download/import still determines the command result. A profile report is best-effort and is not evidence that the vulnerability index was successfully published; separately verify the index/feed status using the normal application health checks. Do not rerun the import just to reproduce a profile. Review only the aggregate report in its private directory. Retain it only for the approved staging analysis window (maximum seven days), then remove that individual report as the application service owner and verify the directory contains no report from that run. Keep unrelated reports untouched. If profiling fails, the import continues and no report is written.

## Recommendations for version handling review

Add offline cases that exercise, independently and without raw provider data:

1. Whitespace at either edge and embedded spaces; confirm the import remains fail-closed if normalization would be lossy.
2. Prerelease and build suffix forms, including comparisons where PHP `version_compare()` may assign special ordering semantics.
3. Unicode punctuation, control characters, empty values, nulls, non-string values, and strings above the token length ceiling.
4. Standalone `*` only, plus near-miss wildcard forms, with inclusive and exclusive lower and upper bounds.
5. Ambiguous or provider-specific labels such as branch names and prefixed tokens; classify uncertain ranges as unknown/unavailable rather than not vulnerable.

## Limitations and handling

This report profiles no provider payload. The local filename search was limited to the app storage tree and the top level of the local temporary directory. The command implementation confirms that the earlier staging temporary feed should have been deleted in its import `finally` block, including after import failure. The search cannot establish whether another copy exists in nested temporary folders, another worktree, an uninspected deployment backup, or an operator-controlled secure archive. This task did not connect to staging, inspect its configuration, or retrieve secrets. Do not commit a future capture or copy raw version values into logs, tests, or CI output.

Wordfence attribution and licensing fields in the production feed and importer were not changed. The synthetic fixture contains no copied provider data.
