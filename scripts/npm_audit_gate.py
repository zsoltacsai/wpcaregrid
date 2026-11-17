#!/usr/bin/env python3
"""npm advisory gate with one strictly scoped, expiring, owner-approved exception.

Replaces the former bare high-threshold ``npm audit`` CI step (docs/SECURITY_EXCEPTIONS.md).
The threshold is unchanged: only ``high`` and ``critical`` vulnerabilities are
evaluated. A vulnerability entry is accepted only when every advisory in its
(transitive) ``via`` closure is exactly the excepted advisory in
config/security/npm-audit-exceptions.json and every affected package is on the
policy allowlist. Everything else, and every unexpected input, fails closed.

Standard library only. Exit code 0 = pass, 1 = fail.
"""
from __future__ import annotations

import argparse
import datetime as dt
import json
import re
import subprocess
import sys
import time
from pathlib import Path
from typing import Callable, Optional

ROOT = Path(__file__).resolve().parents[1]
POLICY_PATH = ROOT / "config/security/npm-audit-exceptions.json"
EVALUATED_SEVERITIES = ("high", "critical")
ALL_SEVERITIES = ("info", "low", "moderate", "high", "critical")
STABLE_VERSION = re.compile(r"^(\d+)\.(\d+)\.(\d+)$")
LOCK_DISALLOWED_FLAGS = ("optional", "devOptional", "peer", "inBundle")
REGISTRY_ATTEMPTS = 3
REGISTRY_RETRY_DELAY_SECONDS = 5

RegistryLookup = Callable[[str], dict]


class GateResult:
    def __init__(self) -> None:
        self.failures: list[str] = []
        self.notes: list[str] = []

    def fail(self, message: str) -> None:
        if message not in self.failures:
            self.failures.append(message)

    def note(self, message: str) -> None:
        self.notes.append(message)

    @property
    def passed(self) -> bool:
        return not self.failures


# --------------------------------------------------------------------------- policy


def load_policy(path: Path) -> dict:
    try:
        policy = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError) as error:
        raise ValueError(f"exception policy unreadable: {path}: {error}") from error
    validate_policy(policy)
    return policy


def validate_policy(policy: object) -> None:
    if not isinstance(policy, dict) or policy.get("schema_version") != 1:
        raise ValueError("exception policy: schema_version 1 required")
    advisory = policy.get("advisory")
    if not isinstance(advisory, dict):
        raise ValueError("exception policy: advisory object required")
    if not isinstance(advisory.get("source_id"), int) or isinstance(advisory.get("source_id"), bool):
        raise ValueError("exception policy: advisory.source_id must be an integer")
    for key in ("url", "package", "vulnerable_range"):
        if not isinstance(advisory.get(key), str) or not advisory[key]:
            raise ValueError(f"exception policy: advisory.{key} required")
    if not advisory["url"].endswith("/" + str(policy.get("id"))):
        raise ValueError("exception policy: id must be the GHSA id at the end of advisory.url")
    gate = policy.get("fixed_version_gate")
    if not isinstance(gate, dict) or gate.get("package") != advisory["package"]:
        raise ValueError("exception policy: fixed_version_gate must name the advisory package")
    if parse_stable(str(gate.get("max_vulnerable_version"))) is None:
        raise ValueError("exception policy: fixed_version_gate.max_vulnerable_version must be x.y.z")
    allowed = policy.get("allowed_packages")
    if not isinstance(allowed, list) or not allowed or not all(isinstance(name, str) and name for name in allowed):
        raise ValueError("exception policy: allowed_packages must be a non-empty list of names")
    if advisory["package"] not in allowed:
        raise ValueError("exception policy: advisory package must be allowed")
    for key in ("dependency_chain", "compensating_controls"):
        value = policy.get(key)
        if not isinstance(value, list) or not value or not all(isinstance(item, str) and item for item in value):
            raise ValueError(f"exception policy: {key} must be a non-empty list of strings")
    for key in ("accepted_risk", "owner_approval", "renewal", "removal"):
        if not isinstance(policy.get(key), str) or not policy[key]:
            raise ValueError(f"exception policy: {key} required")
    try:
        dt.date.fromisoformat(str(policy.get("expires")))
    except ValueError as error:
        raise ValueError("exception policy: expires must be an ISO date") from error


def parse_stable(version: str) -> Optional[tuple[int, int, int]]:
    match = STABLE_VERSION.match(version)
    return tuple(int(part) for part in match.groups()) if match else None


# --------------------------------------------------------------------------- audit


def advisory_matches(via: dict, policy: dict) -> bool:
    advisory = policy["advisory"]
    return (
        via.get("source") == advisory["source_id"]
        and not isinstance(via.get("source"), bool)
        and via.get("url") == advisory["url"]
        and via.get("name") == advisory["package"]
        and via.get("range") == advisory["vulnerable_range"]
    )


def parse_report(text: str, npm_exit_code: int, result: GateResult) -> Optional[dict]:
    if npm_exit_code not in (0, 1):
        result.fail(f"npm audit exited with code {npm_exit_code} (only 0 and 1 are valid)")
        return None
    if not text or not text.strip():
        result.fail("npm audit produced no output")
        return None
    try:
        report = json.loads(text)
    except ValueError as error:
        result.fail(f"npm audit output is not valid JSON (empty or truncated?): {error}")
        return None
    if not isinstance(report, dict):
        result.fail("npm audit output is not a JSON object")
        return None
    if "error" in report:
        result.fail("npm audit reported an error: " + json.dumps(report["error"], sort_keys=True)[:400])
        return None
    version = report.get("auditReportVersion")
    if version != 2 or isinstance(version, bool):
        result.fail(f"unsupported auditReportVersion {version!r} (exactly 2 required)")
        return None
    vulnerabilities = report.get("vulnerabilities")
    metadata = report.get("metadata")
    if not isinstance(vulnerabilities, dict):
        result.fail("audit report has no 'vulnerabilities' object")
        return None
    counts = metadata.get("vulnerabilities") if isinstance(metadata, dict) else None
    if not isinstance(counts, dict):
        result.fail("audit report has no 'metadata.vulnerabilities' object")
        return None
    for severity in ALL_SEVERITIES + ("total",):
        value = counts.get(severity)
        if not isinstance(value, int) or isinstance(value, bool) or value < 0:
            result.fail(f"metadata.vulnerabilities.{severity} missing or not a count")
            return None
    return report


def check_report_consistency(report: dict, npm_exit_code: int, result: GateResult) -> bool:
    vulnerabilities = report["vulnerabilities"]
    counts = report["metadata"]["vulnerabilities"]
    ok = True
    tally = {severity: 0 for severity in ALL_SEVERITIES}
    for key, entry in vulnerabilities.items():
        if not isinstance(entry, dict) or entry.get("name") != key:
            result.fail(f"malformed vulnerability entry {key!r}")
            ok = False
            continue
        severity = entry.get("severity")
        if severity not in tally:
            result.fail(f"vulnerability {key!r} has unknown severity {severity!r}")
            ok = False
            continue
        if not isinstance(entry.get("via"), list) or not isinstance(entry.get("nodes"), list):
            result.fail(f"vulnerability {key!r} has malformed via/nodes")
            ok = False
            continue
        tally[severity] += 1
    if not ok:
        return False
    for severity in ALL_SEVERITIES:
        if tally[severity] != counts[severity]:
            result.fail(
                f"metadata count mismatch for {severity}: metadata says {counts[severity]}, "
                f"vulnerabilities list has {tally[severity]}"
            )
            ok = False
    if counts["total"] != len(vulnerabilities) or counts["total"] != sum(counts[s] for s in ALL_SEVERITIES):
        result.fail("metadata total does not match the vulnerabilities list")
        ok = False
    if npm_exit_code == 0 and vulnerabilities:
        result.fail("npm audit exited 0 but the report lists vulnerabilities")
        ok = False
    if npm_exit_code == 1 and not vulnerabilities:
        result.fail("npm audit exited 1 but the report lists no vulnerabilities")
        ok = False
    return ok


def explain_entry(name: str, vulnerabilities: dict, policy: dict, result: GateResult) -> bool:
    """True when the whole via-closure of ``name`` is exactly the excepted advisory."""
    allowed = set(policy["allowed_packages"])
    advisory_package = policy["advisory"]["package"]
    seen: set[str] = set()
    pending = [name]
    advisory_seen = False
    ok = True
    while pending:
        current = pending.pop()
        if current in seen:
            continue
        seen.add(current)
        entry = vulnerabilities.get(current)
        if entry is None:
            result.fail(f"{name}: via names {current!r}, which is not a reported vulnerability entry")
            ok = False
            continue
        if current not in allowed:
            result.fail(f"{name}: affected package {current!r} is not on the exception allowlist")
            ok = False
        if not entry["via"]:
            result.fail(f"{current}: no advisory explains this vulnerability entry")
            ok = False
        for via in entry["via"]:
            if isinstance(via, str):
                pending.append(via)
            elif isinstance(via, dict):
                if current == advisory_package and advisory_matches(via, policy):
                    advisory_seen = True
                else:
                    result.fail(
                        f"{current}: advisory not covered by the exception: "
                        f"source={via.get('source')!r} url={via.get('url')!r} "
                        f"name={via.get('name')!r} range={via.get('range')!r}"
                    )
                    ok = False
            else:
                result.fail(f"{current}: malformed via item {via!r}")
                ok = False
    if ok and not advisory_seen:
        result.fail(f"{name}: no excepted advisory found in its via closure")
        ok = False
    return ok


def evaluate_audit(report: dict, policy: dict, result: GateResult) -> list[str]:
    """Return the high/critical entries that are covered; record failures for all others."""
    vulnerabilities = report["vulnerabilities"]
    covered: list[str] = []
    for name in sorted(vulnerabilities):
        entry = vulnerabilities[name]
        if entry["severity"] not in EVALUATED_SEVERITIES:
            continue
        if entry["severity"] == "critical":
            result.fail(f"{name}: critical vulnerability (the exception covers high severity only)")
            continue
        if explain_entry(name, vulnerabilities, policy, result):
            covered.append(name)
    return covered


# --------------------------------------------------------------------------- exposure


def lock_package_name(path: str) -> Optional[str]:
    marker = "node_modules/"
    return path.rsplit(marker, 1)[1] if marker in path else None


def check_exposure(root: Path, policy: dict, report_entries: dict, result: GateResult) -> dict:
    allowed = set(policy["allowed_packages"])
    evidence = {"lock_entries": [], "package_json_clean": False}
    try:
        manifest = json.loads((root / "package.json").read_text(encoding="utf-8"))
        lock = json.loads((root / "package-lock.json").read_text(encoding="utf-8"))
    except (OSError, ValueError) as error:
        result.fail(f"package.json / package-lock.json unreadable, exposure cannot be verified: {error}")
        return evidence
    if not isinstance(manifest, dict) or not isinstance(lock, dict) or not isinstance(lock.get("packages"), dict):
        result.fail("package.json / package-lock.json malformed, exposure cannot be verified")
        return evidence
    clean = True
    for section in ("dependencies", "optionalDependencies", "peerDependencies"):
        declared = manifest.get(section) or {}
        if not isinstance(declared, dict):
            result.fail(f"package.json {section} malformed")
            clean = False
            continue
        for name in sorted(allowed & set(declared)):
            result.fail(f"production exposure: {name} is declared in package.json {section}")
            clean = False
    evidence["package_json_clean"] = clean

    advisory = policy["advisory"]
    max_version = parse_stable(policy["fixed_version_gate"]["max_vulnerable_version"])
    packages = lock["packages"]
    present: set[str] = set()
    for path in sorted(packages):
        name = lock_package_name(path)
        if name not in allowed:
            continue
        entry = packages[path]
        present.add(name)
        flags = [flag for flag in LOCK_DISALLOWED_FLAGS if entry.get(flag)]
        if entry.get("dev") is not True or flags:
            result.fail(
                f"production exposure: lock entry {path} is not dev-only "
                f"(dev={entry.get('dev')!r}, flags={flags or 'none'})"
            )
        if name == advisory["package"]:
            version = parse_stable(str(entry.get("version")))
            if version is None or version > max_version:
                result.fail(f"lock entry {path} has version {entry.get('version')!r}, outside the excepted range {advisory['vulnerable_range']}")
        evidence["lock_entries"].append(f"{path}@{entry.get('version')}")
    for name, entry in report_entries.items():
        if name in allowed and name not in present:
            result.fail(f"audit reports {name} but package-lock.json has no entry for it")
        for node in entry.get("nodes", []):
            lock_entry = packages.get(node)
            if lock_entry is None or lock_package_name(node) not in allowed:
                result.fail(f"audit node {node!r} of {name} is not an allowlisted package-lock entry")
    return evidence


# --------------------------------------------------------------------------- registry


def npm_registry_lookup(package: str, root: Path = ROOT) -> dict:
    """Query the npm registry (limited retries; any failure raises)."""
    last_error: Optional[Exception] = None
    for attempt in range(1, REGISTRY_ATTEMPTS + 1):
        try:
            outputs = []
            for field in ("versions", "dist-tags"):
                completed = subprocess.run(
                    ["npm", "view", package, field, "--json"],
                    cwd=root, capture_output=True, text=True, timeout=90, check=False,
                )
                if completed.returncode != 0:
                    raise RuntimeError(f"npm view {package} {field} exited {completed.returncode}")
                outputs.append(json.loads(completed.stdout))
            versions, tags = outputs
            if isinstance(versions, str):
                versions = [versions]
            return {"versions": versions, "dist_tags": tags}
        except (OSError, RuntimeError, ValueError, subprocess.TimeoutExpired) as error:
            last_error = error
            if attempt < REGISTRY_ATTEMPTS:
                time.sleep(REGISTRY_RETRY_DELAY_SECONDS)
    raise RuntimeError(f"npm registry query failed after {REGISTRY_ATTEMPTS} attempts: {last_error}")


def check_fixed_version(policy: dict, lookup: RegistryLookup, result: GateResult) -> Optional[str]:
    gate = policy["fixed_version_gate"]
    package = gate["package"]
    limit = parse_stable(gate["max_vulnerable_version"])
    try:
        data = lookup(package)
        versions = data["versions"]
        tags = data["dist_tags"]
        if not isinstance(versions, list) or not versions or not all(isinstance(v, str) for v in versions):
            raise ValueError("versions list missing or malformed")
        if not isinstance(tags, dict):
            raise ValueError("dist-tags malformed")
    except Exception as error:  # noqa: BLE001 - every lookup failure must fail closed
        result.fail(f"registry lookup for {package} failed (fail closed): {error}")
        return None
    newest: Optional[tuple[int, int, int]] = None
    for candidate in list(versions) + [str(v) for v in tags.values()]:
        parsed = parse_stable(candidate)
        if parsed is None:
            if "-" in candidate and re.match(r"^\d+\.\d+\.\d+-", candidate):
                continue  # prerelease: not a stable fix
            result.fail(f"registry returned an unparseable {package} version {candidate!r}")
            continue
        if newest is None or parsed > newest:
            newest = parsed
        if parsed > limit:
            result.fail(
                f"{package} {candidate} is published and is newer than the excepted range "
                f"<= {gate['max_vulnerable_version']}: the exception no longer applies. Upgrade, then remove it "
                "(docs/SECURITY_EXCEPTIONS.md)."
            )
    return ".".join(map(str, newest)) if newest else None


# --------------------------------------------------------------------------- gate


def run_gate(
    audit_text: str,
    npm_exit_code: int,
    policy: dict,
    root: Path,
    today: dt.date,
    registry_lookup: RegistryLookup,
) -> GateResult:
    result = GateResult()
    expires = dt.date.fromisoformat(policy["expires"])
    if today >= expires:
        result.fail(
            f"exception {policy['id']} expired on {policy['expires']} (today {today.isoformat()} UTC). "
            "Renewal requires an explicit new Owner action."
        )
    report = parse_report(audit_text, npm_exit_code, result)
    if report is None:
        return result
    if not check_report_consistency(report, npm_exit_code, result):
        return result
    covered = evaluate_audit(report, policy, result)
    counts = report["metadata"]["vulnerabilities"]
    result.note(
        f"audit: total={counts['total']} critical={counts['critical']} high={counts['high']} "
        f"moderate={counts['moderate']} low={counts['low']} info={counts['info']}; "
        f"high/critical covered by {policy['id']}: {', '.join(covered) or 'none'}"
    )
    if not covered:
        if result.passed:
            result.note("exception unused — remove it (docs/SECURITY_EXCEPTIONS.md)")
        return result
    evidence = check_exposure(root, policy, {n: report["vulnerabilities"][n] for n in covered}, result)
    result.note("lock entries (all must be dev-only): " + ", ".join(evidence["lock_entries"]))
    result.note("package.json dependencies/optionalDependencies/peerDependencies free of allowlisted packages: "
                + str(evidence["package_json_clean"]))
    newest = check_fixed_version(policy, registry_lookup, result)
    if newest:
        result.note(f"registry: newest stable {policy['fixed_version_gate']['package']} is {newest} "
                    f"(excepted range {policy['advisory']['vulnerable_range']}, no fix published)")
    result.note(
        f"exception: {policy['id']} source {policy['advisory']['source_id']}, {policy['owner_approval']}, "
        f"expires {policy['expires']} ({(expires - today).days} days left)"
    )
    return result


def run_npm_audit(root: Path) -> tuple[str, int]:
    completed = subprocess.run(
        ["npm", "audit", "--json"], cwd=root, capture_output=True, text=True, timeout=300, check=False
    )
    return completed.stdout, completed.returncode


def main(
    argv: Optional[list[str]] = None,
    *,
    today: Optional[dt.date] = None,
    registry_lookup: Optional[RegistryLookup] = None,
    audit_runner: Optional[Callable[[Path], tuple[str, int]]] = None,
) -> int:
    parser = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    parser.add_argument("--root", type=Path, default=ROOT, help="directory holding package.json and package-lock.json")
    parser.add_argument("--policy", type=Path, default=None)
    parser.add_argument("--report-out", type=Path, default=None, help="where to save the raw npm audit JSON")
    parser.add_argument("--audit-json", type=Path, default=None, help="consume a saved report instead of running npm audit")
    parser.add_argument("--audit-exit-code", type=int, default=1, help="npm exit code that belongs to --audit-json")
    parser.add_argument("--registry-json", type=Path, default=None,
                        help="offline registry data {versions:[...],dist_tags:{...}} instead of querying npm (rehearsal only)")
    args = parser.parse_args(argv)
    root = args.root.resolve()
    try:
        policy = load_policy(args.policy or (root / "config/security/npm-audit-exceptions.json"))
    except ValueError as error:
        print(f"FAIL: {error}")
        return 1
    if args.audit_json is not None:
        audit_text, exit_code = args.audit_json.read_text(encoding="utf-8", errors="replace"), args.audit_exit_code
    else:
        try:
            audit_text, exit_code = (audit_runner or run_npm_audit)(root)
        except (OSError, subprocess.TimeoutExpired) as error:
            print(f"FAIL: npm audit could not be run: {error}")
            return 1
    if args.report_out is not None:
        args.report_out.parent.mkdir(parents=True, exist_ok=True)
        args.report_out.write_text(audit_text, encoding="utf-8")
    lookup = registry_lookup
    if lookup is None and args.registry_json is not None:
        offline = json.loads(args.registry_json.read_text(encoding="utf-8"))
        lookup = lambda _package: offline  # noqa: E731
    if lookup is None:
        lookup = lambda package: npm_registry_lookup(package, root)  # noqa: E731
    result = run_gate(
        audit_text, exit_code, policy, root,
        today or dt.datetime.now(dt.timezone.utc).date(), lookup,
    )
    print(f"npm audit gate (npm exit code {exit_code}; threshold high; exception {policy['id']})")
    for note in result.notes:
        print("  " + note)
    if result.passed:
        print("PASS")
        return 0
    for failure in result.failures:
        print("  FAIL: " + failure)
    print("FAIL: npm advisory gate")
    return 1


if __name__ == "__main__":
    sys.exit(main())
