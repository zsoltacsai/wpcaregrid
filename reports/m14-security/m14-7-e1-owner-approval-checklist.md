# M147-E.2 Owner Approval Checklist

**Current state: NOT AUTHORIZED / NOT EXECUTED.** M147-E.1 authorizes offline planning only. Completing or signing this checklist in the future does not retroactively authorize work; the Owner must provide explicit Level 3 approval for the named operation set. No staging connection or M12 read has occurred. All six live M12 gates remain **UNKNOWN**.

## A. Exact scope to approve

| Approval item | Owner decision / evidence | Required before GO |
|---|---|---|
| Staging identity | Host/environment identifier: ____________________ | Independently verified as staging, not production. |
| Laravel release | Exact 40-character SHA: ____________________ | Matches verified runtime receipt/HEAD and Owner-approved release. |
| WordPress target | Synthetic tenant/site identifier and host: ____________________ | Disposable, isolated, contains no customer data, approved explicitly. |
| Connector artifact | Version / source SHA / package SHA-256: ____________________ | Exact fingerprint and API/capabilities match. Pairing is only to approved site. |
| Operation IDs | E2-00 through E2-09 subset: ____________________ | Each operation and its side effects individually approved; no blanket authorization. |
| Window | Start/end UTC: ____________________ | Named operator and stop authority are available. |
| Evidence custodian | Name/role: ____________________ | Storage and redaction method agreed. |
| Cleanup owner/verifier | Owner: __________ Verifier: __________ | Scoped cleanup selector and independent readback agreed. |
| Recovery contact/plan | ____________________ | Fresh recovery evidence and action-specific escalation available. |
| Level 3 authorization reference | ____________________ | Written approval identifies exact targets, SHA, Connector fingerprint, operations, window, cleanup and stop criteria. |

## B. Readiness evidence required before the window

Check only after independently obtaining fresh evidence. Do not copy secrets or full environment output into this record.

- [ ] Staging application identity verified through approved, read-only method.
- [ ] Exact Laravel SHA and current release receipt verified.
- [ ] Synthetic WordPress tenant/site identity and ownership proven; customer/production links excluded.
- [ ] WordPress version, PHP runtime, DB engine/version and site filesystem limits recorded.
- [ ] Connector version, API, capabilities, package hash/source revision, pairing and revocation state verified.
- [ ] All six M12 values are freshly **false**; record boolean only, UTC, environment and source SHA.
- [ ] Scanner source and coverage known. If Wordfence is needed: legal/feed approval, host/IP/redirect policy and freshness proven without revealing key.
- [ ] Effective `SECURITY_SCHEDULE_ENABLED`, scan budget/parts, incident auto-open/backup, remediation and plugin-deactivation settings reviewed.
- [ ] Queue depth/reservations, active scans, worker state and scheduler state are known; no conflicting work exists.
- [ ] Incident notification routes cannot reach customers, or the operation is specifically approved with bounded recipients.
- [ ] Backup destination is identified as staging-only; bucket/prefix, permissions, capacity, and any object-write approval are explicit.
- [ ] Disk space and CPU/time/memory limits meet the planned scan/backup workload.
- [ ] Egress destinations and network policy are known; no unapproved endpoint or customer Connector is reachable.
- [ ] Fresh backup/recovery evidence is available for the action. Backup existence is not called restore proof.
- [ ] Cleanup IDs/markers are unique, scoped, dry-run/countable, and do not depend on destructive retention or broad deletion.
- [ ] Evidence template is secret-safe and does not capture paths/content, addresses, payloads or raw logs unnecessarily.
- [ ] Stop conditions, emergency contact and safe behavior on cleanup failure are rehearsed on paper.

## C. Six M12 gates — current live state

Source mapping is defined in `config/privacy.php`. Safe state for all six is false. **No live value was read during M147-E.1.**

| Gate | Current live value | Future required result | Evidence time/operator |
|---|---|---|---|
| `M12_DESTRUCTIVE_RETENTION_ENABLED` | UNKNOWN | false | Not collected |
| `M12_PROFILE_ANONYMIZATION_ENABLED` | UNKNOWN | false | Not collected |
| `M12_BACKUP_DELETION_ENABLED` | UNKNOWN | false | Not collected |
| `M12_PROVIDER_DELETION_ENABLED` | UNKNOWN | false | Not collected |
| `M12_BULK_RETENTION_EXECUTION_ENABLED` | UNKNOWN | false | Not collected |
| `M12_SYNTHETIC_PROFILE_ANONYMIZATION_ENABLED` | UNKNOWN | false | Not collected |

Any true, unknown, unreadable, stale, wrong-environment or ambiguous value means **NO-GO**. No gate may be changed to make a test pass.

## D. Stop / abort acknowledgements

The operator will stop before any operation if a host/SHA/Connector mismatch appears; a customer or production identity is discovered; a gate is not false; queue state is ambiguous; the feed is unavailable when required; a real destination or notification may be reached; scan results could trigger an unapproved incident/backup; remediation or quarantine becomes reachable; resources exceed bounds; a secret is exposed; or cleanup cannot be scoped. Stop does not authorize rollback, restore or broader cleanup. Use only the separately approved recovery action.

## E. Owner decision record (future)

| Decision | Selection | Date / approver | Notes |
|---|---|---|---|
| Proceed with named E.2 operation set | APPROVE / DEFER / REJECT | — | Must cite exact operation IDs and evidence above. |
| Approve staging incident side effects | APPROVE / NOT APPROVED | — | Separate from approving scan itself. |
| Approve real backup object writes | APPROVE / NOT APPROVED | — | State destination/prefix, retention and cleanup. |
| Approve live Wordfence feed use | APPROVE / DEFER | — | Requires separate legal/access/provenance decision. |
| Approve any configuration adjustment | APPROVE / NOT APPROVED | — | Must not alter M12 gates; identify exact key, previous value handling and restoration. |
| Approve cleanup/deletion | APPROVE / NOT APPROVED | — | Exact synthetic identifiers only; never infer deletion from test approval. |

**M147-E.1 outcome:** this is a prepared approval form only. It is unsigned, does not authorize staging, and leaves M147-E operational validation NOT STARTED.
