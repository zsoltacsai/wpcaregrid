# GO-7.2A — staging és production release-előkészítési bizonyíték

**Dátum:** 2026-11-13  
**Státusz:** dokumentációs/read-only előkészítés; production **NO-GO**.  
**Release candidate:** 5e0e784c69bcd5cfdcaa3f03a4734cb62ed40bd8  
**Protected primary a dokumentum készítésekor:** claude/ecstatic-wozniak-oemyuf, SHA 5e0e784c69bcd5cfdcaa3f03a4734cb62ed40bd8.

> **Történeti pillanatkép — 2026-11-14:** A #395, #396 és #385 integrációja után
> a protected primary `198e32d127c782bf80ff7ca837f06df82d50655a`. Ez a GO-7.2A
> dokumentum a 2026-11-13-i `5e0e784…` állapotot rögzíti; nem aktuális staging,
> production, CI vagy üzemeltetési bizonyíték. A `5e0e784…` kandidátusazonosságot
> nem módosítottuk. Az aktuális bizonyítékok az issue #174 ledgerben követhetők.
> Production továbbra is NO-GO.

Ez a dokumentum nem bizonyít friss staging vagy production runtime állapotot, és nem engedélyez host-, adatbázis-, staging- vagy production-műveletet.

## 1. GitHub állapot a dokumentum készítésekor

- A GitHub branch API a protected primary refet protected=true állapotban és a fenti SHA-n adta vissza.
- A [CI run 43386058267](https://github.com/zsoltacsai/wpcaregrid/actions/runs/43386058267) ehhez a pontos SHA-hoz tartozik; push esemény, completed/success, a check-runs API 42/42 check-run completed/success eredményt adott (a workflow 38 jobja mind sikeres volt).
- A branch protection részletes endpointját a GitHub-integráció nem engedte olvasni (403); a required-check névlista UNKNOWN. A check-run eredmények exact-SHA-n igazoltak.
- A dokumentum készítésekor a PR #383 nyitott Draft volt, HEAD aecc24e679de7537385d2625bbf6ef9455a89303 és base 5e0e784c69bcd5cfdcaa3f03a4734cb62ed40bd8 mellett. Az akkori #174 bejegyzések Moody exact-HEAD AppSec V3 PASS-t, valamint Hermione Claude API limit miatti BLOCKED Integration QA-ját rögzítették. Ez kizárólag történeti állapot; a PR később frissült, az aktuális státuszt a #174 ledger mutatja.
- Nyitott PR #380 Draft, base 1977224cafdf460205e6a6febcf58c46ddac63c0; nem aktuális primary candidate evidence.

## 2. Staging discovery

| Ellenőrzés | Eredmény | Bizonyíték és korlát |
|---|---|---|
| Jelenlegi app helye / release SHA / aktív release manifest | UNKNOWN | Az SSH kliensdiagnosztika a caregrid-staging aliast deploy@stg1.wpcaregrid.com:22-ra oldotta fel. A known_hosts Ed25519 host fingerprint megegyezett a korábban Owner által jóváhagyott értékkel; StrictHostKeyChecking=yes és csak Ed25519 host key mellett a szerverazonosság PASS. A konfigurált privát kulcsfájl 0600 és olvasható; privát/publikus fingerprint egyezik, de az ssh-agent nem elérhető, és a kulcs aláírásra passphrase nélkül nem nyitható meg. Az egyetlen engedélyezett további SSH próba 255-tel zárult: a szerver elfogadta a publikus kulcs jelöltjét, de helyi aláírás nem történt; a távoli parancs nem futott. **Owner action:** azonos meglévő kulcsot kell elérhetővé tenni az engedélyezett agenten keresztül; új kulcs/csatorna nélkül. E próba keretében több kapcsolat nincs. |
| Telepítési cél szerződés szerinti útvonala | Repo evidence only | A staging-next helper 1.0.2 manifest /var/www/wpcaregrid-staging-new app_dir-t, wpcg_staging_next adatbázist és staging-next.wpcaregrid.com hostot rögzít. Ez nem bizonyít aktuális telepítést. |
| PHP / FPM és szolgáltatás-azonosítók | UNKNOWN runtime | A staging dokumentáció PHP 8.5 scheduler célt említ; a deployment dokumentáció PHP 8.4-et ír baseline-nak. A tényleges CLI/FPM verzió, socket, unit és manifest nem olvasható. |
| Composer platform | Repo PASS / runtime UNKNOWN | composer.json PHP ^8.4.1 követelményt deklarál; 8.4/8.5 megfelel a verziótartománynak. A kiterjesztések jelenlétét a futó kiadáson a composer check-platform-reqs --no-dev --lock ellenőrzi. |
| Helyi PHP tesztek | BLOCKED | PHP/Composer és vendor/bin/phpunit hiányzik. A PHPUnit parancs nem futott le; függőséget nem telepítettem és konténert/adatbázist nem indítottam. |

A következő runtime gyűjtés kizárólag az aktuális host manifestje vagy trusted konfigurációja által igazolt pathon futhat. Ha a deploy-only csatorna ezt nem tudja olvasni, path és release SHA UNKNOWN.

## 3. Queue és scheduler izoláció

Repository evidence: routes/console.php 21 scheduled műveletet definiál, köztük backup, privacy export, admin access expiry, security, monitoring/heartbeat, belső plan/offer, Stripe webhook/reconcile, billing email, subscription expiry és platform update feladatokat. Minden staging-next eventhez a StagingSchedulePolicy fail-closed allowlistet csatol. A marketing HTTP gate nem tiltja le önmagában a queue vagy scheduler processzt.

A policy local, Stripe, mail, Connector és external csoportokat használ; az integrációs csoportok külön opt-in/feltételek mögött vannak. A connector:update-presence helyi DB-t módosít; backup:monitor fájlokat törölhet és transfer jobot dispatch-elhet, tehát a local címke nem jelent side-effect-mentességet.

Dokumentált processzkonfiguráció, nem friss runtime bizonyíték:
- docs/STAGING_SCHEDULER.md: wpcg-staging-next-scheduler.service/.timer, PHP 8.5 és /var/www/wpcaregrid-staging-new; a worker service nincs érintve.
- docs/DEPLOYMENT.md: legacy Supervisor wpcaregrid-default és wpcaregrid-backups worker példák, /var/www/wpcaregrid útvonallal.
- A staging policy csak staging-next környezetben alkalmazódik; a forrás szerint máshol nem változtatja meg a production/legacy scheduler viselkedést.

| Gate | Állapot |
|---|---|
| Forrásoldali allowlist | PASS repository szinten; runtime nincs bizonyítva |
| Aktív scheduler unit/timer/cron és futó schedule | UNKNOWN |
| Queue workers, queue-k és futó jobok | UNKNOWN |
| Customer/payment/provisioning jobok tényleges tiltása | UNKNOWN; a HTTP middleware nem helyettesíti a worker fence-et |
| Production worker/scheduler fence | HOLD; friss exact-SHA host/process evidence kell |

Nem futott schedule:run, queue:work, Artisan vagy service parancs.

## 4. Production controller kompatibilitás

| Elem | Repository tény | Állapot |
|---|---|---|
| Production controller | scripts/production_deploy_controller.py, HELPER_VERSION 2.0.0; rögzített /usr/local/libexec/wpcaregrid-deploy/production/2.0.0 path | A controller SHA a cf2a944 baseline-on és candidate-en a94865d3e6318c7278b6d72e797fc94d5274faeca0ea2073ecc84fad74166283. |
| Helper artifacts | A production-deployment-helper-2.0.0.json manifest 7 deklarált artifact pinje egyezik a candidate fájlok SHA-256 értékeivel | Hash-konzisztencia PASS; host install nincs bizonyítva. |
| Root installer | Candidate tree-ben szerepel, hash pin c5c19f0655cfae5f4747264c13c988f7360f3322be32a54b5e1961d538c2bf9d; a régi baseline után került hozzá | Runbook/installer együtt újra review-zandó. |
| V3 evidence | A protected primary tartalmazza a #385-ben hozzáadott, read-only V3 admission prototípust. A telepített production helper 2.0.0 változatlan, V2 review szerződéshez pinelt, és nincs bizonyíték arra, hogy a prototípus be lenne kötve vagy telepítve. | HOLD: operatív V3-integrációhoz külön, verziózott és review-zott helper/controller utód kell. |
| Phase B | phase_b_default=DISABLED; a production activation policy és recovery evidence függvénye | HOLD. |
| Artifact | Controller canonical release-t készít exact Git source alapján; korábbi ZIP nem automatikus input | Ne használjunk régi ZIP-et; exact-SHA artifact proof kell. |

Legkisebb remediation: a #385 read-only prototípusát külön versioned controller/helper utóddal kell integrálni, exact release identity, report-artifact ellenőrzés, pinned artifact és CI verifier összhangjával, a 2.0.0 telepítés érintetlenül hagyásával, valamint hiányzó/hibás V3-ra HOLD negatív tesztekkel.

## 5. MySQL 8.4 trigger-migráció

A candidate tree-ben 75 migration van; trigger DDL a következőkben:
- 2026_11_02_000002_create_transition_v2_requests_table.php
- 2026_11_02_000003_create_transition_history_events_table.php

Szintetikus CI evidence: [PR #380 run 43321682559](https://github.com/zsoltacsai/wpcaregrid/actions/runs/43321682559), MySQL 8.4.11, binlog ON, ROW, log_bin_trust_function_creators=OFF. Az application account SUPER nélkül 1419-et kap trigger creationnél. A külön DBA account ideiglenes SUPER-rel létrehozta a triggereket, de a minősítés UNRESOLVED_SUPER_ONLY. Ez izolált CI, nem staging/production bizonyíték; PR #380 Draft és régi base-re céloz.

A migration nem rögzít explicit DEFINER-t, ezért a létrehozó account lesz a definer. A qualification megfigyelte, hogy a DBA definer account törlése után guarded write 1449-et okozott. Tartós, legkisebb szükséges trigger runtime grant és definer lifecycle szükséges. Az app account nem kaphat globális SUPER-t; binlog trust setting nem lazítható.

Következő isolated clone qualification: pontos migration SHA inventory; binlog ON/ROW/trust OFF; app grants snapshot; app-only migrate reprodukálja 1419-et és partial DDL-t; külön DBA/definer opció minimum grantokkal, revoke utáni új kapcsolatból app write ellenőrzés; interrupted DDL/re-run/recovery; definer életciklus ellenőrzése. Ha least-privilege megoldás nincs, HOLD. Productionon nincs futtatás.

## 6. Backup és restore

A RELEASE_CHECKLIST.md és PRODUCTION_RELEASE_PREPARATION_5e0e784.md szerint migration-bearing release-hez kell:
- exact SHA-hoz kötött application/source artifact;
- védett config és secret/key recovery (titokértékek kiírása nélkül);
- DB backup checksum és timestamp;
- integritás-ellenőrzés és valódi import disposable izolált MySQL 8.4.11 instance-ba;
- schema/data integrity acceptance és target migration egy külön restored clone-on;
- külön app/config rollback és DB restore terv;
- Owner által meghatározott RPO/RTO.

ISOLATED_STAGING_RESTORE.md meglévő staging MySQL példányban külön schema restore-t ír le; kifejezetten nem off-host DR teszt és nem futtat Laravel migrationt. BACKUP_ENGINE.md customer site backup formátumot ír le, nem bizonyít WP CareGrid app/DB production recovery-t.

| Recovery gate | Állapot |
|---|---|
| Friss production backup/integritás | UNKNOWN; nincs jelenlegi evidence |
| Exact release .env/APP_KEY recovery | UNKNOWN |
| Disposable DB restore PASS | HOLD; nincs evidence |
| Migration rehearsal restored clone-on | HOLD |
| RPO/RTO | UNKNOWN; Owner döntés kell |
| Rollback | Code rollback elválik DB restore-tól; runtime rehearsal UNKNOWN |

Izolált rehearsal plan: jóváhagyott hálózati izolációjú disposable MySQL 8.4.11 target; backup SHA/forrás/DB UUID rögzítése titok nélkül; restore és table/reference invariants; külön migrációs clone; partial DDL/recovery teszt; app smoke, code rollback, RPO/RTO mérés; disposable erőforrás cleanup csak külön eljárással. Restore nem futott.

## 7. Marketing-only acceptance

Hatókör: HU/EN publikus marketingoldalak, assetek, navigation, HTTPS és nyilvános marketing funkciók. Lezárva marad: customer regisztráció/auth, checkout, live payment, provisioning/subscription activation, Connector onboarding, operational API és customer queue/scheduler munka.

Exact candidate-on, fresh staging evidence-szel:
1. boot/config, active SHA és manifest; platform requirements secret értékek nélkül;
2. HU/EN landing, navigation/locale, assetek, canonical/redirect, responsive, browser console/network;
3. TLS/HTTPS redirect, cert, HSTS és security headers, secure cookies, mixed content;
4. negatív customer/API/checkout/payment/provisioning/Connector checkek: csak safe GET vagy izolált synthetic fixture; nincs state-changing kérés nem izolált staging/production ellen;
5. post-test invariant: nincs customer/order/payment/service rekord és operational queued job;
6. timestamp, exact SHA, browser/runtime verzió, PASS/FAIL/UNKNOWN és sanitizált report hash.

Pozitív tiltott útvonal vagy hiányzó worker fence: FAIL/STOP. Hiányzó runtime evidence: UNKNOWN.

## 8. Determinisztikus futtatás

- GitHub primary/ref, exact workflow és checks: read-only; run 43386058267, 42/42 check-run PASS (38/38 workflow job PASS).
- PR/issue snapshot és #174 lekérés: read-only; a #383 akkori státuszát a dokumentumkészítéskori GitHub állapotot rögzítő 1. szakasz tartalmazza, nem aktuális gate-bizonyítékként.
- A 2.0.0 manifest hét artifact SHA pinje a 5e0e784 Git tree-hez egyezik; controller SHA a régi source baseline-on is azonos.
- composer.json PHP ^8.4.1; a composer.lock fájl SHA-256 értéke a candidate tree-ben a9b02c90dcb7ceab75ca8e905083597287685247157c0a7b398f7c476d401ec3 (ez fájlhash, nem Composer platform hash).
- Forrás/manifest/dokumentáció olvasása megtörtént; host/DB/helper/Artisan parancs nem futott.
- SSH read-only próba auth hibával zárult; másik csatorna nem volt próbálva.
- PHPUnit nem futott: a helyi PHP/Composer/vendor dependency hiányzik.

## 9. Sorrendbe tett readiness kapuk

| Sorrend | Gate | Státusz |
|---|---|---|
| 1 | Protected source + exact-SHA CI | PASS: 5e0e784, run 43386058267, 38/38 |
| 2 | Exact release PR, independent QA/AppSec V3 | HOLD: #383 QA blocked, no QA V3; AppSec PASS already recorded |
| 3 | Staging identity, release, config, browser acceptance | UNKNOWN; current deploy SSH did not authenticate |
| 4 | Queue/scheduler/runtime feature fences | UNKNOWN/HOLD; no current host evidence |
| 5 | MySQL migrations | HOLD: UNRESOLVED_SUPER_ONLY; full isolated procedure needed |
| 6 | Backup, restore, rollback | HOLD: fresh backup+isolated restore+RPO/RTO absent |
| 7 | Production controller compatibility | HOLD: current 2.0.0 does not enforce V3; successor needed |
| 8 | Scope/payment/customer feature isolation | Repo plan PASS; runtime UNKNOWN |
| 9 | Final production activation | NO-GO until every previous gate PASS and exact-SHA Owner GO |
| Separate | GO-6.4 inventory helper | HOLD; separate optional diagnostic branch, not app release prerequisite |

## 10. GO-7.2B terv és Owner döntések

### Deploy-only staging evidence (read-only; next phase)
Csak a már jóváhagyott deploy-only SSH alias és explicit read-only parancsok használhatók. Első lépés host identity/fingerprint/trusted manifest összevetés; eltérés esetén STOP. Ha sikeres:
1. release receipt/current symlink és hash-olt manifest;
2. php/FPM, Composer platform check csak hitelesített exact pathon;
3. scheduler/worker systemctl show, timers, cron/Supervisor effective configuration hash (secret tartalom nélkül), processes;
4. release feature flags csak allowlisted boolean státuszként, raw .env nélkül;
5. queue depth/count, job payload nélkül;
6. nincs Artisan, .env olvasás, HTTP POST, service control, migration vagy restore.

Ha az alias továbbra sem authentikál vagy identity nem igazolható, runtime tények UNKNOWN-ok maradnak; ne legyen access workaround.

### Owner döntések / későbbi engedélyek
- GO-7.2B read-only deploy-access identity/fingerprint verification és pontos command allowlist; root hozzáférés nélkül.
- Hermione független QA újrapróbálása a Claude limit feloldása után; nem helyettesíthető.
- Külön isolated environment authorization, ha migration/restore rehearsal nem futtatható a már létező disposable CI service-ben.
- DBA/Owner döntés a tartós trigger definer, minimum grants és revoke/lifecycle eljárásáról; SUPER az app accountnak és global trust gyengítése tilos.
- Owner RPO/RTO és backup recovery elvárásai.
- Minden gate után külön exact-SHA production release GO.
- GO-6.4 root install/first invocation külön és opcionális marad.

**Döntés:** production NO-GO. Következő biztonságos lépés: az existing deploy-only credential/host binding ellenőrzése a már jóváhagyott elérési úton; ha az továbbra is hibázik, Owner biztosítson működő, már jóváhagyott deploy-only auth-ot, access-modell bővítése nélkül.
