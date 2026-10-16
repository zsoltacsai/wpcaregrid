# M14 RBAC, Authentication and Re-authentication Audit

**Status:** source foundations present; end-to-end authorization review remains `PARTIAL`.

## Roles and authority

| Role | View | Request | Approve | Execute | Override | See secrets | Impersonate |
|---|---|---|---|---|---|---|---|
| Customer | own account/site/billing/privacy data via customer policy | own checkout, backup, restore, access, privacy and billing actions | only customer confirmations defined by workflow | only customer-scoped, gated actions | none over legal/provider/system policy | no; own credentials only through explicit secure flow | no |
| Owner | business/admin data allowed by `owner.*` abilities | Owner settings, staff, policy/config and sensitive actions | explicit Owner approvals such as Wave 7 exact-preview approval | only when action-specific server authorization and global/action gates permit | no bypass of external legal/accounting/provider gates; no casual global toggle | secret values should be write-only/masked; inspect every integrations/settings view | no default impersonation path found in route inventory; verify all support tools |
| Operator | website/security/operations data granted by `operator.*` permission | permitted operational tasks | workflow-specific operator decision only | backup/update/scan/remediation/admin actions only with explicit permission and guards | cannot alter Owner-only pricing/config/legal/privacy gate by default | no provider/APP_KEY/Stripe secret visibility | no |
| System/job | minimum execution identity and current object scope | internal workflow only | no human approval | rechecks authorization, tenant, gate and idempotency immediately before effect | no | only injected credential required by that job | no |

Owner is not Operator by default, and neither should be treated as a universal break-glass role. Customer login is separate from Owner and Operator guards.

## Observed authentication controls

- `routes/web.php`, `routes/owner.php`, `routes/operator.php` define separate guards; login routes use the `login` throttle.
- Customer, Owner and Operator login controllers regenerate sessions; logout invalidates and regenerates CSRF token. `EnsureOwner`/`EnsureOperator` clear invalid-role sessions.
- `config/session.php`: database driver default, 120-minute idle lifetime, close-expiry false, secure cookie derived from HTTPS APP_URL, HttpOnly true, SameSite `lax`, encrypted sessions optional/off by default, JSON serialization.
- Customer password reset and activation routes are throttled. Existing authentication tests cover invalid and reused reset/activation tokens.
- Current-password confirmation is used for privacy export and execution-plan approval; circuit-breaker reset requires password confirmation.

## Unverified M14 checks / gaps

| Control | Current state | Required evidence |
|---|---|---|
| Password reset token invalidation and one-time use | useful feature tests exist | cover concurrent token use, password change invalidating other sessions/tokens, generic response and delivery abuse |
| Session fixation/regeneration | sign-in regeneration exists | assert all guards, activation and role transition regenerate IDs and old IDs fail |
| Idle vs absolute lifetime | idle default 120 minutes; no absolute limit evidenced in source config | determine pilot risk and infrastructure/session-store support before choosing UX-impacting policy |
| High-risk re-auth | explicit for privacy export/approval; candidate operations vary | inventory restore, admin access, credentials, policy activation, provider/AI changes; use framework confirm-password mechanism where justified |
| CSRF | only Stripe webhook exclusion is declared | prove all state-changing web routes use `web` CSRF and no alternate exemptions |
| Authorization completeness | explicit route abilities and route contract tests exist | direct service, job, command and API negative matrix, including permission revoked after queueing |
| Owner/Operator secret view | encrypted storage and masked-display docs exist | dynamic UI/response/HTML/log assertions for all credentials and exception paths |
| Concurrent sensitive actions | route throttles and transaction locks in many workflows | stale tab, double-submit, revocation and role changes under MySQL concurrency gate |

## Risk-based re-authentication proposal

Require recent password confirmation for high-impact Owner changes: secret replacement/removal, role/permission grants, irreversible execution approvals, restore/recovery mutation, billing identity or payout-sensitive changes if introduced, backup-policy activation, and future AI data-use authorization. Do not prompt for ordinary read-only pages, every navigation, or low-risk preferences. Re-auth evidence should be session-bound, short-lived under an explicit policy, invalidated on password/session change, and audited without password/token content.

## M14 acceptance

Complete role/action matrix; no Owner/Operator crossover; no customer admin access by implication; all state-changing web routes CSRF-protected; secrets never returned after save; reset/session/reauth tests pass on MySQL concurrency path; missing role or recent-auth fails closed.
