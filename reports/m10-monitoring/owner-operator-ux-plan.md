# M10 Owner, Operator and customer UX plan

## Owner Console

Add **Infrastructure Health** as a platform-wide Owner area using the existing Owner guard, ability and audit conventions. It is distinct from **Owner → Infrastructure → Platform Backups**: link to that existing M9 page rather than cloning backup controls.

Overview cards:

- Application/runtime, database/migrations, queue/worker, scheduler, storage, mail delivery and provider status;
- last observation and freshness badge for every card;
- platform backup last created / uploaded / verified / disposable-import-verified states as separate milestones;
- active critical/attention incidents, age, shared-provider correlation, and recent resolutions;
- monitoring pipeline freshness, failed checks, and alert-delivery failures;
- declared maintenance and suppression state.

Views: active incidents, platform checks, website fleet, provider dependencies, recent transitions, and maintenance windows. Filters: scope, severity, state, reason, time, provider and tenant/site identity. Show evidence fields from the allowlist only. Infrastructure checks and global incidents are Owner-only.

Owner actions in v1: acknowledge/assign responsibility; declare bounded maintenance; configure approved bounded thresholds/cadence if product settings exist; manage alert recipients after authorization; audit changes. Avoid “resolve” buttons that override actual evidence. No production restore button, no provider mutation, no one-click remediation.

## Operator Console

Add a site incident list and website detail health timeline. Reuse current website filtering, security/backup source data and `operator.*` permissions. Enforce website/customer scoping in controllers and queries. Display Connector, WordPress, availability, TLS, backup and security dimensions, each with reason, last observation, age, and safe next action. Operator acknowledgement requires a specific permission; provider/internal platform details remain hidden.

## Customer area

No technical monitoring UI is required for M10 closure unless existing dashboard can consume the safe aggregate without exposing internals. Recommended future-facing status labels are Healthy, Attention required, Service unavailable, Backup issue, Security issue. Customer notifications remain disabled until product policy and copy are approved. Do not expose worker names, provider account/bucket, internal IP, exploit details, or tenant-wide incidents.

## Authorization and audit

- Owner routes require authenticated verified Owner plus named ability and throttling for changes.
- Operator routes use existing scoped website routes and granular permissions.
- Customer routes, if later added, scope only to the authenticated customer's sites.
- Every change to threshold, cadence, recipient, maintenance, acknowledgement or suppression is audited with actor, subject, prior/new non-secret values, and request correlation ID.
- Secret fields are never rendered or returned. Monitoring settings do not store provider credentials.

## Accessibility and operational affordances

State must use label + text + icon/color; display UTC in detail and localized time in UI; distinguish unknown, stale, unsupported, not configured and maintenance. An incident page must link to the source record (backup, security incident, Connector, queue check) without embedding raw payload. Lists are paginated and aggregate counts have a visible “as of” timestamp.

