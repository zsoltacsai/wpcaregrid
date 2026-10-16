# M14.2 Filesystem and Archive Safety Matrix

| Area | Current source controls | Required proof |
|---|---|---|
| Lexical paths | PathGuard rejects empty, >4096-byte, NUL, backslash, absolute, drive-prefixed, empty/dot/dot-dot components | alternate encodings, Unicode separators, path depth and parser edge cases |
| Resolved containment | realpath of existing target or nearest existing parent, normalized root comparison | prove every write checks containment immediately before mutation; symlink/TOCTOU fixture |
| Archive types | TarReader validates checksum/ustar, rejects types except regular file/directory, checks truncation, names through PathGuard | malformed header, PAX path, unsupported type, duplicate/collision fixtures |
| Extraction / restore | private staging then preflight plan and apply; FilePolicy scopes restore targets, excludes connector/wp-config; hashes and symlink checks present | staged-root integrity, exclusive writes, no partial live change on invalid archive, race tests |
| Archive creation | sorted walk, never follows symlinks, excludes policy paths; max file size defaults 1 GiB | total files/bytes and configured max consistency; disk resource bounds |
| Temporary files | prefer outside web root; fallback random directory; 0700 directory, 0600 files and deny files when under web root; stale job cleanup | permissions, web exposure, symlink swap, concurrent cleanup and crash recovery |
| Disk pressure | free-space check with 50 MiB margin when available; writes checked in stream | disabled/unknown free space, partial write cleanup and original files intact |

Inspected TarReader shows no explicit archive-wide entry-count, expanded-byte, expansion-ratio, path-depth or aggregate-work limit. This is a source-observed resource-bound question, not proof of a ZIP bomb (the format is tar/gzip) or exploitable DoS. Define conservative bounds from supported backup evidence.

Disposable WordPress fixtures must test POSIX/Windows traversal, absolute and long/deep paths, NUL/control, PAX path override, malformed/truncated archive, symlink at staging/parent/destination, swap after preflight, duplicate exact and case-only paths, file/directory collision, many tiny entries, high expanded bytes, oversize member, disk full, read-only parent, denied mkdir/rename, process crash and cleanup. Assert no out-of-root write, bounded resource use, and safe/recoverable state. Multisite, Bedrock and custom roots remain unsupported; unknown root blocks mutation/restore; M14.3 owns general compatibility UX.

## Implementation evidence update — 2026-10-16

Writer and reader now share explicit archive ceilings for entry count (100,000), encoded size (64 GiB), expanded size (60 GiB), member size (1 GiB), path depth (64), total path components (300,000) and PAX metadata (8 MiB). Writer budget failures abort and remove partial output. Restore extraction rejects unsafe staging roots/components, creates files exclusively, enforces private modes and rechecks destination containment/symlinks immediately before live rename. Tests cover configured over-limit boundaries, exact and case collisions, file/child collisions, control characters, and a parent changed to an outside symlink after preflight with no outside write. Full WordPress SQLite/MySQL suites pass. Resource-specific disk-full/permission variants remain covered by existing tests where supported; no arbitrary Windows/hosting claim is made. Two opt-in 900-second hard-kill tests are documented skips.

## Final validation evidence — 2026-10-16

Exact-head CI run `38961580730`, staging deployment, post-deploy health, gate readback, and verified cleanup are complete; see [M14.2 closure report](m14-2-closure-report.md). Two opt-in 900-second worker-kill tests remain documented skips. No unsupported hosting or Windows guarantee is claimed.
