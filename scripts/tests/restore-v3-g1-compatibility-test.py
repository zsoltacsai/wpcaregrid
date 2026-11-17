#!/usr/bin/env python3
"""Synthetic-only tests for restore v2/v3 compatibility with the Production G1 instances.

Source-only: no host, MySQL client, systemd unit, network or production resource is
touched. MySQL access is replaced by the same in-process fakes the v3 tests use and
every file lives in a private temporary directory.

What is proven here:
  * the source schema is an explicit allowlist ({wpcaregrid, wpcg_prod_main}) and the
    default for existing callers is unchanged;
  * a second destination kind, `g1_systemd_restore_instance`, is validated against
    the constants in scripts/production_g1_mysql_contract.py and is the ONLY kind that
    may share host_id/environment_id with the source;
  * the disposable-container kind is unchanged;
  * production admission remains HOLD with its three real-host attestations.
"""

import contextlib
import copy
import datetime
import hashlib
import importlib.util
import json
import os
import sys
import tempfile
from pathlib import Path
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]


def _load(name, relative):
    spec = importlib.util.spec_from_file_location(name, ROOT / relative)
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    spec.loader.exec_module(module)
    return module


V3T = _load("authenticated_restore_v3_tests_for_g1", "scripts/tests/authenticated-restore-v3-test.py")
V3 = V3T.V3
V2 = V3.V2
V2T = V3T.V2T
CONTRACT = _load("production_g1_mysql_contract_for_g1_tests", "scripts/production_g1_mysql_contract.py")
OWNER = os.geteuid()
PROD = "wpcg_prod_main"
G1_KIND = "g1_systemd_restore_instance"
PRODUCTION_INSTANCE = CONTRACT.INSTANCES["wpcaregrid-production"]
RESTORE_INSTANCE = CONTRACT.INSTANCES["wpcaregrid-production-restore"]
HOLD_ATTESTATIONS = [
    "mysqldump_endpoint_instance_identity",
    "application_connection_identity",
    "candidate_migration_bytes_execution",
]


def expect(code, function, *args, **kwargs):
    try:
        function(*args, **kwargs)
    except V2.RestoreRefused as error:
        assert str(error) == code, (str(error), code)
        return
    raise AssertionError(f"Expected refusal {code}")


def g1_isolation():
    return {"approved": True, "kind": G1_KIND, "unit": RESTORE_INSTANCE.unit, "port": RESTORE_INSTANCE.port,
            "datadir": RESTORE_INSTANCE.datadir, "socket": RESTORE_INSTANCE.socket}


def g1_policy(base):
    """The same-VPS destination policy: identity equals the (single-host) source."""
    policy = copy.deepcopy(base)
    policy["policy_id"] = "synthetic-g1-policy-v1"
    policy["destination_identity"] = {"environment_id": "source-env", "host_id": "source-host"}
    policy["mysql"]["host"] = "127.0.0.1"
    policy["mysql"]["port"] = RESTORE_INSTANCE.port
    policy["isolation"] = g1_isolation()
    return policy


def _dump(schema):
    return (b"-- Host: 127.0.0.1    Database: " + schema.encode() + b"\n"
            + V3T.MYSQL84_DUMP_BODY_FIXTURE.split(b"\n", 2)[2])


class G1World(V3T.World):
    """A synthetic Production-G1-shaped world: schema wpcg_prod_main, same host/environment."""

    def __init__(self, directory):
        super().__init__(directory)
        self.source_policy["schema"] = PROD
        self.source_policy["mysql"]["port"] = PRODUCTION_INSTANCE.port
        V3T.write_json(self.source_policy_path, self.source_policy)
        self.manifest["backup"]["source_schema"] = PROD
        self.manifest["source_identity"]["database"] = PROD
        self.install(g1_policy(self.policy))

    def install(self, policy, manifest_mutate=None):
        self.policy = policy
        self.manifest["destination_policy_sha256"] = V3T.write_json(self.policy_path, policy)
        if manifest_mutate is not None:
            manifest_mutate(self.manifest)
        V3T.write_json(self.manifest_path, self.manifest)

    def capture(self, observe=None, dump_sql=None):
        sql = dump_sql if dump_sql is not None else V3T.MYSQL84_DUMP_BODY_FIXTURE
        self.archive_path.unlink()
        observations = iter((self.observation(), self.observation()))

        def dump(argv, consume, **kw):
            assert argv[0] == "/usr/bin/mysqldump"
            assert argv[argv.index("--port=" + str(PRODUCTION_INSTANCE.port))] == "--port=3308"
            assert argv[-2:] == ["--", PROD]
            consume(sql)
        with (mock.patch.object(V3, "_bounded_process", side_effect=dump),
              mock.patch.object(V3, "_now", side_effect=lambda n: n or self.finish_at)):
            V3.capture(self.source_policy_path, self.archive_path, self.manifest["backup"]["id"],
                       self.reference_path, self.record_path, self.key_path, owner_uid=OWNER,
                       now=self.begin_at, observe=lambda p: next(observations))
        self.manifest["backup"].update(sha256=hashlib.sha256(self.archive_path.read_bytes()).hexdigest(),
                                      size_bytes=self.archive_path.stat().st_size)
        V3T.write_json(self.manifest_path, self.manifest)
        return self.record_path

    @staticmethod
    def observation():
        return V3T.observe_ok() | {"database": PROD}


def verify(world):
    return V2.verify_manifest_and_policy(world.manifest_path, world.policy_path, owner_uid=OWNER)


# ---------------------------------------------------------------- (a) source schema allowlist

def test_source_schema_is_an_explicit_allowlist_with_an_unchanged_default():
    assert V2.SOURCE_SCHEMA_ALLOWLIST == frozenset({"wpcaregrid", "wpcg_prod_main"})
    assert V2.DEFAULT_SOURCE_SCHEMA == "wpcaregrid"
    legacy = V2T.SAFE_DUMP
    prod = legacy.replace(b"`wpcaregrid`", b"`wpcg_prod_main`")
    target = V2T.SCHEMA
    assert V2.audit_sql(legacy, target)["charset"] == "utf8mb4"                       # default unchanged
    assert V2.audit_sql(legacy, target, source_schema="wpcaregrid")["charset"] == "utf8mb4"
    plan = V2.audit_sql(prod, target, source_schema=PROD)
    assert plan["create_sql"] == f"CREATE DATABASE `{target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    assert b"`wpcg_prod_main`" not in plan["transformed_sql"].replace(b"-- Current Database: `wpcg_prod_main`", b"")
    # A production dump is not silently accepted by the legacy default, nor the reverse.
    expect("DATABASE_SELECTOR_SOURCE_MISMATCH", V2.audit_sql, prod, target)
    expect("DATABASE_SELECTOR_SOURCE_MISMATCH", V2.audit_sql, legacy, target, source_schema=PROD)
    # Only allowlisted names are ever accepted, however the dump is spelled.
    for schema in ("other", "wpcg_prod_restore_main", "", "wpcaregrid`; DROP", None):
        expect("SOURCE_SCHEMA_NOT_ALLOWED", V2.audit_sql, legacy, target, source_schema=schema)
    other = legacy.replace(b"`wpcaregrid`", b"`other`")
    expect("SOURCE_SCHEMA_NOT_ALLOWED", V2.audit_sql, other, target, source_schema="other")
    # The legacy module-level regexes keep their original behaviour.
    assert V2.USE_DB_RE.fullmatch(b"USE `wpcaregrid`;") and not V2.USE_DB_RE.fullmatch(b"USE `wpcg_prod_main`;")


def test_manifest_source_schema_must_be_allowlisted_and_consistent():
    with tempfile.TemporaryDirectory() as directory:
        world = G1World(directory)
        verify(world)
        for backup_schema, database, code in (
                ("wpcaregrid", PROD, "SOURCE_IDENTITY_EVIDENCE_INVALID"),
                (PROD, "wpcaregrid", "SOURCE_IDENTITY_EVIDENCE_INVALID"),
                ("other", "other", "RELEASE_MANIFEST_BACKUP_INVALID"),
                (PROD, "other", "SOURCE_IDENTITY_EVIDENCE_INVALID"),
                ("wpcg_prod_restore_main", "wpcg_prod_restore_main", "RELEASE_MANIFEST_BACKUP_INVALID")):
            manifest = copy.deepcopy(world.manifest)
            manifest["backup"]["source_schema"] = backup_schema
            manifest["source_identity"]["database"] = database
            expect(code, V2._manifest_object, manifest)
    with tempfile.TemporaryDirectory() as directory:        # legacy shape is unchanged
        _manifest_path, _policy_path, _config, manifest = V2T._release_fixture(directory)
        assert V2._manifest_object(manifest)["backup"]["source_schema"] == "wpcaregrid"


def test_source_policy_pins_the_production_port_for_the_g1_schema():
    with tempfile.TemporaryDirectory() as directory:
        world = G1World(directory)
        assert PRODUCTION_INSTANCE.port == 3308 and RESTORE_INSTANCE.port == 3309
        V3.source_policy_object(copy.deepcopy(world.source_policy))
        for mutate in (lambda p: p["mysql"].update(port=RESTORE_INSTANCE.port),     # source must never be the restore instance
                       lambda p: p["mysql"].update(port=3306),
                       lambda p: p["mysql"].update(host="localhost")):
            policy = copy.deepcopy(world.source_policy)
            mutate(policy)
            expect("SOURCE_POLICY_INVALID", V3.source_policy_object, policy)
        for schema in ("other", "wpcg_prod_restore_main"):
            policy = copy.deepcopy(world.source_policy)
            policy["schema"] = schema
            expect("SOURCE_POLICY_INVALID", V3.source_policy_object, policy)
    # Legacy source policy (any port) is unchanged.
    with tempfile.TemporaryDirectory() as directory:
        legacy = V3T.World(directory)
        legacy.source_policy["mysql"]["port"] = 3306
        V3.source_policy_object(legacy.source_policy)


# ---------------------------------------------------------------- (b) destination isolation kind

def test_g1_destination_kind_is_accepted_and_bound_to_the_contract_constants():
    with tempfile.TemporaryDirectory() as directory:
        world = G1World(directory)
        manifest, _msha, policy, _psha = verify(world)
        assert policy["isolation"]["kind"] == G1_KIND
        assert policy["isolation"]["unit"] == "mysql@wpcaregrid-production-restore.service"
        assert policy["isolation"]["port"] == 3309 and policy["mysql"]["port"] == 3309
        assert policy["isolation"]["datadir"] == "/var/lib/mysql-wpcaregrid-production-restore"
        assert policy["isolation"]["socket"] == "/run/mysqld/wpcaregrid-production-restore.sock"
        # Same VPS: identities are equal for this kind only; separation is uuid + port + datadir.
        assert policy["destination_identity"] == {"environment_id": manifest["source_identity"]["environment_id"],
                                                    "host_id": manifest["source_identity"]["host_id"]}
        production, restore = V2._g1_contract()
        assert production.port != restore.port and production.datadir != restore.datadir
        assert production.socket != restore.socket and production.unit != restore.unit
        assert production.schema == PROD and restore.schema != PROD


def test_g1_destination_kind_refuses_every_deviation():
    with tempfile.TemporaryDirectory() as directory:
        world = G1World(directory)
        base = copy.deepcopy(world.policy)
        cases = (
            ("wrong isolation port", "DESTINATION_POLICY_NOT_ISOLATED", lambda p: p["isolation"].update(port=3310)),
            ("production port in isolation", "DESTINATION_POLICY_NOT_ISOLATED",
             lambda p: p["isolation"].update(port=PRODUCTION_INSTANCE.port)),
            ("production port in mysql", "DESTINATION_POLICY_NOT_ISOLATED",
             lambda p: p["mysql"].update(port=PRODUCTION_INSTANCE.port)),
            ("mysql port differs from isolation", "DESTINATION_POLICY_NOT_ISOLATED", lambda p: p["mysql"].update(port=3310)),
            ("string port", "DESTINATION_POLICY_NOT_ISOLATED", lambda p: p["isolation"].update(port="3309")),
            ("bool-ish port", "DESTINATION_POLICY_NOT_ISOLATED", lambda p: p["isolation"].update(port=True)),
            ("wrong datadir", "DESTINATION_POLICY_NOT_ISOLATED",
             lambda p: p["isolation"].update(datadir=PRODUCTION_INSTANCE.datadir)),
            ("datadir trailing slash", "DESTINATION_POLICY_NOT_ISOLATED",
             lambda p: p["isolation"].update(datadir=RESTORE_INSTANCE.datadir + "/")),
            ("wrong socket", "DESTINATION_POLICY_NOT_ISOLATED",
             lambda p: p["isolation"].update(socket=PRODUCTION_INSTANCE.socket)),
            ("production unit", "DESTINATION_POLICY_NOT_ISOLATED",
             lambda p: p["isolation"].update(unit=PRODUCTION_INSTANCE.unit)),
            ("not approved", "DESTINATION_POLICY_NOT_ISOLATED", lambda p: p["isolation"].update(approved=False)),
            ("container fields mixed in", "DESTINATION_POLICY_NOT_ISOLATED",
             lambda p: p["isolation"].update(network_id="wpcg_restore_net_0123456789", public_ports=False)),
            ("missing field", "DESTINATION_POLICY_NOT_ISOLATED", lambda p: p["isolation"].pop("socket")),
            ("non-loopback host", "DESTINATION_POLICY_NOT_ISOLATED", lambda p: p["mysql"].update(host="localhost")),
            ("container host", "DESTINATION_POLICY_NOT_ISOLATED",
             lambda p: p["mysql"].update(host="wpcg_restore_0123456789")),
        )
        for label, code, mutate in cases:
            policy = copy.deepcopy(base)
            mutate(policy)
            try:
                V2._policy_object(policy)
            except V2.RestoreRefused as error:
                assert str(error) == code, (label, str(error))
            else:
                raise AssertionError(f"accepted: {label}")
        V2._policy_object(copy.deepcopy(base))                 # the unmutated policy is fine


def test_g1_destination_kind_requires_distinct_server_uuid_and_the_production_schema():
    with tempfile.TemporaryDirectory() as directory:
        world = G1World(directory)
        verify(world)
        # Same server UUID (source == destination) is refused even though host/env may be equal.
        same = copy.deepcopy(world.policy)
        same["mysql"]["server_uuid"] = world.manifest["source_identity"]["server_uuid"]
        world.install(same, lambda m: m["mysql"].update(server_uuid=same["mysql"]["server_uuid"]))
        expect("DESTINATION_NOT_ISOLATED", verify, world)
        # Manifest and policy must agree on the destination UUID.
        world.install(copy.deepcopy(g1_policy(world.policy)), lambda m: m["mysql"].update(server_uuid="other-uuid"))
        expect("DESTINATION_NOT_ISOLATED", verify, world)
    with tempfile.TemporaryDirectory() as directory:
        world = G1World(directory)
        # Wrong source schema: the G1 kind only restores the G1 production schema.
        def legacy_schema(m):
            m["backup"]["source_schema"] = "wpcaregrid"
            m["source_identity"]["database"] = "wpcaregrid"
        world.install(copy.deepcopy(world.policy), legacy_schema)
        expect("DESTINATION_NOT_ISOLATED", verify, world)


def test_container_kind_is_unchanged_and_still_refuses_equal_host_or_environment():
    with tempfile.TemporaryDirectory() as directory:
        manifest_path, policy_path, _config, manifest = V2T._release_fixture(directory)
        manifest_obj, _msha, policy, _psha = V2.verify_manifest_and_policy(manifest_path, policy_path, owner_uid=OWNER)
        assert policy["isolation"]["kind"] == "disposable_mysql_container"
        assert set(policy["isolation"]) == {"approved", "kind", "network_id", "public_ports"}
        for mutate in (lambda p: p["destination_identity"].update(host_id="source-host"),
                       lambda p: p["destination_identity"].update(environment_id="source-env"),
                       lambda p: p["mysql"].update(server_uuid="source-server-uuid")):
            candidate = copy.deepcopy(policy)
            mutate(candidate)
            candidate_manifest = copy.deepcopy(manifest)
            candidate_manifest["destination_policy_sha256"] = V3T.write_json(policy_path, candidate)
            candidate_manifest["mysql"]["server_uuid"] = candidate["mysql"]["server_uuid"]
            V3T.write_json(manifest_path, candidate_manifest)
            expect("DESTINATION_NOT_ISOLATED", V2.verify_manifest_and_policy, manifest_path, policy_path,
                   owner_uid=OWNER)
        # Container requirements are untouched: wrong network id / public ports / G1 fields all refuse.
        for mutate in (lambda p: p["isolation"].update(network_id="host"),
                       lambda p: p["isolation"].update(public_ports=True),
                       lambda p: p["isolation"].update(kind="docker"),
                       lambda p: p["isolation"].update(unit=RESTORE_INSTANCE.unit)):
            candidate = copy.deepcopy(policy)
            mutate(candidate)
            expect("DESTINATION_POLICY_NOT_ISOLATED", V2._policy_object, candidate)
        # A container policy cannot reach the G1 relaxation by naming the G1 schema alone.
        candidate = copy.deepcopy(policy)
        candidate["destination_identity"]["host_id"] = "source-host"
        candidate_manifest = copy.deepcopy(manifest)
        candidate_manifest["backup"]["source_schema"] = PROD
        candidate_manifest["source_identity"]["database"] = PROD
        candidate_manifest["destination_policy_sha256"] = V3T.write_json(policy_path, candidate)
        V3T.write_json(manifest_path, candidate_manifest)
        expect("DESTINATION_NOT_ISOLATED", V2.verify_manifest_and_policy, manifest_path, policy_path, owner_uid=OWNER)


# ---------------------------------------------------------------- v3 capture / binding

def test_g1_capture_wraps_audits_and_records_the_production_schema():
    with tempfile.TemporaryDirectory() as directory:
        world = G1World(directory)
        world.capture()
        record, _sha = V3.verify_capture_record(world.record_path, world.key, owner_uid=OWNER)
        assert record["source"]["schema"] == PROD
        captured = V2.decode_verified_archive(world.archive_path.read_bytes(),
                                              hashlib.sha256(world.archive_path.read_bytes()).hexdigest())
        assert captured.startswith(b"CREATE DATABASE /*!32312 IF NOT EXISTS*/ `wpcg_prod_main` ")
        V2.audit_sql(captured, V3.CAPTURE_AUDIT_TARGET, world.manifest["approved_definer"], source_schema=PROD)
        expect("DATABASE_SELECTOR_SOURCE_MISMATCH", V2.audit_sql, captured, V3.CAPTURE_AUDIT_TARGET,
               world.manifest["approved_definer"])
    with tempfile.TemporaryDirectory() as directory:
        world = G1World(directory)
        # A wrongly-named dump (wrong selector for the policy schema) is refused before signing.
        mismatched = V3T.MYSQL84_DUMP_BODY_FIXTURE.replace(b"CREATE TABLE `migrations`",
                                                            b"USE `wpcaregrid`;\nCREATE TABLE `migrations`")
        expect("DATABASE_SELECTOR_COUNT_MISMATCH", world.capture, dump_sql=mismatched)
        assert not world.record_path.exists()


def test_bind_g1_kind_allows_equal_host_and_environment_but_nothing_weaker():
    with tempfile.TemporaryDirectory() as directory:
        world = G1World(directory)
        world.capture()
        record, _ = V3.verify_capture_record(world.record_path, world.key, owner_uid=OWNER)
        manifest, _msha, policy, _psha = verify(world)
        assert policy["destination_identity"]["host_id"] == record["source"]["host_id"]
        bound = V3.bind_capture_to_manifest(record, manifest, policy)
        assert bound["source_uuid_sha256"] != bound["destination_uuid_sha256"]
        same_uuid = copy.deepcopy(policy)
        same_uuid["mysql"]["server_uuid"] = "source-server-uuid"
        expect("DESTINATION_NOT_SEPARATE_FROM_SOURCE", V3.bind_capture_to_manifest, record, manifest, same_uuid)
        wrong_schema = copy.deepcopy(record)
        wrong_schema["source"]["schema"] = "wpcaregrid"
        wrong_manifest = copy.deepcopy(manifest)
        wrong_manifest["backup"]["source_schema"] = "wpcaregrid"
        wrong_manifest["source_identity"]["database"] = "wpcaregrid"
        expect("DESTINATION_NOT_SEPARATE_FROM_SOURCE", V3.bind_capture_to_manifest, wrong_schema,
               wrong_manifest, policy)
        # The container kind still refuses equal host/environment in the same binding.
        container = copy.deepcopy(policy)
        container["isolation"] = {"approved": True, "kind": "disposable_mysql_container",
                                  "network_id": "wpcg_restore_net_0123456789", "public_ports": False}
        container["mysql"]["host"] = "wpcg_restore_0123456789"
        expect("DESTINATION_NOT_SEPARATE_FROM_SOURCE", V3.bind_capture_to_manifest, record, manifest, container)


# ---------------------------------------------------------------- v3 restore pipeline + HOLD

def test_g1_restore_chain_receipt_records_only_the_separation_that_holds():
    with tempfile.TemporaryDirectory() as directory:
        world = G1World(directory)
        world.capture()
        with V3T.synthetic_restore_mysql(world.manifest) as drop:
            receipt = world.restore()
            path = V3._auth_path(world.dir / "restore-receipt.json")
            body, _sha = V3.verify_restore_receipt(path, world.key, owner_uid=OWNER)
            assert body == receipt and body["result"] == "PASS"
            assert body["source"]["schema"] == PROD
            assert body["source"]["host_id"] == body["destination"]["host_id"]        # same VPS, stated honestly
            assert body["separation"] == {"server_uuid_distinct": True, "port_distinct": True,
                                          "datadir_distinct": True, "schema_distinct": True,
                                          "target_schema_absent_before_import": True, "isolation_kind": G1_KIND}
            assert "host_distinct" not in body["separation"] and "environment_distinct" not in body["separation"]
            assert body["source"]["server_uuid_sha256"] != body["destination"]["server_uuid_sha256"]
            # Forged G1-shaped receipts still fail their separation rules.
            def resigned(mutate):
                forged = {k: v for k, v in read(path).items() if k != "authentication"}
                mutate(forged)
                V3T.write_json(path, V3.sign_document(forged, world.key, "restore-receipt"))
            resigned(lambda b: b["destination"].update(server_uuid_sha256=b["source"]["server_uuid_sha256"]))
            expect("RESTORE_RECEIPT_SEPARATION_INVALID", V3.verify_restore_receipt, path, world.key, owner_uid=OWNER)
            resigned(lambda b: (b["destination"].update(server_uuid_sha256="4" * 64),
                                b["source"].update(schema="wpcaregrid")))
            expect("RESTORE_RECEIPT_SEPARATION_INVALID", V3.verify_restore_receipt, path, world.key, owner_uid=OWNER)
            resigned(lambda b: (b["source"].update(schema=PROD),
                                b["separation"].update(isolation_kind="disposable_mysql_container")))
            expect("RESTORE_RECEIPT_INVALID", V3.verify_restore_receipt, path, world.key, owner_uid=OWNER)
            # A container-shaped receipt with equal host ids is still refused.
            resigned(lambda b: (b.update(separation={"server_uuid_distinct": True, "environment_distinct": True,
                                                    "host_distinct": True, "schema_distinct": True,
                                                    "target_schema_absent_before_import": True}),
                                b["destination"].update(server_uuid_sha256="4" * 64)))
            expect("RESTORE_RECEIPT_SEPARATION_INVALID", V3.verify_restore_receipt, path, world.key, owner_uid=OWNER)
            drop.assert_not_called()


def read(path):
    return json.loads(Path(path).read_text())


def test_g1_migration_receipt_keeps_production_admission_on_hold():
    with tempfile.TemporaryDirectory() as directory:
        with mock.patch.object(V3T, "World", G1World):
            rehearsal = V3T.Rehearsal(directory)
        body = rehearsal.create()
        assert body["restore_point"]["baseline_schema"] != body["restore_point"]["clone_schema"]
        assert body["production_admission"] == {"state": "HOLD", "required_runtime_attestations": HOLD_ATTESTATIONS}
        assert "isolated_rehearsal_evidence_only" in body["limitations"]
        V3.verify_migration_receipt(rehearsal.out, rehearsal.world.key, owner_uid=OWNER, now=rehearsal.now,
                                    contract=rehearsal.contract)
        # A correctly signed receipt that claims PASS, or drops/reorders an attestation, is refused.
        for mutate in (lambda b: b["production_admission"].update(state="PASS"),
                       lambda b: b["production_admission"].update(required_runtime_attestations=[]),
                       lambda b: b["production_admission"].update(
                           required_runtime_attestations=HOLD_ATTESTATIONS[:2]),
                       lambda b: b["production_admission"].update(
                           required_runtime_attestations=list(reversed(HOLD_ATTESTATIONS)))):
            V3T.resign(rehearsal, mutate)
            try:
                V3.verify_migration_receipt(rehearsal.out, rehearsal.world.key, owner_uid=OWNER, now=rehearsal.now,
                                            contract=rehearsal.contract)
            except V2.RestoreRefused as error:
                assert str(error) in {"PRODUCTION_ADMISSION_MUST_REMAIN_HOLD", "MIGRATION_RECEIPT_INVALID"}
            else:
                raise AssertionError("production admission was loosened")
            rehearsal.out.unlink()
            rehearsal.create()


def test_production_admission_hold_is_hardcoded_and_not_derived_from_the_destination_kind():
    source = (ROOT / "scripts/authenticated-restore-v3.py").read_text()
    assert source.count('"state": "HOLD"') >= 2 and '"state": "PASS"' not in source
    for attestation in HOLD_ATTESTATIONS:
        assert source.count(f'"{attestation}"') == 2, attestation
    # The G1 kind is only referenced for separation, never for admission.
    admission = source[source.index('"production_admission": {\n            "state": "HOLD"'):]
    assert "ISOLATION_KIND_G1_SYSTEMD" not in admission.split('"attested"', 1)[0]
    assert V3.MIGRATION_RECEIPT_SPEC["production_admission"]["state"]("HOLD") is True


def test_no_network_host_or_secret_surface_was_added():
    for relative in ("scripts/isolated-staging-restore-v2.py", "scripts/authenticated-restore-v3.py"):
        text = (ROOT / relative).read_text()
        for forbidden in ("import socket", "urllib", "import requests", "http.client", "systemctl", "docker",
                          "shell=True", "os.system"):
            assert forbidden not in text, (relative, forbidden)
    helper = (ROOT / "scripts/isolated-staging-restore-v2.py").read_text()
    contract_loader = helper[helper.index("def _g1_contract"):helper.index("def _validate_g1_isolation")]
    assert "subprocess" not in contract_loader and "mysql_query" not in contract_loader


def main():
    tests = (
        test_source_schema_is_an_explicit_allowlist_with_an_unchanged_default,
        test_manifest_source_schema_must_be_allowlisted_and_consistent,
        test_source_policy_pins_the_production_port_for_the_g1_schema,
        test_g1_destination_kind_is_accepted_and_bound_to_the_contract_constants,
        test_g1_destination_kind_refuses_every_deviation,
        test_g1_destination_kind_requires_distinct_server_uuid_and_the_production_schema,
        test_container_kind_is_unchanged_and_still_refuses_equal_host_or_environment,
        test_g1_capture_wraps_audits_and_records_the_production_schema,
        test_bind_g1_kind_allows_equal_host_and_environment_but_nothing_weaker,
        test_g1_restore_chain_receipt_records_only_the_separation_that_holds,
        test_g1_migration_receipt_keeps_production_admission_on_hold,
        test_production_admission_hold_is_hardcoded_and_not_derived_from_the_destination_kind,
        test_no_network_host_or_secret_surface_was_added,
    )
    for test in tests:
        test()
    print(f"RESTORE_V3_G1_COMPATIBILITY_TESTS={len(tests)}/PASS")


if __name__ == "__main__":
    main()
