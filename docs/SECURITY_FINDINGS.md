# Security findings

What a finding is, how severity and confidence differ, how repeated scans are correlated, and who may change a finding's status. Overview: [SECURITY_ENGINE.md](SECURITY_ENGINE.md).

## Fields

`security_findings` (model `App\Models\SecurityFinding`):

| Field | Meaning |
| --- | --- |
| `uuid` | public identifier |
| `website_id` | the website; a finding never belongs to more than one |
| `first_scan_id`, `last_scan_id` | the scan that first saw it and the last scan that saw it |
| `type` | `vulnerability`, `integrity`, `suspicious_file`, `suspicious_database`, `suspicious_user`, `suspicious_cron`, `configuration` |
| `code` | what was found, e.g. `CORE_FILE_MODIFIED`, `PHP_REQUEST_EXECUTION`, `VULN_…` |
| `severity` | `low`, `medium`, `high`, `critical`, or empty ("Not rated") when a vulnerability source gives none |
| `confidence` | `low`, `medium`, `high`, `confirmed` |
| `status` | see the state machine below |
| `title`, `description` | plain text (see "Text") |
| `component` | `core`, `plugin:<slug>`, `theme:<slug>`, `uploads`, `wp-content`, `mu-plugins`, `option:<name>`, `user:<id>`, `cron:<hook>`, `config`, `posts` |
| `path` | relative to the WordPress root, for file findings |
| `evidence` | metadata from an allowlist (SECURITY_ENGINE.md, "Data transmitted"): hashes, sizes, dates, rule codes, line numbers, versions, IDs. Never file contents or database values |
| `occurrence_count` | scans that saw it |
| `first_seen_at`, `last_seen_at`, `resolved_at` | times |
| `status_changed_at`, `status_changed_by_user_id`, `status_note` | the last status change, by whom, and the operator's note |

Confirmed malware is not a separate type: it is a `suspicious_file` an operator confirmed (status `confirmed`, confidence `confirmed`), `SecurityFinding::isConfirmedMalware()`. Changing the type would change the finding's identity.

## Severity and confidence

They answer different questions and are stored separately:

- **Severity**: how bad it would be if it is what it looks like. From the rule (connector), the check (integrity, configuration) or the vulnerability source. A vulnerability with no rating in the source has no severity.
- **Confidence**: how sure the engine is that it is what it looks like.

| Confidence | Set by |
| --- | --- |
| low | a weak indicator on its own (obfuscation, a custom cron hook with a random name, a new administrator) |
| medium | a specific indicator (`eval()` of decoded data, PHP in uploads) or integrity evidence that is not conclusive (extra files) |
| high | a strong indicator (request data executed, webshell marker), combined indicators, an indicator in a file that also fails integrity, a checksum mismatch, a version inside a published vulnerable range |
| confirmed | **only** an operator with `security.manage`, by confirming the finding |

Scanners can never report `confirmed`: the server refuses such a result (`INVALID_RESULT`), and a later scan never lowers an operator's confirmation. How the connector combines rule confidences: MALWARE_SCANNING.md.

## Identity and deduplication

A finding is identified per website by a **fingerprint**: SHA-1 of type, code, component and path, except a suspicious file, which is identified by its **path** alone (its leading rule may change with a new rule set; it is still the same file). There is a unique index on (website, fingerprint), so a repeated scan never creates a duplicate.

When a scan reports a finding again (`FindingRecorder`):

- `last_seen_at`, `last_scan_id`, evidence, severity, title, code and component are updated; `occurrence_count` goes up by one (not twice for two parts of the same scan);
- confidence follows the scanner, except that `confirmed` is kept.

Resolution: when a scan **completes** a scanner (integrity, malware or vulnerability), the open and confirmed findings of that scanner's types that it did not see become `resolved` (`resolved_at` set). They are not deleted. A partial or failed scanner resolves nothing.

## State machine

Operator decisions (`FindingStatus::operatorTransitions()`, the only statuses anyone sets by hand, through `FindingDecisions` with `security.manage`):

```
open        → confirmed | false_positive | ignored
confirmed   → open (un-confirm) | false_positive | ignored
false_positive | ignored → open (reopen)
quarantined, remediated, resolved → (nothing by hand)
```

Set by the engine only:

```
confirmed → quarantined                        a completed quarantine
open | confirmed → remediated                  a completed repair or deactivation
quarantined → open                             the quarantine was undone: the file is back
open | confirmed → resolved                    a complete scan no longer sees it
resolved | quarantined | remediated → open     seen again by a scan
false_positive | ignored → open                seen again with a different file hash
```

Anything else is refused with `INVALID_TRANSITION`: an operator can never set `quarantined`, `remediated` or `resolved`, and controller input cannot jump states (the console validates the requested status and the service checks the transition). Customers have no way to change a finding.

Confirming sets confidence to `confirmed`; un-confirming (back to open, or to false positive/ignored) sets it to `high`.

A **false positive** or **ignored** finding stays so while a scan keeps seeing the same thing; if the file's SHA-256 changes it is reopened ("Reopened: the file changed."), because someone needs to look again. Such findings are never resolved by a scan that does not see them.

## New administrators

The first complete malware scan of a website records its administrators as the baseline (`summary.admins`, `summary.baseline = established`) without findings. Later scans report every administrator that was not an administrator at the previous complete scan as `NEW_ADMIN_ACCOUNT` (suspicious_user, high severity, **low** confidence: it may be legitimate), with user ID, login, registration date and roles. WP CareGrid's managed accounts are excluded. A flagged account stays flagged on later scans (being there at the previous scan does not make it known) until an operator marks the finding false positive or ignored.

## Text

Titles, descriptions and evidence values come from scanners and feeds and are untrusted. They are stored as **plain text** (`App\Security\PlainText`: control characters removed, whitespace collapsed, length capped; `<` and `>` are kept, so "Akismet <= 5.3" stays exactly that) and **escaped on output** by Blade. They are never passed through `strip_tags()` and never rendered as HTML. Tests check both that "Akismet <= 5.3" is shown intact and that HTML in a title is shown escaped, not executed.

## Audit

`security.finding_opened` (new, or reopened with `reopened: true`), `security.finding_confirmed`, `security.finding_status` (other operator decisions, with from/to), `security.finding_quarantined`, `security.finding_remediated`, `security.quarantine_restored`. Operator decisions are also written to the timeline of the finding's open incidents.
