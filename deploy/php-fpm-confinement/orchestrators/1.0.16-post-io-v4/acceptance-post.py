#!/usr/bin/python3
"""Run the primary-pinned 1.0.16 post acceptance with separated probe I/O."""
import argparse, hashlib, importlib.util, json, os, pathlib, re, secrets, shutil, signal, stat, subprocess, sys, time

sys.dont_write_bytecode = True
PKG = pathlib.Path('/')
ENVELOPE = pathlib.Path('/')
ROUTE_CLOSURE_EVIDENCE = pathlib.Path('/')
EXPECTED = {
 'package_tree':'442fa9ab4e82652a70f997238ad5d98e11ade5b9',
 'sums':'5a141648ee771e6ceaa4ff296720857bb52ecd9f1c995d6e58b7e6016a50e622',
 'binding':'41d10ad9d8d0c3d03e852bed449b3783aef387a5e55be1e9b9077cd37632633d',
 'matrix':'3ea81dd061e77b27f89b410103b98ba36c5c8b6ab2ed881d4907d4af7bcfcb5a',
 'collector':'ff70498c54223c2d533c352cd422835bcc9c1354a29c5e96269010c61e4fbba7',
 'derive':'22503809027074cce467ab8651531b1d9b5d75732af165be7f8cafcc83202a8a',
 'probe':'5cde081b25341f804f828f18615bc868b30bba1c70454dc1cbf6bc09ac9ff0af',
 'sender':'76940755668615cc7e2fbdcd236b04019f084d8ea7ac3599158dffcb7b9a85ac',
 'listener':'88046b95ef7eeaae3984c77d17a1abf5be969654556e32a8ad21fe8b0de95ea0',
 'verifier':'b821e435ab42ea9f06c2fbe57b0496f1e16e47bc625c3d3f6d51c448586f8c24',
 'fixtures':'e5cc5ee6dc8c60549bb4b8acd63ab6a22487e3edf288b67d1e3bc640191e8ebd',
}
SENDER = pathlib.Path('/usr/local/libexec/wpcaregrid-php-fpm-harness/1.0.16/fd-sender.py')
PARENT = pathlib.Path('/run/mysqld/wpcaregrid-acceptance')
SOCKET = pathlib.Path('/run/mysqld/wpcaregrid-acceptance/canary.sock')
BANNER = 'WPCG-SIBLING-CANARY-v1\n'
PROFILES = ['wpcaregrid-nginx', 'wpcaregrid-php-fpm-production',
            'wpcaregrid-php-cli-production-build', 'wpcaregrid-php-cli-production-deploy']
RESULT_FIELDS = ('profile','status','inventory_before_rc','baseline_rc','confined_rc','inventory_after_rc',
                 'verifier_rc','sibling_required','sibling_cleanup_ok','probe_unloaded','original_exec_check')
ACTIVE_EVIDENCE = ''
CURRENT_PHASE = 'startup'
SIBLING_UNITS = []
GATE_STATE = {'package_pin_ok':False,'fixture_pre_match':False,'host_preflight_ok':False}
PROBE_PROFILE_OWNER = (0,0)
PROBE_SELF_STAT_RULE = b'  owner @{PROC}/@{pid}/stat r,\n'
PROBE_OUTPUT_OWNER = (0,0)
SYSTEMD_APPEND_MIN_VERSION = 249
SYSTEM_MANAGER_LABEL_PATH = pathlib.Path('/proc/1/attr/current')
STALE_PARTIAL_PROFILE = pathlib.Path('/')
EXCEPTION_CLASSES = {
    'AttributeError', 'CalledProcessError', 'FileNotFoundError', 'ImportError',
    'IndexError', 'IsADirectoryError', 'JSONDecodeError', 'KeyError',
    'NotADirectoryError', 'OSError', 'PermissionError', 'RuntimeError',
    'TimeoutExpired', 'TypeError', 'ValueError',
}

class Hold(Exception):
    def __init__(self, code): self.code = code


def verify_artifact_manifest():
    """Verify the commit-pinned runner bundle before any host preflight."""
    root=pathlib.Path(__file__).resolve().parent
    manifest=root/'SHA256SUMS'
    required={'acceptance-post.py','one_shot_bridge.py','pty_io.py','PINNED_INPUTS.json','README.md'}
    try:
        root_st=os.lstat(root)
        if (not stat.S_ISDIR(root_st.st_mode) or stat.S_ISLNK(root_st.st_mode)
                or (root_st.st_uid,root_st.st_gid)!=(0,0)
                or stat.S_IMODE(root_st.st_mode)!=0o755):
            return False
        rows=manifest.read_text(encoding='ascii').splitlines()
        declared={}
        for row in rows:
            digest,name=row.split('  ',1)
            if not re.fullmatch(r'[0-9a-f]{64}',digest) or name in declared or '/' in name:
                return False
            declared[name]=digest
        if set(declared)!=required:
            return False
        actual={p.name for p in root.iterdir() if p.name!='SHA256SUMS'}
        if actual!=required:
            return False
        for name,digest in declared.items():
            path=root/name; st=os.lstat(path)
            mode=0o755 if name in ('acceptance-post.py','one_shot_bridge.py') else 0o644
            if (not stat.S_ISREG(st.st_mode) or st.st_nlink!=1
                    or (st.st_uid,st.st_gid)!=(0,0)
                    or stat.S_IMODE(st.st_mode)!=mode or sha(path)!=digest):
                return False
        st=os.lstat(manifest)
        if (not stat.S_ISREG(st.st_mode) or st.st_nlink!=1
                or (st.st_uid,st.st_gid)!=(0,0) or stat.S_IMODE(st.st_mode)!=0o644):
            return False
        return True
    except (OSError,ValueError):
        return False


def set_phase(value):
    """Keep a short, non-sensitive phase label for safe exception metadata."""
    global CURRENT_PHASE
    if not isinstance(value, str) or not re.fullmatch(r'[a-z0-9_.-]{1,96}', value):
        CURRENT_PHASE = 'phase-label-invalid'
    else:
        CURRENT_PHASE = value


def safe_exception_fields(exc, tb):
    """Return metadata only; never serialize exception text, args, locals or source."""
    cls = type(exc).__name__
    cls = cls if cls in EXCEPTION_CLASSES else 'Other'
    err = getattr(exc, 'errno', None)
    if isinstance(err, bool) or not isinstance(err, int) or not -4096 <= err <= 4096:
        err = None
    frame = None
    cursor = tb
    depth = 0
    while cursor is not None and depth < 64:
        frame = cursor
        cursor = cursor.tb_next
        depth += 1
    truncated = cursor is not None
    if truncated:
        frame = None
    elif frame is not None:
        code = frame.tb_frame.f_code
        base = pathlib.PurePath(code.co_filename).name
        if not re.fullmatch(r'[A-Za-z0-9._-]{1,96}', base or ''):
            base = 'unknown'
        func = code.co_name if re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]{0,79}', code.co_name or '') else 'unknown'
        frame = {'basename': base[:96], 'function': func, 'line': frame.tb_lineno if isinstance(frame.tb_lineno, int) else None}
    return {'phase': CURRENT_PHASE, 'exception_class': cls, 'errno': err,
            'thrower': frame, 'thrower_truncated': truncated}


def write_exception_metadata(fields):
    """Create a unique 0600 metadata file under the verified run envelope."""
    if not ACTIVE_EVIDENCE:
        return False
    try:
        directory = pathlib.Path(ACTIVE_EVIDENCE)
        st = os.lstat(directory)
        if not stat.S_ISDIR(st.st_mode) or stat.S_ISLNK(st.st_mode) or st.st_uid != 0 or st.st_gid != 0 or stat.S_IMODE(st.st_mode) != 0o700:
            return False
        name = 'exception-meta-' + secrets.token_hex(8) + '.json'
        path = directory / name
        nofollow=getattr(os,'O_NOFOLLOW',None)
        if nofollow is None: return False
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | nofollow, 0o600)
        try:
            payload = json.dumps(fields, sort_keys=True, separators=(',', ':')).encode('ascii')
            with os.fdopen(fd, 'wb', closefd=True) as stream:
                stream.write(payload)
                stream.flush()
                os.fsync(stream.fileno())
        except BaseException:
            try: os.close(fd)
            except OSError: pass
            try: os.unlink(path)
            except OSError: pass
            return False
        return True
    except OSError:
        return False


def sha(path):
    h=hashlib.sha256()
    with open(path,'rb') as f:
        for block in iter(lambda:f.read(1024*1024),b''): h.update(block)
    return h.hexdigest()


def git_tree_oid(root):
    """Recompute the Git SHA-1 tree object from a staged package's bytes/modes."""
    root=pathlib.Path(root)
    def build(directory):
        entries=[]
        for child in directory.iterdir():
            info=os.lstat(child)
            if stat.S_ISDIR(info.st_mode) and not stat.S_ISLNK(info.st_mode):
                mode=b'40000'; oid=build(child); sort_name=os.fsencode(child.name)+b'/'
            elif stat.S_ISREG(info.st_mode):
                mode=b'100755' if stat.S_IMODE(info.st_mode)&0o111 else b'100644'
                with open(child,'rb') as stream: content=stream.read()
                oid=hashlib.sha1(b'blob '+str(len(content)).encode('ascii')+b'\0'+content).digest()
                sort_name=os.fsencode(child.name)
            else:
                raise Hold('package-tree-object')
            entries.append((sort_name,mode+b' '+os.fsencode(child.name)+b'\0'+oid))
        payload=b''.join(item for _,item in sorted(entries,key=lambda row:row[0]))
        return hashlib.sha1(b'tree '+str(len(payload)).encode('ascii')+b'\0'+payload).digest()
    return build(root).hex()


def run(argv, out=None, err=None, timeout=30, cwd=None):
    stdout = open(out,'xb') if out else subprocess.PIPE
    stderr = open(err,'xb') if err else subprocess.PIPE
    try:
        return subprocess.run(argv, stdout=stdout, stderr=stderr, timeout=timeout, check=False, cwd=cwd)
    finally:
        if out: stdout.close()
        if err: stderr.close()


def read_json(path, cap=8*1024*1024):
    if os.lstat(path).st_size > cap: raise Hold('evidence-size')
    with open(path,'rb') as f: return json.loads(f.read())


def unit_state(name):
    p=subprocess.run(['/usr/bin/systemctl','show',name,'--property=ActiveState','--value'],stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,check=False)
    return p.returncode, p.stdout.decode('ascii','replace').strip()


def command_ok(argv, timeout=30):
    p=subprocess.run(argv,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=timeout,check=False)
    return p.returncode


def systemd_append_io_supported():
    """Fail closed unless the system manager supports StandardOutput=append:."""
    try:
        p=subprocess.run(['/usr/bin/systemctl','--version'],stdout=subprocess.PIPE,
                         stderr=subprocess.DEVNULL,timeout=5,check=False)
        if p.returncode!=0: return False
        first=p.stdout.decode('ascii','strict').splitlines()[0]
        match=re.fullmatch(r'systemd ([0-9]+)(?:[ \t].*)?',first)
        return bool(match and int(match.group(1))>=SYSTEMD_APPEND_MIN_VERSION)
    except (OSError,UnicodeError,IndexError,ValueError,subprocess.TimeoutExpired):
        return False


def system_manager_is_unconfined():
    """Fail closed unless PID 1 is systemd and its AppArmor label is unconfined."""
    fd=None
    try:
        comm=pathlib.Path('/proc/1/comm').read_bytes()
        if comm not in (b'systemd',b'systemd\n'):
            return False
        fd=os.open(SYSTEM_MANAGER_LABEL_PATH,os.O_RDONLY|getattr(os,'O_CLOEXEC',0)|getattr(os,'O_NONBLOCK',0))
        info=os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_uid!=0 or info.st_gid!=0:
            return False
        label=os.read(fd,129)
        if len(label)>128:
            return False
        # Do not strip arbitrary whitespace or accept mode suffixes/profile names.
        return label in (b'unconfined',b'unconfined\n')
    except OSError:
        return False
    finally:
        if fd is not None:
            try: os.close(fd)
            except OSError: pass


def _create_probe_output(path):
    """Create one empty root-only output target without following or replacing a path."""
    path=pathlib.Path(path)
    try:
        parent=os.lstat(path.parent)
        if (not stat.S_ISDIR(parent.st_mode) or stat.S_ISLNK(parent.st_mode)
                or (parent.st_uid,parent.st_gid)!=PROBE_OUTPUT_OWNER or stat.S_IMODE(parent.st_mode)!=0o700
                or path.parent!=pathlib.Path(ACTIVE_EVIDENCE)):
            raise Hold('probe-output-parent')
        flags=os.O_WRONLY|os.O_CREAT|os.O_EXCL|getattr(os,'O_NOFOLLOW',0)
        fd=os.open(path,flags,0o600)
        try:
            os.fchmod(fd,0o600)
            opened=os.fstat(fd)
            current=os.lstat(path)
            identity=(opened.st_dev,opened.st_ino)
            if (not stat.S_ISREG(opened.st_mode) or stat.S_ISLNK(opened.st_mode)
                    or (opened.st_uid,opened.st_gid)!=PROBE_OUTPUT_OWNER
                    or stat.S_IMODE(opened.st_mode)!=0o600 or opened.st_nlink!=1 or opened.st_size!=0
                    or not stat.S_ISREG(current.st_mode) or stat.S_ISLNK(current.st_mode)
                    or (current.st_dev,current.st_ino)!=identity
                    or (current.st_uid,current.st_gid)!=PROBE_OUTPUT_OWNER
                    or stat.S_IMODE(current.st_mode)!=0o600 or current.st_nlink!=1 or current.st_size!=0):
                raise Hold('probe-output-metadata')
            return identity
        finally:
            os.close(fd)
    except FileExistsError:
        raise Hold('probe-output-exists')
    except OSError:
        raise Hold('probe-output-create')


def _verify_probe_output(path,identity):
    try:
        st=os.lstat(path)
    except OSError:
        raise Hold('probe-output-metadata')
    if (not stat.S_ISREG(st.st_mode) or stat.S_ISLNK(st.st_mode)
            or (st.st_dev,st.st_ino)!=identity or (st.st_uid,st.st_gid)!=PROBE_OUTPUT_OWNER
            or stat.S_IMODE(st.st_mode)!=0o600 or st.st_nlink!=1):
        raise Hold('probe-output-metadata')


def _open_probe_output_for_parent(path,identity):
    """Open only the exact precreated launcher log, without following a replacement."""
    flags=os.O_WRONLY|os.O_APPEND|getattr(os,'O_NOFOLLOW',0)
    try:
        fd=os.open(path,flags)
        st=os.fstat(fd)
        if (not stat.S_ISREG(st.st_mode) or stat.S_ISLNK(st.st_mode)
                or (st.st_dev,st.st_ino)!=identity or (st.st_uid,st.st_gid)!=PROBE_OUTPUT_OWNER
                or stat.S_IMODE(st.st_mode)!=0o600 or st.st_nlink!=1):
            os.close(fd)
            raise Hold('probe-output-metadata')
        return os.fdopen(fd,'ab',buffering=0)
    except OSError:
        raise Hold('probe-output-open')


def service_preflight(matrix, evidence):
    set_phase('preflight.package-pins')
    # Immutable package pins and all package payloads.
    checks={
      'package_tree':git_tree_oid(PKG)==EXPECTED['package_tree'],
      'sums':sha(PKG/'SHA256SUMS')==EXPECTED['sums'],
      'binding':sha(PKG/'binding.json')==EXPECTED['binding'],
      'matrix':sha(PKG/'acceptance/matrix.json')==EXPECTED['matrix'],
      'collector':sha(PKG/'acceptance/collect-peers.py')==EXPECTED['collector'],
      'derive':sha(PKG/'acceptance/derive-probe-profile.py')==EXPECTED['derive'],
      'probe':sha(PKG/'acceptance/probe.py')==EXPECTED['probe'],
      'sender':sha(PKG/'acceptance/fd-sender.py')==EXPECTED['sender'],
      'listener':sha(PKG/'acceptance/sibling-listener.py')==EXPECTED['listener'],
      'verifier':sha(PKG/'acceptance/verify-acceptance.py')==EXPECTED['verifier'],
      'fixtures':sha(PKG/'acceptance/fixtures.py')==EXPECTED['fixtures'],
    }
    if not all(checks.values()): raise Hold('package-pin')
    # Exact staged package file set, ownership, type, links and Git-mode mapping.
    set_phase('preflight.package-metadata')
    manifest_lines=(PKG/'SHA256SUMS').read_text().splitlines()
    listed=set()
    for line in manifest_lines:
        parts=line.split('  ',1)
        if len(parts)!=2 or not re.fullmatch(r'[0-9a-f]{64}',parts[0]): raise Hold('package-manifest')
        listed.add(parts[1])
    expected_files=listed|{'SHA256SUMS'}
    actual_files=set(); actual_dirs=set(); bad_object=False
    for base,dirs,files in os.walk(PKG,topdown=True,followlinks=False):
        for name in list(dirs):
            path=pathlib.Path(base)/name; info=os.lstat(path)
            if stat.S_ISLNK(info.st_mode) or not stat.S_ISDIR(info.st_mode) or info.st_uid!=0 or info.st_gid!=0: bad_object=True
            actual_dirs.add(path.relative_to(PKG).as_posix())
        for name in files:
            path=pathlib.Path(base)/name; info=os.lstat(path); rel=path.relative_to(PKG).as_posix()
            if not stat.S_ISREG(info.st_mode) or info.st_uid!=0 or info.st_gid!=0 or info.st_nlink!=1: bad_object=True
            want=0o755 if rel in ('acceptance/derive-probe-profile.py','acceptance/probe.py') else 0o644
            if stat.S_IMODE(info.st_mode)!=want: bad_object=True
            actual_files.add(rel)
    root_info=os.lstat(PKG)
    if stat.S_ISLNK(root_info.st_mode) or not stat.S_ISDIR(root_info.st_mode) or root_info.st_uid!=0 or root_info.st_gid!=0 or stat.S_IMODE(root_info.st_mode)!=0o755: bad_object=True
    if bad_object or actual_files!=expected_files or len(actual_files)!=21 or len(actual_dirs)!=8 or any(stat.S_IMODE(os.lstat(PKG/x).st_mode)!=0o755 for x in actual_dirs): raise Hold('package-metadata')
    if json.loads((PKG/'binding.json').read_text()).get('package_version')!='1.0.16': raise Hold('package-version')
    set_phase('preflight.package-checksums')
    sums=run(['/usr/bin/sha256sum','-c','SHA256SUMS'],out=evidence/'sha256-check.stdout',err=evidence/'sha256-check.stderr',timeout=60,cwd=PKG)
    if sums.returncode!=0 or len((evidence/'sha256-check.stdout').read_text().splitlines())!=20: raise Hold('package-checksums')
    # Root-owned versioned sender, published by the earlier approved pin step.
    set_phase('preflight.sender-pin')
    s=os.lstat(SENDER)
    if not stat.S_ISREG(s.st_mode) or s.st_uid!=0 or s.st_gid!=0 or stat.S_IMODE(s.st_mode)!=0o644 or s.st_nlink!=1 or sha(SENDER)!=EXPECTED['sender']:
        raise Hold('sender-pin')
    GATE_STATE['package_pin_ok']=True
    # Existing canary parent must match; never create it or alter /run/mysqld.
    set_phase('preflight.canary-paths')
    my=os.lstat('/run/mysqld'); parent=os.lstat(PARENT)
    if stat.S_ISLNK(my.st_mode) or not stat.S_ISDIR(my.st_mode) or my.st_uid!=105 or my.st_gid!=105 or stat.S_IMODE(my.st_mode)!=0o755: raise Hold('mysqld-parent')
    if stat.S_ISLNK(parent.st_mode) or not stat.S_ISDIR(parent.st_mode) or parent.st_uid!=0 or parent.st_gid!=0 or stat.S_IMODE(parent.st_mode)!=0o755: raise Hold('canary-parent')
    if os.path.lexists(SOCKET): raise Hold('sibling-socket-present')
    # Exact fixture status: expected 21 objects, no extras and no absence.
    set_phase('preflight.fixtures-status')
    proc=run(['/usr/bin/python3','-B',str(PKG/'acceptance/fixtures.py'),'status',str(PKG/'acceptance/matrix.json')],out=evidence/'fixtures-before.stdout',err=evidence/'fixtures-before.stderr')
    set_phase('preflight.fixtures-validate')
    found=read_json(evidence/'fixtures-before.stdout')
    expected={x['path'] for x in matrix['fixtures']['files']}|{x['path'] for x in matrix['fixtures']['symlinks']}
    if proc.returncode!=1 or set(found)!=expected or len(found)!=21: raise Hold('fixtures-before')
    GATE_STATE['fixture_pre_match']=True
    # Services and legacy/sentinel units.
    set_phase('preflight.service-state')
    append_io_ok=systemd_append_io_supported()
    manager_unconfined=system_manager_is_unconfined()
    checks['system_manager_unconfined']=manager_unconfined
    checks_rc={
      'nginx':command_ok(['/usr/bin/systemctl','is-active','--quiet','nginx.service']),
      'production_fpm':command_ok(['/usr/bin/systemctl','is-active','--quiet',matrix['role_units']['production']]),
      'php84_active':command_ok(['/usr/bin/systemctl','is-active','--quiet','php8.4-fpm.service']),
      'stock_php85_active':command_ok(['/usr/bin/systemctl','is-active','--quiet','php8.5-fpm.service']),
    }
    php84=subprocess.run(['/usr/bin/systemctl','show','php8.4-fpm.service','--property=UnitFileState','--value'],stdout=subprocess.PIPE,stderr=subprocess.DEVNULL,check=False)
    if not append_io_ok: raise Hold('systemd-append-output')
    if not manager_unconfined: raise Hold('system-manager-apparmor-label')
    if (checks_rc['nginx']!=0 or checks_rc['production_fpm']!=0
            or checks_rc['php84_active'] not in (3,4)
            or checks_rc['stock_php85_active'] not in (3,4)
            or php84.stdout.decode().strip()!='masked'):
        raise Hold('service-state')
    if os.path.lexists('/run/php/php8.4-fpm.sock'): raise Hold('php84-socket')
    set_phase('preflight.parser')
    parser=subprocess.run(['/usr/sbin/apparmor_parser','--version'],stdout=subprocess.PIPE,stderr=subprocess.STDOUT,check=False)
    if parser.returncode!=0 or b'5.0.2' not in parser.stdout: raise Hold('parser-version')
    # Existing production socket must be live; the pinned inventory checks actual workers and FD policy.
    if not os.path.exists('/run/php/wpcaregrid-production-php8.5-fpm.sock') or not stat.S_ISSOCK(os.lstat('/run/php/wpcaregrid-production-php8.5-fpm.sock').st_mode): raise Hold('production-socket')
    sibling=matrix.get('sibling_canary')
    if (not isinstance(sibling,dict)
            or sibling.get('parent')!='/run/mysqld/wpcaregrid-acceptance'
            or sibling.get('path')!='/run/mysqld/wpcaregrid-acceptance/canary.sock'
            or sibling.get('banner')!='WPCG-SIBLING-CANARY-v1\n'):
        raise Hold('sibling-matrix-contract')
    # Probe profiles must not already be loaded.
    set_phase('preflight.profile-list')
    try: labels=pathlib.Path('/sys/kernel/security/apparmor/profiles').read_text(errors='replace')
    except OSError: raise Hold('apparmor-profile-list')
    for name in PROFILES:
        if re.search(r'^'+re.escape(name)+r'-probe \(enforce\)$',labels,re.M): raise Hold('probe-already-loaded')
    set_phase('preflight.route-closure')
    route_summary = validate_route_closure_evidence(ROUTE_CLOSURE_EVIDENCE)
    # Keep only safe booleans in evidence preflight.
    (evidence/'preflight-summary.json').write_text(json.dumps({'pin_checks':checks,'service_rcs':checks_rc,'fixture_count':len(found),'parent_ok':True,'socket_absent':True,'parser_ok':True,'systemd_append_io_supported':True,'route_closure':route_summary},sort_keys=True))
    GATE_STATE['host_preflight_ok']=True
    return checks


def validate_route_closure_evidence(path):
    """Require a root-only attestation tying provider and origin closure evidence."""
    try:
        st=os.lstat(path)
        parent=os.lstat(path.parent)
    except OSError:
        raise Hold('route-closure-evidence')
    if (not stat.S_ISREG(st.st_mode) or stat.S_ISLNK(st.st_mode)
            or (st.st_uid,st.st_gid)!=(0,0) or stat.S_IMODE(st.st_mode)!=0o600
            or st.st_nlink!=1 or st.st_size>16384):
        raise Hold('route-closure-metadata')
    if (path.parent!=ENVELOPE or not stat.S_ISDIR(parent.st_mode) or stat.S_ISLNK(parent.st_mode)
            or (parent.st_uid,parent.st_gid)!=(0,0) or stat.S_IMODE(parent.st_mode)!=0o700):
        raise Hold('route-closure-parent')
    try:
        with open(path,'rb') as source:
            data=source.read(16385)
        record=json.loads(data)
    except (OSError,ValueError,json.JSONDecodeError):
        raise Hold('route-closure-parse')
    fields={'schema','host','provider','route_closed','apex_dns_only','www_dns_only',
            'no_aaaa','no_load_balancer','no_tunnel','origin_explicit_404',
            'origin_app_upstreams','provider_export_sha256','origin_config_sha256'}
    if (not isinstance(record,dict) or set(record)!=fields or type(record.get('schema')) is not int or record.get('schema')!=1
            or record.get('host')!='stg1.wpcaregrid.com' or record.get('provider')!='cloudflare'
            or any(record.get(k) is not True for k in ('route_closed','apex_dns_only','www_dns_only',
                 'no_aaaa','no_load_balancer','no_tunnel','origin_explicit_404'))
            or type(record.get('origin_app_upstreams')) is not int or record.get('origin_app_upstreams')!=0
            or any(not isinstance(record.get(k),str) or not re.fullmatch(r'[0-9a-f]{64}',record[k])
                   for k in ('provider_export_sha256','origin_config_sha256'))):
        raise Hold('route-closure-values')
    return {'attested':True,'provider':'cloudflare','origin_app_upstreams':0,
            'provider_export_sha256':record['provider_export_sha256'],
            'origin_config_sha256':record['origin_config_sha256'],
            'attestation_sha256':sha(path)}


def required_inventory_roles(matrix, profile, phase):
    spec = matrix['profiles'][profile]
    excluded = set(matrix['phases'][phase]['excluded_roles'])
    peer_roles = [role for role in spec['peer_roles'] if role not in excluded]
    required = [role for role in [spec['profile_role']] if role in matrix['role_units']] + peer_roles
    required += [role for role in matrix['role_required']
                 if matrix['role_required'][role] and role not in required and role not in excluded]
    return required


def capture_inventory(pkg, matrix_path, verifier, matrix, profile, boot, evidence, label):
    out=evidence/(label+'.json'); err=evidence/(label+'.stderr')
    set_phase(label+'.capture')
    p=run(['/usr/bin/python3','-B',str(pkg/'acceptance/collect-peers.py'),str(matrix_path)],out=out,err=err,timeout=90)
    set_phase(label+'.validate')
    if p.returncode!=0 or os.lstat(err).st_size!=0: raise Hold('collector')
    inv=read_json(out)
    mhash=sha(matrix_path)
    required=required_inventory_roles(matrix,profile,'post')
    verdict=verifier.Verdict()
    set_phase(label+'.verifier-check')
    verifier.check_inventory(verdict,matrix,inv,label,required,'post',mhash,boot)
    if verdict.failures or inv.get('complete') is not True or inv.get('scans',0)<2 or inv.get('matrix_sha256')!=mhash or inv.get('boot_id','').replace('-','')!=boot: raise Hold('inventory-check')
    return inv, verifier.identity(inv), p.returncode


def pick_peers(inv, matrix, spec):
    set_phase(CURRENT_PHASE.rsplit('.', 1)[0]+'.pick-peers')
    answer={}
    for role in spec['peer_roles']:
        unit=matrix['role_units'].get(role)
        candidates=[p for p in inv['processes'] if p.get('unit')==unit and 33 in p.get('uids',[])]
        if not candidates: raise Hold('peer-missing')
        answer[role]=[min(candidates,key=lambda p:p['pid'])['pid']]
    return answer


def wait_sender_ready(unit, nonce, evidence):
    deadline=time.monotonic()+20
    log=evidence/(unit+'.journal')
    while time.monotonic()<deadline:
        p=run(['/usr/bin/journalctl','--unit',unit+'.service','--no-pager','--output=cat'],out=log,err=evidence/(unit+'.journal.err'),timeout=10)
        # journalctl invocation uses exclusive evidence files; remove only our own generated poll file before next read.
        lines=[]
        if p.returncode==0:
            try: lines=(log.read_text(errors='replace').splitlines())
            except OSError: pass
        ready=None
        for line in lines:
            if line.startswith('READY '):
                m=re.fullmatch(r'READY (/tmp/wpcg-probe-'+re.escape(nonce)+r'/scm\.sock) (wpcg-probe-'+re.escape(nonce)+r') v4=(none|[0-9]+) v6=(none|[0-9]+)',line)
                if m: ready=m.groups(); break
        if ready:
            for target in (log,evidence/(unit+'.journal.err')):
                try: target.unlink()
                except FileNotFoundError: pass
            path,abstract,v4,v6=ready
            tcp={'AF_INET':['127.0.0.1',int(v4)]} if v4!='none' else {}
            if v6!='none': tcp['AF_INET6']=['::1',int(v6)]
            return {'scm_socket':path,'abstract':abstract,'tcp':tcp}
        for target in (log,evidence/(unit+'.journal.err')):
            try: target.unlink()
            except FileNotFoundError: pass
        time.sleep(0.25)
    raise Hold('sender-ready')


def start_sender(nonce,evidence,mode):
    unit='wpcg-g2-'+nonce+'-sender-'+mode
    if command_ok(['/usr/bin/systemctl','is-active','--quiet',unit+'.service'])==0: raise Hold('sender-unit-existing')
    p=run(['/usr/bin/systemd-run','--quiet','--collect','--unit='+unit,'-p','User=www-data','-p','PrivateTmp=no',
           '/usr/bin/python3','-I','-B',str(SENDER),nonce,'/home/wpcg-fixture/canary'],
          out=evidence/(unit+'.start.stdout'),err=evidence/(unit+'.start.stderr'),timeout=20)
    if p.returncode!=0: raise Hold('sender-start')
    return unit


def wait_sender_end(unit, nonce, evidence, timeout=135):
    deadline=time.monotonic()+timeout
    while time.monotonic()<deadline:
        rc,state=unit_state(unit+'.service')
        if rc!=0 or state in ('inactive','failed','dead','not-found'):
            temp=pathlib.Path('/tmp')/('wpcg-probe-'+nonce)
            if os.path.lexists(temp): raise Hold('sender-temp-left')
            return
        time.sleep(0.5)
    raise Hold('sender-timeout')


def launch_probe(pkg,matrix_text,profile,mode,env,confined,out,err):
    if not system_manager_is_unconfined(): raise Hold('system-manager-apparmor-label')
    out=pathlib.Path(out); err=pathlib.Path(err)
    launcher_err=err.with_name(err.name+'.systemd-run')
    outputs=(out,err,launcher_err)
    identities=[]
    for path in outputs:
        identities.append(_create_probe_output(path))
    if len(set(identities))!=len(identities): raise Hold('probe-output-alias')
    unit='wpcg-g2-probe-'+profile.replace('wpcaregrid-','')+'-'+mode+'-'+secrets.token_hex(8)
    unit_rc=command_ok(['/usr/bin/systemctl','is-active','--quiet',unit+'.service'])
    if unit_rc not in (3,4): raise Hold('probe-unit-existing')
    args=['/usr/bin/systemd-run','--quiet','--wait','--collect','--unit='+unit,
          '-p','TimeoutStartSec=10s','-p','RuntimeMaxSec=75s','-p','TimeoutStopSec=5s',
          '-p','User=www-data','-p','PrivateTmp=no','-p','NoNewPrivileges=yes',
          '-p','StandardInput=file:/home/wpcg-fixture/canary',
          '-p','StandardOutput=append:'+str(out),'-p','StandardError=append:'+str(err)]
    if confined: args += ['-p','AppArmorProfile='+profile+'-probe']
    args += ['/usr/bin/python3','-I','-S','-B','-c',(pkg/'acceptance/probe.py').read_text(),mode,matrix_text,profile,json.dumps(env,separators=(',',':'),sort_keys=True)]
    try:
        with _open_probe_output_for_parent(launcher_err,identities[2]) as parent_err:
            p=subprocess.run(args,stdout=subprocess.DEVNULL,stderr=parent_err,timeout=90,check=False)
    except subprocess.TimeoutExpired:
        # RuntimeMaxSec/TimeoutStopSec bound the transient child; never parse its
        # files after a wait timeout because it may still hold the append FDs.
        raise Hold('probe-timeout')
    except OSError:
        raise Hold('probe-launch')
    for path,identity in zip(outputs,identities): _verify_probe_output(path,identity)
    return p.returncode


def start_sibling(pkg,matrix_path,evidence,on_started=None):
    if os.path.lexists(SOCKET): raise Hold('sibling-socket-before')
    unit='wpcg-g2-sibling-'+secrets.token_hex(16)
    unit_service=unit+'.service'
    # Evidence files are exclusive-create. Include this invocation's unit nonce
    # so the build and deploy CLI listeners can share one run directory.
    evidence_nonce=unit[len('wpcg-g2-sibling-'):]
    unit_rc=command_ok(['/usr/bin/systemctl','is-active','--quiet',unit_service])
    if unit_rc==0: raise Hold('sibling-unit-existing')
    if unit_rc not in (3,4): raise Hold('sibling-unit-state')
    try:
        p=run(['/usr/bin/systemd-run','--quiet','--collect','--unit='+unit,'--property=RuntimeMaxSec=600','--property=Type=exec',
               '/usr/bin/python3','-I','-B',str(pkg/'acceptance/sibling-listener.py'),str(matrix_path),'--seconds','600'],
              out=evidence/f'sibling-start-{evidence_nonce}.stdout',
              err=evidence/f'sibling-start-{evidence_nonce}.stderr',timeout=20)
    except (OSError,subprocess.TimeoutExpired):
        # The unit state is ambiguous. No ownership callback was made, so leave
        # any late/foreign unit untouched and fail closed for operator review.
        raise Hold('sibling-start')
    if p.returncode!=0: raise Hold('sibling-start')
    # Ownership starts only after this exact systemd-run invocation confirms success.
    # On spawn error, timeout, or nonzero status, leave any same-name/late unit alone.
    SIBLING_UNITS.append(unit_service)
    if on_started is not None: on_started(unit_service)
    deadline=time.monotonic()+8
    while time.monotonic()<deadline:
        if os.path.exists(SOCKET) and stat.S_ISSOCK(os.lstat(SOCKET).st_mode): return
        time.sleep(0.1)
    raise Hold('sibling-ready')


def stop_sibling(unit_service):
    if not isinstance(unit_service,str) or not re.fullmatch(r'wpcg-g2-sibling-[0-9a-f]{32}\.service',unit_service):
        return False
    command_ok(['/usr/bin/systemctl','stop',unit_service],timeout=30)
    deadline=time.monotonic()+10
    while time.monotonic()<deadline:
        if sibling_gone((unit_service,)): return True
        time.sleep(0.1)
    return False


def sibling_gone(units=None):
    # Check only nonce unit names this invocation successfully started. Never
    # stop the legacy shared unit. Its state is still a pre/postcondition: active
    # means another actor may own the canary and this run must HOLD.
    legacy_rc=command_ok(['/usr/bin/systemctl','is-active','--quiet','wpcg-sibling-canary.service'])
    if legacy_rc not in (3,4): return False
    names=tuple(SIBLING_UNITS if units is None else units)
    if any(not isinstance(name,str) or not re.fullmatch(r'wpcg-g2-sibling-[0-9a-f]{32}\.service',name)
           for name in names):
        return False
    for name in names:
        rc=command_ok(['/usr/bin/systemctl','is-active','--quiet',name])
        if rc not in (3,4): return False
    return not os.path.lexists(SOCKET)


def unload_probe(path):
    state=probe_profile_state(path)
    if state is None: return False
    if not state: return True
    rc=command_ok(['/usr/sbin/apparmor_parser','-R',str(path)],timeout=30)
    return rc==0 and probe_profile_state(path) is False


def probe_profile_state(path):
    label=pathlib.Path(path).name
    if not label.endswith('.profile'): return None
    label=label[:-len('.profile')]
    try:
        lines=pathlib.Path('/sys/kernel/security/apparmor/profiles').read_text(errors='replace').splitlines()
    except OSError:
        return None
    return any(line.startswith(label+' (') for line in lines)


def _remove_created_partial_profile(path, identity):
    """Unlink only this run's exact root-owned 0600 partial profile, if unloaded."""
    parent=pathlib.Path(path).parent
    if not ACTIVE_EVIDENCE or parent!=pathlib.Path(ACTIVE_EVIDENCE): return False
    try:
        parent_st=os.lstat(parent)
    except OSError:
        return False
    if (stat.S_ISLNK(parent_st.st_mode) or not stat.S_ISDIR(parent_st.st_mode)
            or (parent_st.st_uid,parent_st.st_gid)!=PROBE_PROFILE_OWNER
            or stat.S_IMODE(parent_st.st_mode)!=0o700): return False
    try:
        current=os.lstat(path)
    except FileNotFoundError:
        return True
    if (not stat.S_ISREG(current.st_mode) or current.st_nlink!=1
            or (current.st_dev,current.st_ino)!=identity
            or (current.st_uid,current.st_gid)!=PROBE_PROFILE_OWNER
            or stat.S_IMODE(current.st_mode)!=0o600):
        return False
    if probe_profile_state(path) is not False:
        return False
    try:
        if probe_profile_state(path) is not False: return False
        os.unlink(path)
        return True
    except OSError:
        return False


def add_probe_self_stat_rule(profile_bytes):
    """Add one exact owner-restricted rule to bounded derived-profile bytes."""
    if (not isinstance(profile_bytes,bytes) or len(profile_bytes)>2_000_000
            or not profile_bytes.endswith(b'}\n') or PROBE_SELF_STAT_RULE in profile_bytes):
        raise Hold('derive-profile-output')
    return profile_bytes[:-2]+PROBE_SELF_STAT_RULE+b'}\n'


def derive_probe_profile(src, profile_path, stderr_path, derive_script):
    """Derive the pinned profile and add only its owner-restricted self-stat read."""
    created_identity=None
    try:
        with open(src,'rb') as inp,open(stderr_path,'xb') as err:
            proc=subprocess.run(['/usr/bin/python3','-B',str(derive_script)],
                                stdin=inp,stdout=subprocess.PIPE,stderr=err,check=False)
        if proc.returncode!=0:
            raise Hold('derive-profile')
        # The probe binds its own PID lifetime in JSON. AppArmor resolves
        # /proc/self/stat to @{PROC}/@{pid}/stat; owner scopes this to same-UID
        # proc entries, while the inherited deny-ptrace policy remains unchanged.
        derived=add_probe_self_stat_rule(proc.stdout)
        nofollow=getattr(os,'O_NOFOLLOW',None)
        if nofollow is None: raise Hold('derive-artifact-nofollow-unavailable')
        try:
            fd=os.open(profile_path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|nofollow|os.O_CLOEXEC,0o600)
        except FileExistsError:
            raise Hold('derive-artifact-exists')
        except OSError:
            raise Hold('derive-artifact-create')
        try:
            created=os.fstat(fd)
            created_identity=(created.st_dev,created.st_ino)
            if (not stat.S_ISREG(created.st_mode) or created.st_nlink!=1
                    or (created.st_uid,created.st_gid)!=PROBE_PROFILE_OWNER
                    or stat.S_IMODE(created.st_mode)!=0o600):
                raise Hold('derive-artifact-metadata')
            with os.fdopen(fd,'wb',closefd=False) as out:
                out.write(derived)
                out.flush()
        finally:
            os.close(fd)
    except Exception:
        if created_identity is not None and not _remove_created_partial_profile(profile_path,created_identity):
            raise Hold('derive-artifact-cleanup')
        raise
    return 0


def stale_profile_absent():
    """Never remove a previous run's artifact; refuse if it has reappeared."""
    return not os.path.lexists(STALE_PARTIAL_PROFILE)


def load_probe_profile(path):
    rc=command_ok(['/usr/sbin/apparmor_parser','-r',str(path)],timeout=45)
    state=probe_profile_state(path)
    if rc==0 and state is True: return True
    if state is True:
        if not unload_probe(path): raise Hold('probe-load-cleanup')
        raise Hold('probe-load')
    if state is None: raise Hold('probe-load-state')
    raise Hold('probe-load')


def original_exec_check(profile, evidence, boot):
    t0=time.time_ns()
    p=run(['/usr/bin/systemd-run','--quiet','--wait','--collect','--pipe','-p','User=www-data','-p','NoNewPrivileges=yes',
           '-p','AppArmorProfile='+profile,'/usr/bin/python3','--version'],
          out=evidence/(profile+'-original-exec.stdout'),err=evidence/(profile+'-original-exec.stderr'),timeout=30)
    t1=time.time_ns()
    time.sleep(1)
    audit=evidence/(profile+'-original-exec-audit.jsonl')
    since=max(0,t0//1_000_000_000-1); until=t1//1_000_000_000+2
    a=run(['/usr/bin/journalctl','-k','-o','json','--no-pager','--boot',boot,'--since','@'+str(since),'--until','@'+str(until)],out=audit,err=evidence/(profile+'-original-exec-audit.stderr'),timeout=30)
    found=False
    if p.returncode!=0 and a.returncode==0:
        for line in audit.read_text(errors='replace').splitlines():
            try: row=json.loads(line)
            except json.JSONDecodeError: continue
            msg=row.get('MESSAGE','')
            if isinstance(msg,str) and 'apparmor="DENIED"' in msg and 'operation="exec"' in msg and f'profile="{profile}"' in msg and 'name="/usr/bin/python3"' in msg:
                found=True
    return p.returncode, found


def profile_run(name,matrix_text,matrix,verifier,boot,evidence):
    result={'profile':name,'status':'HOLD','inventory_before_rc':-1,'baseline_rc':-1,'confined_rc':-1,'inventory_after_rc':-1,'verifier_rc':-1,
            'sibling_required':name in ('wpcaregrid-php-cli-production-build','wpcaregrid-php-cli-production-deploy'),
            'sibling_cleanup_ok':True,'probe_unloaded':False,'original_exec_check':True,'failure_code':'none'}
    spec=matrix['profiles'][name]
    before,signature,rc=capture_inventory(PKG,PKG/'acceptance/matrix.json',verifier,matrix,name,boot,evidence,name+'-inventory-before')
    result['inventory_before_rc']=rc
    set_phase(name+'.inventory_before.pick-peers')
    peers=pick_peers(before,matrix,spec)
    # One unique nonce and sender for each baseline/confined invocation.
    N=name.replace('wpcaregrid-','').replace('php-','').replace('-','')+secrets.token_hex(3)
    profile_path=evidence/(name+'-probe.profile')
    loaded=False; load_attempted=False; sibling_unit=None; sender=None; sender_nonce=None
    start_ns=time.time_ns()
    try:
        set_phase(name+'.setup')
        if result['sibling_required']:
            # Cleanup is armed only after systemd-run reports that this invocation
            # successfully created its nonce unit. Failed/late starts remain untouched.
            def own_sibling(unit_service):
                nonlocal sibling_unit
                sibling_unit=unit_service
            start_sibling(PKG,PKG/'acceptance/matrix.json',evidence,own_sibling)
        for mode in ('baseline','confined'):
            set_phase(name+'.'+mode+'.sender-start')
            nonce=N+('b' if mode=='baseline' else 'c')
            sender_nonce=nonce
            sender=start_sender(nonce,evidence,mode)
            env=wait_sender_ready(sender,nonce,evidence)
            env['peers']=peers
            env['scm_socket']=env.pop('scm_socket')
            # baseline then derived-profile confined run
            if mode=='confined':
                set_phase(name+'.confined.derive-profile')
                src=pathlib.Path('/etc/apparmor.d')/pathlib.Path(spec['file']).name
                derive_probe_profile(src,profile_path,evidence/(name+'-derive.stderr'),PKG/'acceptance/derive-probe-profile.py')
                load_attempted=True
                loaded=load_probe_profile(profile_path)
            probe_out=evidence/(name+'-'+mode+'.json'); probe_err=evidence/(name+'-'+mode+'.stderr')
            set_phase(name+'.'+mode+'.probe')
            rc_probe=launch_probe(PKG,matrix_text,name,mode,env,mode=='confined',probe_out,probe_err)
            result['baseline_rc' if mode=='baseline' else 'confined_rc']=rc_probe
            # Sender must exit naturally so its pinned finally removes its /tmp socket tree.
            wait_sender_end(sender,nonce,evidence,timeout=135)
            sender=None; sender_nonce=None
            if rc_probe!=0: raise Hold('probe-command')
        if loaded:
            if not unload_probe(profile_path): raise Hold('probe-unload')
            loaded=False; load_attempted=False; result['probe_unloaded']=True
        if sibling_unit is not None:
            result['sibling_cleanup_ok']=stop_sibling(sibling_unit); sibling_unit=None
            if not result['sibling_cleanup_ok']: raise Hold('sibling-cleanup')
        end_ns=time.time_ns()
        set_phase(name+'.audit-capture')
        time.sleep(1)
        audit=evidence/(name+'-audit.jsonl')
        p_audit=run(['/usr/bin/journalctl','-k','-o','json','--no-pager','--boot',boot,'--since','@'+str(max(0,start_ns//1_000_000_000-1)),'--until','@'+str(end_ns//1_000_000_000+2)],out=audit,err=evidence/(name+'-audit.stderr'),timeout=60)
        if p_audit.returncode!=0: raise Hold('audit-capture')
        after,after_sig,rc_after=capture_inventory(PKG,PKG/'acceptance/matrix.json',verifier,matrix,name,boot,evidence,name+'-inventory-after')
        result['inventory_after_rc']=rc_after
        if signature!=after_sig: raise Hold('inventory-changed')
        # GO13 original-profile exec denial applies only where the matrix marks exec-python at original profile scope.
        original_scoped=any(c.get('probe_scope')=='original_profile' and c.get('id')=='exec-python' for c in spec['checks'])
        if original_scoped:
            orc,denied=original_exec_check(name,evidence,boot)
            result['original_exec_check']=bool(orc!=0 and denied)
            if not result['original_exec_check']: raise Hold('original-profile-exec')
        vout=evidence/(name+'-verifier.stdout'); verr=evidence/(name+'-verifier.stderr')
        set_phase(name+'.acceptance-verifier')
        vp=run(['/usr/bin/python3','-B',str(PKG/'acceptance/verify-acceptance.py'),str(PKG/'acceptance/matrix.json'),name,'post',
                str(evidence/(name+'-baseline.json')),str(evidence/(name+'-confined.json')),
                str(evidence/(name+'-inventory-before.json')),str(evidence/(name+'-inventory-after.json')),str(audit)],out=vout,err=verr,timeout=60)
        result['verifier_rc']=vp.returncode
        if vp.returncode!=0: raise Hold('verifier')
        result['status']='PASS'
        return result
    except Hold as exc:
        result['failure_code']=exc.code
        result['status']='HOLD'
        if exc.code == 'sibling-ready':
            result['sibling_cleanup_ok'] = False
        return result
    finally:
        if loaded or load_attempted:
            try: result['probe_unloaded']=unload_probe(profile_path)
            except Exception: result['probe_unloaded']=False
        if sibling_unit is not None:
            try: result['sibling_cleanup_ok']=stop_sibling(sibling_unit)
            except Exception: result['sibling_cleanup_ok']=False
        if sender is not None and sender_nonce is not None:
            # Let the pinned sender reach its documented 120-second timeout and its own finally cleanup.
            try: wait_sender_end(sender,sender_nonce,evidence,timeout=135)
            except Exception: pass


def main(argv=None):
    parser=argparse.ArgumentParser(description='Run the pinned 1.0.16 Gate 2 post acceptance.')
    parser.add_argument('--package-dir',required=True)
    parser.add_argument('--evidence-envelope',required=True)
    parser.add_argument('--route-closure-evidence',required=True)
    args=parser.parse_args(argv)
    if not verify_artifact_manifest(): raise Hold('orchestrator-pin')
    global PKG,ENVELOPE,ROUTE_CLOSURE_EVIDENCE,STALE_PARTIAL_PROFILE
    PKG=pathlib.Path(args.package_dir)
    ENVELOPE=pathlib.Path(args.evidence_envelope)
    ROUTE_CLOSURE_EVIDENCE=pathlib.Path(args.route_closure_evidence)
    STALE_PARTIAL_PROFILE=ENVELOPE/'acceptance-1.0.16-post-20261127T001334Z-795c9f'/'wpcaregrid-nginx-probe.profile'
    set_phase('startup.identity')
    if os.geteuid()!=0: raise Hold('not-root')
    if subprocess.run(['/usr/bin/hostname','-f'],stdout=subprocess.PIPE,stderr=subprocess.DEVNULL).stdout.decode().strip()!='stg1.wpcaregrid.com': raise Hold('host-identity')
    envelope_st=os.lstat(ENVELOPE)
    if not stat.S_ISDIR(envelope_st.st_mode) or stat.S_ISLNK(envelope_st.st_mode) or envelope_st.st_uid!=0 or envelope_st.st_gid!=0 or stat.S_IMODE(envelope_st.st_mode)!=0o700:
        raise Hold('evidence-envelope')
    os.umask(0o077)
    stamp=time.strftime('%Y%m%dT%H%M%SZ',time.gmtime())
    evidence=ENVELOPE/('acceptance-1.0.16-post-'+stamp+'-'+secrets.token_hex(3))
    os.mkdir(evidence,0o700)
    global ACTIVE_EVIDENCE
    ACTIVE_EVIDENCE=str(evidence)
    st=os.lstat(evidence)
    if not stat.S_ISDIR(st.st_mode) or st.st_uid!=0 or st.st_gid!=0 or stat.S_IMODE(st.st_mode)!=0o700: raise Hold('evidence-dir')
    set_phase('startup.matrix-read')
    matrix_path=PKG/'acceptance/matrix.json'; matrix_text=matrix_path.read_text(); matrix=json.loads(matrix_text)
    if set(matrix['profiles'])!=set(PROFILES): raise Hold('profile-set')
    # Ensure exact package and host gates before creating canary listeners or derived profiles.
    package_ok=True
    service_preflight(matrix,evidence)
    set_phase('preflight.stale-profile-artifact')
    if not stale_profile_absent(): raise Hold('stale-profile-artifact-present')
    set_phase('preflight.boot-id')
    boot=pathlib.Path('/proc/sys/kernel/random/boot_id').read_text().strip().replace('-','')
    vpath=PKG/'acceptance/verify-acceptance.py'
    set_phase('preflight.verifier-import')
    spec=importlib.util.spec_from_file_location('wpcg_gate2_verify',vpath)
    verifier=importlib.util.module_from_spec(spec); sys.modules[spec.name]=verifier; spec.loader.exec_module(verifier)
    results=[]; failed=''
    for profile in PROFILES:
        set_phase(profile+'.start')
        item=profile_run(profile,matrix_text,matrix,verifier,boot,evidence)
        results.append(item)
        if item['status']!='PASS':
            failed=item['failure_code']
            break
    cleanup_rc=-1; fixture_empty=False; socket_absent=False
    if not failed and len(results)==4 and all(x['status']=='PASS' for x in results):
        if not sibling_gone():
            failed='sibling-left'
        else:
            set_phase('cleanup.fixture-remove')
            p=run(['/usr/bin/python3','-B',str(PKG/'acceptance/fixtures.py'),'remove',str(matrix_path)],out=evidence/'fixtures-remove.stdout',err=evidence/'fixtures-remove.stderr',timeout=90)
            cleanup_rc=p.returncode
            if p.returncode!=0: failed='fixture-cleanup'
            else:
                set_phase('cleanup.fixture-status')
                q=run(['/usr/bin/python3','-B',str(PKG/'acceptance/fixtures.py'),'status',str(matrix_path)],out=evidence/'fixtures-after.stdout',err=evidence/'fixtures-after.stderr',timeout=60)
                try: remaining=read_json(evidence/'fixtures-after.stdout')
                except Exception: remaining=['parse-error']
                fixture_empty=q.returncode==0 and remaining==[]
                socket_absent=not os.path.lexists(SOCKET)
                my=os.lstat('/run/mysqld'); parent=os.lstat(PARENT)
                if stat.S_ISLNK(my.st_mode) or not stat.S_ISDIR(my.st_mode) or my.st_uid!=105 or my.st_gid!=105 or stat.S_IMODE(my.st_mode)!=0o755: failed='mysqld-parent-after'
                if stat.S_ISLNK(parent.st_mode) or not stat.S_ISDIR(parent.st_mode) or parent.st_uid!=0 or parent.st_gid!=0 or stat.S_IMODE(parent.st_mode)!=0o755: failed='canary-parent-after'
                if not fixture_empty or not socket_absent: failed='fixture-zero-check'
    # Re-check at report time; do not reuse the pre-run absent value after a failed profile.
    socket_absent=sibling_gone()
    report={'phase':'acceptance-post','rc':0 if not failed and len(results)==4 and fixture_empty and socket_absent else 1,
            'evidence_path':str(evidence),'package_pin_ok':GATE_STATE['package_pin_ok'],'fixture_pre_match':GATE_STATE['fixture_pre_match'],'host_preflight_ok':GATE_STATE['host_preflight_ok'],
            'profile_results':results,'fixture_cleanup_rc':cleanup_rc,'fixture_empty':fixture_empty,
            'sibling_socket_absent':socket_absent,'overall_pass':bool(not failed and len(results)==4 and fixture_empty and socket_absent),
            'failure_code':failed or 'none','exception_metadata_written':False,
            'exception_phase':'none','exception_class':'none','exception_errno':None,
            'exception_thrower':None,'exception_thrower_truncated':False}
    print('__WPCG_ONESHOT_REPORT__:'+json.dumps(report,sort_keys=True,separators=(',',':')),flush=True)
    print('__WPCG_ONESHOT_DONE__',flush=True)
    return report['rc']

def run_and_emit(main_fn=main, stream=None):
    """Top-level exception-to-report path, separated for local fake integration tests."""
    import sys as _sys
    output = stream if stream is not None else _sys.stdout
    try: rc=main_fn()
    except Hold as exc:
        report={'phase':'acceptance-post','rc':1,'evidence_path':ACTIVE_EVIDENCE,**GATE_STATE,
                'profile_results':[],'fixture_cleanup_rc':-1,'fixture_empty':False,
                'sibling_socket_absent':False,'overall_pass':False,'failure_code':exc.code,
                'exception_metadata_written':False,'exception_phase':'none','exception_class':'none',
                'exception_errno':None,'exception_thrower':None,'exception_thrower_truncated':False}
        print('__WPCG_ONESHOT_REPORT__:'+json.dumps(report,sort_keys=True,separators=(',',':')),file=output,flush=True)
        print('__WPCG_ONESHOT_DONE__',file=output,flush=True)
        rc=1
    except Exception as exc:
        metadata = safe_exception_fields(exc, exc.__traceback__)
        metadata_written = write_exception_metadata(metadata)
        report={'phase':'acceptance-post','rc':2,'evidence_path':ACTIVE_EVIDENCE,**GATE_STATE,
                'profile_results':[],'fixture_cleanup_rc':-1,'fixture_empty':False,
                'sibling_socket_absent':False,'overall_pass':False,'failure_code':'unhandled',
                'exception_metadata_written':metadata_written,'exception_phase':metadata['phase'],
                'exception_class':metadata['exception_class'],'exception_errno':metadata['errno'],
                'exception_thrower':metadata['thrower'],
                'exception_thrower_truncated':metadata['thrower_truncated']}
        print('__WPCG_ONESHOT_REPORT__:'+json.dumps(report,sort_keys=True,separators=(',',':')),file=output,flush=True)
        print('__WPCG_ONESHOT_DONE__',file=output,flush=True)
        rc=2
    return rc

if __name__=='__main__':
    raise SystemExit(run_and_emit())
