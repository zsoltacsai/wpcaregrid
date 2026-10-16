# M14.1 Authentication, Session and Re-authentication Plan

## Observed source controls

- `config/auth.php` defines separate `web`, `owner`, `operator` session guards using the `users` provider.
- Customer login normalizes email and regenerates session after success; logout logs out the web guard, invalidates session and rotates CSRF token.
- Owner/Operator role middleware clears invalid sessions; activation regenerates session after first password and login.
- `config/session.php` defaults to database sessions, 120-minute idle lifetime, `HttpOnly=true`, `SameSite=lax`, and optional encryption; secure cookie is environment/URL-configured. Verify staging’s actual effective values read-only during implementation.
- Password reset validates emailed token and changes password plus `remember_token`; no app use of `AuthenticateSession` or `logoutOtherDevices` was found. Reset-session revocation is therefore an unresolved test requirement, not yet a confirmed bypass.
- Reset endpoint returns a generic status; login, reset email, reset, activation and staff activation have rate limit middleware.

## Required test plan

1. Login session fixation: old session ID cannot access authenticated route after success for each guard.
2. Logout: current and remembered session are rejected; CSRF token rotates.
3. Password reset: before reset establish sessions for all applicable guards; reset using valid token; prove every session becomes unauthorized and remember credentials are invalidated. Repeat for password activation and staff invitation where applicable. Test concurrent/reused token and MySQL race.
4. Role/permission change: stale Owner/Operator session loses access on next request; no customer/Owner/Operator guard crossover.
5. Recent auth: exact sensitive actions accept fresh confirmation and reject missing, expired, replayed, other-guard or other-session evidence. Include privacy approval/circuit-breaker reset and credential/policy actions.
6. Session lifetime: distinguish configured idle expiration from an absolute lifetime; Owner decision required only if a product-risk policy is not already approved. Do not invent a timeout in this planning pass.
7. CSRF: all state-changing browser routes require valid CSRF; only signed Stripe webhook is exempt; API/Connector authentication remains independent.

## Implementation constraint

Prefer framework-standard session invalidation/confirmation behavior. If reset tests prove old sessions remain usable, implement a narrowly scoped guard-compatible revocation mechanism and regression tests in the fix PR. Avoid forcing authentication on all normal navigation. No secrets, reset tokens or raw passwords in audit/log output.
