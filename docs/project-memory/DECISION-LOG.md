# WP CareGrid — Decision Log (continuity index)

This is a **short index**, not a replacement for primary Owner records. Each entry must retain its source and whether it supersedes an older assumption. A decision about project memory never authorizes host activity.

| Date (Europe/Budapest) | Decision / constraint | Authority / provenance | Consequence |
|---|---|---|---|
| 2026-11-04 | First release is the full Laravel HU/EN marketing-only application; paid functionality stays inactive until separate gates. | [Master roadmap](../MASTER_ROADMAP_AND_INTEGRATION_PLAN.md), Owner release decision | Marketing-only is the supported baseline; not a production GO. |
| 2026-11-14 | Weekly dependency intake and protected release planning documented; production promotion remains dormant before the first approved launch. | [Weekly dependency release](../WEEKLY_DEPENDENCY_RELEASE.md) | A Sunday schedule is not permission to deploy. |
| 2026-11-19 | Despite its historical `stg1` hostname, the existing VPS is **future-production preparation**, not the separate staging environment. A new staging VPS will be provided later. | Latest Owner correction in conversation | **Supersedes** older ChatGPT assumptions and stale references describing the existing host as staging. |
| 2026-11-19 | GO-20.37 evidence review yielded independent HOLD outcomes; no authority to start GO-5.4. | [#174 comment 7019056088](https://github.com/zsoltacsai/wpcaregrid/issues/174#issuecomment-7019056088) | No implication that diagnostics PASS means runtime GO. |
| 2026-11-19 20:35 | GO-20.38 historical reconciliation and gated plan reviewed by Hagrid, Moody and Hermione, all **PASS_WITH_CONDITIONS** for the plan; production G1 still HOLD / GO-5.4 NO-GO. | [#174 comment 7020055888](https://github.com/zsoltacsai/wpcaregrid/issues/174#issuecomment-7020055888) | Plan only. No root/run/service permission created. |
| 2026-11-19 | Owner declared a previously repeated provider-specific infrastructure discussion closed for proactive ChatGPT/Elliot suggestions and prompts. | Explicit Owner conversation instruction, recorded in personal memory | Do not reopen without direct Owner request. **Does not itself change any independent execution gate.** |
| 2026-11-19 | Set up versioned continuity documents plus existing ChatGPT Project instructions, retaining #174 and protected-primary governance. | Explicit Owner agreement in conversation | Documentation-only work; no production changes. |

## How to record the next decision

Record: date/time, scope, exact Owner wording or link, whether this is a new authorization or just analysis, superseded assumptions, execution impact, related PR/issue, and any expiry. Prefer a dated PR update over editing historic rows in place; never alter past approval claims retroactively.

When a new Owner decision conflicts with an older repository control, flag both sources and require a dedicated reviewed reconciliation; a summary alone cannot waive an operational gate.
