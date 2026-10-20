# Wordfence v3 feed schema profile

## Findings

- **Retained real feed found: No in the locations searched.** No downloaded feed was present in the repository's local storage tree or in the top level of the local temporary directory. The only filename match there was a Wordfence-related PR note, not a feed; its contents were not opened. The tracked Wordfence fixtures inspected are explicitly synthetic. No staging connection or Wordfence request was made.
- **Real records/ranges analyzed: 0 / 0.** The rejected provider version is still unknown, so real-feed categories and a rejection count cannot be measured.
- `SyncVulnerabilities::handle()` stages the HTTPS response in a `wpcg-feed-*` temporary file under the configured feed directory, with mode `0600`, and streams the response into it. Download failures delete the staged file in `stageWordfenceFeed()`'s catch path. After a successful download, `handle()` imports it inside a `try` and calls `File::delete($download)` in `finally`, whether import succeeds or fails. The temporary feed is therefore not expected to remain after the reported staging failure; the previous real payload is not available for this profiling task.
- The v3 parser reads a top-level object one record at a time. Config sets a 160 MiB feed ceiling and the JSON record reader has a 2 MiB record ceiling. A successful import publishes the vulnerability index separately; the feed file is still deleted afterward.
- Current v3 schema validation expects each vulnerability record to contain a software list; software entries use an `affected_versions` object whose values are range objects with string `from_version`/`to_version` and boolean `from_inclusive`/`to_inclusive` fields. The only wildcard token defined by the parser is the standalone `*`.
- The current token rule accepts up to 64 ASCII characters, starts with an ASCII letter or digit, and continues with ASCII letters, digits, `.`, `+`, `_`, or `-`. The reported `software.version_token` failure establishes that one range endpoint did not satisfy that rule, but does not reveal its value or which format category it belongs to.
- Existing v3 fixtures inspected are marked synthetic and use fabricated identifiers, software, and references. Existing coverage includes numeric object keys, a prefixed version, an exclusive upper boundary, and invalid boundary types. The previous v2-style feature fixture is not evidence of real v3 feed content.

## Offline synthetic fixture coverage

The new fixture covers numeric dotted versions, standalone wildcard, surrounding whitespace, a prerelease-like suffix, a build suffix, a `v` prefix, Unicode punctuation, an escaped control character, empty and over-length strings, null and numeric endpoints, an unexpected `affected_versions` list, and true/false/non-boolean/missing boundary semantics. These examples are synthetic and are not asserted to be Wordfence values.

The profiler emits counts, JSON types, and short hashes of object key shapes. It does not emit identifiers, labels, titles, references, or version values. It applies the repository's 160 MiB feed ceiling by default, caps each record at 2 MiB, refuses symlink inputs, and reports parse failures without including source text or paths. Categories can overlap. Its `version_compare_semantics_need_review` count marks values outside numeric dotted notation for review; it does not prove whether `version_compare()` gives the intended provider ordering.

Run against an already authorized local capture only:

```sh
php scripts/profile-wordfence-v3-feed.php /secure/local/path/feed.json
```

The optional `--max-bytes=N` argument can lower the 160 MiB ceiling. The utility makes no network calls and does not write to the input or vulnerability index.

## Safe one-time staging profile workflow

The standard synchronization deletes its temporary feed before `handle()` returns. To collect aggregate statistics during exactly one future staging synchronization, first obtain explicit authorization for that synchronization and for a short-lived instrumentation change. Keep scheduled synchronization disabled and ensure no other operator starts a run. On the reviewed staging revision, temporarily invoke this profiler on the returned `$download` path immediately after `stageWordfenceFeed()` succeeds and before `WordfenceFeed::import()` begins. Capture only the profiler's aggregate stdout in a new owner-only report file (directory mode `0700`, file mode `0600`); do not copy, rename, or log the feed, version values, or API key. The profiler does not mutate the feed or index. Make the profiling invocation best-effort so a profiler failure does not change the sync/import result, and log only a fixed failure category if it fails. Let the existing `finally` delete the staged feed after import completes or throws, verify the aggregate report contains no source values, then remove the temporary instrumentation and securely retain or delete the aggregate report under the applicable retention policy. Do not repeat the synchronization for profiling.

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
