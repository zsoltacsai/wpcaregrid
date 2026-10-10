# Security & Malware Engine

Defensive security for customer WordPress sites. The engine detects known vulnerabilities, integrity problems and suspicious content, records them as structured findings, groups serious cases into incidents, and supports controlled, reversible remediation. It is a detection and response tool, not an antivirus: it never claims a site is "100% secure".

Related documents:

- [MALWARE_SCANNING.md](MALWARE_SCANNING.md): what the connector scans, the rules, integrity checking, quarantine and repair on the site
- [SECURITY_FINDINGS.md](SECURITY_FINDINGS.md): findings, severity and confidence, deduplication, statuses
- [SECURITY_INCIDENTS.md](SECURITY_INCIDENTS.md): incidents, remediation, backups, admin access

## The principle: detection is not infection

Four kinds of result are kept apart everywhere (types, UI, posture):

| Kind | Finding type | Example | Never becomes by itself |
| --- | --- | --- | --- |
| Vulnerability | `vulnerability` | Akismet 5.3 is affected by a published XSS | "infected" |
| Integrity problem | `integrity` | `wp-includes/version.php` differs from the official file | malware |
| Suspicious activity | `suspicious_file`, `suspicious_database`, `suspicious_user`, `suspicious_cron`, `configuration` | `eval(base64_decode(…))` in a plugin file | confirmed malware |
| Confirmed malware | a `suspicious_file` an operator confirmed | the webshell an operator looked at | set by a scanner |

Every finding has a **severity** (how bad it would be) and a separate **confidence** (how sure the engine is). Scanners report `low`, `medium` or `high` confidence. Only an operator can make a finding `confirmed` (see SECURITY_FINDINGS.md).

## Architecture

```
            WP CareGrid (Laravel)                                    WordPress site (connector 0.4.0)
┌──────────────────────────────────────────────┐          ┌────────────────────────────────────────┐
│ SecurityScanService                          │  poll    │ SECURITY_SCAN part N (read-only)       │
│  ├ VulnerabilityScanner  ← inventory +       │ ───────▶ │  integrity  ← WordPress.org checksums  │
│  │   VulnerabilitySource (configured feed)   │          │  files      ← RuleSet + FileRules      │
│  ├ queues SECURITY_SCAN parts via JobQueue   │ ◀─────── │  database, admins, cron, config        │
│  ├ ScanResultIngest (strict validation)      │ findings │  stops at its time budget, continues   │
│  └ FindingRecorder (dedupe, resolve)         │  only    │  in part N+1                           │
│ SecurityPosture → websites.security_status   │          │                                        │
│  └ SiteHealth "security" dimension           │          │ SECURITY_QUARANTINE / _RESTORE_…       │
│ IncidentService (timeline, workflow)         │  poll    │ SECURITY_REPAIR_FILE                   │
│ RemediationService ── BackupService (FULL) ──┼────────▶ │ SECURITY_DISABLE_PLUGIN                │
│ FindingDecisions (operator)                  │          │  hash-checked, reversible, run once    │
└──────────────────────────────────────────────┘          └────────────────────────────────────────┘
```

- **Local scanning.** Files and the database are inspected on the site. No source code, database dump, option values, post content, passwords, password hashes, tokens or license keys leave the site: only codes, relative paths, SHA-256 hashes, sizes, dates and counts (see "Data transmitted").
- **Everything is a whitelisted connector job**, delivered by the normal poll and run at most once (`JobRunner`). There is no job that runs PHP, SQL, shell commands or anything from a URL.
- The server never contacts a site. The site contacts WordPress.org only for official checksums and clean copies of official files, at fixed hosts.

| Server part | Where |
| --- | --- |
| Scans | `app/Security/SecurityScanService.php`, `ScanResultIngest.php`, `ScanType.php`, `ScanStatus.php`, `app/Models/SecurityScan.php` |
| Findings | `FindingRecorder.php`, `FindingDecisions.php`, `FindingType.php`, `FindingStatus.php`, `Severity.php`, `Confidence.php`, `PlainText.php`, `app/Models/SecurityFinding.php` |
| Vulnerabilities | `app/Security/Vulnerabilities/` (`VulnerabilitySource`, `WordfenceFeed`, `NoVulnerabilitySource`, `VulnerabilityScanner`) |
| Posture | `SecurityPosture.php`; `app/SiteHealth/` (Unified Site Health) |
| Incidents | `IncidentService.php`, `IncidentStatus.php`, `app/Models/SecurityIncident.php`, `SecurityIncidentEvent.php` |
| Remediation | `RemediationService.php`, `RemediationType.php`, `RemediationStatus.php`, `app/Models/SecurityRemediation.php`, `SecurityQuarantine.php` |
| Console | `app/Http/Controllers/Operator/Security*Controller.php`, `resources/views/operator/security/` |
| Customer | `resources/views/components/security/customer-panel.blade.php` |
| Commands | `security:monitor`, `security:schedule`, `security:vulnerabilities:sync` (`app/Console/Commands/Security/`) |
| Configuration | `config/security.php` |
| Connector | `wordpress/wp-caregrid-connector/includes/Security/`, `includes/Jobs/Handlers/Security*Handler.php` |

## Scans

Types: `vulnerability` (server only), `integrity`, `malware`, `full` (every scanner that is available). A scanner that is not available is reported as such (`coverage.<scanner>.status = unavailable` with a reason), never as "nothing found".

Lifecycle (`ScanStatus`, transitions enforced):

```
queued → running → completed | partial | failed
queued | running → cancelled
```

- **queued** when requested (`security.scan_requested`); vulnerability matching runs at once on the server.
- **running** when the site receives part 1 (`security.scan_started`).
- The site stops a part at its **time budget** (`SECURITY_SCAN_TIME_BUDGET`, default 60 s) or at 80 % of PHP's `memory_limit`, reports `partial / SCAN_INCOMPLETE` with what it found, and the server sends the next part, which continues from a saved cursor. At most `SECURITY_SCAN_MAX_PARTS` (20) parts; after that the scan is **partial** (`SCAN_PART_LIMIT`).
- **completed**: every available scanner finished. **partial**: at least one scanner ran only partly. **failed**: nothing completed (e.g. `NO_SCANNER_COMPLETED` for a vulnerability-only scan without a source, `INVALID_RESULT`, `SCAN_STATE_LOST`, `SCAN_TIMEOUT`).
- `security:monitor` (every 10 min) fails scans without progress for `SECURITY_SCAN_TIMEOUT_MINUTES` (120).
- One scan at a time per website (`SCAN_IN_PROGRESS`). Refused without an active plan (`SUBSCRIPTION_INACTIVE`), a connector (`NOT_CONNECTED`) or connector 0.4.0 (`CAPABILITY_MISSING`).

Each scan records: UUID, website, type, status, requester and actor type/user, `scanner_version` (server engine), `rules_version` (connector rule set), `connector_version`, `vulnerability_source`, coverage per scanner, a summary (counts by severity and type, new, resolved, unavailable scanners), `started_at`, `completed_at`, `duration_ms`, error code. Never credentials or content.

The job keeps only counts after ingestion; findings live in `security_findings` alone.

### Scheduling

Off unless `SECURITY_SCHEDULE_ENABLED=true`. `config('security.schedule.scans')` sets a frequency per scan type: `plan` uses the plan's own `security_check_frequency` from `config/pricing.php` (the default for `full`); `daily`, `weekly`, `monthly` or `null` (never) otherwise. No frequency is invented. `security:schedule` runs hourly and queues at most one scan per website per run, once per plan window.

## Vulnerability source

Vulnerabilities are reported **only** from a configured, trusted source:

| `SECURITY_VULNERABILITY_SOURCE` | Behaviour |
| --- | --- |
| `none` (default) | "Vulnerability scanner not configured." Coverage `unavailable`; a vulnerability-only scan fails. Nothing is reported as "0 vulnerabilities". |
| `wordfence` | Wordfence Intelligence v2 feed. `security:vulnerabilities:sync` downloads it daily from `SECURITY_VULNERABILITY_FEED_URL` (HTTPS only, with `SECURITY_VULNERABILITY_FEED_KEY` as a bearer token when set), or imports a file (`--file=`), and builds a compact index. An index older than `SECURITY_VULNERABILITY_MAX_AGE_HOURS` (72) counts as unavailable. |

Matching uses the inventory the connector already reports (core version, plugin slugs and versions, theme stylesheets and versions); the engine does no discovery of its own. A finding is reported only when the installed version is inside a range the source publishes (`version_compare`). Title, CVE, CVSS score, rating and patched versions come from the source as they are; a vulnerability without a rating has **no severity** ("Not rated"), never a guessed one. Confidence is `high` (the version matched a published range).

Other sources (WPScan, Patchstack) can be added behind `VulnerabilitySource`; none is included.

## Security posture and Unified Site Health

`SecurityPosture` is the only place that turns findings, scans and incidents into a website's security state (stored in `websites.security_status`, `security_open_findings`, `security_scanned_at`):

| State | When |
| --- | --- |
| **critical** | a confirmed malware finding; an open finding with critical severity and high confidence; a critical vulnerability; an open incident with critical severity |
| **attention** | any other open or confirmed finding; any other open incident |
| **healthy** | none of the above **and** the latest finished scan completed at least one real scanner within `SECURITY_SCAN_FRESH_FOR_DAYS` (35) |
| **unknown** | never scanned, latest scan failed, scanner unavailable, or result too old |

"Healthy" is never inferred from a scanner that did not run. An open critical incident keeps security **critical** until the incident is resolved through its workflow (rescan, health check, functional check; see SECURITY_INCIDENTS.md); after that, security may return to a lower state if no other critical condition remains.

Unified Site Health (`app/SiteHealth/SiteHealth.php`) has five dimensions: connector (presence), WordPress (the connector's health report), backups (a recent verified backup for the plan's schedule), updates (pending updates) and **security** (this posture). The overall state is the worst known dimension (critical > attention > healthy); an unknown dimension is reported as unknown and never counts as healthy.

## Who sees what

| | Customer (website page) | Operator (console) |
| --- | --- | --- |
| State, last scan, number of open issues | yes | yes |
| Kinds of issue, with a plain explanation | yes | yes |
| Vulnerable software, installed and fixed version | yes | yes, plus CVE, CVSS, source |
| Paths, rule codes, hashes, evidence, confidence | no | yes (`security.view`) |
| Operator notes, incidents, remediations, quarantine | no | yes |
| Change a finding | no | `security.manage` |
| Run or cancel a scan | no | `security.scan` |
| Incidents (open, status, notes) | no | `security.manage` |
| Quarantine, repair, deactivate, undo quarantine | no | `security.remediate` (not in the standard permission set) |

Console routes (all behind the operator guard, each with exactly one permission; POST routes rate limited):

| Method | URI (`/operator/…`) | Permission | Limit |
| --- | --- | --- | --- |
| GET | `security` | `security.view` | – |
| GET | `websites/{uuid}/security` | `security.view` | – |
| POST | `websites/{uuid}/security/scans` | `security.scan` | `operator-security` |
| POST | `websites/{uuid}/security/scans/{uuid}/cancel` | `security.scan` | `operator-action` |
| POST | `websites/{uuid}/security/findings/{uuid}/status` | `security.manage` | `operator-action` |
| POST | `websites/{uuid}/security/findings/{uuid}/remediations` | `security.remediate` | `operator-remediate` |
| POST | `websites/{uuid}/security/quarantines/{uuid}/restore` | `security.remediate` | `operator-remediate` |
| POST | `websites/{uuid}/security/incidents` | `security.manage` | `operator-action` |
| GET | `websites/{uuid}/security/incidents/{uuid}` | `security.view` | – |
| POST | `websites/{uuid}/security/incidents/{uuid}/status`, `…/notes` | `security.manage` | `operator-action` |
| POST | `websites/{uuid}/security/incidents/{uuid}/backup` | `backups.run` | `operator-backup` |
| POST | `websites/{uuid}/security/incidents/{uuid}/admin-access` | `admin_access.request` | `admin-access` |

Nested models use scoped bindings: a scan, finding, quarantine or incident of another website is a 404.

Untrusted text (titles, descriptions, evidence values) is stored as plain text (`PlainText`: control characters removed, whitespace collapsed, `<` and `>` kept) and escaped on output. It is never rendered as HTML. "Akismet <= 5.3" stays exactly that.

## Audit log

Recorded in `website_events` with actor type and user (`customer`, `operator`, `system`):

`security.scan_requested`, `scan_started`, `scan_completed`, `scan_partial`, `scan_failed`, `scan_cancelled`, `finding_opened` (new or reopened), `finding_confirmed`, `finding_status`, `finding_quarantined`, `finding_remediated`, `quarantine_restored`, `incident_opened`, `incident_status`, `incident_resolved`, `incident_closed`, `remediation_requested`, `remediation_refused`, `remediation_blocked`, `remediation_failed`.

Remediation entries carry the remediation, finding, incident and backup UUIDs (and the backup link). No entry contains credentials, tokens, passwords or file contents.

## Data transmitted

From the site to WP CareGrid, per finding: type, code, severity, confidence, title, optional description, component (`core`, `plugin:<slug>`, `theme:<slug>`, `uploads`, `option:<name>`, `user:<id>`, `cron:<hook>`, `config`), a path relative to the WordPress root, and evidence from an allowlist: `sha256`, `size`, `modified_at`, `permissions`, `rules`, `lines`, `matches`, `integrity`, `reference`, `version`, `plugin`, `theme`, `slug`, `directory`, `extension`, `file_count`, `option_name`, `post_ids`, `post_type`, `user_id`, `user_login`, `registered`, `roles`, `new_admin`, `hook`, `schedule`, `next_run`, `callbacks`, `indicators`, `setting`, `environment`, `managed`. Any other key is dropped by the server; the connector also passes evidence through its redactor.

Administrators (for the baseline): user ID, login, registration date, roles, whether it is a WP CareGrid managed account. No e-mail address, password or password hash.

Never transmitted: file contents, database values, post content, option values, `wp-config.php`, passwords, password hashes, API tokens, license keys, database credentials.

## Configuration

```
SECURITY_SCAN_TIME_BUDGET=60
SECURITY_SCAN_MAX_PARTS=20
SECURITY_SCAN_TIMEOUT_MINUTES=120
SECURITY_SCAN_FRESH_FOR_DAYS=35
SECURITY_VULNERABILITY_SOURCE=none            # or wordfence
SECURITY_VULNERABILITY_FEED_PATH=             # default storage/app/private/security/vulnerabilities.json
SECURITY_VULNERABILITY_FEED_URL=
SECURITY_VULNERABILITY_FEED_KEY=
SECURITY_VULNERABILITY_MAX_AGE_HOURS=72
SECURITY_SCHEDULE_ENABLED=false
SECURITY_INCIDENT_AUTO_OPEN=true
SECURITY_INCIDENT_AUTO_BACKUP=true
SECURITY_REMEDIATION_ENABLED=false            # keep false in production (SECURITY_INCIDENTS.md)
SECURITY_REMEDIATION_BACKUP_MAX_AGE=60
SECURITY_REMEDIATION_ALLOW_DISABLE_PLUGIN=false
```

Needs the scheduler (`security:monitor`, `security:schedule`, `security:vulnerabilities:sync`) and a current Connector on the sites; the advertised minimum/latest release is derived from `wordpress/wp-caregrid-connector/release.json`.

## Limitations

- Heuristic rules find known patterns and indicators, not every piece of malware; obfuscated code built to avoid them will be missed. There are no antivirus signatures and no machine learning.
- Integrity checking is only as wide as WordPress.org's reference: core and WordPress.org plugins. Premium plugins, custom code and themes have no official checksums and are "unavailable".
- Vulnerabilities need a configured feed; without one nothing is reported.
- JavaScript skimmers inside theme or plugin `.js` files are not scanned (only PHP-like files, `.htaccess`, SVGs and images in uploads).
- The database checks are conservative text indicators on options and published posts; they do not parse serialized data.
- New administrators are compared with the previous complete scan; the first scan only records the baseline.
- Scans on very large sites take several parts; a site that never reports back ends as `SCAN_TIMEOUT`.
