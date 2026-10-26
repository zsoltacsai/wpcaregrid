# Customer backup browser QA

The deterministic browser suite runs the production `customer-backup-download.js` module in installed desktop Chrome against an in-process synthetic HTTP server. It uses synthetic grants and bytes only; it does not connect to Laravel, Stripe, S3, SFTP, staging, or production.

From the repository root, run:

```powershell
npm ci
npx playwright install chromium
npm run test:e2e:backup-download
```

The suite covers an actual user click, Blob download bytes, a successful first range followed by a partial second response whose stream fails, retry of only that second range on the same writer, picker cancellation without grant creation, cancel and fresh start, expired grant and valid resume, writer failure and fresh restart, failed revocation, malformed Content-Range, and object identity change. File System Access API behavior is represented by a deterministic browser-side API stub; these checks exercise the page lifecycle and production module but do not establish behavior of Chrome's native operating-system picker or its native file writer.

Each browser scenario writes a synthetic PNG screenshot under the ignored local directory `storage/e2e/backup-download/evidence/`. The test report includes assertions for byte contents, grant creation/revocation, range offsets and writer identity; the screenshots are supporting visual evidence, not substitutes for those assertions.

## Captured evidence for this PR

The following screenshots were captured by the passing headed-Chromium Playwright run using only a synthetic four-byte payload. The image files are committed so reviewers can inspect the rendered states with the PR:

| Scenario | Screenshot |
| --- | --- |
| Blob download complete | [blob-download-complete.png](evidence/customer-backup-download/blob-download-complete.png) |
| Partial second range interrupted | [partial-second-range-interrupted.png](evidence/customer-backup-download/partial-second-range-interrupted.png) |
| Partial second range retried | [partial-second-range-retried.png](evidence/customer-backup-download/partial-second-range-retried.png) |
| Picker cancellation (synthetic API stub) | [picker-cancelled-no-grant.png](evidence/customer-backup-download/picker-cancelled-no-grant.png) |
| Cancel, then fresh start | [cancel-fresh-start.png](evidence/customer-backup-download/cancel-fresh-start.png) |
| Expired grant resumed | [expired-grant-resumed.png](evidence/customer-backup-download/expired-grant-resumed.png) |
| Writer failure and restart | [writer-failure-restart.png](evidence/customer-backup-download/writer-failure-restart.png) |
| Revoke not confirmed | [revoke-not-confirmed.png](evidence/customer-backup-download/revoke-not-confirmed.png) |
| Revoke retried and download complete | [revoke-retried-and-download-complete.png](evidence/customer-backup-download/revoke-retried-and-download-complete.png) |
| Invalid range failed closed | [invalid-range-failed-closed.png](evidence/customer-backup-download/invalid-range-failed-closed.png) |
| Object identity change failed closed | [object-identity-change-failed-closed.png](evidence/customer-backup-download/object-identity-change-failed-closed.png) |

### Manual native-picker observation

On this Windows/Chrome session the real operating-system save picker opened from the page's Start click. Canceling it returned to the page with the localized “No save location selected” state and restored Start; no grant was requested by that gesture. A subsequent attempt to save to the dedicated worktree output path was rejected by the native dialog as a path it could not resolve, so no file was written and the native writer completion/byte verification remains **UNKNOWN**. The automated writer-stub coverage must not be represented as a successful native file write.

## Manual native-picker check (Chrome or Edge)

Use the manual local harness below, which serves a synthetic 4-byte backup. Do not use a real backup or storage service. In a headed Chrome or Edge window:

1. Click **Start** from the page (the picker must be opened from this user gesture).
2. Select a disposable local test file path, if the browser permits replacing/creating it.
3. Verify the browser's native picker closes, the page reports that it saved to the selected location, and the file bytes equal `00 01 02 03`.
4. Repeat and cancel the transfer; click **Start** again, select a new disposable path, and verify it begins at byte zero.
5. Record browser/version, OS, observed result, and a screenshot with no customer data or secrets.

For a headed manual test page, run `node scripts/e2e/customer-backup-download-manual-server.mjs` in a terminal and open `http://127.0.0.1:8765/` in Chrome or Edge. If that port is already occupied, PowerShell can run `$env:PORT='8766'; node scripts/e2e/customer-backup-download-manual-server.mjs` and you can use port 8766 instead. For the Cancel case use the corresponding `/?slow=1` URL; its first content response is delayed for five seconds so Cancel can be clicked. The server accepts only the two exact two-byte ranges for the synthetic four-byte fixture. Stop it with Ctrl+C after the test.

The automated Chromium suite does not claim this native picker check passed. Safari/iOS does not expose the File System Access API used by the direct-to-disk path; the Playwright suite's no-picker Blob route verifies the actual browser Blob download path in Chromium, not on Safari/WebKit. WebKit support remains unverified until the same synthetic suite is run with a WebKit browser installation/device.

## Acceptance evidence

| Requirement | Test / evidence | Result |
| --- | --- | --- |
| Blob fallback downloads exact bytes after a user click | `real browser Blob fallback completes only after a user click and saves exact synthetic bytes` | PASS |
| Partial second range is not written; retry requests only that range on the same writer | `completed first range plus partial second response retries only the incomplete range` | PASS |
| Picker cancellation returns to a usable Start state without a grant | Stub regression plus Windows Chrome native picker opened and canceled | PASS |
| Cancel / Start creates a fresh writer at offset zero | `Cancel then Start obtains a fresh writer and downloads from byte zero` | PASS |
| Expired grant resume preserves completed range and object identity | `expired grant is replaced and resumes after the completed range on the same writer` | PASS |
| Writer failure restarts from zero with a new writer | `writer failure forces a new picker and a byte-zero restart` | PASS |
| Failed revoke is never presented as success | `failed revoke is not shown as success and blocks a replacement grant until confirmed` | PASS |
| Invalid Content-Range and changed object identity fail closed | Two dedicated Playwright regression cases | PASS |
| Native Windows picker opens and cancel is handled | Manual Chrome session; no file was written | PASS for open/cancel; native file write **UNKNOWN** |
| Safari/WebKit Blob behavior | No Safari/WebKit runtime available in this QA session | **UNKNOWN** |
| Feature remains default-off and customer controls are eligibility-gated | `AccountBackupsTest::test_customer_backup_browser_shows_only_verified_objects_when_downloads_are_enabled` and default-off view regression | PASS |
| Feature limits, no public provider URL, no restore/retention change | Source review of the complete PR; limits remain 256 MiB disk / 16 MiB Blob; #191C excluded | PASS |
