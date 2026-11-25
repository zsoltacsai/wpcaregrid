# Production-only confinement acceptance compatibility 1.0.15

Package 1.0.15 is an immutable acceptance-tool successor to 1.0.14. The nine installed runtime files, including every AppArmor profile, PHP-FPM file, and systemd unit, are byte-for-byte unchanged. Helper 2.1.11 remains bound to runtime package 1.0.12; this acceptance-only update does not alter or install the helper.

## Gate 2 sibling-socket canary

On the target host, `/run/mysqld` is owned by the MySQL service account and must keep its existing ownership and mode. Package 1.0.14 incorrectly required that directory itself to be root-owned, so its pinned sibling listener refused to start. Version 1.0.15 places the canary at `/run/mysqld/wpcaregrid-acceptance/canary.sock`. The dedicated parent must be a real `root:root` directory with mode `0755`, and the listener refuses any other parent, path, owner, group, mode, symlink, or pre-existing socket.

After verifying the package pins and confirming the path is absent, create only the dedicated acceptance directory with the root console:

```sh
install -d -o root -g root -m 0755 /run/mysqld/wpcaregrid-acceptance
```

This does not change `/run/mysqld` metadata. The canary remains beneath the same `/run/mysqld` tree as the sibling-socket probes. The build CLI profile denies Unix sockets; the deploy CLI profile grants only its exact production socket and denies other socket paths by its AppArmor path policy. The pinned verifier still requires the baseline to read the fixed banner and the confined probe to produce a matching denial record; this repository-only change does not claim live enforcement. Both results and correlated audit evidence remain mandatory. A missing listener, failed baseline connection, missing denial, inventory churn, or incomplete evidence is HOLD. Stop the pinned listener after the matrix completes and verify its socket path is absent.

The host-specific parent directory is temporary acceptance setup, not a production runtime dependency. Do not place secrets there. Do not continue to helper installation, secret placement, DB/application checks, or route opening from a Gate 2 result; those remain separate gates.

## Validation

The 1.0.15 regression test verifies package checksums, binding and installed runtime-file identity, immutable predecessor bytes, the fixed matrix path, fd-pinned parent handling, and the profiles' exact socket/network allowlists. Ubuntu 26.04 CI continues to compile the unchanged runtime profiles and validate the packaged unit.
