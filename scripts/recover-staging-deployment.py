#!/usr/bin/env python3
"""Continue only the recognized gated migration failure; never open the gate."""

import argparse
from contextlib import contextmanager
import hashlib
import json
import os
import re
import stat
import subprocess
import sys
import tempfile
from datetime import datetime, timezone
from pathlib import Path

APP = Path('/var/www/wpcaregrid')
APP_SHA = 'ee3d29818ff1647d82087446dfaf017ca688cb77'
BASELINE_SHA = '761e0e5d37558da808ac961ca2d2a68b4ffab0db'
BRANCH = 'claude/ecstatic-wozniak-oemyuf'
URL = 'https://staging.wpcaregrid.com'
HELPER = '/usr/local/sbin/wpcaregrid-deploy-priv'
HELPER_SHA = '59dc8359d81410741d65d9980a11cc7841d488e5c657b6201eb843cae7ef99e9'
RUNTIME_LAUNCHER = Path('/usr/local/bin/wpcaregrid-php')
RUNTIME_LAUNCHER_SHA = '6d91e5e12ae69a6c96eb669bf06f8688adee4df58a101a5b468d1169c77167cc'
PHP = '/usr/bin/php8.4'
COMPOSER = Path('/usr/local/bin/composer')
COMPOSER_SHA = '7a2d379d5b8ffdaa028580ef26494c36d2feef4b178d3dd1473a4dbc5e17c8d6'
GATE = Path('/var/lib/wpcaregrid/deploy-maintenance')
WORKER_MARKER = Path('/var/lib/wpcaregrid/workers-disabled')
SCHEDULER_MARKER = Path('/var/lib/wpcaregrid/scheduler-paused')
BACKUP = Path('/home/deploy/private-backups/wpcaregrid-pre-deploy-20261104T133855Z.sql.gz')
DEPLOY_LOCK = Path('/home/deploy/private-backups/.deploy.lock')
BACKUP_SHA = '5a2cce05c8a0cc44cfa76be3619f86bcdb748793dd6fed098fd8ef349b02c431'
DBA_ATTESTATION = Path('/var/lib/wpcaregrid/staging-db-recovery-attestation.json')
MYSQL_UUID_SHA = 'bc35682d80f0894c2c2c2879552fb8fe13dc2a530868d79d817a6280e31bde1c'
RECEIPTS = Path('/home/deploy/private-backups/deployment-receipts')
MIGRATION_FILES = (
    '2026_11_02_000002_create_transition_v2_requests_table.php',
    '2026_11_02_000003_create_transition_history_events_table.php',
)
MIGRATIONS = tuple(item[:-4] for item in MIGRATION_FILES)
TABLES = ('transition_v2_requests', 'transition_history_events')
WORKERS = ('wpcaregrid-default', 'wpcaregrid-backups')
FATAL_INERT_REFUSAL_CODES = frozenset({
    'FATAL_INERT_MARKERS_INVALID', 'FATAL_INERT_NGINX_CONFIG_INVALID',
    'FATAL_INERT_HTTP_GATE_OPEN', 'FATAL_INERT_SUPERVISOR_CONTRACT_INVALID',
    'FATAL_INERT_SCHEDULER_CONTRACT_INVALID', 'FATAL_INERT_WORKER_STATE_INVALID',
    'FATAL_INERT_WORKER_PID_PRESENT', 'FATAL_INERT_PROCESS_CENSUS_UNAVAILABLE',
    'FATAL_INERT_CONSUMER_PRESENT', 'FATAL_INERT_ARTIFACT_INVALID',
    'FATAL_INERT_ARTIFACT_HASH_MISMATCH', 'FATAL_INERT_DESTINATION_INVALID',
    'FATAL_INERT_ROLLBACK_EVIDENCE_INVALID', 'FATAL_INERT_PUBLISH_FAILED',
    'FATAL_INERT_POSTCHECK_FAILED',
})
COLS = {
    TABLES[0]: [('id','bigint unsigned','NO',False,'auto_increment'),('request_id','char(36)','NO',False,''),('request_digest','char(64)','NO',False,''),('operation','varchar(32)','NO',False,''),('operation_id','char(36)','NO',False,''),('generation','bigint unsigned','NO',False,''),('source_tuple_digest','char(64)','YES',False,''),('target_tuple_digest','char(64)','NO',False,''),('history_sequence','bigint unsigned','NO',False,''),('receipt_payload','longtext','NO',False,''),('receipt_digest','char(64)','NO',False,''),('committed_at','timestamp(6)','NO',False,''),('created_at','timestamp(6)','YES',False,''),('updated_at','timestamp(6)','YES',False,'')],
    TABLES[1]: [('id','bigint unsigned','NO',False,'auto_increment'),('event_sequence','bigint unsigned','NO',False,''),('operation_id','char(36)','NO',False,''),('generation','bigint unsigned','NO',False,''),('request_id','char(36)','NO',False,''),('request_digest','char(64)','NO',False,''),('event_type','varchar(32)','NO',False,''),('source_tuple_digest','char(64)','YES',False,''),('target_tuple_digest','char(64)','NO',False,''),('occurred_at','timestamp(6)','NO',False,''),('created_at','timestamp(6)','YES',False,''),('updated_at','timestamp(6)','YES',False,'')],
}
INDEXES = {
    TABLES[0]: {'PRIMARY':(0,'id'),'transition_v2_requests_request_id_unique':(0,'request_id'),'transition_v2_requests_history_sequence_unique':(0,'history_sequence'),'transition_v2_requests_operation_committed_idx':(1,'operation,committed_at')},
    TABLES[1]: {'PRIMARY':(0,'id'),'transition_history_events_sequence_unique':(0,'event_sequence'),'transition_history_events_request_id_unique':(0,'request_id'),'transition_history_events_scope_sequence_idx':(1,'operation_id,generation,event_sequence')},
}
FOREIGN_KEYS = {
    TABLES[0]: {},
    TABLES[1]: {'transition_history_events_request_fk':('request_id','transition_v2_requests','request_id','RESTRICT',('RESTRICT','NO ACTION'))},
}

class RecoveryError(RuntimeError):
    """Fixed, non-sensitive recovery refusal."""


def stop(code):
    raise RecoveryError(code)


def validate_attestation(value, *, phase, app_sha=APP_SHA, server_sha=MYSQL_UUID_SHA):
    expected = {'schema':1,'application_sha':app_sha,'mysql_server_uuid_sha256':server_sha,
                'instance_scope':'DEDICATED_STAGING','replication':'NONE',
                'unrelated_application_use':'NONE','original_log_bin_trust_function_creators':'OFF'}
    if phase == 'migrate':
        expected.update({'phase':'MIGRATION_WINDOW','temporary_setting':'ENABLED_FOR_MIGRATION',
                         'observed_log_bin_trust_function_creators':'ON',
                         'operation_authorized_by_dba':True})
    elif phase == 'finalize':
        expected.update({'phase':'RESTORATION_VERIFIED','temporary_setting':'RESTORED',
                         'restored_log_bin_trust_function_creators':'OFF',
                         'restoration_independently_verified':True})
    else:
        stop('dba_attestation_phase_invalid')
    if value != expected:
        stop('dba_attestation_mismatch')
    return True


def validate_schema(snapshot, table):
    value = snapshot.get('tables', {}).get(table)
    if not isinstance(value, dict) or value.get('present') is not True:
        return False
    if (value.get('engine') != 'InnoDB' or value.get('collation') != 'utf8mb4_unicode_ci'
            or value.get('rows') != 0 or value.get('indexes') != INDEXES[table]
            or not validate_foreign_keys(value.get('foreign_keys'),table)):
        return False
    columns = value.get('columns')
    if not isinstance(columns, list) or len(columns) != len(COLS[table]):
        return False
    actual = [(c.get('name'),c.get('type'),c.get('nullable'),c.get('has_default'),c.get('extra'))
              for c in columns if isinstance(c, dict)]
    return actual == COLS[table]


def validate_foreign_keys(actual, table):
    expected = FOREIGN_KEYS[table]
    if not isinstance(actual, dict) or actual.keys() != expected.keys():
        return False
    for name, contract in expected.items():
        value = actual.get(name)
        if not isinstance(value, (tuple,list)) or len(value) != 5 or tuple(value[:4]) != contract[:4]:
            return False
        if value[4] not in contract[4]:
            return False
    return True


def validate_snapshot(snapshot, *, phase, setting):
    mysql = snapshot.get('mysql') if isinstance(snapshot, dict) else None
    if not isinstance(mysql, dict):
        stop('database_snapshot_invalid')
    if (mysql.get('version') != '8.4.11' or mysql.get('database') != 'wpcaregrid'
            or mysql.get('log_bin') is not True or mysql.get('binlog_format') != 'ROW'
            or mysql.get('server_uuid_sha256') != MYSQL_UUID_SHA):
        stop('mysql_instance_mismatch')
    if mysql.get('trust_function_creators') is not setting:
        stop('dba_setting_state_mismatch')
    pending, applied = snapshot.get('pending_migrations'), snapshot.get('applied_migrations')
    if not isinstance(pending, list) or not isinstance(applied, list):
        stop('migration_ledger_unavailable')
    if any(not isinstance(x,str) or not re.fullmatch(r'[0-9A-Za-z_]+',x) for x in pending+applied):
        stop('migration_ledger_invalid')
    if len(pending) != len(set(pending)) or len(applied) != len(set(applied)) or set(pending)&set(applied):
        stop('migration_ledger_ambiguous')
    if not set(pending).issubset(MIGRATIONS):
        stop('unexpected_pending_migration')
    applied_target = set(applied)&set(MIGRATIONS)
    if phase == 'migrate':
        if set(pending) != set(MIGRATIONS)-applied_target or not pending:
            stop('migration_pending_set_mismatch')
        for i, table in enumerate(TABLES):
            value = snapshot.get('tables',{}).get(table,{})
            exists = value.get('present') is True
            required = i == 0 or MIGRATIONS[i] in applied_target
            if required and not exists:
                stop('partial_table_state_mismatch')
            if not exists and (value.get('present') is not False or (i == 1 and MIGRATIONS[1] in applied_target)):
                stop('partial_table_state_mismatch')
            if i == 1 and exists and MIGRATIONS[0] not in applied_target:
                stop('partial_table_order_mismatch')
            if exists and not validate_schema(snapshot,table):
                stop('partial_schema_mismatch')
            triggers=snapshot['tables'][table].get('triggers',[])
            if not isinstance(triggers,list) or len(triggers)>3 or any(t.get('definition_valid') is not True for t in triggers):
                stop('partial_trigger_state_mismatch')
    elif phase == 'finalize':
        if pending or applied_target != set(MIGRATIONS):
            stop('migration_ledger_incomplete')
        for table in TABLES:
            if not validate_schema(snapshot,table):
                stop('final_schema_mismatch')
            triggers=snapshot['tables'][table].get('triggers')
            if not isinstance(triggers,list) or len(triggers)!=3 or any(t.get('definition_valid') is not True for t in triggers):
                stop('trigger_definition_mismatch')
    else:
        stop('recovery_phase_invalid')
    return set(pending), applied_target


def validate_migration_result(snapshot):
    phase = 'finalize' if snapshot.get('pending_migrations') == [] else 'migrate'
    return validate_snapshot(snapshot,phase=phase,setting=True)


def acquire_nonblocking_lock(fd, flock, lock_flag):
    try:
        flock(fd,lock_flag)
    except BlockingIOError:
        stop('deployment_lock_busy')
    except OSError:
        stop('deployment_lock_unavailable')


@contextmanager
def deployment_lock():
    try:
        import fcntl
        import grp
        import pwd
        deploy_uid=pwd.getpwnam('deploy').pw_uid
        deploy_gid=grp.getgrnam('deploy').gr_gid
        before=DEPLOY_LOCK.lstat()
        if (not stat.S_ISREG(before.st_mode) or stat.S_ISLNK(before.st_mode)
                or before.st_uid!=deploy_uid or before.st_gid!=deploy_gid
                or stat.S_IMODE(before.st_mode)!=0o600):
            stop('deployment_lock_metadata_invalid')
        fd=os.open(DEPLOY_LOCK,os.O_RDWR|getattr(os,'O_NOFOLLOW',0))
    except RecoveryError:
        raise
    except (OSError,ImportError,KeyError):
        stop('deployment_lock_unavailable')
    try:
        opened=os.fstat(fd)
        if (not stat.S_ISREG(opened.st_mode) or (opened.st_dev,opened.st_ino)!=(before.st_dev,before.st_ino)
                or opened.st_uid!=deploy_uid or opened.st_gid!=deploy_gid
                or stat.S_IMODE(opened.st_mode)!=0o600):
            stop('deployment_lock_metadata_invalid')
        acquire_nonblocking_lock(fd,fcntl.flock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        yield
    finally:
        try:
            fcntl.flock(fd,fcntl.LOCK_UN)
        except (OSError,UnboundLocalError):
            pass
        os.close(fd)


def verify_application_checkout(app_sha):
    if git('rev-parse','--verify','HEAD^{commit}')!=app_sha: stop('application_head_mismatch')
    if git('status','--porcelain','--untracked-files=all'): stop('application_worktree_dirty')


def run(argv, *, cwd=APP, timeout=90, check=True):
    env={'PATH':'/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin','LANG':'C.UTF-8','LC_ALL':'C.UTF-8','HOME':'/home/deploy'}
    try:
        result=subprocess.run(argv,cwd=cwd,env=env,stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True,timeout=timeout,check=False)
    except (OSError,subprocess.TimeoutExpired):
        if check: stop('command_unavailable')
        return None
    if result.returncode and check: stop('command_failed')
    if len(result.stdout)>131072: stop('command_output_too_large')
    return result


def sha256(path):
    h=hashlib.sha256()
    with path.open('rb') as f:
        for block in iter(lambda:f.read(1024*1024),b''): h.update(block)
    return h.hexdigest()


def git(*args):
    return run(['/usr/bin/git','-C',str(APP),*args],timeout=20).stdout.strip()


def verify_provenance(app_sha,tool_sha,package_dir):
    if app_sha!=APP_SHA or not re.fullmatch(r'[0-9a-f]{40}',tool_sha): stop('release_identity_mismatch')
    verify_application_checkout(app_sha)
    tool=Path(__file__)
    verifier=package_dir/'verify-release-ci.py'
    if tool.is_symlink() or verifier.is_symlink() or not tool.is_file() or not verifier.is_file(): stop('recovery_bundle_invalid')
    for rel,path in (('scripts/recover-staging-deployment.py',tool),('scripts/verify-release-ci.py',verifier)):
        try: expected=git('rev-parse','--verify',f'{tool_sha}:{rel}'); actual=git('hash-object',str(path))
        except RecoveryError: stop('recovery_source_unavailable')
        if expected!=actual: stop('recovery_source_mismatch')
    runs={}
    for sha in (app_sha,tool_sha):
        result=run(['/usr/bin/python3',str(verifier),sha],timeout=30)
        try: evidence=json.loads(result.stdout)
        except Exception: stop('ci_evidence_invalid')
        if evidence.get('target_sha')!=sha or not isinstance(evidence.get('workflow_run_id'),int): stop('ci_evidence_invalid')
        runs[sha]=evidence['workflow_run_id']
    return runs


def marker_ok(path):
    try: st=path.lstat()
    except OSError: return False
    return stat.S_ISREG(st.st_mode) and not stat.S_ISLNK(st.st_mode) and st.st_uid==0 and stat.S_IMODE(st.st_mode)==0o644


def verify_protected_state():
    import pwd
    if os.geteuid()!=pwd.getpwnam('deploy').pw_uid: stop('must_run_as_deploy')
    if Path.cwd().resolve()!=APP: stop('application_directory_mismatch')
    if not all(marker_ok(p) for p in (GATE,WORKER_MARKER,SCHEDULER_MARKER)): stop('safety_marker_missing')
    helper=Path(HELPER)
    try: helper_stat=helper.lstat()
    except OSError: stop('helper_unavailable')
    if not stat.S_ISREG(helper_stat.st_mode) or stat.S_ISLNK(helper_stat.st_mode) or helper_stat.st_uid!=0 or helper_stat.st_gid!=0 or stat.S_IMODE(helper_stat.st_mode)!=0o755 or sha256(helper)!=HELPER_SHA: stop('helper_integrity_failed')
    verify_http_gate()
    for worker in WORKERS:
        out=run(['/usr/bin/sudo','-n',HELPER,'worker','status',worker]).stdout.strip()
        if not re.fullmatch(re.escape(worker)+r'\s+STOPPED(?:\s|$).*',out): stop('worker_not_stopped')
    env=APP/'.env'
    try: st=env.lstat()
    except OSError: stop('environment_file_unavailable')
    if stat.S_ISLNK(st.st_mode) or not stat.S_ISREG(st.st_mode) or st.st_uid!=os.geteuid() or stat.S_IMODE(st.st_mode)!=0o600: stop('environment_file_metadata_invalid')
    cache=APP/'bootstrap/cache/config.php'
    if cache.is_symlink() or not cache.is_file(): stop('config_cache_unavailable')


def verify_fatal_inert_protected_state():
    """Recovery-only precondition: distinguish FATAL_INERT from Supervisor STOPPED."""
    import pwd
    if os.geteuid()!=pwd.getpwnam('deploy').pw_uid: stop('must_run_as_deploy')
    if Path.cwd().resolve()!=APP: stop('application_directory_mismatch')
    if not all(marker_ok(p) for p in (GATE,WORKER_MARKER,SCHEDULER_MARKER)): stop('safety_marker_missing')
    helper=Path(HELPER)
    try: helper_stat=helper.lstat()
    except OSError: stop('helper_unavailable')
    if (not stat.S_ISREG(helper_stat.st_mode) or stat.S_ISLNK(helper_stat.st_mode)
            or helper_stat.st_uid!=0 or helper_stat.st_gid!=0
            or stat.S_IMODE(helper_stat.st_mode)!=0o755 or sha256(helper)!=HELPER_SHA):
        stop('helper_integrity_failed')
    verify_http_gate()
    for worker in WORKERS:
        out=run(['/usr/bin/sudo','-n',HELPER,'worker','status',worker]).stdout.strip()
        if not re.fullmatch(re.escape(worker)+r'\s+FATAL',out): stop('worker_not_fatal_inert')
    env=APP/'.env'
    try: st=env.lstat()
    except OSError: stop('environment_file_unavailable')
    if stat.S_ISLNK(st.st_mode) or not stat.S_ISREG(st.st_mode) or st.st_uid!=os.geteuid() or stat.S_IMODE(st.st_mode)!=0o600: stop('environment_file_metadata_invalid')
    cache=APP/'bootstrap/cache/config.php'
    if cache.is_symlink() or not cache.is_file(): stop('config_cache_unavailable')


def verify_http_gate():
    status=run(['/usr/bin/curl','--silent','--show-error','--output','/dev/null','--write-out','%{http_code}','--connect-timeout','5','--max-time','15',f'{URL}/up'],timeout=20).stdout.strip()
    if status!='503': stop('maintenance_gate_not_effective')


def phase_runtime_launcher_recovery(app_sha,tool_sha,package_dir):
    """Install only the approved launcher without starting or reconfiguring services."""
    verify_fatal_inert_protected_state()
    backup=verify_backup()
    runs=verify_provenance(app_sha,tool_sha,package_dir)
    result=run(['/usr/bin/sudo','-n',HELPER,'runtime-launcher-recover-fatal-inert'],timeout=60,check=False)
    if result is None:
        stop('launcher_recovery_unavailable')
    if result.returncode:
        stderr=result.stderr
        if len(stderr)>2048:
            stop('launcher_recovery_failed')
        match=re.fullmatch(r'FATAL_INERT_RECOVERY=REFUSED;CODE=([A-Z_]+)\n?',stderr)
        if match and match.group(1) in FATAL_INERT_REFUSAL_CODES:
            stop(match.group(1).lower())
        stop('launcher_recovery_failed')
    if result.stderr or len(result.stdout)>4096:
        stop('launcher_recovery_evidence_invalid')
    try: evidence=json.loads(result.stdout)
    except Exception: stop('launcher_recovery_evidence_invalid')
    expected={
        'operation':'runtime-launcher-recover-fatal-inert',
        'state':'FATAL_INERT',
        'launcher_sha256':RUNTIME_LAUNCHER_SHA,
        'workers':{name:'FATAL' for name in sorted(WORKERS)},
        'live_consumers':0,
        'maintenance_http':503,
        'supervisor_control':'NOT_INVOKED',
    }
    if evidence!=expected: stop('launcher_recovery_evidence_invalid')
    verify_fatal_inert_protected_state()
    try: launcher_stat=RUNTIME_LAUNCHER.lstat()
    except OSError: stop('launcher_installation_unverified')
    if (not stat.S_ISREG(launcher_stat.st_mode) or stat.S_ISLNK(launcher_stat.st_mode)
            or launcher_stat.st_uid!=0 or launcher_stat.st_gid!=0
            or stat.S_IMODE(launcher_stat.st_mode)!=0o755
            or sha256(RUNTIME_LAUNCHER)!=RUNTIME_LAUNCHER_SHA):
        stop('launcher_installation_unverified')
    return {
        'RECOVERY':'LAUNCHER_INSTALLED_GATE_CLOSED',
        'APPLICATION_SHA':app_sha,
        'RECOVERY_TOOL_SHA':tool_sha,
        'APPLICATION_CI_RUN_ID':runs[app_sha],
        'RECOVERY_TOOL_CI_RUN_ID':runs[tool_sha],
        'BACKUP_SHA256':backup['sha256'],
        'WORKER_STATE':'FATAL_INERT',
        'SCHEDULER':'PAUSED',
        'MAINTENANCE_GATE':'CLOSED',
        'SUPERVISOR_CONTROL':'NOT_INVOKED',
    }


def verify_backup():
    try: st=BACKUP.lstat()
    except OSError: stop('backup_unavailable')
    if not stat.S_ISREG(st.st_mode) or stat.S_ISLNK(st.st_mode) or st.st_uid!=os.geteuid() or stat.S_IMODE(st.st_mode)!=0o600 or sha256(BACKUP)!=BACKUP_SHA: stop('backup_identity_mismatch')
    parent=BACKUP.parent.lstat()
    if not stat.S_ISDIR(parent.st_mode) or stat.S_ISLNK(parent.st_mode) or parent.st_uid!=os.geteuid() or stat.S_IMODE(parent.st_mode)!=0o700: stop('backup_directory_invalid')
    run(['/usr/bin/gzip','-t',str(BACKUP)],timeout=60)
    return {'name':BACKUP.name,'sha256':BACKUP_SHA,'bytes':st.st_size}


PHP_SNAPSHOT = r'''require "vendor/autoload.php";$app=require "bootstrap/app.php";$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();$db=Illuminate\Support\Facades\DB::connection();$m=$db->select("SELECT VERSION() version,@@GLOBAL.log_bin log_bin,@@GLOBAL.binlog_format binlog_format,@@GLOBAL.log_bin_trust_function_creators trust_creators,@@GLOBAL.server_uuid server_uuid,DATABASE() db_name")[0];$o=["mysql"=>["version"=>$m->version,"log_bin"=>(bool)$m->log_bin,"binlog_format"=>$m->binlog_format,"trust_function_creators"=>(bool)$m->trust_creators,"server_uuid_sha256"=>hash("sha256",$m->server_uuid),"database"=>$m->db_name],"pending_migrations"=>[],"applied_migrations"=>[],"tables"=>[]];$applied=array_fill_keys($db->table("migrations")->pluck("migration")->all(),true);foreach(glob(base_path("database/migrations/*.php")) as $f){$n=basename($f,".php");if(isset($applied[$n]))$o["applied_migrations"][]=$n;else $o["pending_migrations"][]=$n;}$spec=["transition_v2_requests"=>["2026_11_02_000002_create_transition_v2_requests_table","Phase 2B request receipts require the locked authoritative fence and are append-only."],"transition_history_events"=>["2026_11_02_000003_create_transition_history_events_table","Phase 2B history requires the matching committed receipt and authoritative fence and is append-only."]];foreach($spec as $t=>$s){$exists=Illuminate\Support\Facades\Schema::hasTable($t);$e=["present"=>$exists,"engine"=>null,"collation"=>null,"rows"=>0,"columns"=>[],"indexes"=>[],"foreign_keys"=>[],"triggers"=>[]];if($exists){$q=$db->select("SELECT ENGINE engine,TABLE_COLLATION collation FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?",[$t])[0];$e["engine"]=$q->engine;$e["collation"]=$q->collation;$e["rows"]=$db->table($t)->count();foreach($db->select("SELECT COLUMN_NAME name,COLUMN_TYPE type,IS_NULLABLE nullable,COLUMN_DEFAULT d,EXTRA extra FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? ORDER BY ORDINAL_POSITION",[$t]) as $c)$e["columns"][]=["name"=>$c->name,"type"=>strtolower($c->type),"nullable"=>$c->nullable,"has_default"=>$c->d!==null,"extra"=>$c->extra];foreach($db->select("SELECT INDEX_NAME name,NON_UNIQUE n,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') cols FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? GROUP BY INDEX_NAME,NON_UNIQUE",[$t]) as $i)$e["indexes"][$i->name]=[(int)$i->n,$i->cols];foreach($db->select("SELECT k.CONSTRAINT_NAME name,k.COLUMN_NAME column_name,k.REFERENCED_TABLE_NAME referenced_table,k.REFERENCED_COLUMN_NAME referenced_column,r.DELETE_RULE delete_rule,r.UPDATE_RULE update_rule FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.REFERENCED_TABLE_NAME IS NOT NULL",[$t]) as $f)$e["foreign_keys"][$f->name]=[$f->column_name,$f->referenced_table,$f->referenced_column,$f->delete_rule,$f->update_rule];}$migration=require base_path("database/migrations/".$s[0].".php");$r=new ReflectionObject($migration);$g=$r->getMethod("guardDefinitions");$g->setAccessible(true);$defs=$g->invoke($migration,$t,$t."_immutable",$s[1]);$norm=static fn($x)=>rtrim(preg_replace("/\\s+/","",strtolower(str_replace(["`",chr(34)],"",trim($x)))),";");foreach($db->select("SELECT TRIGGER_NAME name,EVENT_MANIPULATION ev,ACTION_TIMING timing,ACTION_STATEMENT body FROM information_schema.triggers WHERE trigger_schema=DATABASE() AND event_object_table=? ORDER BY trigger_name",[$t]) as $x){$d=$defs[$x->name]??null;$e["triggers"][]=["name"=>$x->name,"event"=>$x->ev,"timing"=>$x->timing,"definition_valid"=>is_array($d)&&$d["event"]===$x->ev&&$x->timing==="BEFORE"&&$norm($d["mysql"])===$norm($x->body),"definition_sha256"=>hash("sha256",$norm($x->body))];}$o["tables"][$t]=$e;}echo json_encode($o,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);'''


def snapshot():
    result=run([PHP,'-r',PHP_SNAPSHOT],timeout=30)
    try: value=json.loads(result.stdout)
    except Exception: stop('database_snapshot_invalid')
    if value.get('mysql',{}).get('database')!='wpcaregrid': stop('database_identity_mismatch')
    return value


def load_attestation(phase):
    try:
        st=DBA_ATTESTATION.lstat()
        if (not stat.S_ISREG(st.st_mode) or stat.S_ISLNK(st.st_mode) or st.st_uid!=0
                or st.st_gid!=0 or stat.S_IMODE(st.st_mode)!=0o644 or st.st_size>4096):
            stop('dba_attestation_metadata_invalid')
        value=json.loads(DBA_ATTESTATION.read_text(encoding='utf-8'))
    except RecoveryError: raise
    except Exception: stop('dba_attestation_unavailable')
    validate_attestation(value,phase=phase)
    return hashlib.sha256(DBA_ATTESTATION.read_bytes()).hexdigest()


def enforce_fail_closed():
    failures=[]
    for args in (['gate-on'],['worker-runtime-disable'],['worker','stop-all'],['scheduler-pause']):
        try: run(['/usr/bin/sudo','-n',HELPER,*args],timeout=30)
        except RecoveryError: failures.append(args[0])
    if not all(marker_ok(p) for p in (GATE,WORKER_MARKER,SCHEDULER_MARKER)): failures.append('marker')
    for worker in WORKERS:
        try:
            out=run(['/usr/bin/sudo','-n',HELPER,'worker','status',worker]).stdout.strip()
            if not re.fullmatch(re.escape(worker)+r'\s+STOPPED(?:\s|$).*',out): failures.append('worker')
        except RecoveryError: failures.append('worker')
    if failures: stop('fail_closed_state_unverified')


def verify_profile():
    for key,value in (('app.env','production'),('app.url',URL),('marketing_release.profile','marketing_only')):
        output=run([PHP,'artisan','config:show',key,'--no-interaction'],timeout=30).stdout
        actual=next((line.split()[-1] for line in output.splitlines() if line.split()),'')
        if actual!=value: stop('marketing_profile_mismatch')


def verify_health():
    result=run([PHP,'artisan','system:check','--json'],check=False,timeout=60)
    if result is None or result.returncode not in (0,1): stop('system_check_unavailable')
    try: rows=json.loads(result.stdout)
    except Exception: stop('system_check_invalid')
    if not isinstance(rows,list): stop('system_check_invalid')
    critical={'application','database','safety'}; seen=set()
    for row in rows:
        if not isinstance(row,dict) or not isinstance(row.get('check'),str) or not isinstance(row.get('level'),str): stop('system_check_invalid')
        name,level=row['check'],row['level']; seen.add(name)
        if (name in critical and level!='OK') or (level=='ERROR' and name not in {'queue','scheduler'}): stop('system_check_failed')
    if not critical.issubset(seen): stop('system_check_incomplete')


def write_receipt(app_sha,tool_sha,runs,backup,attestation_sha,db):
    try: st=RECEIPTS.lstat()
    except OSError: stop('receipt_directory_unavailable')
    if not stat.S_ISDIR(st.st_mode) or stat.S_ISLNK(st.st_mode) or st.st_uid!=os.geteuid() or stat.S_IMODE(st.st_mode)!=0o700: stop('receipt_directory_invalid')
    now=datetime.now(timezone.utc).isoformat().replace('+00:00','Z')
    triggers=sorted([{'table':t,'name':x['name'],'event':x['event'],'timing':x['timing'],'definition_sha256':x['definition_sha256']} for t in TABLES for x in db['tables'][t]['triggers']],key=lambda x:(x['table'],x['name']))
    data={'schema':1,'event':'gated_staging_recovery_verified','status':'VERIFIED_GATE_CLOSED','recorded_at_utc':now,
          'application_sha':app_sha,'recovery_tool_sha':tool_sha,'application_ci_run_id':runs[app_sha],
          'recovery_tool_ci_run_id':runs[tool_sha],'baseline_sha':BASELINE_SHA,'backup':backup,
          'database':{'version':db['mysql']['version'],'server_uuid_sha256':db['mysql']['server_uuid_sha256'],
                      'log_bin_trust_function_creators':'OFF','dba_attestation_sha256':attestation_sha},
          'migrations':list(MIGRATIONS),'triggers':triggers,'maintenance_gate':'CLOSED','workers':'STOPPED',
          'scheduler':'PAUSED','public_http_acceptance':'PENDING_SEPARATE_OWNER_GATE_RELEASE'}
    name=f"gated-recovery-{now.replace(':','').replace('-','')}-{app_sha}.json"; dest=RECEIPTS/name
    if dest.exists() or dest.is_symlink(): stop('receipt_collision')
    fd,temp=tempfile.mkstemp(prefix='.gated-recovery-',dir=RECEIPTS)
    try:
        os.fchmod(fd,0o600)
        with os.fdopen(fd,'w',encoding='utf-8') as stream:
            json.dump(data,stream,sort_keys=True,separators=(',',':')); stream.write('\n'); stream.flush(); os.fsync(stream.fileno())
        os.link(temp,dest,follow_symlinks=False); os.unlink(temp)
        dfd=os.open(RECEIPTS,os.O_RDONLY|getattr(os,'O_DIRECTORY',0)|getattr(os,'O_NOFOLLOW',0))
        try: os.fsync(dfd)
        finally: os.close(dfd)
    except Exception:
        try: os.unlink(temp)
        except OSError: pass
        stop('receipt_write_failed')
    return name


def phase_migrate(app_sha,tool_sha,package_dir):
    verify_protected_state(); backup=verify_backup(); runs=verify_provenance(app_sha,tool_sha,package_dir)
    attestation=load_attestation('migrate'); db=snapshot()
    if db['mysql']['server_uuid_sha256']!=MYSQL_UUID_SHA or db['mysql']['trust_function_creators'] is not True: stop('dba_window_not_verified')
    pending,_=validate_migration_result(db)
    if not pending:
        verify_protected_state(); verify_application_checkout(app_sha)
        return {'MIGRATIONS':'PASS','TRIGGERS':6,'GATE':'CLOSED','APPLICATION_SHA':app_sha,
                'RECOVERY_TOOL_SHA':tool_sha,'APPLICATION_CI_RUN_ID':runs[app_sha],
                'RECOVERY_TOOL_CI_RUN_ID':runs[tool_sha],'BACKUP_SHA256':backup['sha256'],
                'DBA_ATTESTATION_SHA256':attestation}
    for filename,name,table in zip(MIGRATION_FILES,MIGRATIONS,TABLES):
        verify_protected_state()
        current=snapshot(); current_pending,_=validate_snapshot(current,phase='migrate',setting=True)
        if name not in current_pending: continue
        invoke_single_migration(filename)
        updated=snapshot(); updated_pending,_=validate_migration_result(updated)
        if name in updated_pending or not validate_schema(updated,table): stop('migration_postcheck_failed')
        tr=updated['tables'][table]['triggers']
        if len(tr)!=3 or any(x['definition_valid'] is not True for x in tr): stop('migration_trigger_postcheck_failed')
    verify_protected_state()
    final=snapshot(); validate_migration_result(final)
    return {'MIGRATIONS':'PASS','TRIGGERS':6,'GATE':'CLOSED','APPLICATION_SHA':app_sha,
            'RECOVERY_TOOL_SHA':tool_sha,'APPLICATION_CI_RUN_ID':runs[app_sha],
            'RECOVERY_TOOL_CI_RUN_ID':runs[tool_sha],'BACKUP_SHA256':backup['sha256'],
            'DBA_ATTESTATION_SHA256':attestation}


def phase_finalize(app_sha,tool_sha,package_dir):
    verify_protected_state(); backup=verify_backup(); runs=verify_provenance(app_sha,tool_sha,package_dir)
    attestation=load_attestation('finalize'); db=snapshot(); validate_snapshot(db,phase='finalize',setting=False)
    run(['/usr/bin/sudo','-n',HELPER,'php-runtime-preflight','8.4'],timeout=60)
    run(['/usr/bin/sudo','-n',HELPER,'nginx-check'],timeout=30)
    info=COMPOSER.lstat()
    if not stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode) or info.st_uid!=0 or info.st_gid!=0 or stat.S_IMODE(info.st_mode)!=0o755 or sha256(COMPOSER)!=COMPOSER_SHA: stop('composer_integrity_failed')
    run([PHP,str(COMPOSER),'check-platform-reqs','--no-dev','--lock'],timeout=180)
    verify_profile()
    run([PHP,'artisan','optimize:clear'],timeout=120)
    run(['/usr/bin/sudo','-n',HELPER,'cache-acl'],timeout=60)
    for artisan in ('config:cache','route:cache','view:cache','event:cache'): run([PHP,'artisan',artisan],timeout=120)
    run([PHP,'artisan','connector:package'],timeout=180)
    run([PHP,'artisan','about','--only=environment'],timeout=60)
    verify_profile(); run([PHP,'artisan','schedule:list'],timeout=60); verify_health()
    run(['/usr/bin/sudo','-n',HELPER,'php-runtime-preflight','8.4'],timeout=60)
    run(['/usr/bin/sudo','-n',HELPER,'nginx-check'],timeout=30)
    # Keep both Laravel and Nginx maintenance gates closed for Owner review.
    if not all(marker_ok(p) for p in (GATE,WORKER_MARKER,SCHEDULER_MARKER)): stop('safety_marker_lost')
    verify_http_gate()
    for worker in WORKERS:
        current=run(['/usr/bin/sudo','-n',HELPER,'worker','status',worker]).stdout.strip()
        if not re.fullmatch(re.escape(worker)+r'\s+STOPPED(?:\s|$).*',current): stop('worker_state_changed')
    after=snapshot(); validate_snapshot(after,phase='finalize',setting=False)
    name=write_receipt(app_sha,tool_sha,runs,backup,attestation,after)
    return {'RECOVERY':'VERIFIED_GATE_CLOSED','RECEIPT':name,'PUBLIC_HTTP_ACCEPTANCE':'PENDING_SEPARATE_OWNER_GATE_RELEASE'}


def invoke_single_migration(filename):
    if filename not in MIGRATION_FILES:
        stop('migration_not_allowlisted')
    run([PHP,'artisan','migrate','--force','--no-interaction',
         f'--path=database/migrations/{filename}'],timeout=300)


def main(argv=None):
    parser=argparse.ArgumentParser(allow_abbrev=False)
    parser.add_argument('phase',choices=('recover-runtime-launcher','continue-migrations','finalize'))
    parser.add_argument('--application-sha',required=True)
    parser.add_argument('--recovery-tool-sha',required=True)
    args=parser.parse_args(argv)
    if args.application_sha!=APP_SHA: print('RECOVERY=STOP;CODE=release_identity_mismatch',file=sys.stderr); return 1
    try:
        with deployment_lock():
            try:
                verify_application_checkout(args.application_sha)
                package=Path(__file__).resolve().parent
                phase_map={
                    'recover-runtime-launcher':phase_runtime_launcher_recovery,
                    'continue-migrations':phase_migrate,
                    'finalize':phase_finalize,
                }
                result=phase_map[args.phase](args.application_sha,args.recovery_tool_sha,package)
            except RecoveryError:
                if args.phase!='recover-runtime-launcher':
                    try: enforce_fail_closed()
                    except RecoveryError: stop('fail_closed_state_unverified')
                raise
            except Exception:
                if args.phase!='recover-runtime-launcher':
                    try: enforce_fail_closed()
                    except RecoveryError: stop('fail_closed_state_unverified')
                stop('unexpected_failure')
        print(json.dumps(result,sort_keys=True,separators=(',',':'))); return 0
    except RecoveryError as error:
        print(f'RECOVERY=STOP;CODE={error}',file=sys.stderr); return 1


if __name__=='__main__':
    raise SystemExit(main())
