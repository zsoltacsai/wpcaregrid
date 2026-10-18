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


## Follow-up — authenticated package payload comparison (2026-10-18)

**Current result: A — BINARY PROVENANCE VERIFIED.** This follow-up supersedes the earlier C disposition above; that C status accurately records the evidence available when the report was first written. No installed file was changed.

To avoid relying on an unauthenticated third-party Inno Setup extractor, the comparison used the same official release's full x64 archive, `Git-2.56.0-64-bit.tar.bz2`, and Windows' built-in `C:\\Windows\\System32\\tar.exe`.

### Package and extraction-tool provenance

- Official repository/tag: `git-for-windows/git`, `v2.56.0.windows.1`; release verification passed with GitHub CLI `2.102.0`, resolving tag commit `53e90d5e633672f1dc5e08618474720ca367873f`. GitHub release API attestation was loaded and the immutable release verified.
- Installer was independently rechecked and not executed: `Git-2.56.0-64-bit.exe`, 66,697,744 bytes, SHA-256 `bfe94e7b419b16eee9fecbd1253a98e3d4f49ba8f029630549052278ffe286a6`; `gh release verify-asset` succeeded; Authenticode `Valid`, signer Johannes Schindelin, thumbprint `C1D7B0452AA64EDD7530285141AF764C30F12DFD`.
- Comparison archive: `Git-2.56.0-64-bit.tar.bz2`, 118,083,769 bytes, SHA-256 `fdf531fb5c003fff0fed1b3ef282134c9186518f284c2e10860214ce127a973b`; `gh release verify-asset` succeeded against the same verified immutable release.
- Extractor: Microsoft Windows `tar.exe`, version `bsdtar 3.8.9 / libarchive 3.8.9`, 92,264 bytes, SHA-256 `A3AC62FD4CDF619A7C53F71CA3FE5FB42F72020975670FF29AFE3603F6416A8E`; Authenticode `Valid`, signer Microsoft Windows, thumbprint `667BBA1E1F478221AA65DE4F75187A751B03A453`.
- Archive listing contained 9,631 members. A pre-extraction path check found zero absolute, drive-qualified, backslash, dot-segment or parent-traversal paths. Only the three exact `usr/bin` members below were extracted to a newly created task-specific temporary directory. No installer or extracted SSH executable was run. The downloaded archive and extracted files were not added to Git.

### Fresh installed-versus-package comparison

Hashes and byte sizes were recalculated from both files during this run:

| Binary | Official package path | Official package bytes / SHA-256 | Installed bytes / SHA-256 | Result |
|---|---|---|---|---|
| `ssh.exe` | `usr/bin/ssh.exe` | 960,985 / `C84594A323EE6AFC04D9FAF614A05BE6DC9A2FAECD856268A854084FEA17FD44` | 960,985 / `C84594A323EE6AFC04D9FAF614A05BE6DC9A2FAECD856268A854084FEA17FD44` | MATCH |
| `ssh-keyscan.exe` | `usr/bin/ssh-keyscan.exe` | 526,978 / `CD3561C764F577EFC235942CC66314889D288F619F464D6F21CF185DEAA45BB4` | 526,978 / `CD3561C764F577EFC235942CC66314889D288F619F464D6F21CF185DEAA45BB4` | MATCH |
| `ssh-keygen.exe` | `usr/bin/ssh-keygen.exe` | 543,574 / `604D127D97502B11ED2D1F6F8191BFE86F443D821322E4AB285C961D0E06F5E1` | 543,574 / `604D127D97502B11ED2D1F6F8191BFE86F443D821322E4AB285C961D0E06F5E1` | MATCH |

This establishes that all three currently installed SSH files are byte-for-byte identical to the corresponding files in the authenticated official Git for Windows 2.56.0 x64 release archive. It does not establish any staging host identity or authorize a connection.

### Local compatibility recheck

All checks used absolute installed paths and made no network connection:

- `C:\\Program Files\\Git\\usr\\bin\\ssh.exe -V`: `OpenSSH_10.5p1, OpenSSL 3.5.8 25 Aug 2026`.
- `ssh -Q kex` included `sntrup761x25519-sha512@openssh.com`, `curve25519-sha256`, and `curve25519-sha256@libssh.org`.
- `ssh -Q key` included `ssh-ed25519`.
- `ssh -G` with an empty temporary config and the synthetic host `localhost` accepted the explicit options and showed: `StrictHostKeyChecking=yes`, `UserKnownHostsFile=C:/Temp/m147-e2a-placeholder-known-hosts`, `GlobalKnownHostsFile=none`, `BatchMode=yes`, and `UpdateHostKeys=no`. `ssh -G` only printed effective configuration; it did not connect or read/write a known-hosts file.

An initial discarded local `ssh -G` probe mistakenly supplied the Windows hosts file as an SSH config and produced parser errors; it did not connect. The successful check used an empty temporary config. No credentials, key files, or agent identities were inspected.

### Authorization boundary and next step

No staging or production connection, Rackforest console interaction, SSH authentication, credential access, RO-00–RO-09 operation, M12 read, scanner/feed/queue/backup/restore/remediation/deployment action, system-wide install, PATH/registry/trust-store change, or modification of installed SSH binaries occurred. All six live M12 gates remain `UNKNOWN`; M147-E.2b remains `NO-GO / NOT AUTHORIZED`.

The verified client binaries are a suitable local candidate for a **separately Owner-authorized** RO-00 attempt. This result does not authorize that attempt; a fresh target-specific execution window, host-key pin and exact operation authorization remain required.
