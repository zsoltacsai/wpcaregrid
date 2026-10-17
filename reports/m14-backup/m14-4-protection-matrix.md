# M14.4 backup protection matrix

**Implementation evidence:** additive hold reasons are represented in the GFS shadow evaluator and source fingerprint; Owner extension-only changes are applied through PR #99 (`29bdd9489eafd6581d9d5b9bc5d7a12fe26b4209`). Feature tests cover multiple simultaneous reasons and stale previews. No reason or Owner policy can invoke deletion.

Protection is additive and reasoned; no boolean-only `protected` field is authoritative. A point with any active reason is not an eligibility candidate. Releasing one reason never removes another.

| Reason | Source / scope | Release condition | Owner removable? | Customer removable? | Legal/accounting dependency | Eligibility effect |
|---|---|---|---|---|---|---|
| `GFS_DAILY` | Effective entitlement slot | No longer selected after deterministic recompute | No | No | No | KEEP |
| `GFS_WEEKLY` | Effective entitlement slot | Same | No | No | No | KEEP |
| `GFS_MONTHLY` | Effective entitlement slot | Same | No | No | No | KEEP |
| `SAFETY_BACKUP` | Linked pre-update/pre-remediation/recovery operation | Owning workflow safely completes and confirms no rollback dependency | No direct removal | No | No | KEEP until release, then recompute |
| `LAST_KNOWN_GOOD` | Newest verified usable same-site point | A verified usable replacement is established | No | No | No | KEEP; never leave site without one |
| `ACTIVE_RESTORE` | Restore/rollback/compensation relationship | Workflow reaches terminal safe state and no recovery reference remains | No | No | No | KEEP |
| `UPDATE_DEPENDENCY` | Update/compensation job | Job terminal and dependency released | No | No | No | KEEP |
| `INCIDENT_HOLD` | Security/operational incident | Authorized incident resolution and audit | Not without incident workflow | No | Possible external hold | BLOCKED |
| `OWNER_EXTENSION` | Approved extension above paid floor | Recent-authenticated impact preview confirmation removes extension reason | Yes, extension only | No | No | Removing may create ELIGIBLE after full recompute |
| `LEGAL_HOLD` | Approved legal policy/evidence | Legal authority releases hold | No engineering override | No | `WAIT_LEGAL` | BLOCKED |
| `ACCOUNTING_HOLD` | Approved accounting policy/evidence | Accounting authority releases hold | No engineering override | No | `WAIT_ACCOUNTING` | BLOCKED |
| `POST_SERVICE_POLICY_PENDING` | Canceled/offboarded site lifecycle | Approved legal/accounting post-service rule and decision | No | No | `WAIT_LEGAL` + `WAIT_ACCOUNTING` | BLOCKED |
| `PROVIDER_STATE_UNKNOWN` | Missing provider/version/delete-state evidence | Reconciliation establishes bounded provider state | No | No | `WAIT_FACT` | UNKNOWN/BLOCKED; never executable |
| `POLICY_UNKNOWN` | Missing/ambiguous entitlement mapping/version | Authorized mapping review resolves it | No | No | Possible | UNKNOWN; not eligible |

Owner may add `OWNER_EXTENSION`; the effective protection must be at least the purchased floor plus the system safety floor. Owner cannot clear GFS, active operation, last-good, incident, legal/accounting or provider uncertainty. Customer has no retention-reduction control in M14.4. Safety backups are a separate workflow class and do not consume routine daily/weekly/monthly slots.

Eligibility candidate rules: no applicable protection reason remains; mapping, current/effective policy, verification/object state, dependencies and all required safety facts are known; preview/evaluation is fresh. `ELIGIBLE` is still only a report/preview status. M14.4 never queues cleanup or calls provider deletion. Existing `BackupDeletion` is a separate effect boundary and must not be fed by shadow evaluator output.
