# M147-E.2a RO-00 — új Owner-engedélyezési rekord

**Állapot: DRAFT — NINCS VÉGREHAJTÁSI JÓVÁHAGYÁS**  
**Hatókör: egyetlen, előre engedélyezendő RO-00 host-key ellenőrzés**  
**Staging-kapcsolat ebben a feladatban: NEM TÖRTÉNT**  
**RO-01–RO-09 és M147-E.2b: NEM ENGEDÉLYEZETT**

Ez a lap új, külön Owner-jóváhagyást igénylő végrehajtási ablak előkészítésére szolgál. Kitöltése és jóváhagyása nélkül tilos csatlakozni. A korábbi időablak nem használható fel újra.

## Cél és tervezett művelet

| Mező | Érték |
|---|---|
| Cél | Rackforest staging — `stg1.wpcaregrid.com` / `85.155.243.164` |
| SSH port / felhasználó | `22` / `deploy` |
| Függetlenül megerősített ED25519 fingerprint | `SHA256:7eMFqVIvqAipy7heD3YlizeroorFjSw4N/Ep5Ku7irQ` — a végrehajtás előtt az Ownernek frissen kell megerősítenie |
| Művelet | RO-00: a megadott IP ED25519 host public key-jének lekérése és helyi fingerprint összehasonlítása |
| Hitelesítés | RO-00 alatt nincs SSH hitelesítés, nincs távoli parancs |
| Tervezett kliens | Git for Windowsból származó, azonos telepítésből vett OpenSSH eszközpár; csak a provenance blocker lezárása után |
| M147-E.2b | NO-GO / nem engedélyezett |

## Az Owner által kitöltendő engedélyezési mezők

| Mező | Kitöltendő érték |
|---|---|
| Végrehajtás dátuma (YYYY-MM-DD) | **____________________** |
| Kezdés (Europe/Budapest) | **____________________** |
| Befejezés (Europe/Budapest) | **____________________** |
| Név szerint engedélyezett operátor | **____________________** |
| Jóváhagyási / ticket hivatkozás | **____________________** |
| Fingerprint független forrásból friss megerősítve (igen/nem; forrás megnevezése, titok nélkül) | **____________________** |
| A helyi, hitelesség-ellenőrzött klienscsomag azonosítója | **____________________** |
| RO-00 futtatásához jóváhagyott helyi végrehajtókörnyezet | **____________________** |
| Bizonyíték tárolási helye és redakcióért felelőse | **____________________** |
| Leállításért felelős személy és eszkalációs kontakt | **____________________** |
| Owner kifejezett jóváhagyása / dátuma | **____________________** |

**Credential-mechanizmus:** RO-00 nem használ hitelesítőadatot. Ha később RO-01–RO-04 engedélyezése merül fel, az engedélyezett, már létező `deploy` credential hozzáférési módját külön kell megadni és jóváhagyni. Jelenleg ez a mechanizmus **UNKNOWN**; privát kulcsot, agentet vagy credential-tárat nem vizsgálunk és nem tesztelünk.

## Kötelező előfeltételek

Az ablak előtt az Ownernek ellenőrizhetően jóvá kell hagynia a fenti kötelező mezőket, és a kiválasztott Git for Windows csomag provenance-át a diagnosztikai jelentésben meghatározott módon le kell zárni. A használt `ssh-keyscan.exe`, `ssh-keygen.exe` és `ssh.exe` ugyanabból a hitelesség-ellenőrzött telepítésből származzon. Az abszolút útvonalakat és verziókat a helyi, hálózatmentes előellenőrzésben kell ismét rögzíteni.

Bármely hiányzó/lejárt mező, nem igazolt klienscsomag, időablakon kívüli futás, cél- vagy fingerprint-eltérés, KEX hiba, üres/duplikált/hibás kulcskimenet vagy váratlan kimenet esetén: **STOP; ne legyen hostkulcs megbízhatóként rögzítve, ne történjen SSH hitelesítés, ne próbálkozzon másik hosttal vagy algoritmussal.**

## Engedélyezett / tiltott

**Jóváhagyás után, az ablakban:** kizárólag az engedélyezett cél IP ED25519 host key-jének unauthenticated lekérése, helyi SHA-256 fingerprint számítás és pontos összehasonlítás, majd egyezés esetén kizárólag lokális, ideiglenes, szűkített known-hosts bizonyítékfájl előállítása. Ez a művelet célhoz kapcsolódó hálózati kapcsolat, ezért új Level 3 engedély szükséges.

**Nem része ennek az engedélynek:** SSH belépés vagy bármely távoli parancs; credential/agent használat vagy teszt; RO-01–RO-09; gate-olvasás; scanner/feed/queue; backup/restore; konfigurációmódosítás; deploy; M147-E.2b. Host key egyezése önmagában nem engedélyez ilyen műveleteket.

## Végrehajtás utáni eredmény

Ezt a részt csak a külön jóváhagyott RO-00 végrehajtás után lehet kitölteni; jelenleg üresen marad.

| Bizonyíték | Eredmény |
|---|---|
| UTC kezdés / befejezés | NOT EXECUTED |
| Használt klienscsomag és eszközverzió | NOT EXECUTED |
| Keyscan kimenet validációja | NOT EXECUTED |
| Függetlenül jóváhagyott fingerprint egyezése | NOT EXECUTED |
| Ideiglenes helyi host-key bizonyíték létrejött/törlés | NOT EXECUTED |
| Nem történt hitelesítés/távoli parancs | E feladatban nem történt; jövőbeli RO-00 után igazolandó |
