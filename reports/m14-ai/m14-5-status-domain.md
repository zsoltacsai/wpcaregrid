# M14.5 status domain

The five concepts are separate columns/read-model fields; do not infer any from another.

| Concept | M14.5 meaning | Allowed M14.5 values |
|---|---|---|
| `CONFIGURED` | Fixed provider has Owner-managed encrypted credential present | true / false; derived from credential row |
| `ENABLED` | Product AI feature may execute | always false; no feature grant field needed |
| `HEALTHY` | Latest explicit probe succeeded for the currently stored generation | informational observation; false/unknown until tested; never grants execution |
| `DEFAULT` | Provider selected for a feature | none / false; no default-provider setting |
| `AUTHORIZED_FOR_FEATURE` | Independently approved feature-purpose-data grant | empty / false; no grant is created |

Health result values: `NOT_CONFIGURED`, `UNKNOWN`, `HEALTHY`, `AUTH_FAILED`, `RATE_LIMITED`, `UNAVAILABLE`, `TIMEOUT`, `MISCONFIGURED`, `ERROR`. They are stable domain enums; localized customer-facing wording is not canonical data. `AUTH_FAILED` is emitted only for documented provider authentication rejection. 403/permission semantics that cannot distinguish key rejection from account policy remain `ERROR` or `MISCONFIGURED`, not overclaimed invalid-key diagnosis.

`HEALTHY` means only “the selected provider's metadata endpoint returned a bounded expected response for this key at checked_at.” It does not mean account paid, model available for inference, no provider incident, feature enabled, privacy approved, or data processing authorized. A result more than seven days old, a generation mismatch, decryption issue or missing status row is presented as stale/`UNKNOWN`. No background probe is scheduled.

Save/replace creates a new opaque UUID generation and resets health. Remove invalidates the generation, clears local configured state and health. Probe stores result only if generation matches. A stale result is discarded. The registry has no array-order priority; tests assert exactly three entries and no default. Optional AI state does not affect global application health, boot, queue, scheduler or unrelated integrations.

## Implementation evidence addendum — 2026-10-17

M14.5 executable work is merged and deployed to staging at `3debba99c677b68465cf439733f440442fbd0f22`. PR #102 exact CI 39017126211 and PR #103 exact CI 39020595623 each passed all 7 required jobs; merged-SHA run 39021792985 also passed all 7, including MySQL process/concurrency. The staging fake-provider transaction passed and was rolled back. There were no real provider credentials, real vendor calls, or customer-data egress. No AI feature/default/feature authorization exists. See [closure report](m14-5-closure-report.md) for full bounded evidence and limitations.
