#!/usr/bin/env python3
"""Fail-closed weekly dependency intake and immutable release-gate helpers.

This module never deploys. Scheduled GitHub jobs use it to classify Dependabot
work, build exact-SHA candidate manifests, validate host recovery receipts and
produce an Owner handoff. Staging and production mutations remain with the
versioned, installed deployment controllers.
"""

from __future__ import annotations

import argparse
import codecs
import hashlib
import json
import re
import stat
import subprocess
import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
if __package__:
    from . import release_admission_v3 as _release_admission
else:
    # ``python -I`` and direct script execution do not put the repository root
    # on the import path. Load the shared correction validator from this
    # protected source directory rather than maintaining a third parser.
    sys.path.insert(0, str(Path(__file__).resolve().parent))
    import release_admission_v3 as _release_admission
REPOSITORY = "zsoltacsai/wpcaregrid"
PRIMARY_BRANCH = "claude/ecstatic-wozniak-oemyuf"
SHA_RE = re.compile(r"[0-9a-f]{40}\Z")
SHA256_RE = re.compile(r"[0-9a-f]{64}\Z")
REVIEW_ARTIFACT_RE = re.compile(
    r"WPCG_RELEASE_REVIEW_ARTIFACT_V3\n"
    r"role=(integration_qa|appsec)\n"
    r"pr=([1-9][0-9]{0,8})\n"
    r"head_sha=([0-9a-f]{40})\n"
    r"base_sha=([0-9a-f]{40})\n"
    r"agent=([a-z0-9][a-z0-9._-]{2,63})\n"
    r"report_sha256=([0-9a-f]{64})\n"
    r"report_base64=([A-Za-z0-9+/]+={0,2})\n?\Z")
REVIEW_ARTIFACT_PREFIX = "WPCG_RELEASE_REVIEW_ARTIFACT_V3"
MAX_JSON_BYTES = 8_000_000
# The Issue #174 review ledger is an append-only V3 evidence store that
# ``review-subject`` must read in full.  It is already larger than the generic
# JSON input bound (measured 2026-11-22: 1 534 comments, 16 pages, 9 324 174 B;
# growth about 2.38 MB per week), so it is processed page by page below instead
# of raising ``MAX_JSON_BYTES`` for every input.  Every bound here is finite and
# fails closed with its own reason code; none of them is a time window, a
# truncation or a subject filter.
LEDGER_PAGE_ITEMS = 100
MAX_LEDGER_JSON_BYTES = 160_000_000     # whole file, before any read
MAX_LEDGER_COMMENTS = 20_000            # ~50 weeks of growth at the measured rate
MAX_LEDGER_PAGES = MAX_LEDGER_COMMENTS // LEDGER_PAGE_ITEMS + 1
MAX_LEDGER_PAGE_BYTES = 16_000_000      # one page of 100 full V3 artifact reports
MAX_LEDGER_COMMENT_BYTES = 1_000_000    # one comment with a 65 536 character report
# Structural tokens of the ledger snapshot (strings are matched as a whole, so
# the scan cost stays linear in the file size instead of per character).
_LEDGER_TOKEN = re.compile(r'["\[\]{}]')


def _ledger_string_end(text: str, start: int) -> int | None:
    """Return the index just past the JSON string starting at ``start``."""
    position = start + 1
    while True:
        quote = text.find('"', position)
        if quote < 0:
            return None
        backslashes = 0
        index = quote - 1
        while index >= start and text[index] == "\\":
            backslashes += 1
            index -= 1
        if backslashes % 2 == 0:
            return quote + 1
        position = quote + 1
LOCKFILES = ("composer.lock", "wordpress/composer.lock", "package-lock.json")
RECEIPT_SCHEMA = "wpcg-release-recovery-receipt-v1"
CI_STAGING_RECEIPT_SCHEMA = "wpcg-staging-equivalent-ci-receipt-v1"
CI_STAGING_REQUIRED_STEPS = {
    "migration_recovery_rehearsal": "Gated interrupted staging migration recovery rehearsal (isolated MySQL 8.4.11)",
    "migrations_and_rollback": "Migrations from an empty database, full rollback, and again",
    "production_caches_build": "Production caches build (config, routes, views, events)",
    "isolated_backup_verification": "Mandatory isolated MySQL backup-import verification",
    "mysql_process_concurrency_gate": "Required MySQL process-concurrency gate",
    "seed_synthetic_legal_documents": "Seed synthetic published legal documents for marketing-only smoke",
    "system_check": "System check (runs, never prints secrets)",
    "http_smoke_marketing_only": "HTTP smoke test against a running server (production caches)",
    "http_smoke_application_mode": "HTTP smoke test in application mode (isolated loopback server)"
}


class PolicyError(ValueError):
    """A missing or invalid weekly release input."""


def _read_json(path: Path) -> Any:
    try:
        raw = path.read_bytes()
    except OSError:
        raise PolicyError("input_unavailable") from None
    if len(raw) > MAX_JSON_BYTES:
        raise PolicyError("input_too_large")
    try:
        return json.loads(raw.decode("utf-8"))
    except (UnicodeError, json.JSONDecodeError):
        raise PolicyError("input_invalid") from None


def _pages(value: Any) -> list[dict[str, Any]]:
    """Normalize gh api --paginate --slurp output and require complete pages."""
    if not isinstance(value, list):
        raise PolicyError("api_list_invalid")
    pages = value if not value or isinstance(value[0], list) else [value]
    output: list[dict[str, Any]] = []
    for page in pages:
        if not isinstance(page, list) or len(page) > 100:
            raise PolicyError("api_list_invalid")
        for item in page:
            if not isinstance(item, dict):
                raise PolicyError("api_list_invalid")
            output.append(item)
    if len(output) > 5000:
        raise PolicyError("api_list_too_large")
    return output


class _LedgerScan:
    """Page-by-page view of the append-only #174 review ledger.

    ``comments`` holds only the records that the admission checks can act on
    (owner V3 artifacts, artifact corrections and the fixed set of allowlisted
    legacy correction targets), never the whole ledger.

    Memory is *not* exclusively page-size dependent.  A scan holds, at once:

    * one decoded page and its raw text (bounded by ``MAX_LEDGER_PAGE_BYTES``);
    * one digest entry per page (bounded by ``MAX_LEDGER_PAGES``);
    * the retained relevant records (bounded by the comment count);
    * the global duplicate-detection set of comment identifiers, which grows
      with the number of comments because the check spans every page.

    Measured peaks for one scan (2026-11-22, CPython 3.14, tracemalloc):
    9.39 MB / 1 534 comments -> 3.48 MB, 28.15 MB / 4 600 comments -> 3.56 MB,
    so the page window dominates and the identifier set adds 0.20 MB at 1 534
    identifiers and 3.25 MB at the ``MAX_LEDGER_COMMENTS`` cap of 20 000.
    """

    __slots__ = ("comments", "comment_count", "page_count", "byte_count", "sha256")

    def __init__(self, comments: list[dict[str, Any]], comment_count: int,
                 page_count: int, byte_count: int, sha256: str) -> None:
        self.comments = comments
        self.comment_count = comment_count
        self.page_count = page_count
        self.byte_count = byte_count
        self.sha256 = sha256


class _LedgerReader:
    """UTF-8 character reader with a bounded, sliding look-ahead window."""

    def __init__(self, handle: Any, *, chunk: int = 65_536) -> None:
        self._handle = handle
        self._decoder = codecs.getincrementaldecoder("utf-8")("strict")
        self._chunk = chunk
        self._buffer = ""
        self._position = 0
        self._floor = 0
        self._eof = False
        self._digest = hashlib.sha256()
        self.byte_count = 0

    def _pull(self) -> None:
        data = self._handle.read(self._chunk)
        try:
            tail = self._decoder.decode(data, final=not data)
        except UnicodeDecodeError:
            raise PolicyError("ledger_json_invalid") from None
        if not data:
            self._eof = True
        else:
            self._digest.update(data)
            self.byte_count += len(data)
        drop = min(self._floor, self._position)
        if drop:
            self._buffer = self._buffer[drop:]
            self._position -= drop
            self._floor -= drop
        self._buffer += tail

    def _fill(self, count: int) -> None:
        while self._position + count > len(self._buffer) and not self._eof:
            self._pull()

    def peek(self) -> str:
        self._fill(1)
        return self._buffer[self._position] if self._position < len(self._buffer) else ""

    def take(self) -> str:
        char = self.peek()
        if char:
            self._position += 1
        return char

    def skip_space(self) -> str:
        while True:
            char = self.peek()
            if char and char in " \t\r\n":
                self._position += 1
                continue
            return char

    def read_span(self, limit: int, code: str) -> str:
        """Return the raw text of the next JSON value, bounded by ``limit``."""
        self.skip_space()
        if self.peek() == "":
            raise PolicyError("ledger_json_invalid")
        # ``_floor`` marks the first buffer character of the value; ``_pull``
        # keeps it adjusted while dropping already-consumed characters.
        self._floor = self._position
        depth = 0
        try:
            while True:
                match = _LEDGER_TOKEN.search(self._buffer, self._position)
                if match is None:
                    if self._eof:
                        raise PolicyError("ledger_json_invalid")
                    # No structural token in the buffered text: the value is
                    # unterminated or a bare scalar.  Keep the window bounded
                    # while pulling more input so a malformed page cannot grow
                    # memory without limit.
                    if len(self._buffer) - self._floor > limit:
                        raise PolicyError(code)
                    self._pull()
                    continue
                self._position = match.start()
                char = self._buffer[self._position]
                if char == '"':
                    end = _ledger_string_end(self._buffer, self._position)
                    if end is None:
                        if self._eof:
                            raise PolicyError("ledger_json_invalid")
                        if len(self._buffer) - self._floor > limit:
                            raise PolicyError(code)
                        self._pull()
                        continue
                    self._position = end
                elif char in "[{":
                    depth += 1
                    self._position += 1
                else:
                    depth -= 1
                    if depth < 0:
                        raise PolicyError("ledger_json_invalid")
                    self._position += 1
                    if depth == 0:
                        text = self._buffer[self._floor:self._position]
                        if len(text.encode("utf-8")) > limit:
                            raise PolicyError(code)
                        return text
                if self._position - self._floor > limit:
                    raise PolicyError(code)
        finally:
            self._floor = 0

    def digest_hex(self) -> str:
        return self._digest.hexdigest()


def _ledger_relevant(comment: dict[str, Any], comment_id: Any, allowlist: Any) -> bool:
    """True when an admission check can ever act on this ledger record."""
    if type(comment_id) is int and comment_id in allowlist:
        return True
    body = comment.get("body")
    return isinstance(body, str) and (
        body.startswith(REVIEW_ARTIFACT_PREFIX)
        or body.startswith(_release_admission.ARTIFACT_CORRECTION_PREFIX))


def _scan_ledger(path: Path) -> _LedgerScan:
    """Read the whole ledger with bounded memory and fail-closed limits.

    The ledger stays a single ``gh api --paginate --slurp`` snapshot: it is read
    once, in order, page by page.  Nothing is dropped, no window is applied and
    no record is filtered out of the duplicate/identity checks.
    """
    try:
        info = path.lstat()
    except OSError:
        raise PolicyError("input_unavailable") from None
    if not stat.S_ISREG(info.st_mode):
        raise PolicyError("input_unavailable")
    if info.st_size <= 0:
        raise PolicyError("ledger_json_invalid")
    if info.st_size > MAX_LEDGER_JSON_BYTES:
        raise PolicyError("ledger_too_large")
    allowlist = _release_admission._LEGACY_ARTIFACT_ALLOWLIST
    relevant: list[dict[str, Any]] = []
    seen_ids: set[int] = set()
    duplicate_id = False
    page_digests: set[bytes] = set()
    page_sizes: list[int] = []
    comment_count = 0
    try:
        handle = path.open("rb")
    except OSError:
        raise PolicyError("input_unavailable") from None
    with handle:
        reader = _LedgerReader(handle)
        reader.skip_space()
        if reader.take() != "[":
            raise PolicyError("ledger_json_invalid")
        while True:
            char = reader.skip_space()
            if char == "]":
                reader.take()
                break
            text = reader.read_span(MAX_LEDGER_PAGE_BYTES, "ledger_page_too_large")
            digest = hashlib.sha256(text.encode("utf-8")).digest()
            if digest in page_digests:
                raise PolicyError("ledger_page_repeated")
            page_digests.add(digest)
            try:
                page = json.loads(text)
            except json.JSONDecodeError:
                raise PolicyError("ledger_json_invalid") from None
            if not isinstance(page, list) or len(page) > LEDGER_PAGE_ITEMS:
                raise PolicyError("api_list_invalid")
            page_sizes.append(len(page))
            if len(page_sizes) > MAX_LEDGER_PAGES:
                raise PolicyError("ledger_pages_too_many")
            for comment in page:
                if not isinstance(comment, dict):
                    raise PolicyError("api_list_invalid")
                comment_count += 1
                if comment_count > MAX_LEDGER_COMMENTS:
                    raise PolicyError("ledger_comments_too_many")
                if (len(json.dumps(comment, separators=(",", ":")).encode("utf-8"))
                        > MAX_LEDGER_COMMENT_BYTES):
                    raise PolicyError("ledger_comment_too_large")
                comment_id = comment.get("id")
                if type(comment_id) is int:
                    if comment_id in seen_ids:
                        duplicate_id = True
                    else:
                        seen_ids.add(comment_id)
                if _ledger_relevant(comment, comment_id, allowlist):
                    relevant.append(comment)
            char = reader.skip_space()
            if char == ",":
                reader.take()
                continue
            if char == "]":
                reader.take()
                break
            raise PolicyError("ledger_json_invalid")
        if reader.skip_space() != "":
            raise PolicyError("ledger_json_invalid")
        byte_count = reader.byte_count
        ledger_sha256 = reader.digest_hex()
    if byte_count != info.st_size:
        raise PolicyError("ledger_changed")
    # Whole-ledger duplicate detection keeps the admission module's existing
    # ``artifact_correction_ambiguous`` semantics: it applies to every comment
    # with an integer id, relevant or not.
    if duplicate_id:
        raise PolicyError("artifact_correction_ambiguous")
    # Completeness: every page but the last must be a full page.  A short
    # intermediate page means the snapshot is not a complete pagination run.
    if len(page_sizes) > 1 and any(size != LEDGER_PAGE_ITEMS for size in page_sizes[:-1]):
        raise PolicyError("ledger_page_incomplete")
    return _LedgerScan(relevant, comment_count, len(page_sizes), byte_count, ledger_sha256)


def _scan_ledger_stable(path: Path) -> _LedgerScan:
    """Scan the same snapshot twice and require one identical identity.

    Two independent passes over one unchanged file must agree on the byte
    count, the SHA-256 of the bytes read, the page count and the comment
    count.  A disagreement means the snapshot changed while it was being read,
    so the run holds instead of admitting a subject built from mixed input.
    Only the first pass' records are used; both passes stay bounded exactly
    like a single one.
    """
    first = _scan_ledger(path)
    second = _scan_ledger(path)
    if (first.sha256 != second.sha256
            or first.byte_count != second.byte_count
            or first.page_count != second.page_count
            or first.comment_count != second.comment_count):
        raise PolicyError("ledger_unstable")
    return first


def _ledger_comments(value: Any) -> list[dict[str, Any]]:
    """Accept either the bounded scan or the legacy in-memory page list."""
    if isinstance(value, _LedgerScan):
        return value.comments
    return _pages(value)


def _safe_text(value: Any, limit: int = 240) -> str:
    if not isinstance(value, str):
        return "unknown"
    value = "".join(char for char in value if char >= " " and char not in "\x7f\u2028\u2029")
    # Avoid Markdown injection and accidental @mentions in Owner notifications.
    value = value.replace("@", "＠").replace("<", "&lt;").replace(">", "&gt;")
    value = value.replace("|", "\\|").replace("`", "ʼ")
    return value[:limit]


def _pr_package(pr: dict[str, Any]) -> str:
    title = pr.get("title")
    if isinstance(title, str):
        match = re.search(r"(?i)\b(?:bump|update)\s+(.+?)\s+from\s+\S+\s+to\s+\S+", title)
        if match:
            return match.group(1).strip().lower()
    return ""


def _pr_ecosystem(pr: dict[str, Any]) -> str:
    branch = pr.get("head", {}).get("ref", "") if isinstance(pr.get("head"), dict) else ""
    match = re.match(r"dependabot/([^/]+)/", branch) if isinstance(branch, str) else None
    if not match:
        return ""
    return {"npm_and_yarn": "npm", "github_actions": "github_actions"}.get(
        match.group(1), match.group(1))


def classify_dependency_updates(pr_data: Any, alert_data: Any) -> dict[str, Any]:
    prs = _pages(pr_data)
    alerts = _pages(alert_data)
    open_alerts: list[dict[str, Any]] = []
    for alert in alerts:
        if alert.get("state") != "open":
            continue
        vulnerability = alert.get("security_vulnerability")
        advisory = alert.get("security_advisory")
        package = vulnerability.get("package") if isinstance(vulnerability, dict) else None
        if not isinstance(package, dict) or not isinstance(advisory, dict):
            raise PolicyError("security_alert_invalid")
        package_name = package.get("name")
        ecosystem = package.get("ecosystem")
        severity = advisory.get("severity")
        if (not isinstance(package_name, str) or not package_name.strip()
                or not isinstance(ecosystem, str) or ecosystem not in {"composer", "npm", "github_actions"}
                or type(alert.get("number")) is not int or alert["number"] <= 0
                or severity not in {"critical", "high", "medium", "low"}):
            raise PolicyError("security_alert_severity_invalid")
        dependency = alert.get("dependency")
        scope = dependency.get("scope") if isinstance(dependency, dict) else "unknown"
        manifest_path = dependency.get("manifest_path") if isinstance(dependency, dict) else "unknown"
        advisory_id = advisory.get("ghsa_id")
        open_alerts.append({"package": _safe_text(package_name),
                            "ecosystem": _safe_text(ecosystem),
                            "severity": severity,
                            "alert_number": alert["number"],
                            "advisory_id": _safe_text(advisory_id),
                            "manifest_path": _safe_text(manifest_path, 180),
                            "scope": _safe_text(scope)})

    updates = []
    for pr in prs:
        user = pr.get("user")
        head = pr.get("head")
        login = user.get("login") if isinstance(user, dict) else None
        branch = head.get("ref") if isinstance(head, dict) else None
        if login != "dependabot[bot]" or not isinstance(branch, str) or not branch.startswith("dependabot/"):
            continue
        package = _pr_package(pr)
        ecosystem = _pr_ecosystem(pr)
        matches = [alert for alert in open_alerts
                   if alert["package"].lower() == package and alert["ecosystem"].lower() == ecosystem]
        labels = pr.get("labels", [])
        label_names = {label.get("name", "").lower() for label in labels if isinstance(label, dict)}
        if matches:
            severity_order = {"critical": 0, "high": 1, "medium": 2, "low": 3}
            severity = min((item["severity"] for item in matches), key=severity_order.get)
            category = f"security_{severity}"
            priority = 0 if severity in {"critical", "high"} else 1
            next_action = "expedite" if priority == 0 else "review_this_week"
        elif "security" in label_names or "security-update" in label_names:
            category, priority, next_action = "security_unclassified", 0, "manual_security_triage"
        else:
            category, priority, next_action = "routine", 2, "weekly_qualification"
        updates.append({"number": pr.get("number"), "url": _safe_text(pr.get("html_url"), 500),
                        "title": _safe_text(pr.get("title")), "package": package or "unparsed",
                        "ecosystem": ecosystem or "unparsed", "category": category,
                        "priority": priority, "next_action": next_action})
    updates.sort(key=lambda item: (item["priority"], item["number"] or 0))
    matched = {(item["package"].lower(), item["ecosystem"].lower()) for item in updates
               if item["category"].startswith("security_")}
    unpatched = [item for item in open_alerts
                 if item["severity"] in {"critical", "high"}
                 and (item["package"].lower(), item["ecosystem"].lower()) not in matched]
    return {"schema": "wpcg-dependency-intake-v1", "repository": REPOSITORY,
            "generated_at": datetime.now(timezone.utc).isoformat(),
            "updates": updates, "open_alerts": open_alerts,
            "unpatched_critical_high": unpatched,
            "status": "HOLD" if unpatched or any(x["category"] == "security_unclassified" for x in updates)
            else "PASS"}


def dependency_markdown(report: dict[str, Any]) -> str:
    lines = ["# WP CareGrid weekly dependency intake", "",
             f"Status: **{report['status']}**", "",
             "Security updates marked critical/high are expedited through the normal protected PR gates.", "",
             "| Priority | Category | Package | PR | Action |", "|---:|---|---|---:|---|"]
    for item in report["updates"]:
        lines.append(f"| {item['priority']} | {item['category']} | {item['package']} "
                     f"| {item['number']} | {item['next_action']} |")
    if not report["updates"]:
        lines.append("| — | none | — | — | No open Dependabot PRs |")
    lines += ["", "## Unpatched critical/high alerts", ""]
    if report["unpatched_critical_high"]:
        for item in report["unpatched_critical_high"]:
            lines.append(f"- **{item['severity']}** {item['ecosystem']} / {item['package']} "
                         f"(alert {item['alert_number']}, {item['manifest_path']}); open an expedited security PR.")
    else:
        lines.append("None reported by the complete Dependabot alerts response.")
    lines += ["", "Routine updates still require the complete protected PR CI and independent QA/AppSec gates.",
              "This report does not merge, stage, or deploy dependencies."]
    return "\n".join(lines) + "\n"


def merged_pr_subject(pr_data: Any, ledger_data: Any, candidate_sha: str) -> dict[str, Any]:
    if not SHA_RE.fullmatch(candidate_sha):
        raise PolicyError("candidate_sha_invalid")
    matches = []
    for pr in _pages(pr_data):
        base = pr.get("base")
        head = pr.get("head")
        if (pr.get("merge_commit_sha") != candidate_sha or not pr.get("merged_at")
                or not isinstance(base, dict) or base.get("ref") != PRIMARY_BRANCH
                or not isinstance(head, dict)):
            continue
        head_sha = head.get("sha")
        base_sha = base.get("sha")
        number = pr.get("number")
        if (type(number) is not int or number <= 0 or not isinstance(head_sha, str)
                or not SHA_RE.fullmatch(head_sha) or not isinstance(base_sha, str)
                or not SHA_RE.fullmatch(base_sha)):
            raise PolicyError("merged_pr_identity_invalid")
        matches.append({"pr_number": number, "head_sha": head_sha, "base_sha": base_sha})
    if len(matches) != 1:
        raise PolicyError("merged_pr_subject_ambiguous")
    subject = matches[0]
    comments = _ledger_comments(ledger_data)
    try:
        invalidated_artifacts = _release_admission._authorized_artifact_corrections(
            comments, owner="zsoltacsai")
    except _release_admission.AdmissionRefused as exc:
        raise PolicyError(str(exc)) from None
    artifacts: dict[str, set[tuple[str, str]]] = {"integration_qa": set(), "appsec": set()}
    for comment in comments:
        body = comment.get("body")
        user = comment.get("user")
        if (not isinstance(body, str) or not body.startswith(REVIEW_ARTIFACT_PREFIX)
                or not isinstance(user, dict) or user.get("login") != "zsoltacsai"
                or comment.get("author_association") != "OWNER"):
            continue
        parsed = REVIEW_ARTIFACT_RE.fullmatch(body)
        if not parsed:
            # Only the admission module's exact six-record correction policy
            # can invalidate a malformed historical artifact. It never
            # becomes PASS evidence; fresh exact-subject artifacts are still
            # required below.
            if comment.get("id") in invalidated_artifacts:
                continue
            raise PolicyError("review_artifact_invalid")
        role, pr_text, head_sha, base_sha, agent, digest, encoded = parsed.groups()
        if int(pr_text) != subject["pr_number"] or head_sha != subject["head_sha"]:
            continue
        if (not encoded or len(encoded) > 65_536 or not SHA256_RE.fullmatch(digest)
                or not agent):
            raise PolicyError("review_artifact_invalid")
        artifacts[role].add((base_sha, agent))
    if any(len(artifacts[role]) != 1 for role in artifacts):
        raise PolicyError("review_artifact_subject_missing_or_ambiguous")
    qa_base, qa_agent = next(iter(artifacts["integration_qa"]))
    appsec_base, appsec_agent = next(iter(artifacts["appsec"]))
    if qa_base != appsec_base or qa_agent == appsec_agent:
        raise PolicyError("review_artifact_subject_mismatch")
    subject["base_sha"] = qa_base
    return subject


def _git(*args: str) -> str:
    try:
        result = subprocess.run(["git", "-C", str(ROOT), *args], check=True,
                                stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                stdin=subprocess.DEVNULL, timeout=15)
    except (OSError, subprocess.SubprocessError):
        raise PolicyError("git_identity_unavailable") from None
    try:
        return result.stdout.decode("ascii").strip()
    except UnicodeError:
        raise PolicyError("git_identity_invalid") from None


def compute_ci_staging_receipt_digest(receipt: dict[str, Any]) -> str:
    payload = dict(receipt)
    payload.pop("receipt_digest_sha256", None)
    serialized = json.dumps(payload, sort_keys=True, separators=(",", ":")).encode("utf-8")
    return hashlib.sha256(serialized).hexdigest()


def build_ci_staging_equivalent_receipt(ci_runs_data: Any, ci_jobs_data: Any,
                                        candidate_sha: str) -> dict[str, Any]:
    if not SHA_RE.fullmatch(candidate_sha):
        raise PolicyError("candidate_sha_invalid")

    if isinstance(ci_runs_data, dict):
        runs = ci_runs_data.get("workflow_runs")
        if not isinstance(runs, list):
            raise PolicyError("ci_runs_data_invalid")
    elif isinstance(ci_runs_data, list):
        runs = _pages(ci_runs_data)
    else:
        raise PolicyError("ci_runs_data_invalid")

    matching_runs = [r for r in runs if isinstance(r, dict)
                     and r.get("head_sha") == candidate_sha
                     and r.get("head_branch") == PRIMARY_BRANCH
                     and r.get("event") == "push"
                     and r.get("name") == "CI"
                     and r.get("status") == "completed"
                     and r.get("conclusion") == "success"]
    if not matching_runs:
        raise PolicyError("exact_ci_run_missing_or_not_successful")
    ci_run = max(matching_runs, key=lambda r: _timestamp(r.get("created_at")))
    ci_run_id = ci_run.get("id")
    if type(ci_run_id) is not int or ci_run_id <= 0:
        raise PolicyError("ci_run_identity_invalid")

    if isinstance(ci_jobs_data, dict):
        jobs = ci_jobs_data.get("jobs")
        if not isinstance(jobs, list):
            raise PolicyError("ci_jobs_data_invalid")
    elif isinstance(ci_jobs_data, list):
        jobs = _pages(ci_jobs_data)
    else:
        raise PolicyError("ci_jobs_data_invalid")

    matching_jobs = [j for j in jobs if isinstance(j, dict)
                     and j.get("name") == "Server tests (PHP 8.5, mysql)"
                     and j.get("status") == "completed"
                     and j.get("conclusion") == "success"]
    if not matching_jobs:
        raise PolicyError("ci_server_tests_job_missing_or_not_successful")
    server_job = matching_jobs[0]
    job_id = server_job.get("id")
    if type(job_id) is not int or job_id <= 0:
        raise PolicyError("ci_job_identity_invalid")

    steps = server_job.get("steps")
    if not isinstance(steps, list):
        raise PolicyError("ci_job_steps_invalid")
    step_map = {s.get("name"): s for s in steps if isinstance(s, dict)}

    required_steps = {}
    for key, step_name in CI_STAGING_REQUIRED_STEPS.items():
        step = step_map.get(step_name)
        if not step or step.get("status") != "completed" or step.get("conclusion") != "success":
            raise PolicyError(f"ci_step_{key}_missing_or_failed")
        step_num = step.get("number")
        if type(step_num) is not int or step_num <= 0:
            raise PolicyError(f"ci_step_{key}_number_invalid")
        required_steps[key] = {
            "number": step_num,
            "name": step_name,
            "status": "completed",
            "conclusion": "success"
        }

    receipt = {
        "schema": CI_STAGING_RECEIPT_SCHEMA,
        "repository": REPOSITORY,
        "candidate_sha": candidate_sha,
        "ci_workflow_run_id": ci_run_id,
        "ci_workflow_name": "CI",
        "ci_event": "push",
        "ci_branch": PRIMARY_BRANCH,
        "ci_run_created_at": ci_run.get("created_at"),
        "ci_run_updated_at": ci_run.get("updated_at"),
        "environment_evidence": "isolated_ci_synthetic",
        "synthetic_only_scope": True,
        "production_data_used": False,
        "runtimes": {
            "php_version": "8.5",
            "mysql_version": "8.4"
        },
        "job": {
            "id": job_id,
            "name": server_job["name"],
            "status": "completed",
            "conclusion": "success"
        },
        "required_steps": required_steps,
        "created_at": datetime.now(timezone.utc).isoformat()
    }
    receipt["receipt_digest_sha256"] = compute_ci_staging_receipt_digest(receipt)
    return receipt


def validate_ci_staging_equivalent_receipt(receipt: Any, candidate_sha: str,
                                           *, now: datetime | None = None) -> dict[str, Any]:
    if not isinstance(receipt, dict):
        raise PolicyError("ci_staging_receipt_invalid")
    if receipt.get("schema") != CI_STAGING_RECEIPT_SCHEMA:
        raise PolicyError("ci_staging_receipt_schema_unsupported")
    if receipt.get("repository") != REPOSITORY:
        raise PolicyError("ci_staging_receipt_repository_mismatch")
    if receipt.get("candidate_sha") != candidate_sha or not SHA_RE.fullmatch(candidate_sha):
        raise PolicyError("ci_staging_receipt_sha_mismatch")
    if receipt.get("ci_branch") != PRIMARY_BRANCH:
        raise PolicyError("ci_staging_receipt_branch_invalid")
    if receipt.get("ci_workflow_name") != "CI":
        raise PolicyError("ci_staging_receipt_workflow_invalid")
    if receipt.get("ci_event") != "push":
        raise PolicyError("ci_staging_receipt_event_invalid")
    if receipt.get("environment_evidence") != "isolated_ci_synthetic":
        raise PolicyError("ci_staging_receipt_environment_invalid")
    if receipt.get("synthetic_only_scope") is not True or receipt.get("production_data_used") is not False:
        raise PolicyError("ci_staging_receipt_scope_invalid")

    runtimes = receipt.get("runtimes")
    if not isinstance(runtimes, dict) or runtimes.get("php_version") != "8.5" or runtimes.get("mysql_version") != "8.4":
        raise PolicyError("ci_staging_receipt_runtime_invalid")

    job = receipt.get("job")
    if (not isinstance(job, dict) or type(job.get("id")) is not int or job["id"] <= 0
            or job.get("name") != "Server tests (PHP 8.5, mysql)"
            or job.get("status") != "completed" or job.get("conclusion") != "success"):
        raise PolicyError("ci_staging_receipt_job_invalid")

    steps = receipt.get("required_steps")
    if not isinstance(steps, dict):
        raise PolicyError("ci_staging_receipt_steps_invalid")
    for key, expected_name in CI_STAGING_REQUIRED_STEPS.items():
        step = steps.get(key)
        if (not isinstance(step, dict) or step.get("name") != expected_name
                or type(step.get("number")) is not int or step["number"] <= 0
                or step.get("status") != "completed" or step.get("conclusion") != "success"):
            raise PolicyError(f"ci_staging_receipt_step_{key}_invalid")

    created_str = receipt.get("created_at")
    if not isinstance(created_str, str):
        raise PolicyError("ci_staging_receipt_timestamp_invalid")
    created = _timestamp(created_str)
    current = now or datetime.now(timezone.utc)
    if current - created > timedelta(days=8):
        raise PolicyError("ci_staging_receipt_expired")
    if created > current + timedelta(seconds=300):
        raise PolicyError("ci_staging_receipt_future_skew")

    digest = receipt.get("receipt_digest_sha256")
    if not isinstance(digest, str) or not SHA256_RE.fullmatch(digest):
        raise PolicyError("ci_staging_receipt_digest_invalid")
    expected_digest = compute_ci_staging_receipt_digest(receipt)
    if digest != expected_digest:
        raise PolicyError("ci_staging_receipt_digest_invalid")

    ci_workflow_run_id = receipt.get("ci_workflow_run_id")
    if type(ci_workflow_run_id) is not int or ci_workflow_run_id <= 0:
        raise PolicyError("ci_staging_receipt_run_identity_invalid")

    return {
        "status": "PASS",
        "schema": CI_STAGING_RECEIPT_SCHEMA,
        "candidate_sha": candidate_sha,
        "ci_workflow_run_id": ci_workflow_run_id,
        "receipt_digest_sha256": digest,
        "created_at": created.isoformat(),
    }


def candidate_manifest(sha: str, workflow_run_id: str, ci_run_id: str,
                       *, ci_staging_receipt: dict[str, Any] | None = None,
                       now: datetime | None = None) -> dict[str, Any]:
    if (not SHA_RE.fullmatch(sha) or _git("rev-parse", "HEAD") != sha
            or _git("rev-parse", f"refs/remotes/origin/{PRIMARY_BRANCH}") != sha):
        raise PolicyError("candidate_sha_mismatch")
    if not workflow_run_id.isdigit() or not ci_run_id.isdigit():
        raise PolicyError("workflow_identity_invalid")
    hashes = {}
    for relpath in LOCKFILES:
        path = ROOT / relpath
        if not path.is_file() or path.is_symlink():
            raise PolicyError("lockfile_missing")
        hashes[relpath] = hashlib.sha256(path.read_bytes()).hexdigest()
    manifest = {"schema": "wpcg-release-candidate-v1", "repository": REPOSITORY,
                "primary_branch": PRIMARY_BRANCH, "candidate_sha": sha,
                "workflow_run_id": int(workflow_run_id), "ci_run_id": int(ci_run_id),
                "lockfile_sha256": hashes,
                "created_at": (now or datetime.now(timezone.utc)).isoformat(),
                "staging_status": "HOLD", "production_status": "DISABLED",
                "freeze_status": "NOT_FROZEN",
                "note": "Freeze requires exact-SHA staging deployment, smoke and recovery receipts."}

    if ci_staging_receipt is not None:
        validated = validate_ci_staging_equivalent_receipt(ci_staging_receipt, sha, now=now)
        manifest["staging_status"] = "STAGING_EQUIVALENT_CI_VERIFIED"
        manifest["freeze_status"] = "FROZEN"
        manifest["ci_staging_run_id"] = validated["ci_workflow_run_id"]
        manifest["ci_staging_receipt_digest_sha256"] = validated["receipt_digest_sha256"]
        manifest["note"] = ("Freeze admitted via exact-SHA CI staging-equivalent receipt. "
                            "Physical staging host deployment and production promotion remain HOLD/DISABLED.")

    return manifest


def validate_recovery_receipt(path: Path, *, environment: str, candidate_sha: str,
                              now: datetime | None = None) -> dict[str, Any]:
    """Validate a root-owned, read-only host receipt; never accept a symlink."""
    try:
        absolute = path.absolute()
        current_path = Path(absolute.anchor)
        for component in absolute.parts[1:-1]:
            current_path /= component
            parent_info = current_path.lstat()
            if (not stat.S_ISDIR(parent_info.st_mode) or stat.S_ISLNK(parent_info.st_mode)
                    or parent_info.st_uid != 0 or stat.S_IMODE(parent_info.st_mode) & 0o022):
                raise PolicyError("recovery_receipt_parent_metadata_invalid")
        info = path.lstat()
        if (not stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode)
                or info.st_uid != 0 or stat.S_IMODE(info.st_mode) != 0o640):
            raise PolicyError("recovery_receipt_metadata_invalid")
        if info.st_size <= 0 or info.st_size > 65_536:
            raise PolicyError("recovery_receipt_size_invalid")
        receipt = _read_json(path)
    except OSError:
        raise PolicyError("recovery_receipt_unavailable") from None
    return validate_recovery_receipt_document(receipt, environment=environment,
                                              candidate_sha=candidate_sha, now=now)


def validate_recovery_receipt_document(receipt: Any, *, environment: str, candidate_sha: str,
                                       now: datetime | None = None) -> dict[str, Any]:
    """Validate the secret-safe receipt contract independently of host metadata."""
    if not isinstance(receipt, dict) or receipt.get("schema") != RECEIPT_SCHEMA:
        raise PolicyError("recovery_receipt_invalid")
    if (receipt.get("result") != "PASS" or receipt.get("environment") != environment
            or receipt.get("candidate_sha") != candidate_sha or not SHA_RE.fullmatch(candidate_sha)):
        raise PolicyError("recovery_receipt_scope_mismatch")
    if (not isinstance(receipt.get("backup_id"), str)
            or not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._:-]{0,127}", receipt["backup_id"])
            or not isinstance(receipt.get("destination_id"), str)
            or not re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._:-]{0,127}", receipt["destination_id"])
            or receipt.get("destination_isolated") is not True):
        raise PolicyError("recovery_receipt_backup_destination_invalid")
    source_sha = receipt.get("source_application_sha")
    if not isinstance(source_sha, str) or not SHA_RE.fullmatch(source_sha):
        raise PolicyError("recovery_receipt_source_identity_invalid")
    for name in ("backup_sha256", "transfer_receipt_sha256", "configuration_recovery_sha256",
                 "app_key_recovery_sha256", "restore_evidence_sha256", "data_integrity_sha256",
                 "trigger_inventory_sha256", "migration_rehearsal_sha256"):
        value = receipt.get(name)
        if not isinstance(value, str) or not SHA256_RE.fullmatch(value):
            raise PolicyError("recovery_receipt_digest_invalid")
    rollback_sha = receipt.get("rollback_sha")
    if not isinstance(rollback_sha, str) or not SHA_RE.fullmatch(rollback_sha) or rollback_sha != source_sha:
        raise PolicyError("recovery_receipt_rollback_invalid")
    if receipt.get("rollback_database_compatible") is not True:
        raise PolicyError("recovery_receipt_rollback_not_qualified")
    if any(type(receipt.get(name)) is not int or receipt[name] <= 0 for name in ("rpo_seconds", "rto_seconds")):
        raise PolicyError("recovery_receipt_objectives_invalid")
    try:
        issued = datetime.fromisoformat(receipt["issued_at"].replace("Z", "+00:00"))
        expires = datetime.fromisoformat(receipt["expires_at"].replace("Z", "+00:00"))
    except (KeyError, AttributeError, ValueError):
        raise PolicyError("recovery_receipt_time_invalid") from None
    current = now or datetime.now(timezone.utc)
    if (issued.tzinfo is None or expires.tzinfo is None or not issued < expires
            or expires - issued > timedelta(days=7)
            or not issued <= current < expires):
        raise PolicyError("recovery_receipt_expired")
    return receipt


def _timestamp(value: Any) -> datetime:
    if not isinstance(value, str):
        raise PolicyError("deployment_timestamp_invalid")
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        raise PolicyError("deployment_timestamp_invalid") from None
    if parsed.tzinfo is None:
        raise PolicyError("deployment_timestamp_invalid")
    return parsed


def verify_latest_staging_deployment(deployment_data: Any | None, candidate_sha: str,
                                     *, ci_receipt: Any | None = None,
                                     now: datetime | None = None) -> dict[str, Any]:
    if not SHA_RE.fullmatch(candidate_sha):
        raise PolicyError("candidate_sha_invalid")
    if ci_receipt is not None:
        validated = validate_ci_staging_equivalent_receipt(ci_receipt, candidate_sha, now=now)
        return {"status": "PASS", "environment": "staging-equivalent-ci", "candidate_sha": candidate_sha,
                "ci_workflow_run_id": validated["ci_workflow_run_id"],
                "receipt_digest_sha256": validated["receipt_digest_sha256"],
                "deployment_id": None, "created_at": validated["created_at"]}
    if deployment_data is None:
        raise PolicyError("staging_deployment_missing")
    deployments = _pages(deployment_data)
    staging = [item for item in deployments if item.get("environment") == "staging"]
    if not staging:
        raise PolicyError("staging_deployment_missing")
    latest = max(staging, key=lambda item: _timestamp(item.get("created_at")))
    deployment_id = latest.get("id")
    created = _timestamp(latest.get("created_at"))
    current = now or datetime.now(timezone.utc)
    if (type(deployment_id) is not int or deployment_id <= 0 or latest.get("sha") != candidate_sha
            or current - created > timedelta(days=8) or created > current):
        raise PolicyError("staging_deployment_stale_or_mismatched")
    return {"status": "PASS", "environment": "staging", "candidate_sha": candidate_sha,
            "deployment_id": deployment_id, "created_at": created.isoformat()}


def verify_staging_deployment_status(status_data: Any, *, deployment_id: int,
                                     candidate_sha: str) -> dict[str, Any]:
    statuses = _pages(status_data)
    if type(deployment_id) is not int or deployment_id <= 0 or not SHA_RE.fullmatch(candidate_sha):
        raise PolicyError("staging_deployment_identity_invalid")
    if not statuses:
        raise PolicyError("staging_deployment_status_missing")
    latest = max(statuses, key=lambda item: _timestamp(item.get("created_at")))
    expected_url = f"https://api.github.com/repos/{REPOSITORY}/deployments/{deployment_id}"
    if (latest.get("state") != "success" or latest.get("environment") not in {None, "staging"}
            or latest.get("deployment_url") != expected_url):
        raise PolicyError("staging_deployment_not_successful")
    return {"status": "PASS", "candidate_sha": candidate_sha, "deployment_id": deployment_id,
            "state": "success"}


def latest_primary_ci_run(run_data: Any, candidate_sha: str) -> int:
    if not SHA_RE.fullmatch(candidate_sha) or not isinstance(run_data, dict):
        raise PolicyError("ci_run_data_invalid")
    runs = run_data.get("workflow_runs")
    if not isinstance(runs, list) or len(runs) > 100:
        raise PolicyError("ci_run_data_invalid")
    exact = [run for run in runs if isinstance(run, dict)
             and run.get("head_sha") == candidate_sha
             and run.get("event") == "push"
             and run.get("path") == ".github/workflows/ci.yml"]
    if not exact:
        raise PolicyError("exact_sha_ci_missing")
    for run in exact:
        if type(run.get("run_number")) is not int or type(run.get("id")) is not int:
            raise PolicyError("ci_run_identity_invalid")
    latest = max(exact, key=lambda run: (_timestamp(run.get("created_at")),
                                         run.get("run_number", 0), run.get("id", 0)))
    if (latest.get("status") != "completed" or latest.get("conclusion") != "success"
            or type(latest.get("id")) is not int or latest["id"] <= 0):
        raise PolicyError("latest_exact_sha_ci_not_successful")
    return latest["id"]


def production_gate(*, first_launch_sha: str, recurring_enabled: str, candidate_sha: str,
                    staging_sha: str, staging_smoke_sha: str, recovery_receipt: dict[str, Any] | None,
                    deployment_lock_clear: str, incident_clear: str) -> dict[str, Any]:
    checks = {
        "first_launch_completed": bool(SHA_RE.fullmatch(first_launch_sha)),
        "recurring_policy_activated": recurring_enabled == "true",
        "candidate_sha_valid": bool(SHA_RE.fullmatch(candidate_sha)),
        "staging_exact_sha": staging_sha == candidate_sha and bool(SHA_RE.fullmatch(candidate_sha)),
        "staging_smoke_exact_sha": staging_smoke_sha == candidate_sha,
        "recovery_receipt_exact_sha": isinstance(recovery_receipt, dict)
        and recovery_receipt.get("result") == "PASS"
        and recovery_receipt.get("environment") == "production"
        and recovery_receipt.get("candidate_sha") == candidate_sha,
        "deployment_lock_clear": deployment_lock_clear == "true",
        "incident_clear": incident_clear == "true",
    }
    return {"schema": "wpcg-production-promotion-gate-v1",
            "candidate_sha": candidate_sha if SHA_RE.fullmatch(candidate_sha) else None,
            "status": "PASS" if all(checks.values()) else "HOLD", "checks": checks,
            "deployment_executed": False}


def _write_json(path: Path, value: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    encoded = (json.dumps(value, sort_keys=True, separators=(",", ":")) + "\n").encode()
    path.write_bytes(encoded)
    print(encoded.decode("utf-8"), end="")


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)
    intake = sub.add_parser("intake")
    intake.add_argument("--pull-requests", type=Path, required=True)
    intake.add_argument("--alerts", type=Path, required=True)
    intake.add_argument("--output", type=Path, required=True)
    candidate = sub.add_parser("candidate")
    candidate.add_argument("--sha", required=True)
    candidate.add_argument("--workflow-run-id", required=True)
    candidate.add_argument("--ci-run-id", required=True)
    candidate.add_argument("--ci-staging-receipt", type=Path)
    candidate.add_argument("--output", type=Path, required=True)
    subject = sub.add_parser("review-subject")
    subject.add_argument("--candidate-sha", required=True)
    subject.add_argument("--pull-requests", type=Path, required=True)
    subject.add_argument("--ledger-comments", type=Path, required=True)
    subject.add_argument("--ledger-metadata", type=Path, required=True)
    subject.add_argument("--output", type=Path, required=True)
    gate = sub.add_parser("production-gate")
    gate.add_argument("--candidate-sha", required=True)
    gate.add_argument("--first-launch-sha", default="")
    gate.add_argument("--recurring-enabled", default="false")
    gate.add_argument("--staging-sha", default="")
    gate.add_argument("--staging-smoke-sha", default="")
    gate.add_argument("--recovery-receipt", type=Path)
    gate.add_argument("--deployment-lock-clear", default="false")
    gate.add_argument("--incident-clear", default="false")
    gate.add_argument("--output", type=Path, required=True)
    recovery = sub.add_parser("validate-recovery")
    recovery.add_argument("--environment", choices=("staging", "production"), required=True)
    recovery.add_argument("--candidate-sha", required=True)
    recovery.add_argument("--receipt", type=Path, required=True)
    recovery.add_argument("--output", type=Path, required=True)
    staging_deployment = sub.add_parser("staging-deployment")
    staging_deployment.add_argument("--candidate-sha", required=True)
    staging_deployment.add_argument("--deployments", type=Path)
    staging_deployment.add_argument("--ci-receipt", type=Path)
    staging_deployment.add_argument("--output", type=Path, required=True)
    staging_status = sub.add_parser("staging-status")
    staging_status.add_argument("--candidate-sha", required=True)
    staging_status.add_argument("--deployment-id", required=True, type=int)
    staging_status.add_argument("--statuses", type=Path, required=True)
    staging_status.add_argument("--output", type=Path, required=True)
    ci = sub.add_parser("ci-run")
    ci.add_argument("--candidate-sha", required=True)
    ci.add_argument("--runs", type=Path, required=True)
    ci.add_argument("--output", type=Path, required=True)
    gen_receipt = sub.add_parser("generate-ci-staging-receipt")
    gen_receipt.add_argument("--candidate-sha", required=True)
    gen_receipt.add_argument("--ci-runs", type=Path, required=True)
    gen_receipt.add_argument("--ci-jobs", type=Path, required=True)
    gen_receipt.add_argument("--output", type=Path, required=True)
    val_receipt = sub.add_parser("validate-ci-staging-receipt")
    val_receipt.add_argument("--candidate-sha", required=True)
    val_receipt.add_argument("--receipt", type=Path, required=True)
    val_receipt.add_argument("--output", type=Path, required=True)
    host_gate = sub.add_parser("production-host-preflight")
    host_gate.add_argument("--candidate-sha", required=True)
    host_gate.add_argument("--first-launch-sha", default="")
    host_gate.add_argument("--recurring-enabled", default="false")
    host_gate.add_argument("--staging-sha", default="")
    host_gate.add_argument("--staging-smoke-sha", default="")
    host_gate.add_argument("--deployment-lock-clear", default="false")
    host_gate.add_argument("--incident-clear", default="false")
    host_gate.add_argument("--recovery-receipt", type=Path, required=True)
    host_gate.add_argument("--output", type=Path, required=True)
    args = parser.parse_args(argv)
    try:
        if args.command == "intake":
            result = classify_dependency_updates(_read_json(args.pull_requests), _read_json(args.alerts))
            result["markdown"] = dependency_markdown(result)
            _write_json(args.output, result)
            return 0 if result["status"] == "PASS" else 2
        if args.command == "candidate":
            ci_receipt = _read_json(args.ci_staging_receipt) if args.ci_staging_receipt else None
            result = candidate_manifest(args.sha, args.workflow_run_id, args.ci_run_id,
                                        ci_staging_receipt=ci_receipt)
            _write_json(args.output, result)
            return 0
        if args.command == "review-subject":
            # The ledger is streamed page by page: the generic 8 MB JSON bound
            # stays unchanged for every other input, and the streamed comment
            # count must match the count GitHub reports for the issue itself.
            # Two passes over the same file must also agree on the identity.
            scan = _scan_ledger_stable(args.ledger_comments)
            metadata = _read_json(args.ledger_metadata)
            if (not isinstance(metadata, dict) or type(metadata.get("comments")) is not int
                    or metadata["comments"] < 0):
                raise PolicyError("ledger_metadata_invalid")
            if metadata["comments"] != scan.comment_count:
                raise PolicyError("ledger_count_mismatch")
            print(f"CANDIDATE_LEDGER_SCAN name=review-ledger passes=2 bytes={scan.byte_count} "
                  f"pages={scan.page_count} comments={scan.comment_count} sha256={scan.sha256} "
                  f"limit_bytes={MAX_LEDGER_JSON_BYTES} limit_pages={MAX_LEDGER_PAGES} "
                  f"limit_comments={MAX_LEDGER_COMMENTS}", file=sys.stderr)
            result = merged_pr_subject(_read_json(args.pull_requests), scan, args.candidate_sha)
            _write_json(args.output, result)
            return 0
        if args.command == "validate-recovery":
            receipt = validate_recovery_receipt(args.receipt, environment=args.environment,
                                                candidate_sha=args.candidate_sha)
            result = {"status": "PASS", "environment": args.environment,
                      "candidate_sha": args.candidate_sha,
                      "backup_id": receipt["backup_id"],
                      "destination_id": receipt["destination_id"],
                      "source_application_sha": receipt["source_application_sha"],
                      "backup_sha256": receipt["backup_sha256"],
                      "transfer_receipt_sha256": receipt["transfer_receipt_sha256"],
                      "configuration_recovery_sha256": receipt["configuration_recovery_sha256"],
                      "app_key_recovery_sha256": receipt["app_key_recovery_sha256"],
                      "restore_evidence_sha256": receipt["restore_evidence_sha256"],
                      "data_integrity_sha256": receipt["data_integrity_sha256"],
                      "trigger_inventory_sha256": receipt["trigger_inventory_sha256"],
                      "migration_rehearsal_sha256": receipt["migration_rehearsal_sha256"],
                      "rollback_sha": receipt["rollback_sha"]}
            _write_json(args.output, result)
            return 0
        if args.command == "generate-ci-staging-receipt":
            result = build_ci_staging_equivalent_receipt(_read_json(args.ci_runs),
                                                          _read_json(args.ci_jobs),
                                                          args.candidate_sha)
            _write_json(args.output, result)
            return 0
        if args.command == "validate-ci-staging-receipt":
            result = validate_ci_staging_equivalent_receipt(_read_json(args.receipt),
                                                            args.candidate_sha)
            _write_json(args.output, result)
            return 0
        if args.command == "staging-deployment":
            deployments = _read_json(args.deployments) if args.deployments else None
            ci_receipt = _read_json(args.ci_receipt) if args.ci_receipt else None
            if deployments is None and ci_receipt is None:
                raise PolicyError("staging_deployment_missing")
            result = verify_latest_staging_deployment(deployments, args.candidate_sha,
                                                      ci_receipt=ci_receipt)
            _write_json(args.output, result)
            return 0
        if args.command == "staging-status":
            result = verify_staging_deployment_status(_read_json(args.statuses),
                                                      deployment_id=args.deployment_id,
                                                      candidate_sha=args.candidate_sha)
            _write_json(args.output, result)
            return 0
        if args.command == "ci-run":
            run_id = latest_primary_ci_run(_read_json(args.runs), args.candidate_sha)
            _write_json(args.output, {"status": "PASS", "candidate_sha": args.candidate_sha,
                                      "workflow_run_id": run_id})
            return 0
        if args.command == "production-host-preflight":
            try:
                receipt = validate_recovery_receipt(args.recovery_receipt, environment="production",
                                                    candidate_sha=args.candidate_sha)
            except PolicyError:
                receipt = None
            result = production_gate(first_launch_sha=args.first_launch_sha,
                                     recurring_enabled=args.recurring_enabled,
                                     candidate_sha=args.candidate_sha, staging_sha=args.staging_sha,
                                     staging_smoke_sha=args.staging_smoke_sha,
                                     recovery_receipt=receipt,
                                     deployment_lock_clear=args.deployment_lock_clear,
                                     incident_clear=args.incident_clear)
            _write_json(args.output, result)
            return 0 if result["status"] == "PASS" else 2
        receipt = None
        if args.recovery_receipt:
            try:
                receipt = validate_recovery_receipt(args.recovery_receipt, environment="production",
                                                    candidate_sha=args.candidate_sha)
            except PolicyError:
                receipt = None
        result = production_gate(first_launch_sha=args.first_launch_sha,
                                 recurring_enabled=args.recurring_enabled,
                                 candidate_sha=args.candidate_sha, staging_sha=args.staging_sha,
                                 staging_smoke_sha=args.staging_smoke_sha, recovery_receipt=receipt,
                                 deployment_lock_clear=args.deployment_lock_clear,
                                 incident_clear=args.incident_clear)
        _write_json(args.output, result)
        return 0 if result["status"] == "PASS" else 2
    except PolicyError as error:
        print(json.dumps({"status": "HOLD", "reason": str(error)}, separators=(",", ":")),
              file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
