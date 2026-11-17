#!/usr/bin/env python3
"""The rollback order as an executable, fault-testable state machine.

Invariant I: no same-UID peer may be returned to unconfined while any production secret-bearing path is
still readable by UID 33, production is still routed or running, or a production job unit is not masked.
This module encodes the runbook's R1..R3 order against an abstract ``host`` so the order can be proven
under injected faults. It performs nothing itself; a real host object is a separate, reviewed change.

Every unconfine is preceded by a fresh fence verification (the fence is re-proven for each peer, not
assumed from an earlier step) and followed by another one, so a re-exposure after one peer's restart
(for example a deploy re-creating a world-readable ``config.php``) stops the remaining peers.
The first failure raises ``RollbackAborted`` and nothing after it runs; re-running is safe because every
step is idempotent and the preconditions are re-proven from the start. Production job units are only ever
checked, never unmasked.
"""
from __future__ import annotations

# Newest stage first: edge/jobs (Stage 3), then staging-next FPM (Stage 2), then the shared legacy pool (Stage 1).
PEERS_NEWEST_FIRST = ("jobs-legacy-scheduler", "jobs-scheduler", "jobs-supervisor", "nginx", "staging-next", "staging-legacy")
PRODUCTION_CLI_LABELS = (
    "wpcaregrid-php-cli-production-deploy (enforce)",
    "wpcaregrid-php-cli-production-build (enforce)",
)


class RollbackAborted(Exception):
    def __init__(self, step: str, reason: str, log: list) -> None:
        super().__init__(f"{step}: {reason}")
        self.step, self.reason, self.log = step, reason, list(log)


def run_rollback(host, peers=PEERS_NEWEST_FIRST) -> list:
    log: list = []

    def step(name: str, call) -> None:
        log.append(name)
        try:
            call()
        except RollbackAborted:
            raise
        except Exception as error:  # any fault stops the sequence before the next, more dangerous step
            raise RollbackAborted(name, str(error) or type(error).__name__, log) from error

    def require_fenced(name: str) -> None:
        def check() -> None:
            exposed = host.exposed_paths()
            if exposed:
                raise RuntimeError(f"{len(exposed)} production path(s) still readable by uid 33, first: {sorted(exposed)[0]}")
            if host.route_open() or host.production_service_running():
                raise RuntimeError("production is still routed or running")
            if not host.jobs_masked():
                raise RuntimeError("a production job unit is not masked and inactive")
            if host.production_cli_running(PRODUCTION_CLI_LABELS):
                raise RuntimeError("a production deploy or build CLI is still running: it can read production secrets")
        step(name, check)

    def no_production_cli() -> None:
        # Detect both exact enforce labels from /proc/<pid>/attr/current, never by process name.
        if host.production_cli_running(PRODUCTION_CLI_LABELS):
            raise RuntimeError("a production deploy or build CLI is running: it reads production secrets and would become unconfined if its profile is removed")

    # Check before the first rollback mutation, then again after stopping production in case a CLI
    # started during the route/service transition.
    step("R1:assert_no_production_cli", no_production_cli)
    step("R1:close_route", host.close_route)
    step("R1:stop_production_service", host.stop_production_service)
    step("R1:assert_jobs_masked", lambda: (_ for _ in ()).throw(RuntimeError("production job units are not masked and inactive"))
         if not host.jobs_masked() else None)
    step("R1:assert_no_production_cli_after_stop", no_production_cli)
    step("R2:fence_secrets", host.fence_secrets)
    for peer in peers:
        require_fenced(f"R3:require_fenced_before:{peer}")
        step(f"R3:unconfine:{peer}", lambda peer=peer: host.unconfine(peer))
        step(f"R3:restart:{peer}", lambda peer=peer: host.restart(peer))
        require_fenced(f"R3:require_fenced_after:{peer}")
    step("R4:assert_no_production_cli", no_production_cli)  # either CLI would go unconfined with its profile
    step("R4:remove_artifacts", host.remove_artifacts)
    return log
