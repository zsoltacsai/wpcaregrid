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
