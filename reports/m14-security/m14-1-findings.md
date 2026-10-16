# M14.1 Planning Findings

**Review disposition:** no confirmed Critical or exploitable High issue established by this source-only pass. This is not a clean security certification. Runtime and adversarial tests were not run.

| ID | Severity / state | Evidence | Impact / attack path | Required action |
|---|---|---|---|---|
| F-14.1-01 | P0 validation gap; exploitability unconfirmed | `app/Http/Controllers/Auth/PasswordResetController.php` rotates password and `remember_token`; source search found no application `AuthenticateSession`/`logoutOtherDevices` usage; separate guards share the users provider | A pre-existing authenticated session may remain valid after reset if guard/session provider does not invalidate it. An attacker holding an existing session could retain access after the user resets their password. | Add cross-guard stale-session regression using real database sessions and framework behavior. If any old session still authenticates, treat as a security defect and fix before M14.1 closure. |
| F-14.1-02 | P1 validation gap; not a confirmed tenant defect | `app/Jobs/TransferBackup.php` serializes integer backup ID and reloads `Backup::find()` | Queue boundary relies on current row/relations; a stale/reparented/deleted record must not cause work against another customer’s object or unsafe storage key. | MySQL/process tests for source-parent change, object relation, retries and deletion; add execution-time ownership/immutability guard only if proof exposes a defect. |
| F-14.1-03 | P1 deployment evidence gap | `config/cache.php` defaults to database; effective environment and node topology not observed in this source review | Per-process cache or mismatched stores could make rate limits inconsistent across workers/nodes. | Read-only staging config review and two-process shared-counter test; no credentials in report. |
| F-14.1-04 | P1 inventory gap | Expanded runtime route:list unavailable because local PHP CLI absent; source declarations total 235 | A route, middleware expansion or command may be omitted from static hand-built audit. | During implementation generate expanded route/command inventory and reconcile every state-changing route. |

## Finding classification rules

F-14.1-01 is deliberately not labeled “confirmed High” until stale-session behavior is tested against the actual framework/provider configuration. A reproducible session that survives password reset becomes a concrete high-impact finding requiring root-cause remediation. No production/staging exploit was attempted.
