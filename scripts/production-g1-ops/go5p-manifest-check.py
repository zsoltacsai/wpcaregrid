import hashlib, importlib.util, json, sys
d = sys.argv[1]
try:
    m = json.load(open(f"{d}/production-g1-mysql-manifest.json", encoding="ascii"))
    assert m["schema"] == 1 and m["tool"] == "wpcaregrid-production-mysql-g1-v1", "manifest_schema_or_tool"
    src = {n: hashlib.sha256(open(f"{d}/{n}", "rb").read()).hexdigest()
           for n in ("production-g1-mysql.py", "production_g1_mysql_contract.py")}
    assert m["sources"] == src, "manifest_sources_mismatch"
    UNCHANGED = {
        "/etc/apparmor.d/wpcaregrid-mysql-production": "9a40053158880f0e6baf0db06431587651701487910634015e712e878dcfe13d",
        "/etc/apparmor.d/wpcaregrid-mysql-production-restore": "ce56847e98f23b45590aaf55ad069df89f97dc12b672c2a289bb0fa7fb923b3e",
        "/etc/mysql/mysql.conf.d/zz-wpcaregrid-production-restore.cnf": "14fb59069eb42c7d0a938799330595f195bc43265df60b385e073ba58bf80a7b",
        "/etc/mysql/mysql.conf.d/zz-wpcaregrid-production.cnf": "7de16da204b888e634ea46a61e00b592bcfa69b5426a02228534b627e9567ad9",
        "/etc/systemd/system/mysql@wpcaregrid-production-restore.service.d/90-wpcaregrid-g1.conf": "d5df1ab7132248defe4c529afedb7517d002da73295b98ad87aa50a6ffc9e8ad",
        "/etc/systemd/system/mysql@wpcaregrid-production.service.d/90-wpcaregrid-g1.conf": "644e98059d68462b66a575034b5fdfbb4dbc037569f2970ab7dfa7bdccecc017",
    }
    assert src["production-g1-mysql.py"] == "32da9353289e97f1c9974d544185f51b2fea45e0c926efaaf523753bfbfb528e", "helper_not_the_installed_hotfix"
    assert src["production_g1_mysql_contract.py"] == "270a7f2ddc93a38bbabc94197e11fa078602004b39c18a2c86a34bf4c6f05f8f", "contract_not_the_apparmor_hotfix"
    inst = m["installed_artifacts"]
    HELPER, CONTRACT = "/usr/local/libexec/wpcaregrid-g1-mysql.py", "/usr/local/libexec/production_g1_mysql_contract.py"
    assert set(inst) == set(UNCHANGED) | {HELPER, CONTRACT}, "manifest_artifact_set"
    assert inst[HELPER] == src["production-g1-mysql.py"] and inst[CONTRACT] == src["production_g1_mysql_contract.py"], "manifest_helper_or_contract"
    for path, digest in UNCHANGED.items():
        assert inst[path] == digest, "manifest_differs_for_unchanged_artifact"
    assert not any("/90-wpcaregrid-production" in p and p.endswith(".cnf") for p in inst), "legacy_90_option_file_in_manifest"
    spec = importlib.util.spec_from_file_location("g1c", f"{d}/production_g1_mysql_contract.py")
    c = importlib.util.module_from_spec(spec); sys.modules["g1c"] = c; spec.loader.exec_module(c)
    assert {p: hashlib.sha256(b).hexdigest() for p, b in c.artifact_files().items()} == UNCHANGED, "generated_artifacts_differ"
except Exception as exc:
    print(f"G1_GO52C_MANIFEST=STOP;CODE={exc if isinstance(exc, AssertionError) and str(exc) else exc.__class__.__name__}")
    raise SystemExit(3)
print("G1_GO52C_MANIFEST=PASS")
