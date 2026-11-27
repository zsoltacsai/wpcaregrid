import sys
import termios
import json

print("Password:", end="", flush=True)
_ = sys.stdin.buffer.readline()
print("\r\nfake# ", end="", flush=True)
while True:
    line = sys.stdin.buffer.readline()
    if not line:
        sys.exit(0)
    command = line.strip()
    if command == b"stty -echo":
        attrs = termios.tcgetattr(sys.stdin.fileno())
        attrs[3] &= ~termios.ECHO
        termios.tcsetattr(sys.stdin.fileno(), termios.TCSANOW, attrs)
        print("fake# ", end="", flush=True)
    elif b"__WPCG_ROOT_CHECK__" in command:
        print("\r\n__WPCG_ROOT_CHECK__:0:stg1.wpcaregrid.com\r\nfake# ", end="", flush=True)
    elif command == b"emit-report":
        print("\r\n__WPCG_SAFE_REPORT__:bridge=alive\r\nUNSAFE_RAW_SENTINEL\r\nfake# ", end="", flush=True)
    elif command == b"printf '%s\\n' '__WPCG_SAFE_REPORT__:bridge_probe=PASS:rc=0'":
        print("\r\n__WPCG_SAFE_REPORT__:bridge_probe=PASS:rc=0\r\nUNSAFE_RAW_SENTINEL\r\nfake# ", end="", flush=True)
    elif command == b"emit-command-rc":
        print("\r\n__WPCG_SAFE_REPORT__:CHECK_RC=7\r\nUNSAFE_RAW_SENTINEL\r\nfake# ", end="", flush=True)
    elif command == b"emit-raw-only":
        print("\r\nUNSAFE_RAW_SENTINEL\r\nfake# ", end="", flush=True)
    elif command == b"package-check":
        payload = {
            "phase": "package-check",
            "rc": 7,
            "package_version": "1.0.16",
            "tree_match": True,
            "sha256sums_ok": True,
            "pin_count": 20,
            "sha256sums_digest_match": True,
            "binding_digest_match": True,
            "filelist_match": True,
            "owner_mode_type_links_match": True,
        }
        print("\r\nUNSAFE_RAW_SENTINEL\r\n__WPCG_ONESHOT_REPORT__:" + json.dumps(payload, separators=(",", ":")) + "\r\n__WPCG_ONESHOT_DONE__\r\nUNSAFE_RAW_SENTINEL\r\nfake# ", end="", flush=True)
    elif command == b"inventory-evidence-metadata":
        payload = {
            "phase": "inventory-evidence-metadata",
            "rc": 0,
            "evidence_path": "/root/exact-evidence",
            "directory": {"type": "directory", "uid": 0, "gid": 0, "mode": 448,
                          "nlink": 2, "size": 4096, "mtime_ns": 1},
            "children": [{"name": "collector.stdout", "type": "regular", "uid": 0, "gid": 0,
                          "mode": 384, "nlink": 1, "size": 12, "mtime_ns": 2}],
            "error_class": "none",
            "content_inspection": "not-read-basename-unknown",
        }
        print("\r\nUNSAFE_RAW_SENTINEL\r\n__WPCG_ONESHOT_REPORT__:" + json.dumps(payload, separators=(",", ":")) + "\r\n__WPCG_ONESHOT_DONE__\r\nUNSAFE_RAW_SENTINEL\r\nfake# ", end="", flush=True)
    elif command == b"inventory-candidate":
        meta_dir = {"type": "directory", "uid": 0, "gid": 0, "mode": 448,
                    "nlink": 2, "size": 4096, "mtime_ns": 1000000000000000000}
        meta_file = {"type": "regular", "uid": 0, "gid": 0, "mode": 420,
                     "nlink": 1, "size": 1234, "mtime_ns": 1000000000000000000}
        payload = {
            "phase": "inventory-candidate", "rc": 0,
            "evidence_path": "/root/exact-evidence", "stdout_name": "inventory-1.json",
            "stderr_name": "inventory-1.stderr", "candidate_origin_proven": False,
            "directory_meta": meta_dir, "stdout_meta": meta_file, "stderr_meta": {**meta_file, "size": 0},
            "access_gate": True, "content_read": True,
            "stderr_error_class": "none", "parse_ok": True, "schema": 2,
            "complete": True, "scans": 2, "matrix_sha256_match": True,
            "boot_id_match": True, "collected_time_valid": True, "process_count": 4,
            "units": [{"name": "php-fpm.service", "present": True, "pid_count": 2}],
            "production_surface": {"present": True, "complete": True, "real_file_count": 0,
                                   "fixture_file_count": 0, "reason_count": 0},
            "reason_codes": {}, "verifier_check_ran": True,
            "verifier_failure_count": 0, "rejected_fd_count": 0,
            "verifier_failure_codes": [],
        }
        print("\r\nUNSAFE_RAW_SENTINEL\r\n__WPCG_ONESHOT_REPORT__:" + json.dumps(payload, separators=(",", ":")) + "\r\n__WPCG_ONESHOT_DONE__\r\nUNSAFE_RAW_SENTINEL\r\nfake# ", end="", flush=True)
    elif command == b"exit":
        sys.exit(0)
