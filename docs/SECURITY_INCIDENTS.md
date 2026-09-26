# Security incidents and remediation

How serious findings are handled: incidents and their workflow, controlled remediation behind a verified full backup, reversible quarantine, and investigation access through the existing admin access. Overview: [SECURITY_ENGINE.md](SECURITY_ENGINE.md); on the site: [MALWARE_SCANNING.md](MALWARE_SCANNING.md).

## Workflow

```
DETECTION ─▶ INCIDENT ─▶ VERIFIED FULL BACKUP ─▶ INVESTIGATION ─▶ (SECURE ADMIN ACCESS)
   ─▶ QUARANTINE ─▶ REMEDIATION ─▶ RESCAN ─▶ HEALTH CHECK ─▶ FUNCTIONAL CHECK ─▶ RESOLVED
```

Automatic: opening the incident and starting the backup. Everything that changes a site is asked for by an operator and waits for a verified full backup. Nothing destructive happens because a scan found something.

## Incidents

`security_incidents` (model `SecurityIncident`): UUID, website, status, severity (optional), title, summary, who opened it (actor type and user), `resolved_at`, `closed_at`. Linked findings (`security_incident_finding`, same website only) and a timeline (`security_incident_events`: opened, status, note, finding, scan, backup, admin_access, remediation; each with actor and data).

Related records are read from their own tables, not copied: the incident page shows the website's backups and admin access requests since the incident was opened, and scans and remediations linked to it.

### Opening

- By an operator with `security.manage` (console: tick findings, "Open incident").
- Automatically (`SECURITY_INCIDENT_AUTO_OPEN`, on by default) when a scan sees a suspicious file with **high** confidence and **high or critical** severity: added to the website's open incident, or a new one ("Suspicious files detected", critical when any such file is critical). With `SECURITY_INCIDENT_AUTO_BACKUP` (on by default) a verified full backup is started through `BackupService` for the investigation and recorded on the timeline. Weak indicators, integrity changes and vulnerabilities do not open incidents by themselves.

### State machine

```
open          → investigating | containment | closed
investigating → containment | remediation | verification | closed
containment   → remediation | verification | investigating
remediation   → verification | investigating
verification  → resolved | remediation | investigating
resolved      → closed | investigating   (reopening)
closed        → nothing
```

Everything else is refused (`INVALID_TRANSITION`): for example closed → remediation, resolved → containment, open → resolved. The only way back from resolved is **reopening into investigation**, which clears `resolved_at`. A closed incident cannot be reopened; a new one is opened instead.

### Resolving

`verification → resolved` requires, in this order (`IncidentService::resolutionRefusal`):

1. `RESCAN_REQUIRED`: a completed or partial scan **requested after** the incident was opened, or after its last completed remediation (not the scan that raised it);
2. `FINDINGS_STILL_OPEN`: none of the incident's findings with high or confirmed confidence is still open or confirmed;
3. `HEALTH_CHECK_REQUIRED`: the site reported its health after that point;
4. `FUNCTIONAL_CHECK_REQUIRED`: the operator confirms that the site works (front page, login, checkout or forms), recorded on the timeline.

### Effect on security posture

An open incident keeps security at least **attention**; an open **critical** incident keeps it **critical**, even if its findings have been dealt with, until the incident is resolved through the steps above. Once resolved, security returns to whatever the findings and the latest scan say: healthy only if no other critical condition remains and a scanner completed recently.

Audit: `security.incident_opened`, `security.incident_status`, `security.incident_resolved`, `security.incident_closed`.

## Remediation

Types (`RemediationType`), each one fixed connector job:

| Type | Job | For | Needs a backup |
| --- | --- | --- | --- |
| `quarantine_file` | `SECURITY_QUARANTINE` | a suspicious file an operator **confirmed** | yes |
| `repair_core_file` | `SECURITY_REPAIR_FILE` | `CORE_FILE_MODIFIED` / `CORE_FILE_MISSING` | yes |
| `repair_plugin_file` | `SECURITY_REPAIR_FILE` | `PLUGIN_FILE_MODIFIED` / `PLUGIN_FILE_MISSING` of a WordPress.org plugin | yes |
| `disable_plugin` | `SECURITY_DISABLE_PLUGIN` | a finding in an installed plugin, only with `SECURITY_REMEDIATION_ALLOW_DISABLE_PLUGIN` | yes |
| `restore_quarantined_file` | `SECURITY_RESTORE_QUARANTINE` | undoing a quarantine | no |

There is no replacement from a URL, no uploaded content, no arbitrary file deletion. A vulnerable plugin is never disabled automatically.

### Who and when

`RemediationService::request()` refuses, before anything is created or backed up:

| Code | When |
| --- | --- |
| `NOT_AUTHORIZED` | the user is not an operator with `security.remediate` (checked in the service, not only on the route); or an incident of another website was given |
| `REMEDIATION_DISABLED` | `SECURITY_REMEDIATION_ENABLED` is off (default). Undoing a quarantine stays possible |
| `DISABLE_PLUGIN_NOT_ALLOWED` | plugin deactivation is not allowed by policy |
| `FINDING_NOT_CONFIRMED` | quarantine of a finding an operator has not confirmed |
| `NOT_A_FILE_FINDING`, `NOT_REPAIRABLE`, `NOT_A_PLUGIN_FINDING`, `NOT_QUARANTINED` | the type does not fit the finding |
| `REMEDIATION_IN_PROGRESS` | a remediation for this finding is waiting or running |
| `SUBSCRIPTION_INACTIVE`, `NOT_CONNECTED`, `CONNECTOR_OFFLINE`, `CAPABILITY_MISSING` | the plan, the connector or its presence does not allow it |

The console also requires an explicit confirmation checkbox; its routes are scoped to the website (a finding or quarantine of another website is a 404) and rate limited (`operator-remediate`: 3/min per operator, 10/hour per website). `security.remediate` is not part of the standard operator permission set.

### Verified FULL backup first

```
request ─▶ recent verified FULL backup (≤ SECURITY_REMEDIATION_BACKUP_MAX_AGE, 60 min)?
             yes ─▶ job queued
             no  ─▶ BackupService: FULL backup, reason "recovery" ─▶ remediation "awaiting_backup"
                      backup verified          ─▶ job queued
                      backup failed / corrupt  ─▶ remediation "blocked", BACKUP_REQUIRED_FAILED
                      backup cannot start      ─▶ remediation "blocked", BACKUP_REQUIRED_FAILED
```

No destructive job exists before the backup is verified, and none is ever created when it is not. The backup is made by the existing `BackupService` (no second backup path) and is the one a restore would use (`RestoreService`, with its own safety backup, connector checks and post-restore health checks, unchanged).

### Lifecycle and record

`RemediationStatus`: `awaiting_backup → queued → running → completed | failed`, and `awaiting_backup → blocked`.

Each remediation records the finding, the incident (the finding's open incident unless one is given), the backup, the actor (type and user), the job, **before** (path, SHA-256, size, version, finding status) and **after** (as reported by the site), an error code, and the quarantine record it created. On completion the finding becomes `quarantined` (quarantine), `remediated` (repair, deactivation) or `open` again (quarantine undone). A failed change leaves the finding's status as it was.

Idempotent: a second request while one is active is refused; a completed quarantine leaves the finding `quarantined`, so it cannot be quarantined again; a result reported twice is ignored; and the site refuses a second job with the same remediation UUID (`REMEDIATION_ALREADY_APPLIED`). Every job carries the file's SHA-256 as reported, and the site refuses to act if the file changed (`FILE_CHANGED`).

Audit (with remediation, finding, incident and backup UUIDs, and the backup linked): `security.remediation_requested` (operator), `remediation_refused`, `remediation_blocked`, `remediation_failed`, `finding_quarantined`, `finding_remediated`, `quarantine_restored`.

## Quarantine

Central record (`security_quarantines`): website, finding, remediation, original path, SHA-256, size, original modification time, original permissions, reason (finding code and title), status (`quarantined`, `restored`), quarantine and restore times, who restored it. The file itself stays on the site in the connector's non-executable quarantine directory (MALWARE_SCANNING.md).

Undo ("Undo quarantine", `security.remediate`, confirmation) puts the file back exactly where it was; it never overwrites a file that exists there now (`QUARANTINE_TARGET_EXISTS`), and a failed undo leaves the quarantine as it was.

## Admin access for an investigation

"Open Admin" on an incident uses the existing `AdminAccessService` at **maintenance** level (`admin_access.request`), is recorded on the incident timeline and audited as an operator request. There is no other login mechanism. Temporary full administrator access is not offered from an incident; operators may only request it through the existing admin access page, with `admin_access.full` **and** `ADMIN_ACCESS_OPERATOR_FULL_ADMIN=true` (off by default: full admin stays customer-only). See ADMIN_ACCESS.md.

## Before remediation is enabled in production

`SECURITY_REMEDIATION_ENABLED` must stay **false** in production until:

1. Remediation has been run end to end against a staging copy of real customer sites (quarantine, undo, core repair, plugin repair, deactivation), with the connector 0.4.0 package that will be shipped.
2. Restoring the pre-remediation backup through the normal restore flow has been rehearsed on staging, including the post-restore health checks.
3. The vulnerability feed decision is made and `security:vulnerabilities:sync` runs with a licensed feed, or vulnerability scanning is documented to customers as not included.
4. Rules have been run across a representative set of real sites with `SECURITY_REMEDIATION_ENABLED=false` and every medium/high finding reviewed; false positives fixed in the rule set.
5. Operator procedures are written: who may hold `security.remediate`, when a customer is contacted, when full admin (customer-confirmed) is used.
6. `WP_CAREGRID_QUARANTINE_DIR` is set outside the web root on hosts that support it, and quarantine storage is monitored.
7. Customer terms cover changes made to their site during an incident.

Even then, remediation remains operator-initiated and one finding at a time; there is no automatic or bulk remediation.
