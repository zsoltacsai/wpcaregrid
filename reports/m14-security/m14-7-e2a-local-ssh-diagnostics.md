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
