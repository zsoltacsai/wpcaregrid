# M147-E.2a — Git for Windows SSH bináris provenance bizonyíték

**Mód:** lokális provenance-ellenőrzés; staging és SSH kapcsolat nélkül  
**Eredmény:** **C — RELEASE OR EXTRACTION PROVENANCE INCONCLUSIVE**  
**Staging / RO-00:** nem történt  
**Installer futtatása / telepítés:** nem történt

## Ellenőrzött hivatalos kiadás

- Repository: `git-for-windows/git`
- Tag: `v2.56.0.windows.1`
- A GitHub CLI `gh release verify` eredménye: sikeres; tag commit `53e90d5e633672f1dc5e08618474720ca367873f`; a GitHub API attestáció betöltődött, release ellenőrzött.
- X64 installer: `Git-2.56.0-64-bit.exe`
- Kiadásban rögzített SHA-256: `bfe94e7b419b16eee9fecbd1253a98e3d4f49ba8f029630549052278ffe286a6`
- Letöltött artifact mérete: 66,697,744 byte.
- Helyi SHA-256: a kiadási értékkel egyezett.
- `gh release verify-asset`: sikeres; a fájl jelen van a megadott release-ben és annak asset digestje egyezett.
- Authenticode: `Valid`; aláíró `CN=Johannes Schindelin, O=Johannes Schindelin, L=Bruehl, C=DE`; tanúsítvány thumbprint `C1D7B0452AA64EDD7530285141AF764C30F12DFD`.

A GitHub release integrity ellenőrzése az immutable release-t és a helyi artifact release-assethez való egyezését vizsgálta. Ez a release installerének hitelességét támasztja alá, de önmagában nem bizonyítja, hogy a telepített SSH binárisok ugyanabból a payloadból származnak.

## Ellenőrző eszköz

- GitHub CLI: `C:\Program Files\GitHub CLI\gh.exe`, verzió `2.102.0`.
- Authenticode: `Valid`; aláíró GitHub, Inc.; thumbprint `E02B58DF37FE75CD487B29DAA774841D99298579`.
- A CLI release és asset ellenőrzési parancsai sikeresek voltak.
- Az installer payload biztonságos, nem futtató kicsomagolásához a PATH-ban, a vizsgált szokásos telepítési útvonalakon és az uninstall/Appx telepítési metaadatokban nem találtam 7-Zip, Inno Setup Extractor, PeaZip vagy más megfelelő kicsomagolót. A Windows `tar.exe` elérhető, de nem tekinthető Inno Setup installer kicsomagolójának; nem használtam erre.
- Nem telepítettem vagy töltöttem le ellenőrző eszközt. Az eszközhiány miatt az installer payloadját nem bontottam ki.

## Telepített binárisok — előzetes helyi hash és Authenticode

| Telepített fájl | Lokális SHA-256 | Authenticode |
|---|---|---|
| `C:\Program Files\Git\usr\bin\ssh.exe` | `C84594A323EE6AFC04D9FAF614A05BE6DC9A2FAECD856268A854084FEA17FD44` | NotSigned |
| `C:\Program Files\Git\usr\bin\ssh-keyscan.exe` | `CD3561C764F577EFC235942CC66314889D288F619F464D6F21CF185DEAA45BB4` | NotSigned |
| `C:\Program Files\Git\usr\bin\ssh-keygen.exe` | `604D127D97502B11ED2D1F6F8191BFE86F443D821322E4AB285C961D0E06F5E1` | NotSigned |

Az aláírás hiánya önmagában nem bizonyít módosítást vagy rosszindulatú eredetet. A három telepített fájl hash-e helyi fájlazonosító, nem hitelességi bizonyíték.

## Csomagpayload-összevetés

| Fájl | Kicsomagolt hivatalos fájl hash-e | Összevetés |
|---|---|---|
| `usr/bin/ssh.exe` | **NOT AVAILABLE** | **NOT PERFORMED** |
| `usr/bin/ssh-keyscan.exe` | **NOT AVAILABLE** | **NOT PERFORMED** |
| `usr/bin/ssh-keygen.exe` | **NOT AVAILABLE** | **NOT PERFORMED** |

A szükséges kicsomagoló hiányzik, ezért a FAIL-CLOSED feltétel életbe lépett. Nem próbáltam alternatív, nem ellenőrzött eszközt beszerezni vagy használni. A letöltött nyilvános installer nem futott és nem települt; a Git for Windows telepítése, SSH fájljai, PATH, SSH konfiguráció és known_hosts nem változott.

## Végső minősítés

**C — RELEASE OR EXTRACTION PROVENANCE INCONCLUSIVE.**

Az installer hivatalos release-integritása és Authenticode aláírása ellenőrzött, de az installed-versus-package byte összevetés hiányzik. Ezért a helyi SSH binárisok provenance-a **nem tekinthető igazoltnak**.

### Minimális további lépés

Owner külön engedélyével biztosítani kell egy már telepített, megbízható, nem futtató Inno Setup kicsomagolót (vagy a hivatalos csomag payloadjának megfelelő, hitelesített alternatív archívumot). Ezután csak a három fájl payload-hash összevetése végezhető el. Eszköz beszerzése/telepítése új Owner-jóváhagyást igényel.

## Határok és mellékhatások

Nem vizsgáltam hitelesítőadatot, kulcsot vagy SSH agentet; nem indítottam SSH kapcsolatot, keyscant vagy távoli parancsot; nem csatlakoztam staginghez/productionhöz; nem olvastam M12 gate-et, nem futtattam scannert, nem hajtottam végre telepítést, konfigurációmódosítást vagy M147-E.2b műveletet. PR #129 nem lett merge-elve.
