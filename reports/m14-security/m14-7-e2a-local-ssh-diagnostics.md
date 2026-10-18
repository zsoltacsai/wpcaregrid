# M147-E.2a — Local SSH client diagnostics

**Mode:** local diagnostics only. **No network connection was made during this diagnostics pass.** This report explains the earlier authorized RO-00 attempt; it does not authorize another attempt.

Checked 2026-10-18 (local execution environment). The previous RO-00 connection attempt occurred only during the Owner-approved window. No SSH authentication or remote command was performed.

## Resolved Windows OpenSSH binaries

| Binary | Resolved path | Version / backend | Authenticode |
|---|---|---|---|
| `ssh.exe` | `C:\Windows\System32\OpenSSH\ssh.exe` | OpenSSH_for_Windows 9.5p2; file/product version 9.5.6.4; LibreSSL 3.8.2 | Valid; Microsoft Windows signer |
| `ssh-keyscan.exe` | `C:\Windows\System32\OpenSSH\ssh-keyscan.exe` | OpenSSH_for_Windows 9.5p2; file/product version 9.5.6.4 | Valid; Microsoft Windows signer |
| `ssh-keygen.exe` | `C:\Windows\System32\OpenSSH\ssh-keygen.exe` | OpenSSH_for_Windows 9.5p2; file/product version 9.5.6.4 | Valid; Microsoft Windows signer |

These were the only Windows OpenSSH executables resolved on PATH. `ssh-keyscan` does not support a version flag; its help lists `-O option`, but the tested `-O KexAlgorithms=curve25519-sha256` was rejected as unsupported. The Windows client's `ssh -Q kex` list and `ssh -G` effective KEX list do not include either `sntrup761x25519-sha512` name. Relevant SSH config files contained no KEX or proxy override. The default `ssh -G` user was the local Windows account; any future SSH command must explicitly specify the authorized remote account.

## Other local client found

Git for Windows 2.56.0.windows.1 includes:

| Binary | Resolved path | Version / backend | Authenticode |
|---|---|---|---|
| `ssh.exe` | `C:\Program Files\Git\usr\bin\ssh.exe` | OpenSSH_10.5p1; OpenSSL 3.5.8 | Binary not individually Authenticode-signed |
| `ssh-keyscan.exe` | `C:\Program Files\Git\usr\bin\ssh-keyscan.exe` | Same Git for Windows OpenSSH installation | Binary not individually Authenticode-signed |

This client's `ssh -Q kex` and `ssh -G` effective KEX list include `sntrup761x25519-sha512`, `sntrup761x25519-sha512@openssh.com`, and curve25519 options. This is local capability evidence only: the Git-bundled client was not used to contact the target. Because the executable files are not individually signed, a future operator must verify the installed Git for Windows package provenance through an approved trusted source before relying on them.

## Cause assessment

**Best-supported cause:** the Windows OpenSSH 9.5p2 client build resolved first on PATH does not advertise/support the KEX method named in the earlier keyscan error. The same-version signed `ssh` and `ssh-keyscan` binaries and their effective KEX lists support this client-side compatibility diagnosis.

**Not established:** whether the root is specifically a compile-time feature omission, interaction with the LibreSSL backend, or a target-side algorithm preference/selection detail. The version is not evidence of a generally obsolete SSH client. The effective client config shows no custom KEX restriction, and no relevant config override was found; therefore a local SSH configuration override is not supported by current evidence. No packet capture/debug transcript exists, and the target was not contacted again, so the server's full KEX offer and available fallback algorithms remain unknown. Do not modify server SSH configuration based on this evidence.

The earlier error and banner do not authenticate server identity. No SSH host key was obtained, no fingerprint match was established, and no credentials were offered.

## Minimal future RO-00 retry proposal

For a separately authorized window, use one provenance-verified OpenSSH installation whose local `ssh -Q kex` includes the KEX method needed by the target. The already-installed Git for Windows OpenSSH is a candidate only after its package provenance is verified. Use the matching `ssh-keyscan.exe` and `ssh.exe` from that same installation; do not mix binaries.

1. Offline: record both binary paths/versions and verify the source/signature of their installed package. Confirm `ssh -Q kex` includes the relevant KEX.
2. During the new approved window, run an unauthenticated key scan for only the approved endpoint, port, and ED25519 host-key type.
3. Compute the returned key fingerprint locally and compare it byte-for-byte with the Owner-supplied trusted fingerprint. Do not authenticate, save the key as trusted, or proceed if retrieval fails or the fingerprint differs.
4. Only after a match, use the same client with strict host-key checking and an isolated known-hosts file containing the verified key for the separately approved RO-01–RO-04 commands. Specify remote user `deploy`; do not use privilege escalation. Keep all commands within the approved list.
5. If the compatible client still cannot retrieve a key, stop. Do not weaken host-key checks, change server algorithms, or substitute a key learned from DNS/HTTP or an unverified scan.

This proposal does not itself authorize a connection or the RO-01–RO-04 commands.

## Prerequisites for a new Owner-approved window

* New explicit Owner authorization with exact target/IP, remote account, trusted fingerprint, approved operator, and a future start/end time with timezone. The prior window cannot be reused after expiry.
* Confirmation that the chosen client/package is obtained from a trusted source and the binary pair is from the same installation.
* The exact approved operations remain RO-00–RO-04 only; RO-05–RO-09 and M147-E.2b remain unauthorized.
* Existing authorized SSH credential available through the approved mechanism. No credential discovery, copying into the repository, or privilege elevation.
* Strict host-key comparison before authentication. Any missing key, KEX error, changed key, fingerprint mismatch, unexpected identity, or command-safety uncertainty is an immediate stop.
* No staging connection during this local diagnostics task. No M12 gate read, scan, feed request, queue action, backup, restore, configuration change, or deployment.

## Risks and limits

* The alternate local client is newer and advertises the needed KEX, but this does not prove it can negotiate with the target or that the target's host key matches the Owner's pin.
* Git-bundled executables have no individual Authenticode signature; package provenance must be established before use.
* Host-key fingerprint verification remains mandatory and independent of KEX compatibility.
* RO-01–RO-04 were not executed. Staging identity, deployed SHA, PHP version, and disk capacity remain UNKNOWN. All six live M12 gates remain UNKNOWN. M147-E.2b remains NO-GO / NOT AUTHORIZED.

## Local-only provenance and compatibility verification — 2026-10-18

**No external package/source request or staging connection was made during local verification.** The authorized documentation-only PR update was performed through GitHub separately; no staging endpoint was contacted. No software, SSH configuration, PATH, registry, trust store, known_hosts file, or credential was changed.

### Exact binaries, versions, sizes, and current hashes

All paths resolve beneath `C:\Program Files\Git`; the SSH tools are from the same installation root. The main Git executable is UCRT64; `cmd\git.exe` is also present.

| Binary | Absolute path | Version / backend | Bytes | SHA-256 |
|---|---|---|---:|---|
| Git launcher | `C:\Program Files\Git\cmd\git.exe` | Git 2.56.0.windows.1 | 29,696 | `7CD588850959546663C81077AF076835558C6A6AF33506A64A65784743DF92A1` |
| Git executable | `C:\Program Files\Git\ucrt64\bin\git.exe` | Git 2.56.0.windows.1 | 4,398,592 | `B9DF456BE0FE7D16A6DC20D02095E2F3C4B3BCEE11DB4FD166F9481BF4F732AE` |
| SSH client | `C:\Program Files\Git\usr\bin\ssh.exe` | OpenSSH_10.5p1; OpenSSL 3.5.8 (25 Aug 2026) | 960,985 | `C84594A323EE6AFC04D9FAF614A05BE6DC9A2FAECD856268A854084FEA17FD44` |
| Key scanner | `C:\Program Files\Git\usr\bin\ssh-keyscan.exe` | Same Git for Windows OpenSSH installation | 526,978 | `CD3561C764F577EFC235942CC66314889D288F619F464D6F21CF185DEAA45BB4` |
| Key utility | `C:\Program Files\Git\usr\bin\ssh-keygen.exe` | Same Git for Windows OpenSSH installation | 543,574 | `604D127D97502B11ED2D1F6F8191BFE86F443D821322E4AB285C961D0E06F5E1` |

The SSH tools have no PE file-version resource and are individually Authenticode **NotSigned**. Their runtime version came from the absolute-path `ssh.exe -V`; the separate `ssh-keyscan.exe` does not support a version flag. The local package manifest labels them `openssh 10.5p1-1` and `openssl 3.5.8-1`.

### Package provenance and executable integrity

Local Windows uninstall metadata reports DisplayName `Git`, DisplayVersion `2.56.0`, Publisher `The Git Development Community`, InstallLocation `C:\Program Files\Git\`, and install date `20260929`; InstallSource is blank. `etc\package-versions.txt` records `mingw-w64-ucrt-x86_64-git 2.56.0.1-1`, `openssh 10.5p1-1`, and `openssl 3.5.8-1`. `etc\install-options.txt` records installation options. The inspected Downloads directory contained no `Git-*.exe` installer. No cryptographically verifiable package-manager record or original installer was available in the inspected local sources.

ACL readback showed the Git `usr\bin` tree is owned by TrustedInstaller, with SYSTEM and Administrators full control and Users read/execute; the individual SSH executables inherit SYSTEM/Administrators full control and Users read/execute. This is evidence of current filesystem access controls, not proof that the installed bits match an authentic upstream release.

**Provenance classification: PARTIALLY VERIFIED.** Installation identity, package component/version metadata, common installation root, ACLs, file sizes, and current SHA-256 identities are locally evidenced. Authentic installer provenance and a trusted reference hash/signature for the currently installed binaries are not. Hashes identify the observed files but do not authenticate them. The valid Microsoft signature applies to the separate Windows OpenSSH binaries, not the Git for Windows package.

### Supported algorithms and strict-host-key capabilities

The Git-bundled client’s local `ssh -Q kex` output was:

```text
diffie-hellman-group1-sha1
diffie-hellman-group14-sha1
diffie-hellman-group14-sha256
diffie-hellman-group16-sha512
diffie-hellman-group18-sha512
diffie-hellman-group-exchange-sha1
diffie-hellman-group-exchange-sha256
ecdh-sha2-nistp256
ecdh-sha2-nistp384
ecdh-sha2-nistp521
mlkem768nistp256-sha256
curve25519-sha256
curve25519-sha256@libssh.org
sntrup761x25519-sha512
sntrup761x25519-sha512@openssh.com
mlkem768x25519-sha256
```

Both requested sntrup names and standard Curve25519 are present. This is local capability evidence; no server negotiation was attempted.

`ssh -Q key` and `ssh -Q key-sig` include `ssh-ed25519`. Effective `ssh -G -F none ...` output includes `ssh-ed25519` in `HostKeyAlgorithms` and confirms the client accepts a dedicated `UserKnownHostsFile`, `StrictHostKeyChecking=yes`, `GlobalKnownHostsFile=NUL`, `UpdateHostKeys=no`, and `BatchMode=yes`. This was configuration expansion only; it did not open a connection or create the named known-hosts file.

### Exact local diagnostic commands executed

The following PowerShell block was executed locally with explicit absolute paths. It performs no network request; `ssh -G` expands configuration only. No keyscan, SSH authentication, or remote command was run in this task.

```powershell
$gitCmd='C:\Program Files\Git\cmd\git.exe'
$gitCore='C:\Program Files\Git\ucrt64\bin\git.exe'
$ssh='C:\Program Files\Git\usr\bin\ssh.exe'
$scan='C:\Program Files\Git\usr\bin\ssh-keyscan.exe'
$keygen='C:\Program Files\Git\usr\bin\ssh-keygen.exe'
$paths=@($gitCmd,$gitCore,$ssh,$scan,$keygen)
Get-Item -LiteralPath $paths | Select-Object FullName,Length,@{n='FileVersion';e={$_.VersionInfo.FileVersion}},@{n='ProductVersion';e={$_.VersionInfo.ProductVersion}} | Format-Table -AutoSize
Get-FileHash -Algorithm SHA256 -LiteralPath $paths | Select-Object Path,Hash | Format-Table -AutoSize
& $gitCmd --version
& $gitCore --version
& $ssh -V 2>&1
& $ssh -Q kex
& $ssh -Q key
& $ssh -Q key-sig
Get-AuthenticodeSignature -FilePath $paths | Select-Object Path,Status | Format-Table -AutoSize
Get-ItemProperty 'HKLM:\Software\Microsoft\Windows\CurrentVersion\Uninstall\Git_is1' | Select-Object DisplayName,DisplayVersion,Publisher,InstallLocation,InstallSource,InstallDate,UninstallString | Format-List
Select-String -LiteralPath 'C:\Program Files\Git\etc\package-versions.txt' -Pattern '(?i)^(mingw-w64-ucrt-x86_64-git |openssh |openssl )' | ForEach-Object { $_.Line }
icacls 'C:\Program Files\Git\usr\bin\ssh.exe'
icacls 'C:\Program Files\Git\usr\bin\ssh-keyscan.exe'
icacls 'C:\Program Files\Git\usr\bin'
& $ssh -G -F none -l deploy -p 22 -o StrictHostKeyChecking=yes -o UserKnownHostsFile=C:/Temp/wpcaregrid-ro00-known_hosts -o GlobalKnownHostsFile=NUL -o UpdateHostKeys=no -o BatchMode=yes -o IdentitiesOnly=yes 85.155.243.164 2>$null | Select-String '^(user|hostname|port|checkhostip|stricthostkeychecking|userknownhostsfile|globalknownhostsfile|updatehostkeys|batchmode|identitiesonly|kexalgorithms|hostkeyalgorithms) '
```

Additional offline provenance checks executed:

```powershell
Select-String -LiteralPath 'C:\Program Files\Git\etc\install-options.txt' -Pattern '(?i)version|architecture|install|command|path|default'
$downloads=Join-Path $env:USERPROFILE 'Downloads'
Get-ChildItem -LiteralPath $downloads -File -Filter 'Git-*.exe'
```

The Downloads search covered only that directory and filename pattern; it does not prove the installer is absent from the entire machine.
### Future RO-00 procedure — design only, NOT EXECUTED

Use the literal IP for both acquisition and subsequent connection. Do not introduce a hostname alias or rely on DNS to bind the key to a different endpoint. Before the new Owner-approved window, verify the provenance of the Git for Windows installation/package through a trusted local record or a separately approved trusted source. Confirm the exact absolute paths and KEX support again; do not assume these hashes remain current.

During that separately approved window:

1. Use the absolute-path `ssh-keyscan.exe` with only ED25519, the approved IP, port 22, and a short timeout. Capture stdout and stderr separately into a temporary, access-restricted local workspace. Do not print candidate key material.
2. Require exit code zero, exactly one well-formed `ssh-ed25519` key for the expected IP, and no error output. Reject empty, duplicate, unexpected-host, extra-key, or malformed output.
3. Use the absolute-path `ssh-keygen.exe -lf <candidate-file> -E sha256` to derive the fingerprint locally. Compare the complete fingerprint exactly to the independently obtained Rackforest Console value. No trust-on-first-use and no authentication before this comparison.
4. Only on an exact match, create a separate temporary known-hosts file containing that verified IP/key line. If comparison or parsing fails, do not create the trusted file and stop.
5. For any separately approved SSH command, use the matching absolute-path `ssh.exe`, connect directly to `deploy@85.155.243.164`, and explicitly set `-F none`, `-p 22`, `-o HostKeyAlgorithms=ssh-ed25519`, `-o StrictHostKeyChecking=yes`, `-o UserKnownHostsFile=<verified-temp-file>`, `-o GlobalKnownHostsFile=NUL`, `-o UpdateHostKeys=no`, `-o BatchMode=yes`, `-o PreferredAuthentications=publickey`, `-o PasswordAuthentication=no`, `-o KbdInteractiveAuthentication=no`, `-o IdentitiesOnly=yes`, `-o IdentityAgent=none`, and `-i <separately approved existing deploy-key path>`. Set a short `ConnectTimeout` and one connection attempt. Do not include a remote command except one exact command separately authorized for RO-01–RO-04.

This uses the same literal IP as the known-hosts entry, avoiding hostname/IP alias ambiguity. `-F none` avoids unexpected per-user/system SSH configuration; explicit strict checking and an isolated user-known-hosts file prevent implicit TOFU or fallback to unrelated trust stores. SSH keyscan itself does not authenticate the host; the independently trusted fingerprint comparison is the trust step.

The exact credential path/method and command list must be approved before a later connection. The private key must remain unread and outside evidence. Any timeout, KEX failure, fingerprint mismatch, host identity ambiguity, credential uncertainty, or unexpected output is an immediate stop. Do not weaken the algorithm set, host-key checks, or server policy to force success.

### Readiness recommendation

**CONDITIONAL — ADDITIONAL EVIDENCE REQUIRED.** Algorithm compatibility and strict host-key options are locally confirmed. Package provenance is only partially verified because the original installer/signature and trusted reference hashes were unavailable, and credential validity is unknown. Do not recommend or use this client for Level 3 until package provenance/integrity is independently established and a new Owner authorization names the exact execution window, target/IP, account, approved existing credential mechanism, commands, evidence handling, and stop authority.

RO-00 remains uncompleted; RO-01–RO-09 were not executed in this diagnostics task. All six live M12 gates remain UNKNOWN. M147-E.2b remains NO-GO / NOT AUTHORIZED.


## Offline provenance, credential and RO-00 readiness review — 2026-10-18

**Scope:** local/offline review plus GitHub PR metadata/CI readback. No staging connection, keyscan, authentication, remote command, credential/agent inspection, M12 read, or software installation occurred.

### Current decision summary

| Area | Result | Evidence / blocker |
|---|---|---|
| Git for Windows client provenance | **PARTIALLY VERIFIED; insufficient for approval** | Local registry/package metadata, shared installation root, ACLs and file identity hashes exist. No original installer or cryptographically trusted package-manager installation record is available. Hashes identify local files only; they do not authenticate them. |
| SSH compatibility | **PASS locally** | Absolute-path Git-bundled OpenSSH 10.5p1 reports OpenSSL 3.5.8; KEX query includes sntrup761x25519-sha512@openssh.com and curve25519-sha256. Host-key query includes ssh-ed25519. Local config expansion supports strict host-key checking and a dedicated user-known-hosts file. No server negotiation was performed. |
| Approved `deploy` credential-access mechanism | **UNKNOWN** | No Owner-approved mechanism is evidenced. No credential path, private-key material, authentication agent, or credential store was inspected or tested. RO-00 itself needs no SSH authentication; later RO-01–RO-04 would require separate approval. |
| Documentation | **PASS for this review package, subject to CI** | This report and the companion draft authorization record specify provenance blockers, a fail-closed future RO-00 process, and blank date/window fields. |
| Exact-head CI | **PENDING at reviewed head; prior to this review's documentation update** | PR #129 head `99c8df55127c4ccf60d9c923d5b2989da9422345` run 39147411113 was in progress at readback. Quality and Connector SQLite/MySQL/MariaDB were successful; Server SQLite, Server MySQL, and Server MariaDB were still in progress. The MySQL concurrency step was pending, not passed. A documentation commit changes the PR head and requires a new exact-head result. |

### Reliable installation provenance method

The local uninstall entry (`Git`, version 2.56.0, publisher string, install path/date) and Git's bundled package-version manifest are inventory metadata, not cryptographic proof of package origin. An empty `InstallSource`, absence of an installer in the inspected Downloads pattern, and individual binaries marked `NotSigned` leave a provenance gap. The local SHA-256 values above are reproducible file identifiers, not authenticity evidence.

A defensible verification of the existing installation requires a chain that binds a trusted official package to the installed files. Preferred procedure for a separately authorized offline/provenance task:

1. Locate an already-present exact-version Git for Windows 2.56.0 installer or a package-manager cached artifact and its associated package metadata. Do not fetch, install, replace, or execute software as part of this review.
2. Verify the installer's Authenticode signature status and certificate chain locally. Confirm the signer identity against an independently trusted Git for Windows publisher identity; a merely `Valid` status or display-name string alone is insufficient. Record the signer certificate thumbprint and chain result, without trusting an unverified certificate.
3. If the package manager supplies a signed/catalog-backed artifact record, verify that record and exact package digest. A registry uninstall entry or package-version text file is not such a record.
4. Bind the currently installed SSH tools to that verified package: compare their bytes/hashes to the corresponding files from the exact verified package payload, using an already-approved, trusted extraction/inspection method that does not run the installer. If the exact installer/package payload or a trusted signed manifest cannot be obtained locally, classify installed-file authenticity as **UNKNOWN**.
5. If the original artifact is absent, a future Owner-approved acquisition could use only the official Git for Windows release channel, then verify the artifact's signature and independently anchored exact-version checksum/release metadata before inspecting its payload. That acquisition is outside this task and needs separate authorization. Do not trust a checksum copied from an unverified mirror or use the installed-file hash as its own reference.

No official reference checksum/signature was retrieved or checked in this offline task. Therefore this installation is not approved for a new Level 3 connection yet.

### Credential readiness

No approved access mechanism for the existing `deploy` account could be established from the available evidence. This is **not** a finding that the credential is absent or invalid. It remains unknown. The new authorization record deliberately does not ask for a key, token, agent listing, or credential test. RO-00 is unauthenticated; any later authenticated read-only operation must name a separate, approved credential-use mechanism and must not disclose the credential.

### Proposed minimal RO-00 sequence — design only, NOT EXECUTED

Prerequisites: separate currently valid Owner authorization record; exact future date/window; target and trusted fingerprint freshly confirmed from the independent Rackforest Console; provenance-verified Git for Windows package; exact matching absolute paths for `ssh-keyscan.exe`, `ssh-keygen.exe`, and (only if a later authorized SSH connection is separately approved) `ssh.exe`. Use a local temporary directory with restrictive ACL. Do not rely on DNS or an SSH alias.

The sequence below is a design sketch; it has not run. PowerShell should treat nonzero exit, unexpected stderr, empty output, more than one key, malformed output, or fingerprint mismatch as fatal:

```powershell
$ErrorActionPreference = 'Stop'
$target = '85.155.243.164'
$expected = 'SHA256:7eMFqVIvqAipy7heD3YlizeroorFjSw4N/Ep5Ku7irQ'
$scan = 'C:\Program Files\Git\usr\bin\ssh-keyscan.exe'
$keygen = 'C:\Program Files\Git\usr\bin\ssh-keygen.exe'
# Allocate an access-restricted temporary directory; capture stdout and stderr separately.
# Invoke only: & $scan -4 -T 5 -p 22 -t ed25519 $target
# Require exit code 0 and exactly one non-empty output record.
# Accept only host token 85.155.243.164 (or the documented [85.155.243.164]:22 form),
# key type ssh-ed25519, and valid base64 key bytes. Reject comments/extra records.
# Write the single candidate line to a temporary candidate file; never trust it yet.
# Invoke: & $keygen -lf $candidateFile -E sha256
# Require exit code 0 and exactly one parsed SHA256 fingerprint.
# Compare the full fingerprint using ordinal exact equality to $expected.
# On mismatch or any error: delete local temporaries, report BLOCKED, stop.
# Only after an exact match, write a canonical
# "85.155.243.164 ssh-ed25519 <validated-base64-key>" line into a separate
# temporary known_hosts evidence file. Keep it local and temporary.
```

Use the literal IP for both scan and later lookup, so a hostname/IP alias cannot redirect which known-host entry is checked. Do not use implicit TOFU. Do not authenticate during RO-00. If a later operation is separately authorized, its SSH invocation must use the same verified absolute-path client, `-F none`, `-o HostKeyAlgorithms=ssh-ed25519`, `-o StrictHostKeyChecking=yes`, `-o UserKnownHostsFile=<the verified temporary file>`, `-o GlobalKnownHostsFile=NUL`, and `-o UpdateHostKeys=no`; it must fail closed if any trust file or identity is missing. RO-00 does not authorize that later connection or any remote command.

### New authorization record and outstanding prerequisites

The companion document [M147-E.2a RO-00 authorization record](m14-7-e2a-ro00-authorization-record.md) is a **draft**, not execution approval. It leaves the execution date and start/end window blank and also requires the Owner to identify the operator, authorization reference, client package, local execution context, evidence handling, and stop contact. It limits scope to unauthenticated RO-00 only. The existing deploy credential mechanism is explicitly UNKNOWN and is not required for RO-00.

Until provenance is cryptographically established and a new record is completed and approved, recommendation is **NO-GO for a retry**. No staging operation occurred in this review; the six live M12 values remain UNKNOWN; M147-E.2b remains NO-GO / NOT AUTHORIZED.
