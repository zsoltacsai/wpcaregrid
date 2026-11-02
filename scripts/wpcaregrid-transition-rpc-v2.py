#!/usr/bin/env python3
"""Isolated inert root-side client for the versioned Package B lease RPC.

This module is intentionally not imported by or wired into the Package A coordinator.
It only exchanges typed lease persistence requests with an unprivileged Laravel CLI child.
"""

import ctypes
import hashlib
import json
import os
import re
import socket
import stat
import struct
import subprocess
import time
import uuid
from datetime import datetime
from pathlib import Path


PROTOCOL_VERSION = 2
MAX_LINE_BYTES = 65536
DEFAULT_SOCKET_DIR = Path("/run/wpcaregrid")
DEFAULT_APP = Path("/var/www/wpcaregrid")
DEFAULT_COMMAND = ("/usr/local/bin/wpcaregrid-php", str(DEFAULT_APP / "artisan"), "deployment:lease-bridge")
SAFE_ENV = {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LANG": "C.UTF-8", "LC_ALL": "C.UTF-8"}
PAYLOAD_KEYS = {
    "create_lease": {"operation_id", "generation", "execution_id", "lease_id", "expected_fence_state",
                     "fence_tuple", "holder_digest", "expires_at", "nonce_digest"},
    "claim_lease": {"operation_id", "generation", "execution_id", "lease_id", "expected_fence_state",
                    "fence_tuple", "holder_digest", "nonce_digest"},
    "reject_lease": {"operation_id", "generation", "execution_id", "lease_id", "expected_fence_state",
                     "fence_tuple", "holder_digest", "nonce_digest", "rejection_code"},
    "read_lease_receipt": {"request_id", "request_digest", "fence_tuple"},
}
MUTATIONS = {"create_lease", "claim_lease", "reject_lease"}
FENCE_STATES = {"OPEN", "HOLD_REQUESTED", "CLOSING", "CLOSED", "DRAINING", "QUIESCED", "READY_PENDING_OWNER"}
REJECTION_CODES = {"CANCELLED", "SUPERSEDED", "OWNER_REVOKED", "STALE_BOOT"}
UUID7_RE = re.compile(r"\A[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\Z", re.I)
SHA256_RE = re.compile(r"\A[0-9a-f]{64}\Z")
UUID_RE = re.compile(r"\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\Z", re.I)


class LeaseRpcHold(RuntimeError):
    """The lease RPC is unavailable or ambiguous; no mutation retry is allowed."""


class LeaseRpcAmbiguous(LeaseRpcHold):
    def __init__(self, request_id, request_digest):
        super().__init__("Lease RPC outcome is ambiguous; exact read-only receipt reconciliation is required")
        self.request_id = request_id
        self.request_digest = request_digest


def canonical_json(value):
    return (json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=True, allow_nan=False) + "\n").encode("ascii")


def uuid7():
    millis = int(time.time_ns() // 1_000_000) & ((1 << 48) - 1)
    random_bits = int.from_bytes(os.urandom(10), "big") & ((1 << 74) - 1)
    value = ((millis << 80) | (0x7 << 76) | (((random_bits >> 62) & 0xFFF) << 64)
             | (0b10 << 62) | (random_bits & ((1 << 62) - 1)))
    return str(uuid.UUID(int=value))


def request_digest(request_id, operation, payload):
    return hashlib.sha256(canonical_json({
        "version": PROTOCOL_VERSION,
        "request_id": request_id,
        "operation": operation,
        "payload": payload,
    })).hexdigest()


class LaravelLeaseDatabaseAdapter:
    """Typed lease persistence client requiring a caller-held root EXCLUSIVE lock."""

    def __init__(self, lock, *, socket_dir=DEFAULT_SOCKET_DIR, app=DEFAULT_APP,
                 command=DEFAULT_COMMAND, timeout_seconds=10.0):
        if not callable(getattr(lock, "is_held", None)):
            raise TypeError("Package B lease RPC requires the stable root coordinator lock")
        if type(timeout_seconds) not in {int, float} or not 0 < timeout_seconds <= 30:
            raise ValueError("lease RPC timeout must be between 0 and 30 seconds")
        self.lock = lock
        self.socket_dir = Path(socket_dir)
        self.app = Path(app)
        self.command = tuple(command)
        self.timeout_seconds = float(timeout_seconds)

    def create_lease(self, *, operation_id, generation, execution_id, lease_id, fence_state, fence_tuple,
                     holder_digest, expires_at, nonce):
        self._validate_fence_tuple(fence_tuple, operation_id, generation, fence_state)
        return self._mutate("create_lease", {
            "operation_id": operation_id, "generation": generation, "execution_id": execution_id,
            "lease_id": lease_id, "expected_fence_state": fence_state, "holder_digest": holder_digest,
            "fence_tuple": fence_tuple, "expires_at": expires_at, "nonce_digest": self._nonce_digest(nonce),
        })

    def claim_lease(self, *, operation_id, generation, execution_id, lease_id, fence_state, fence_tuple,
                    holder_digest, nonce):
        self._validate_fence_tuple(fence_tuple, operation_id, generation, fence_state)
        return self._mutate("claim_lease", {
            "operation_id": operation_id, "generation": generation, "execution_id": execution_id,
            "lease_id": lease_id, "expected_fence_state": fence_state, "holder_digest": holder_digest,
            "fence_tuple": fence_tuple, "nonce_digest": self._nonce_digest(nonce),
        })

    def reject_lease(self, *, operation_id, generation, execution_id, lease_id, fence_state, fence_tuple,
                     holder_digest, rejection_code, nonce):
        self._validate_fence_tuple(fence_tuple, operation_id, generation, fence_state)
        return self._mutate("reject_lease", {
            "operation_id": operation_id, "generation": generation, "execution_id": execution_id,
            "lease_id": lease_id, "expected_fence_state": fence_state, "holder_digest": holder_digest,
            "fence_tuple": fence_tuple, "nonce_digest": self._nonce_digest(nonce), "rejection_code": rejection_code,
        })

    def read_lease_receipt(self, request_id, digest, fence_tuple):
        self._validate_fence_tuple(fence_tuple, fence_tuple.get("operation_id"), fence_tuple.get("generation"), fence_tuple.get("state"))
        result = self._exchange_once("read_lease_receipt", {
            "request_id": request_id, "request_digest": digest, "fence_tuple": fence_tuple,
        })
        if result.get("status") != "READ_ONLY" or not isinstance(result.get("result"), dict):
            raise LeaseRpcHold("Lease receipt readback returned an invalid status")
        value = result["result"]
        if set(value) != {"found", "receipt"} or type(value["found"]) is not bool:
            raise LeaseRpcHold("Lease receipt readback shape is invalid")
        receipt = value["receipt"]
        if not value["found"]:
            if receipt is not None:
                raise LeaseRpcHold("Absent lease receipt readback contained a receipt")
            return None
        if (not isinstance(receipt, dict) or receipt.get("request_id") != request_id
                or receipt.get("request_digest") != digest):
            raise LeaseRpcHold("Lease readback receipt identity mismatch")
        self._verify_receipt(receipt, request_id, digest, expected_payload={"fence_tuple": fence_tuple})
        return receipt

    def _mutate(self, operation, payload):
        request_id = uuid7()
        digest = request_digest(request_id, operation, payload)
        response = None
        try:
            response = self._exchange_once(operation, payload, request_id=request_id, digest=digest)
        except LeaseRpcAmbiguous:
            pass
        if response is not None:
            result = response.get("result")
            expected_outcome = response.get("status")
            if (expected_outcome in {"COMMITTED", "REFUSED"} and isinstance(result, dict)
                    and result.get("outcome") == expected_outcome):
                try:
                    return self._verify_receipt(result, request_id, digest, expected_action=operation, expected_payload=payload)
                except LeaseRpcHold:
                    # A response that cannot prove its exact receipt is ambiguous after mutation.
                    pass

        # Never replay a mutation after an uncertain result; perform one exact read only.
        receipt = self.read_lease_receipt(request_id, digest, payload["fence_tuple"])
        if receipt is None:
            raise LeaseRpcHold("Ambiguous lease mutation has no committed authoritative receipt") from None
        return self._verify_receipt(receipt, request_id, digest, expected_action=operation, expected_payload=payload)

    @staticmethod
    def _verify_receipt(receipt, request_id, digest, *, expected_action=None, expected_payload=None):
        expected = {"action", "committed", "execution_id", "fence_tuple_digest", "fence_state", "fence_boot_id",
                    "fence_journal_seq", "fence_journal_head_hash", "fence_evidence_digest",
                    "fence_permit_manifest_hash", "generation", "lease_id", "nonce_digest",
                    "operation_id", "outcome", "request_digest", "request_id", "result_code",
                    "source_state_digest", "state", "state_digest", "state_version"}
        if (not isinstance(receipt, dict) or set(receipt) != expected
                or receipt.get("request_id") != request_id or receipt.get("request_digest") != digest
                or receipt.get("outcome") not in {"COMMITTED", "REFUSED"}
                or receipt.get("committed") is not (receipt.get("outcome") == "COMMITTED")
                or receipt.get("action") not in MUTATIONS
                or (expected_action is not None and receipt.get("action") != expected_action)
                or not UUID7_RE.fullmatch(str(receipt.get("operation_id", "")))
                or not UUID7_RE.fullmatch(str(receipt.get("execution_id", "")))
                or not UUID7_RE.fullmatch(str(receipt.get("lease_id", "")))
                or type(receipt.get("generation")) is not int or receipt["generation"] < 0
                or not SHA256_RE.fullmatch(str(receipt.get("fence_tuple_digest", "")))
                or receipt.get("fence_state") not in FENCE_STATES
                or not UUID_RE.fullmatch(str(receipt.get("fence_boot_id", "")))
                or type(receipt.get("fence_journal_seq")) is not int or receipt["fence_journal_seq"] < 0
                or not SHA256_RE.fullmatch(str(receipt.get("fence_journal_head_hash", "")))
                or not SHA256_RE.fullmatch(str(receipt.get("fence_evidence_digest", "")))
                or ((receipt.get("fence_state") == "DRAINING") != bool(SHA256_RE.fullmatch(str(receipt.get("fence_permit_manifest_hash", "")))))
                or (receipt.get("nonce_digest") is not None and not SHA256_RE.fullmatch(str(receipt["nonce_digest"])))):
            raise LeaseRpcHold("Lease receipt does not match the exact request")

        receipt_tuple = {
            "state": receipt["fence_state"], "operation_id": receipt["operation_id"],
            "generation": receipt["generation"], "boot_id": receipt["fence_boot_id"],
            "journal_seq": receipt["fence_journal_seq"],
            "journal_head_hash": receipt["fence_journal_head_hash"],
            "evidence_digest": receipt["fence_evidence_digest"],
        }
        if receipt["fence_state"] == "DRAINING":
            receipt_tuple["permit_manifest_hash"] = receipt["fence_permit_manifest_hash"]
        elif receipt["fence_permit_manifest_hash"] is not None:
            raise LeaseRpcHold("Lease receipt contains an unexpected drain manifest")
        if hashlib.sha256(canonical_json(receipt_tuple)).hexdigest() != receipt["fence_tuple_digest"]:
            raise LeaseRpcHold("Lease receipt fence tuple digest is invalid")

        if expected_payload is not None:
            scope_fields = tuple(field for field in ("operation_id", "generation", "execution_id", "lease_id")
                                 if field in expected_payload)
            if (any(receipt.get(field) != expected_payload.get(field) for field in scope_fields)
                    or ("fence_tuple" in expected_payload and receipt_tuple != expected_payload["fence_tuple"])):
                raise LeaseRpcHold("Lease receipt scope differs from the originating request")
            if "nonce_digest" in expected_payload:
                replay = receipt.get("outcome") == "REFUSED" and receipt.get("result_code") == "NONCE_REPLAY"
                if ((receipt.get("nonce_digest") is None) != replay
                        or (not replay and receipt.get("nonce_digest") != expected_payload.get("nonce_digest"))):
                    raise LeaseRpcHold("Lease receipt nonce binding differs from the originating request")
            if (expected_action == "reject_lease" and receipt.get("outcome") == "COMMITTED"
                    and receipt.get("result_code") != expected_payload.get("rejection_code")):
                raise LeaseRpcHold("Lease rejection receipt differs from the requested rejection")

        if receipt["outcome"] == "REFUSED":
            refusal_codes = {
                "create_lease": {"STALE_GENERATION", "STALE_FENCE_TUPLE", "FENCE_STATE_MISMATCH",
                                 "EXECUTION_ALREADY_LEASED", "LEASE_EXPIRED", "NONCE_REPLAY"},
                "claim_lease": {"STALE_GENERATION", "STALE_FENCE_TUPLE", "FENCE_STATE_MISMATCH",
                                "LEASE_NOT_FOUND", "LEASE_EXPIRED", "LEASE_NOT_CLAIMABLE",
                                "HOLDER_MISMATCH", "NONCE_REPLAY"},
                "reject_lease": {"STALE_GENERATION", "STALE_FENCE_TUPLE", "FENCE_STATE_MISMATCH",
                                 "LEASE_NOT_FOUND", "LEASE_NOT_CLAIMABLE", "HOLDER_MISMATCH", "NONCE_REPLAY"},
            }
            if (receipt.get("result_code") not in refusal_codes[receipt["action"]]
                    or receipt.get("state") is not None or receipt.get("state_digest") is not None
                    or receipt.get("state_version") is not None or receipt.get("source_state_digest") is not None
                    or ((receipt["result_code"] == "NONCE_REPLAY") is not (receipt.get("nonce_digest") is None))):
                raise LeaseRpcHold("Lease refusal receipt is not a valid terminal refusal")
        else:
            valid_state = {
                "create_lease": ("CREATED", "ISSUED", 1, None),
                "claim_lease": ("CLAIMED", "CLAIMED", 2, "digest"),
                "reject_lease": ("CANCELLED", "REJECTED", 2, "digest"),
            }
            result = (receipt.get("result_code"), receipt.get("state"), receipt.get("state_version"))
            if receipt["action"] == "reject_lease":
                accepted = receipt.get("result_code") in {"CANCELLED", "SUPERSEDED", "OWNER_REVOKED", "STALE_BOOT"}
                accepted = accepted and result[1:] == ("REJECTED", 2)
                source_expected = "digest"
            else:
                code, state, version, source_expected = valid_state[receipt["action"]]
                accepted = result == (code, state, version)
            if (not accepted or not SHA256_RE.fullmatch(str(receipt.get("state_digest", "")))
                    or (source_expected is None and receipt.get("source_state_digest") is not None)
                    or (source_expected == "digest" and not SHA256_RE.fullmatch(str(receipt.get("source_state_digest", ""))))):
                raise LeaseRpcHold("Committed lease receipt state binding is invalid")
        return receipt

    @staticmethod
    def _nonce_digest(nonce):
        if not isinstance(nonce, str) or not re.fullmatch(r"[A-Za-z0-9_-]{32,128}", nonce):
            raise ValueError("lease nonce is invalid")
        return hashlib.sha256(nonce.encode("ascii")).hexdigest()

    def _exchange_once(self, operation, payload, *, request_id=None, digest=None):
        if operation not in PAYLOAD_KEYS or not isinstance(payload, dict) or set(payload) != PAYLOAD_KEYS[operation]:
            raise LeaseRpcHold("Lease RPC operation or payload is not allowlisted")
        self._validate_payload(operation, payload)
        get_effective_uid = getattr(os, "geteuid", None)
        if get_effective_uid is None or get_effective_uid() != 0 or not self.lock.is_held("exclusive"):
            raise LeaseRpcHold("Lease RPC requires the root coordinator EXCLUSIVE lock")
        import grp
        import pwd
        if not request_id:
            request_id = uuid7()
        if not digest:
            digest = request_digest(request_id, operation, payload)
        if not UUID7_RE.fullmatch(request_id) or not SHA256_RE.fullmatch(digest):
            raise LeaseRpcHold("Lease RPC request identity is invalid")
        envelope = {"version": PROTOCOL_VERSION, "request_id": request_id, "request_digest": digest,
                    "operation": operation, "payload": payload}
        encoded = canonical_json(envelope)
        if len(encoded) > MAX_LINE_BYTES:
            raise LeaseRpcHold("Lease RPC request exceeds its bound")

        directory_fd = self._open_root_directory(self.socket_dir)
        os.close(directory_fd)
        try:
            app_user = pwd.getpwnam("www-data")
            app_group = grp.getgrnam("www-data")
        except KeyError as exc:
            raise LeaseRpcHold("Laravel application identity is unavailable") from exc
        if app_user.pw_gid != app_group.gr_gid:
            raise LeaseRpcHold("Laravel CLI UID/GID mapping is inconsistent")

        socket_path = self.socket_dir / (".lease-rpc-v2-" + uuid.uuid4().hex + ".sock")
        listener = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        process = connection = None
        inode = None
        sent = False
        try:
            if len(os.fsencode(socket_path)) >= 108:
                raise LeaseRpcHold("Lease RPC socket path exceeds the platform bound")
            listener.settimeout(self.timeout_seconds)
            listener.bind(str(socket_path))
            info = socket_path.lstat()
            inode = (info.st_dev, info.st_ino)
            os.chown(socket_path, 0, app_group.gr_gid, follow_symlinks=False)
            os.chmod(socket_path, 0o620, follow_symlinks=False)
            info = socket_path.lstat()
            if (not stat.S_ISSOCK(info.st_mode) or info.st_uid != 0 or info.st_gid != app_group.gr_gid
                    or stat.S_IMODE(info.st_mode) != 0o620 or info.st_nlink != 1 or (info.st_dev, info.st_ino) != inode):
                raise LeaseRpcHold("Lease RPC socket ownership or mode is invalid")
            listener.listen(1)
            argv = [*self.command, "--connect-socket", str(socket_path)]
            process = subprocess.Popen(
                argv, cwd=str(self.app), env=dict(SAFE_ENV), stdin=subprocess.DEVNULL,
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, close_fds=True,
                preexec_fn=lambda: self._drop_to_application_identity(app_user.pw_uid, app_group.gr_gid),
            )
            connection, _ = listener.accept()
            connection.settimeout(self.timeout_seconds)
            peer_pid, peer_uid, peer_gid = struct.unpack("3i", connection.getsockopt(
                socket.SOL_SOCKET, socket.SO_PEERCRED, struct.calcsize("3i")))
            if peer_pid != process.pid or peer_uid != app_user.pw_uid or peer_gid != app_group.gr_gid:
                raise LeaseRpcHold("Lease RPC peer credentials do not match the dropped CLI child")
            sent = True
            connection.sendall(encoded)
            response_bytes = self._read_line(connection)
            try:
                response = json.loads(response_bytes.decode("ascii"))
            except (UnicodeDecodeError, json.JSONDecodeError):
                raise LeaseRpcHold("Lease RPC response is malformed") from None
            if (not isinstance(response, dict) or set(response) != {"version", "request_id", "request_digest", "status", "result"}
                    or response.get("version") != PROTOCOL_VERSION or response.get("request_id") != request_id
                    or response.get("request_digest") != digest or response.get("status") not in {"COMMITTED", "REFUSED", "READ_ONLY"}
                    or canonical_json(response) != response_bytes):
                raise LeaseRpcHold("Lease RPC response binding is invalid")
            connection.close()
            connection = None
            try:
                code = process.wait(timeout=self.timeout_seconds)
            except subprocess.TimeoutExpired as exc:
                process.kill()
                process.wait()
                if operation in MUTATIONS and sent:
                    raise LeaseRpcAmbiguous(request_id, digest) from exc
                raise LeaseRpcHold("Read-only lease RPC child exceeded its time bound") from exc
            if code != 0:
                if operation in MUTATIONS and sent:
                    raise LeaseRpcAmbiguous(request_id, digest)
                raise LeaseRpcHold("Read-only lease RPC child exited unsuccessfully")
            return response
        except LeaseRpcAmbiguous:
            raise
        except LeaseRpcHold as exc:
            if operation in MUTATIONS and sent:
                raise LeaseRpcAmbiguous(request_id, digest) from exc
            raise
        except (OSError, socket.timeout, subprocess.SubprocessError) as exc:
            if process is not None and process.poll() is None:
                try:
                    process.wait(timeout=0.5)
                except subprocess.TimeoutExpired:
                    pass
            if operation in MUTATIONS and sent:
                raise LeaseRpcAmbiguous(request_id, digest) from exc
            raise LeaseRpcHold("Lease RPC transport failed before a trustworthy response") from exc
        finally:
            cleanup_failed = False
            if connection is not None:
                try:
                    connection.close()
                except OSError:
                    cleanup_failed = True
            try:
                listener.close()
            except OSError:
                cleanup_failed = True
            if process is not None and process.poll() is None:
                try:
                    process.kill()
                except ProcessLookupError:
                    pass
                except OSError:
                    cleanup_failed = True
                try:
                    process.wait(timeout=self.timeout_seconds)
                except (subprocess.TimeoutExpired, OSError):
                    cleanup_failed = True
            try:
                current = socket_path.lstat()
            except FileNotFoundError:
                current = None
            except OSError:
                current = None
                cleanup_failed = True
            if current is not None:
                if (inode == (current.st_dev, current.st_ino) and stat.S_ISSOCK(current.st_mode)
                        and current.st_uid == 0):
                    try:
                        socket_path.unlink()
                    except OSError:
                        cleanup_failed = True
                else:
                    cleanup_failed = True
            if cleanup_failed:
                self._raise_cleanup_failure(operation, sent, request_id, digest)

    @staticmethod
    def _raise_cleanup_failure(operation, sent, request_id, digest):
        if operation in MUTATIONS and sent:
            raise LeaseRpcAmbiguous(request_id, digest) from None
        raise LeaseRpcHold("Lease RPC local resource cleanup was not verified") from None

    @staticmethod
    def _validate_payload(operation, payload):
        if operation == "read_lease_receipt":
            if not UUID7_RE.fullmatch(str(payload.get("request_id", ""))) or not SHA256_RE.fullmatch(
                str(payload.get("request_digest", ""))
            ):
                raise LeaseRpcHold("Lease receipt lookup identity is invalid")
            fence_tuple = payload.get("fence_tuple")
            if not isinstance(fence_tuple, dict):
                raise LeaseRpcHold("Lease receipt lookup fence tuple is missing")
            LaravelLeaseDatabaseAdapter._validate_fence_tuple(
                fence_tuple, fence_tuple.get("operation_id"), fence_tuple.get("generation"), fence_tuple.get("state")
            )
            return

        for field in ("operation_id", "execution_id", "lease_id"):
            if not UUID7_RE.fullmatch(str(payload.get(field, ""))):
                raise LeaseRpcHold("Lease operation identity is invalid")
        if (type(payload.get("generation")) is not int or payload["generation"] < 0
                or payload.get("expected_fence_state") not in FENCE_STATES
                or not SHA256_RE.fullmatch(str(payload.get("holder_digest", "")))
                or not SHA256_RE.fullmatch(str(payload.get("nonce_digest", "")))):
            raise LeaseRpcHold("Lease operation tuple or digest is invalid")
        LaravelLeaseDatabaseAdapter._validate_fence_tuple(
            payload.get("fence_tuple"), payload["operation_id"], payload["generation"], payload["expected_fence_state"]
        )
        if operation == "create_lease":
            expiry = payload.get("expires_at")
            if not isinstance(expiry, str) or not re.fullmatch(
                r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z", expiry
            ):
                raise LeaseRpcHold("Lease expiry is not canonical UTC")
            try:
                parsed = datetime.strptime(expiry, "%Y-%m-%dT%H:%M:%S.%fZ")
            except ValueError:
                raise LeaseRpcHold("Lease expiry is invalid") from None
            if parsed.strftime("%Y-%m-%dT%H:%M:%S.%fZ") != expiry:
                raise LeaseRpcHold("Lease expiry is not canonical UTC")
        if operation == "reject_lease" and payload.get("rejection_code") not in REJECTION_CODES:
            raise LeaseRpcHold("Lease rejection code is not allowlisted")

    @staticmethod
    def _read_line(connection):
        data = bytearray()
        while len(data) <= MAX_LINE_BYTES:
            chunk = connection.recv(min(4096, MAX_LINE_BYTES + 1 - len(data)))
            if not chunk:
                break
            data.extend(chunk)
            if b"\n" in chunk:
                if data.count(b"\n") != 1 or not data.endswith(b"\n") or len(data) > MAX_LINE_BYTES:
                    raise LeaseRpcHold("Lease RPC response framing is invalid")
                return bytes(data)
        raise LeaseRpcHold("Lease RPC response is incomplete or oversized")

    @staticmethod
    def _open_root_directory(path):
        flags = os.O_RDONLY | getattr(os, "O_DIRECTORY", 0) | getattr(os, "O_NOFOLLOW", 0)
        fd = os.open(path, flags)
        info = os.fstat(fd)
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or stat.S_IMODE(info.st_mode) & 0o022:
            os.close(fd)
            raise LeaseRpcHold("Lease RPC socket directory is not root-trusted")
        return fd

    @staticmethod
    def _drop_to_application_identity(uid, gid):
        os.setgroups([])
        os.setresgid(gid, gid, gid)
        os.setresuid(uid, uid, uid)
        libc = ctypes.CDLL(None, use_errno=True)
        if libc.prctl(38, 1, 0, 0, 0) != 0:  # PR_SET_NO_NEW_PRIVS
            error = ctypes.get_errno()
            raise OSError(error, "Unable to lock the lease child against privilege gain")

    @staticmethod
    def _validate_fence_tuple(value, operation_id, generation, state):
        expected = {"state", "operation_id", "generation", "boot_id", "journal_seq", "journal_head_hash", "evidence_digest"}
        if state == "DRAINING":
            expected.add("permit_manifest_hash")
        valid = (
            isinstance(value, dict) and set(value) == expected
            and value.get("state") == state and value.get("operation_id") == operation_id
            and type(value.get("generation")) is int and value["generation"] == generation
            and UUID_RE.fullmatch(str(value.get("boot_id", ""))) is not None
            and type(value.get("journal_seq")) is int and value["journal_seq"] >= 0
            and SHA256_RE.fullmatch(str(value.get("journal_head_hash", ""))) is not None
            and SHA256_RE.fullmatch(str(value.get("evidence_digest", ""))) is not None
            and (state != "DRAINING" or SHA256_RE.fullmatch(str(value.get("permit_manifest_hash", ""))) is not None)
        )
        if not valid:
            raise LeaseRpcHold("Lease root fence tuple is invalid")


def _lease_rpc_v2_test_worker(socket_path):
    """CI-only transport peer used to exercise AF_UNIX and credential drop."""
    libc = ctypes.CDLL(None, use_errno=True)
    no_new_privs = libc.prctl(39, 0, 0, 0, 0) == 1  # PR_GET_NO_NEW_PRIVS
    if os.geteuid() == 0 or dict(os.environ) != SAFE_ENV or not no_new_privs:
        return 41
    connection = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    try:
        connection.settimeout(5.0)
        connection.connect(socket_path)
        request_bytes = LaravelLeaseDatabaseAdapter._read_line(connection)
        try:
            request = json.loads(request_bytes.decode("ascii"))
        except (UnicodeDecodeError, json.JSONDecodeError):
            return 42
        if (not isinstance(request, dict) or request.get("version") != PROTOCOL_VERSION
                or request.get("operation") != "read_lease_receipt"):
            return 43
        response = {
            "version": PROTOCOL_VERSION,
            "request_id": request.get("request_id"),
            "request_digest": request.get("request_digest"),
            "status": "READ_ONLY",
            "result": {"found": False, "receipt": None},
        }
        connection.sendall(canonical_json(response))
        return 0
    except OSError:
        return 44
    finally:
        connection.close()


if __name__ == "__main__" and len(os.sys.argv) > 1 and os.sys.argv[1] == "--lease-rpc-v2-worker":
    if len(os.sys.argv) != 4 or os.sys.argv[2] != "--connect-socket":
        raise SystemExit(45)
    raise SystemExit(_lease_rpc_v2_test_worker(os.sys.argv[3]))
