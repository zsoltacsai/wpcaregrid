# M13 Search Intent Map

This is a page architecture map, not a keyword-volume forecast. Query demand and Search Console data were not available in this source audit.

| Cluster | Example queries | Intent | Preferred page | Current coverage | Recommendation |
|---|---|---|---|---|---|
| Brand | WP CareGrid | navigational | localized home | home | protect brand/entity consistency |
| General WordPress maintenance | WordPress maintenance; managed WordPress maintenance; WordPress-karbantartás; WordPress karbantartás szolgáltatás | commercial/informational | home | direct home hero and maintenance sections | primary top-level topic; avoid duplicate generic pages |
| Managed operations platform | managed WordPress operations; WordPress weboldal felügyelet | commercial | home, with “operations platform” explanation | partially represented; supplied positioning exceeds explicit page taxonomy | explain current workflow (observe/manage/verify) without claiming autonomous AI |
| Backup service | WordPress backup service; WordPress biztonsági mentés; WooCommerce mentés | commercial | home backup section; future dedicated page only if substantive | home, pricing plan labels | qualify off-site/verified claims to actual service/storage evidence; do not promise future GFS tiers |
| Restore/recovery | WordPress restore; controlled recovery; WordPress helyreállítás | informational/commercial | recovery feature page if user need and host limits are documented | home process mentions backup/verification | explain compatibility and restore-readiness limits; no “one-click everywhere” |
| Update management | WordPress update management; plugin/theme updates; WordPress frissítés | commercial | home update/process sections | current | explain approval/verification boundaries and plan differences |
| Security/vulnerability monitoring | WordPress security monitoring; WordPress security; WordPress vírusirtás | commercial/informational | home security section; dedicated page only with findings/method detail | current home and FAQ | distinguish scan/monitoring from complete protection; “malware removal” only where in plan and supported |
| WooCommerce operations | WooCommerce maintenance; WooCommerce karbantartás | commercial | pricing WooCommerce tab plus home WooCommerce section | current | possible dedicated page P1 if it adds specific store workflow and substantive HU/EN content |
| Website monitoring | WordPress website monitoring; uptime monitoring | commercial | home monitoring/plan section | some package features | state actual cadence and alert behavior from configuration; no “real-time” unless measured/true |
| Admin access | secure WordPress admin access | commercial/trust | security/process section | product feature exists in app, public description limited | describe limited, controlled access only with current policy evidence |
| Pricing/plan comparison | WordPress maintenance plans; WordPress karbantartás árak; WooCommerce plans | transactional/commercial | pricing | current shared price catalogue | one canonical pricing page; plan tabs are one document; ensure crawlable plan text |
| Troubleshooting/how-to | failed update, restore limits, backups after cancellation | informational | FAQ or later resource page | FAQ on home only | add concise factual answers; avoid thin articles and legal claims |

## Cannibalization rules

- One primary landing page per query family; retain home for broad service and pricing for plan/price comparison.
- A page for recovery, security, monitoring, or WooCommerce must have unique scope, evidence, and bilingual answer content before it is indexed.
- Do not create six plan URLs for the future backup schedule before M14 entitlements and M16 presentation are implemented.
- Use Search Console query/impression data after setup to refine priorities; do not infer volume from keyword tools alone.
