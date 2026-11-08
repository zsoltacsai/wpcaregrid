#!/usr/bin/env python3
"""Compile both generated G1 profiles without loading or caching them."""

from __future__ import annotations

import importlib.util
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTRACT_PATH = ROOT / "production_g1_mysql_contract.py"
SPEC = importlib.util.spec_from_file_location("production_g1_mysql_contract", CONTRACT_PATH)
if SPEC is None or SPEC.loader is None:
    raise SystemExit("Unable to load Production G1 contract.")
CONTRACT = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = CONTRACT
SPEC.loader.exec_module(CONTRACT)


def main() -> int:
    parser = shutil.which("apparmor_parser")
    if parser is None:
        print("Required apparmor_parser is unavailable.", file=sys.stderr)
        return 2

    with tempfile.TemporaryDirectory(prefix="wpcg-g1-apparmor-") as temp_dir:
        root = Path(temp_dir)
        for instance in CONTRACT.INSTANCES.values():
            profile = root / instance.apparmor_profile
            profile.write_bytes(CONTRACT.apparmor_profile(instance).encode("utf-8"))
            result = subprocess.run(
                [parser, "-Q", "-K", str(profile)],
                check=False,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
            )
            if result.returncode != 0:
                print(f"{instance.apparmor_profile}: parser rejected generated profile.", file=sys.stderr)
                if result.stderr:
                    print(result.stderr.rstrip(), file=sys.stderr)
                return 1
            print(f"{instance.apparmor_profile}=PASS")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
