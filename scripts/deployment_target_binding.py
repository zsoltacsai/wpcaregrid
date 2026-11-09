#!/usr/bin/env python3
"""Closed, fail-closed deployment target binding for staging tooling.

Two deployment targets exist and nothing else can be named:

* ``legacy-staging`` - the original staging application and database. Values
  mirror the constants hardcoded in the legacy deploy/recovery tools and are
  preserved byte-for-byte so those tools keep their behaviour.
* ``staging-next`` - the isolated staging application directory and database,
  derived from the compiled ``deployment_environment_contract.STAGING_NEXT``
  identity (never re-declared here).

Production is deliberately *not* selectable through this module; it is owned
by the separately approved production profile. A target is chosen by exact
name only. Callers can never supply an application path, database name,
marker, lock or backup location: every such value is checked against the
compiled binding, and a value that belongs to the other target is a STOP.

The module is pure policy (no mutation, no subprocess, no network). Filesystem
inspection goes through an injectable ``lstat`` so every check is testable on
synthetic fixtures without touching a real host.
"""

from __future__ import annotations

import importlib.util
import os
import re
import stat
import sys
import types
from dataclasses import dataclass
from pathlib import Path, PurePosixPath
from types import MappingProxyType
from typing import Callable, Mapping


class TargetRefused(ValueError):
    """A stable, non-sensitive STOP reason for target binding failures."""


def _load_contract():
    path = Path(__file__).resolve().with_name("deployment_environment_contract.py")
    name = "_wpcg_target_binding_environment_contract"
    previous = sys.modules.get(name)
    preloaded = globals().get("_PRELOADED_CONTRACT_SOURCE")
    try:
        if preloaded is not None:
            # A privileged loader already authenticated these exact bytes; do not
            # read the contract from disk a second time.
            if not isinstance(preloaded, bytes):
                raise TargetRefused("environment_contract_unavailable")
            module = types.ModuleType(name)
            module.__file__ = str(path)
            sys.modules[name] = module  # needed while dataclasses are created
            exec(compile(preloaded, str(path), "exec", dont_inherit=True), module.__dict__)
            return module
        spec = importlib.util.spec_from_file_location(name, path)
        module = importlib.util.module_from_spec(spec)
        sys.modules[name] = module  # needed while dataclasses are created
        spec.loader.exec_module(module)
        if Path(module.__file__).resolve() != path:
            raise TargetRefused("environment_contract_unavailable")
        return module
    except TargetRefused:
        raise
    except (Exception, SystemExit):
        raise TargetRefused("environment_contract_unavailable") from None
    finally:
        # Never leave (or keep trusting) a module registered under a private name.
        if previous is None:
            sys.modules.pop(name, None)
        else:
            sys.modules[name] = previous


@dataclass(frozen=True)
class DeploymentTarget:
    name: str
    app_dir: str
    database: str
    state_root: str
    backup_root: str
    backup_name_pattern: str
    lock_file: str
    maintenance_marker: str
    worker_fence_marker: str
    scheduler_fence_marker: str
    extra_locks: tuple[str, ...] = ()

    def locks(self) -> tuple[str, ...]:
        return (self.lock_file, *self.extra_locks)

    def owned_roots(self) -> tuple[str, ...]:
        return (self.app_dir, self.state_root, self.backup_root)

    def marker_paths(self) -> tuple[str, ...]:
        return (self.maintenance_marker, self.worker_fence_marker, self.scheduler_fence_marker)


# Mirrors scripts/recover-staging-deployment.py, deploy-staging.sh and
# run-scheduler.sh. Changing a value here is a behaviour change of the legacy
# environment and must be reviewed as such.
LEGACY_STAGING = DeploymentTarget(
    name="legacy-staging",
    app_dir="/var/www/wpcaregrid",
    database="wpcaregrid",
    state_root="/var/lib/wpcaregrid",
    backup_root="/home/deploy/private-backups",
    backup_name_pattern=(
        r"wpcaregrid-pre-(?:deploy-[0-9]{8}T[0-9]{6}Z|recovery-[0-9a-f]{40}-[0-9a-f]{16})\.sql\.gz"
    ),
    lock_file="/home/deploy/private-backups/.deploy.lock",
    maintenance_marker="/var/lib/wpcaregrid/deploy-maintenance",
    worker_fence_marker="/var/lib/wpcaregrid/workers-disabled",
    scheduler_fence_marker="/var/lib/wpcaregrid/scheduler-paused",
    extra_locks=("/var/lib/wpcaregrid/scheduler.lock",),
)


def _staging_next() -> DeploymentTarget:
    try:
        contract = _load_contract().STAGING_NEXT
        state = contract.state_root
        state_root = str(PurePosixPath(state).parent)
        app_root, database, backup_root = contract.app_root, contract.database, contract.backup_root
        markers = (contract.maintenance_marker, contract.worker_fence_marker, contract.scheduler_fence_marker)
    except TargetRefused:
        raise
    except Exception:
        raise TargetRefused("environment_contract_unavailable") from None
    return DeploymentTarget(
        name="staging-next",
        app_dir=app_root,
        database=database,
        state_root=state_root,
        backup_root=backup_root,
        backup_name_pattern=r"staging-next-[0-9a-f]{40}-[0-9]{1,30}-[0-9a-f]{8}\.sql\.gz",
        lock_file=f"{state}/deploy.lock",
        maintenance_marker=markers[0],
        worker_fence_marker=markers[1],
        scheduler_fence_marker=markers[2],
    )


STAGING_NEXT = _staging_next()


def _validate_registry(targets) -> None:
    """Refuse a registry whose targets are malformed or overlap each other."""
    seen_roots: list[PurePosixPath] = []
    for target in targets:
        values = (target.app_dir, target.state_root, target.backup_root, target.lock_file,
                  *target.extra_locks, *target.marker_paths())
        for value in values:
            path = PurePosixPath(value) if isinstance(value, str) else None
            if (path is None or not path.is_absolute() or str(path) != value
                    or any(part in {"", ".", ".."} for part in path.parts[1:])):
                raise TargetRefused("target_registry_invalid")
        if not all(_within(PurePosixPath(m), target.state_root) for m in target.marker_paths()):
            raise TargetRefused("target_registry_invalid")
        for root in map(PurePosixPath, target.owned_roots()):
            if any(root == other or root in other.parents or other in root.parents for other in seen_roots):
                raise TargetRefused("target_registry_invalid")
        seen_roots.extend(map(PurePosixPath, target.owned_roots()))
    if len({t.database for t in targets}) != len(list(targets)):
        raise TargetRefused("target_registry_invalid")
    if len({m for t in targets for m in t.marker_paths()}) != 3 * len(list(targets)):
        raise TargetRefused("target_registry_invalid")

TARGETS: Mapping[str, DeploymentTarget] = MappingProxyType({
    LEGACY_STAGING.name: LEGACY_STAGING,
    STAGING_NEXT.name: STAGING_NEXT,
})

_PATH_KINDS = frozenset({"marker", "lock", "backup", "state", "app"})


def select_target(name: object) -> DeploymentTarget:
    """Resolve a target by exact, case-sensitive name. Anything else is STOP."""
    if not isinstance(name, str) or not name:
        raise TargetRefused("target_missing")
    if name == "production" or name.startswith("production"):
        raise TargetRefused("production_target_not_selectable")
    target = TARGETS.get(name)
    if target is None:
        raise TargetRefused("target_unknown")
    return target


def _within(path: PurePosixPath, root: str) -> bool:
    base = PurePosixPath(root)
    return path == base or base in path.parents


def _syntax(value: object) -> PurePosixPath:
    if not isinstance(value, str) or not value or "\x00" in value:
        raise TargetRefused("path_invalid")
    path = PurePosixPath(value)
    if (not path.is_absolute() or str(path) != value
            or any(part in {"", ".", ".."} for part in path.parts[1:])):
        raise TargetRefused("path_invalid")
    return path


def _registered(target: object) -> DeploymentTarget:
    if (not isinstance(target, DeploymentTarget) or type(target.name) is not str
            or TARGETS.get(target.name) is not target):
        raise TargetRefused("target_not_registered")
    return target


def _other_targets(target: DeploymentTarget):
    return [other for other in TARGETS.values() if other.name != target.name]


def validate_pair(target: DeploymentTarget, app_dir: object, database: object) -> None:
    """The application directory and database must be exactly the bound pair."""
    _registered(target)
    if not isinstance(app_dir, str) or not isinstance(database, str):
        raise TargetRefused("target_pair_invalid")
    for other in _other_targets(target):
        if app_dir == other.app_dir or database == other.database:
            raise TargetRefused("target_pair_cross_environment")
    if app_dir != target.app_dir or database != target.database:
        raise TargetRefused("target_pair_mismatch")


def _allowed_roots(target: DeploymentTarget, kind: str) -> tuple[str, ...]:
    if kind == "marker":
        return target.marker_paths()
    if kind == "lock":
        return target.locks()
    if kind == "backup":
        return (target.backup_root,)
    if kind == "state":
        return (target.state_root,)
    return (target.app_dir,)


def check_path(
    target: DeploymentTarget,
    value: object,
    kind: str,
    *,
    lstat_fn: Callable[[str], os.stat_result] = os.lstat,
    must_exist: bool = True,
    expected_uid: int | None = None,
) -> str:
    """Validate a caller-visible path against the bound target.

    Refuses: non-normalised/relative/traversal paths, paths that belong to the
    other target, paths outside the target, any symlink component, and (when
    ``expected_uid`` is given) wrong owner or group/other-writable mode.
    """
    _registered(target)
    if kind not in _PATH_KINDS:
        raise TargetRefused("path_kind_unknown")
    path = _syntax(value)
    for other in _other_targets(target):
        if any(_within(path, root) for root in _allowed_roots(other, kind)) or any(
                _within(path, root) for root in other.owned_roots()):
            raise TargetRefused("path_belongs_to_other_target")
    roots = _allowed_roots(target, kind)
    if kind in {"marker", "lock"}:
        # Single files: exact match only, never a child path.
        if str(path) not in roots:
            raise TargetRefused("path_outside_target")
    elif not any(_within(path, root) for root in roots):
        raise TargetRefused("path_outside_target")

    current = PurePosixPath("/")
    info = None
    for component in path.parts[1:]:
        current /= component
        try:
            info = lstat_fn(str(current))
        except FileNotFoundError:
            if must_exist:
                raise TargetRefused("path_missing") from None
            info = None
            break
        except Exception:
            raise TargetRefused("path_unavailable") from None
        try:
            is_link = stat.S_ISLNK(info.st_mode)
        except Exception:
            raise TargetRefused("path_unavailable") from None
        if is_link:
            raise TargetRefused("path_symlink")
    if info is not None and expected_uid is not None:
        try:
            wrong = info.st_uid != expected_uid or bool(stat.S_IMODE(info.st_mode) & 0o022)
        except Exception:
            raise TargetRefused("path_unavailable") from None
        if wrong:
            raise TargetRefused("path_permission_mismatch")
    return str(path)


def check_backup(
    target: DeploymentTarget,
    value: object,
    *,
    lstat_fn: Callable[[str], os.stat_result] = os.lstat,
    expected_uid: int | None = None,
) -> str:
    """A backup is usable only if it sits in, and is named for, this target."""
    _registered(target)
    try:
        checked = check_path(target, value, "backup", lstat_fn=lstat_fn, expected_uid=expected_uid)
    except TargetRefused as error:
        if str(error) == "path_belongs_to_other_target":
            raise TargetRefused("backup_belongs_to_other_target") from None
        raise
    candidate = PurePosixPath(checked)
    if candidate.parent != PurePosixPath(target.backup_root) \
            or re.fullmatch(target.backup_name_pattern, candidate.name) is None:
        raise TargetRefused("backup_identity_invalid")
    try:
        info = lstat_fn(checked)
        regular = stat.S_ISREG(info.st_mode) and info.st_nlink == 1
    except Exception:
        raise TargetRefused("path_unavailable") from None
    if not regular:
        raise TargetRefused("backup_not_regular_file")
    return checked


def check_marker_document(target: DeploymentTarget, document: object) -> None:
    """A state marker/journal naming any other target is STOP; it must name this one."""
    _registered(target)
    try:
        recorded = document["target"] if isinstance(document, Mapping) and "target" in document else None
        present = isinstance(document, Mapping) and "target" in document
    except Exception:
        raise TargetRefused("marker_target_missing") from None
    if not present:
        raise TargetRefused("marker_target_missing")
    if type(recorded) is not str or recorded != target.name:
        raise TargetRefused("marker_belongs_to_other_target")


def check_fences(target: DeploymentTarget, exists_fn: Callable[[str], bool]) -> None:
    """Both fences of *this* target must be active; the other target's are irrelevant."""
    _registered(target)
    def active(path: str) -> bool:
        try:
            return exists_fn(path) is True
        except Exception:
            raise TargetRefused("fence_check_unavailable") from None

    if not active(target.worker_fence_marker):
        raise TargetRefused("worker_fence_inactive")
    if not active(target.scheduler_fence_marker):
        raise TargetRefused("scheduler_fence_inactive")


_COMPLETE_PHASES = frozenset({"idle", "completed", "rolled_back"})
_IN_FLIGHT_PHASES = frozenset({
    "gate_closed", "backup_verified", "released", "migrating", "migrated", "verifying", "failed",
})


def recovery_decision(
    target: DeploymentTarget,
    journal_target: object,
    phase: object,
    *,
    maintenance_active: bool,
    workers_fenced: bool,
    scheduler_fenced: bool,
) -> str:
    """Deterministic crash-recovery classification.

    Returns ``"none"`` for a settled target or ``"recover"`` for an interrupted
    deploy whose gate and fences are all still in place. Everything else
    (other target's journal, unknown phase, interrupted deploy with a missing
    gate or fence) raises ``TargetRefused`` so nothing is replayed blindly.
    """
    _registered(target)
    if not all(type(flag) is bool for flag in (maintenance_active, workers_fenced, scheduler_fenced)):
        raise TargetRefused("recovery_flags_invalid")
    if not isinstance(phase, str):
        raise TargetRefused("recovery_phase_unknown")
    if journal_target != target.name:
        raise TargetRefused("journal_belongs_to_other_target")
    if phase in _COMPLETE_PHASES:
        if maintenance_active or not (workers_fenced is False and scheduler_fenced is False):
            # A "settled" phase that still holds gate/fences is ambiguous.
            raise TargetRefused("settled_phase_with_active_gate")
        return "none"
    if phase not in _IN_FLIGHT_PHASES:
        raise TargetRefused("recovery_phase_unknown")
    if not (maintenance_active and workers_fenced and scheduler_fenced):
        raise TargetRefused("partial_deploy_gate_missing")
    return "recover"


# Real transaction journal states of the staging-next database release
# (scripts/staging_next_db_transaction_*.py). Order is the progression order.
TRANSACTION_STATES = (
    "PREPARED", "BACKUP_VERIFIED", "RESTORE_REHEARSAL_VERIFIED", "MIGRATION_REHEARSAL_VERIFIED",
    "FENCES_CLOSED", "MIGRATING", "MIGRATIONS_VERIFIED", "RELEASE_ACTIVATED",
    "ACCEPTANCE_VERIFIED", "COMPLETE", "RECOVERY_REQUIRED",
)
_FENCED_STATES = frozenset(TRANSACTION_STATES[4:9])


def check_journal_identity(target: DeploymentTarget, journal: object) -> None:
    """A transaction journal must name exactly this target's profile and database."""
    _registered(target)
    try:
        profile = journal["profile"] if isinstance(journal, Mapping) else None
        database = journal["database"] if isinstance(journal, Mapping) else None
    except Exception:
        raise TargetRefused("journal_identity_missing") from None
    if profile is None or database is None:
        raise TargetRefused("journal_identity_missing")
    if type(profile) is not str or type(database) is not str:
        raise TargetRefused("journal_belongs_to_other_target")
    if profile != target.name or database != target.database:
        raise TargetRefused("journal_belongs_to_other_target")


def transaction_state_decision(target: DeploymentTarget, state: object, *, fences_verified: object) -> str:
    """Classify a real journal state for resumption; anything uncertain is a STOP.

    ``"resume"`` - the interrupted release may continue under the existing,
    independently re-verified gate; ``"none"`` - nothing to recover.
    A state past the fence step is resumable only when the caller has just
    verified the worker/scheduler fences; ``RECOVERY_REQUIRED`` and unknown
    states are never resumed automatically.
    """
    _registered(target)
    if type(fences_verified) is not bool:
        raise TargetRefused("recovery_flags_invalid")
    if not isinstance(state, str) or state not in TRANSACTION_STATES:
        raise TargetRefused("recovery_phase_unknown")
    if state == "COMPLETE":
        return "none"
    if state == "RECOVERY_REQUIRED":
        raise TargetRefused("manual_recovery_required")
    if state in _FENCED_STATES and not fences_verified:
        raise TargetRefused("partial_deploy_gate_missing")
    return "resume"


_validate_registry(tuple(TARGETS.values()))
