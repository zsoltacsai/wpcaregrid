# M147-E.2a RO-01–RO-04 — külön tulajdonosi engedélyezési rekord

**Állapot: DRAFT — VÉGREHAJTÁSRA NINCS JÓVÁHAGYVA**  
**Hatókör:** kizárólag a RO-01–RO-04 titokmentes, read-only előellenőrzésének előkészítése.  
**Staging-hozzáférés:** ebben a rekordban nem történt.  
**RO-00:** függetlenül egyeztetve az alábbi utólagos tulajdonosi megerősítés alapján; az eredeti RO-00 futás a korábban hibásan átírt várt fingerprint miatt nem teljesítette az akkori összehasonlítási feltételt.  
**M147-E.2b:** NEM ENGEDÉLYEZETT.

## 1. Célkörnyezet

| Mező | Tervezett érték | Állapot |
|---|---|---|
| Repository | `zsoltacsai/wpcaregrid` | Rögzített |
| Jelölt staging host | `stg1.wpcaregrid.com` | Tulajdonosi engedélyben szereplő cél; végrehajtás előtt újra megerősítendő |
| Cél IPv4 | `85.155.243.164` | Tulajdonosi engedélyben szereplő cél; végrehajtás előtt újra megerősítendő |
| SSH port | `22` | Végrehajtás előtt újra megerősítendő |
| SSH account | `deploy` | Jelölt; hozzáférés/jogosultság nincs tesztelve |
| ED25519 host fingerprint | `SHA256:7eMFqVIvoAipy7heD3YliZer0orFjSv4N/Ep5Ku7irQ` | A tulajdonos szerint közvetlenül a Rackforest VPS konzolban, `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub -E sha256` paranccsal függetlenül ellenőrizve; az eredeti RO-00 által megfigyelt értékkel egyezik |
| Laravel útvonal | `/var/www/wpcaregrid` | Dokumentált jelölt; végrehajtás előtt megerősítendő |
| Tényleges staging-identitás | ÜRES | Owner kitöltése / végrehajtás előtti egyeztetés |

A fenti fingerprint utólagos tulajdonosi megerősítés. Ez a rekord nem állítja, hogy Codex ismételten csatlakozott vagy a Rackforest konzolt ellenőrizte.

## 2. Új engedélyezési időablak és felelősség

| Mező | Owner kitölti |
|---|---|
| Végrehajtás dátuma | ______________________________ |
| Kezdési idő, Europe/Budapest | ______________________________ |
| Zárási idő, Europe/Budapest | ______________________________ |
| Végrehajtó | Codex, tulajdonosi felügyelettel — megerősítendő |
| Felügyelő Owner | ______________________________ |
| Leállítási jogosultság / felelős | ______________________________ |
| Vészhelyzeti/recovery kontakt | ______________________________ |
| Engedélyezési hivatkozás | ______________________________ |

Az időablak lejártakor semmilyen új művelet nem indulhat. Hiányzó, kétértelmű vagy lejárt mező esetén a végrehajtás BLOCKED.

## 3. SSH-hitelesítő adat készenléte — titok megtekintése nélkül

| Ellenőrzési mező | Állapot / Owner kitölti |
|---|---|
| Meglévő, jóváhagyott SSH hozzáférési mechanizmus pontos megnevezése | ______________________________ |
| A mechanizmus a Codex aktuális helyi munkamenetében már engedélyezetten elérhető | UNKNOWN — Owner megerősítése szükséges |
| A `deploy` fiók RO-01–RO-04 olvasási jogosultsága jóváhagyott | ______________________________ |
| A fiók célhostra való jogosultsága külön adminisztratív bizonyítékkal alátámasztott | ______________________________ |
| Credential kezelési mód biztonsági felülvizsgálata | ______________________________ |

Nem kérünk és nem rögzítünk privát kulcsot, jelszót, agent-identitást, credential-store tartalmat vagy titkot. A hitelesítő adatot nem szabad megjeleníteni, másolni vagy tesztelni az engedélyezési rekord kitöltésekor. Ha a meglévő jóváhagyott hitelesítési mód nem használható a titkok megtekintése nélkül, a végrehajtás BLOCKED; új credential beszerzése vagy privilegizálása külön engedélyt igényel.

## 4. Előzetesen javasolt műveleti allowlist

Az alábbiak csak tervezett parancsok. Végrehajtásukhoz a Ownernek a rekord végleges jóváhagyásakor kifejezetten meg kell erősítenie a pontos parancslistát és útvonalat. Egyik művelet sem futott le e dokumentum készítésekor.

| ID | Javasolt cél/parancs | Korlát |
|---|---|---|
| RO-01 | `hostname -f; id -un; date -u +%Y-%m-%dT%H:%M:%SZ` | Csak hostnév, végrehajtó felhasználó és UTC-idő; nincs konfigurációdump |
| RO-02 | `git -C /var/www/wpcaregrid rev-parse --verify HEAD` | Csak a telepített checkout commit SHA-ja |
| RO-03 | `php -v` | CLI verzió; Laravel/WordPress bootstrap nélkül |
| RO-04 | `df -P /var/www/wpcaregrid` | Csak az alkalmazási fájlrendszer kapacitási sora |

A későbbi futtatásnál csak az ellenőrzött Git for Windows OpenSSH binárisok használhatók abszolút útvonalról, szigorú host-key ellenőrzéssel, a jóváhagyott fingerprintből előzetesen létrehozott, izolált known-hosts fájllal. Nem használható trust-on-first-use, host-key ellenőrzést kikapcsoló beállítás, TTY, shell, sudo, vagy más célhost/port.

Minden parancsnál végrehajtás előtt rögzítendő az abszolút cél, várható kimenet, timeout, kimenetkorlát, titok-kockázat és stop-feltétel. Ha egy parancs alkalmazás-bootstrapot vagy írást okozhat, azt ki kell hagyni és UNKNOWN-ként jelenteni.

## 5. Engedélyezett határ, ha a rekordot később véglegesítik

Csak a RO-01–RO-04 fenti, külön megerősített read-only ellenőrzések. Nincs RO-05–RO-09, M12 gate-olvasás, scanner, feed-letöltés, queue/scheduler művelet, konfigurációmódosítás, backup, restore, incident, notification, remediation, deployment vagy M147-E.2b.

### Azonnali stop-feltételek

- A host kulcs nem egyezik a korrigált, tulajdonos által megerősített ED25519 fingerprinttel.
- A célhost, környezet, útvonal, fiók vagy végrehajtási ablak nem egyértelmű.
- A kapcsolat hitelesítési titkot kér vagy megjelenít, amit az előzetesen jóváhagyott mechanizmus nem fed le biztonságosan.
- A parancs módosítást, queue-fogyasztást, alkalmazás-bootstrapot vagy nem engedélyezett műveletet okozhat.
- Titok, customer/production kötés, váratlan mellékhatás vagy nem korlátozható blast radius jelenik meg.

## 6. Owner végső döntése

Jelenlegi döntés: **DRAFT / DEFER — NO-GO**.

- [ ] A célhost, IP, port, staging-identitás és host-key fingerprint jóváhagyva.
- [ ] A pontos RO-01–RO-04 parancslista jóváhagyva.
- [ ] A meglévő SSH credential mechanizmus és `deploy` jogosultság biztonságos használata titokfeltárás nélkül jóváhagyva.
- [ ] A dátum és kezdő/záró időpont kitöltve, Europe/Budapest időzónában.
- [ ] Az evidence/redakciós mód jóváhagyva.
- [ ] A leállításért felelős és recovery kontakt megadva.
- [ ] Owner kifejezetten engedélyezi a RO-01–RO-04 végrehajtását.

A jelen dokumentum nem engedélyez staging-kapcsolódást, autentikációt vagy távoli parancsot. A végrehajtás csak akkor kezdhető meg, ha a mezők kitöltöttek, a pontos scope jóváhagyott, és az új időablak aktív.


## Owner pontosítás — SSH-fiók és credential készenlét (2026-10-18)

A korábban feltételezett `deploy` SSH felhasználónév nincs megerősítve. Az Owner közlése szerint Codex korábban root hozzáférést kért, de a kérés önmagában nem bizonyít tényleges, jóváhagyott vagy sikeresen használt root belépési mechanizmust. Nincs bizonyíték arra, hogy Codex staginghez valaha SSH-val rootként, deployként vagy más fiókkal csatlakozott volna. A repositoryban említett `deploy` egy jelölt; a telepítő script futásidejű felhasználója vagy a staging→GitHub read-only deploy key nem bizonyítja a Codex kliens→staging SSH hitelesítést.

A nem érzékeny lokális metaadat olvasása szerint a jelenlegi felhasználónál nincs `.ssh/config`, `SSH_AUTH_SOCK` és `SSH_AGENT_PID` nincs beállítva, a Windows `ssh-agent` szolgáltatás Stopped. Privát kulcsot, agent-identitást vagy credential store-t nem nyitottunk meg, nem másoltunk és nem teszteltünk. Ezért a hozzáférési mechanizmus és a tényleges SSH account **UNKNOWN**; **ROOT-ONLY ACCESS nincs bizonyítva**.

A rekord **DRAFT / NO-GO** marad. Új staging-engedélyezéshez az Ownernek titokértékek nélkül kell megneveznie a tényleges, jóváhagyott hozzáférési mechanizmust és a fiókot. RO-01–RO-04 esetén a legkisebb jogosultságú, dedikált nem-root read-only fiók preferált; ennek létrehozása vagy konfigurálása nincs engedélyezve. Nincs staging kapcsolat, hitelesítés vagy távoli parancs.


## Owner RSA fingerprint közlése és RO-01–RO-04 előfeltételek — 2026-10-18

Az Owner közlése szerint a Rackforest VPS `root` és `deploy` `authorized_keys` fájlja azonos RSA fingerprintet tartalmaz. A közölt karakterlánc: `SHA256+tFoYq7gsUPjpPBCqnNOvdzksvB6VsLE4AwdunkU`. A helyi `id_rsa.pub` fájlról frissen számított érték: `SHA256:Cy8+tFoYq7gsUPjpPBCqnNOvdzksvB6VsLE4AwdunkU`. A két teljes érték karakterre pontosan nem egyezik; a szerveroldali közlés formátuma/átírása tisztázandó. Emiatt az egyezés nem jelölhető PASS-ként, és a hozzáférés readiness UNKNOWN marad.

Még az egyezés későbbi megerősítése után is **UNKNOWN** marad:
- a helyi megfelelő privát kulcs rendelkezésre állása (nem vizsgáltuk);
- tényleges SSH-hitelesíthetőség (nem teszteltük);
- a `deploy` fiók hozzáférésének és az RO-01–RO-04 műveleteknek a külön Owner-jóváhagyása.

Jövőbeli, külön jóváhagyott RO-01–RO-04 futáshoz a minimális előfeltételek:
1. Az Owner adja meg vagy erősítse meg a teljes szerveroldali fingerprintet pontos `SHA256:<base64>` alakban, és igazolja, hogy a helyi publikus kulcs az engedélyezett `deploy` fiókhoz tartozik.
2. Az Owner kifejezetten hagyja jóvá a nem root `deploy` fiók használatát, az exact staging célt és a csak olvasási, parancsonkénti allowlistet; sudo/root jogosultság nem része a javaslatnak.
3. Töltse ki és hagyja jóvá a friss végrehajtási dátumot/időablakot (Europe/Budapest), végrehajtót, leállítási felelőst, evidence-kezelést és engedélyezési hivatkozást.
4. A jövőbeli engedélyezés előtt külön, offline vizsgálat hagyja jóvá a parancsok mellékhatásmentességét; nem biztonságos vagy bootstrapoló Laravel/WordPress parancs UNKNOWN/BLOCKED maradjon.

Ez a kiegészítés nem engedélyez privát kulcs vizsgálatot, SSH-kapcsolatot, hitelesítést vagy távoli parancsot. A rekord **DRAFT / NO-GO** marad; M147-E.2b **NOT AUTHORIZED**.


## RSA fingerprint újraközlés — reconciliation még nyitott (2026-10-18)

A helyi publikus `id_rsa.pub` friss, ismételt eredménye `SHA256:Cy8+tFoYq7gsUPjpPBCqnNOvdzksvB6VsLE4AwdunkU`. Az Owner legutóbb közölt `SHA256+tFoYq7gsUPjpPBCqnNOvdzksvB6VsLE4AwdunkU` értéke továbbra sem egyezik byte-for-byte; változatlanul meg kell őrizni mint történeti hibás átírást. Így a public-key fingerprint match **PASS nem adható**, és a szerveroldali teljes ujjlenyomat továbbra is UNKNOWN.

Az Owner azt állítja, hogy a root és deploy `authorized_keys` ugyanazt a fingerprintet adta. Ez nem bizonyítja, hogy a helyi privát kulcs elérhető, hogy a Codex a deploy accounttal hitelesíteni tudna, vagy hogy az account használata jóváhagyott. Ezek mind **UNKNOWN** maradnak. A `deploy` accountot csak jövőbeli, külön engedélyezett nem-root célként kezeljük; root belépés nem javasolt és nincs engedélyezve.

RO-01–RO-04 továbbra is **DRAFT / NO-GO**. A továbblépés minimuma: az Owner küldje meg a teljes, pontos, OpenSSH-formátumú szerver fingerprintet; külön hagyja jóvá a `deploy` fiókot és a konkrét read-only parancslistát; rögzítsen új dátumot/időablakot, operátort, stop felelőst és evidence-kezelést. Ez a dokumentációs addendum nem engedélyez SSH-kapcsolatot vagy hitelesítést.
