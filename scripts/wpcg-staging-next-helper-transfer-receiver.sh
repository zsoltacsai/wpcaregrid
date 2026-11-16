#!/bin/sh
# One-shot deploy-identity receiver, executed by ssh as deploy (never via sudo).
# It is streamed to /bin/sh; it is not installed on the host.
set -eu

fail() { printf 'TRANSFER=STOP;CODE=%s\n' "$1" >&2; exit 1; }

if [ "${WPCG_RECEIVER_TEST:-}" = 1 ]; then
    case "${WPCG_RECEIVER_TEST_ROOT:-}" in /*) ;; *) fail test_root_invalid ;; esac
    [ "$WPCG_RECEIVER_TEST_ROOT" != / ] || fail test_root_invalid
    root=$WPCG_RECEIVER_TEST_ROOT
    expected_user=$(id -un)
    expected_uid=$(id -u)
    expected_home_uid=$expected_uid
else
    root=
    expected_user=deploy
    expected_uid=1000
    expected_home_uid=0
fi
home=/home
deploy_home=/home/deploy
private=/home/deploy/.private

operation=${1:-}
version=${2:-}
nonce=${3:-}
case "$version" in ''|*[!0-9.]*|.*|*..*|*.) fail version_invalid ;; esac
case "$nonce" in ????????-????-????-????-????????????) ;; *) fail nonce_invalid ;; esac
case "$nonce" in *[!0-9a-f-]*) fail nonce_invalid ;; esac

remote_path() {
    case "$1" in /*) printf '%s%s' "$root" "$1" ;; *) fail path_invalid ;; esac
}
private=$(remote_path "$private")
home=$(remote_path "$home")
deploy_home=$(remote_path "$deploy_home")
stage=$private/.wpcg-helper-upload-$nonce
dest=$private/wpcg-staging-next-helper-$version
version_slug=$(printf '%s' "$version" | tr '.' '_')
if [ "$version" = 1.4.10 ]; then
    expected_names="wpcaregrid-staging-next-deploy-priv-$version_slug.py staging_next_deploy_backend_$version_slug.py staging_next_db_transaction_$version_slug.py deployment_target_binding_$version_slug.py deployment_environment_contract.py"
else
    expected_names="wpcaregrid-staging-next-deploy-priv-$version_slug.py staging_next_deploy_backend_$version_slug.py staging_next_db_transaction_$version_slug.py deployment_target_binding_$version_slug.py deployment_environment_contract_$version_slug.py"
fi
ops_names="wpcg_staging_next_runtime_layout.py wpcg-staging-next-path-transition-1_0.py install_staging_next_helper_1_4_12.py"
helper_dest=$dest
ops_dest=$private/wpcg-staging-next-ops-1.0

validate_identity() {
    [ "$(id -un)" = "$expected_user" ] || fail remote_username_mismatch
    [ "$(id -u)" = "$expected_uid" ] || fail remote_uid_mismatch
    [ -d "$home" ] && [ ! -L "$home" ] || fail home_directory_invalid
    [ "$(stat -c '%u' "$home" 2>/dev/null)" = "$expected_home_uid" ] \
        || fail home_directory_owner_mismatch
    find "$home" -maxdepth 0 -perm /022 -print -quit | grep -q . && fail home_directory_writable_by_group_or_other || :
    [ -d "$deploy_home" ] && [ ! -L "$deploy_home" ] || fail deploy_home_invalid
    [ "$(stat -c '%u' "$deploy_home" 2>/dev/null)" = "$expected_uid" ] \
        || fail deploy_home_owner_mismatch
    find "$deploy_home" -maxdepth 0 -perm /022 -print -quit | grep -q . && fail deploy_home_writable_by_group_or_other || :
    command -v sha256sum >/dev/null 2>&1 || fail sha256sum_unavailable
    command -v find >/dev/null 2>&1 || fail find_unavailable
    command -v ln >/dev/null 2>&1 || fail ln_unavailable
    command -v stat >/dev/null 2>&1 || fail stat_unavailable
    command -v wc >/dev/null 2>&1 || fail wc_unavailable
    command -v tr >/dev/null 2>&1 || fail tr_unavailable
    stat --version >/dev/null 2>&1 || fail gnu_stat_required
    find --version >/dev/null 2>&1 || fail gnu_find_required
}

ensure_private() {
    if [ -e "$private" ] || [ -L "$private" ]; then
        [ -d "$private" ] && [ ! -L "$private" ] || fail private_directory_not_real
        [ "$(stat -c '%u:%a' "$private")" = "$expected_uid:700" ] \
            || fail private_directory_metadata_mismatch
        return
    fi
    [ "${1:-readonly}" = create ] || return 0
    mkdir -m 700 "$private" || fail private_directory_create_failed
    [ "$(stat -c '%u:%a' "$private")" = "$expected_uid:700" ] \
        || fail private_directory_metadata_mismatch
}

case "$operation" in
    preflight)
        validate_identity
        command -v mkdir >/dev/null 2>&1 || fail mkdir_unavailable
        command -v rmdir >/dev/null 2>&1 || fail rmdir_unavailable
        command -v rm >/dev/null 2>&1 || fail rm_unavailable
        command -v chmod >/dev/null 2>&1 || fail chmod_unavailable
        command -v sh >/dev/null 2>&1 || fail sh_unavailable
        ensure_private readonly
        if [ -d "$private" ]; then private_state=verified; else private_state=absent_will_create_on_stage; fi
        printf 'TRANSFER_PREFLIGHT=PASS;IDENTITY=deploy:%s;PRIVATE=%s\n' "$expected_uid" "$private_state"
        ;;
    begin)
        validate_identity
        ensure_private create
        [ ! -e "$dest" ] && [ ! -L "$dest" ] || fail destination_exists
        [ ! -e "$stage" ] && [ ! -L "$stage" ] || fail upload_workspace_exists
        mkdir -m 700 "$stage" || fail upload_workspace_create_failed
    [ "$(stat -c '%u:%a' "$stage")" = "$expected_uid:700" ] \
            || fail upload_workspace_metadata_mismatch
        printf 'TRANSFER_WORKSPACE=READY;NONCE=%s\n' "$nonce"
        ;;
    publish)
        validate_identity
        [ ! -e "$dest" ] && [ ! -L "$dest" ] || fail destination_exists
        [ -d "$stage" ] && [ ! -L "$stage" ] || fail upload_workspace_missing
        [ "$(stat -c '%u:%g:%a' "$stage")" = "$expected_uid:$expected_uid:700" ] \
            || fail upload_workspace_metadata_mismatch
        shift 3
        [ "$#" -eq 10 ] || fail manifest_arguments_invalid
        names=$expected_names
        name_count=0
        verify_pairs=
        for name in $names; do
            supplied_name=$1; hash=$2; shift 2
            [ "$supplied_name" = "$name" ] || fail manifest_filename_mismatch
            case "$hash" in ????????* ) ;; *) fail manifest_hash_invalid ;; esac
            [ "${#hash}" -eq 64 ] || fail manifest_hash_invalid
            case "$hash" in *[!0-9a-f]*) fail manifest_hash_invalid ;; esac
            [ -f "$stage/$name" ] && [ ! -L "$stage/$name" ] || fail staged_file_invalid
            [ "$(stat -c '%u:%a' "$stage/$name")" = "$expected_uid:600" ] \
                || fail staged_file_metadata_mismatch
            printf '%s  %s\n' "$hash" "$stage/$name" | sha256sum -c - >/dev/null \
                || fail staged_file_hash_mismatch
            verify_pairs="$verify_pairs $hash:$name"
            name_count=$((name_count + 1))
        done
        [ "$name_count" -eq 5 ] || fail manifest_file_count_invalid
        actual_count=$(find "$stage" -mindepth 1 -maxdepth 1 -type f | wc -l | tr -d ' ')
        [ "$actual_count" -eq 5 ] || fail staged_file_set_invalid
        other_count=$(find "$stage" -mindepth 1 -maxdepth 1 ! -type f | wc -l | tr -d ' ')
        [ "$other_count" -eq 0 ] || fail staged_file_set_invalid
        mkdir -m 700 "$dest" || fail destination_create_failed
        for name in $names; do
            ln "$stage/$name" "$dest/$name" || fail destination_no_overwrite_failed
        done
        [ "$(stat -c '%u:%a' "$dest")" = "$expected_uid:700" ] \
            || fail destination_metadata_mismatch
        for pair in $verify_pairs; do
            hash=${pair%%:*}
            name=${pair#*:}
            [ -f "$dest/$name" ] && [ ! -L "$dest/$name" ] || fail destination_file_invalid
            [ "$(stat -c '%u:%a' "$dest/$name")" = "$expected_uid:600" ] \
                || fail destination_file_metadata_mismatch
            printf '%s  %s\n' "$hash" "$dest/$name" | sha256sum -c - >/dev/null \
                || fail destination_file_hash_mismatch
        done
        actual_count=$(find "$dest" -mindepth 1 -maxdepth 1 -type f | wc -l | tr -d ' ')
        [ "$actual_count" -eq 5 ] || fail destination_file_set_invalid
        other_count=$(find "$dest" -mindepth 1 -maxdepth 1 ! -type f | wc -l | tr -d ' ')
        [ "$other_count" -eq 0 ] || fail destination_file_set_invalid
        # Remove only the five verified staged names; never recursively delete.
        for name in $names; do rm "$stage/$name" || fail stage_cleanup_failed; done
        rmdir "$stage" || fail stage_cleanup_failed
        printf 'TRANSFER=PASS;VERSION=%s;SOURCE_DIR=%s;FILES=5;OVERWRITE=NO\n' \
            "$version" "$dest"
        ;;
    publish-all)
        validate_identity
        [ ! -e "$dest" ] && [ ! -L "$dest" ] || fail destination_exists
        [ ! -e "$ops_dest" ] && [ ! -L "$ops_dest" ] || fail operations_destination_exists
        [ -d "$stage" ] && [ ! -L "$stage" ] || fail upload_workspace_missing
        [ "$(stat -c '%u:%g:%a' "$stage")" = "$expected_uid:$expected_uid:700" ] \
            || fail upload_workspace_metadata_mismatch
        shift 3
        [ "$#" -eq 16 ] || fail manifest_arguments_invalid
        verify_pairs=
        count=0
        for name in $expected_names $ops_names; do
            supplied_name=$1; hash=$2; shift 2
            [ "$supplied_name" = "$name" ] || fail manifest_filename_mismatch
            case "$hash" in ????????* ) ;; *) fail manifest_hash_invalid ;; esac
            [ "${#hash}" -eq 64 ] || fail manifest_hash_invalid
            case "$hash" in *[!0-9a-f]*) fail manifest_hash_invalid ;; esac
            [ -f "$stage/$name" ] && [ ! -L "$stage/$name" ] || fail staged_file_invalid
            # Normalize only an exact regular object in this create-only upload directory.
            # The later root installer rechecks the independent SHA pin before use.
            [ "$(stat -c '%u' "$stage/$name")" = "$expected_uid" ] \
                || fail staged_file_owner_mismatch
            chmod 600 "$stage/$name" || fail staged_file_mode_set_failed
            [ "$(stat -c '%u:%a' "$stage/$name")" = "$expected_uid:600" ] \
                || fail staged_file_metadata_mismatch
            printf '%s  %s\n' "$hash" "$stage/$name" | sha256sum -c - >/dev/null \
                || fail staged_file_hash_mismatch
            verify_pairs="$verify_pairs $hash:$name"
            count=$((count + 1))
        done
        [ "$count" -eq 8 ] || fail manifest_file_count_invalid
        actual_count=$(find "$stage" -mindepth 1 -maxdepth 1 -type f | wc -l | tr -d ' ')
        [ "$actual_count" -eq 8 ] || fail staged_file_set_invalid
        other_count=$(find "$stage" -mindepth 1 -maxdepth 1 ! -type f | wc -l | tr -d ' ')
        [ "$other_count" -eq 0 ] || fail staged_file_set_invalid
        mkdir -m 700 "$helper_dest" || fail destination_create_failed
        mkdir -m 700 "$ops_dest" || fail operations_destination_create_failed
        for name in $expected_names; do
            ln "$stage/$name" "$helper_dest/$name" || fail destination_no_overwrite_failed
        done
        for name in $ops_names; do
            ln "$stage/$name" "$ops_dest/$name" || fail operations_no_overwrite_failed
        done
        [ "$(stat -c '%u:%a' "$helper_dest")" = "$expected_uid:700" ] \
            || fail destination_metadata_mismatch
        [ "$(stat -c '%u:%a' "$ops_dest")" = "$expected_uid:700" ] \
            || fail operations_destination_metadata_mismatch
        for pair in $verify_pairs; do
            hash=${pair%%:*}
            name=${pair#*:}
            if [ "$name" = wpcg_staging_next_runtime_layout.py ] \
                || [ "$name" = wpcg-staging-next-path-transition-1_0.py ] \
                || [ "$name" = install_staging_next_helper_1_4_12.py ]; then
                final="$ops_dest/$name"
            else
                final="$helper_dest/$name"
            fi
            [ -f "$final" ] && [ ! -L "$final" ] || fail destination_file_invalid
            [ "$(stat -c '%u:%a' "$final")" = "$expected_uid:600" ] \
                || fail destination_file_metadata_mismatch
            printf '%s  %s\n' "$hash" "$final" | sha256sum -c - >/dev/null \
                || fail destination_file_hash_mismatch
        done
        for name in $expected_names $ops_names; do rm "$stage/$name" || fail stage_cleanup_failed; done
        rmdir "$stage" || fail stage_cleanup_failed
        printf 'TRANSFER=PASS;VERSION=%s;SOURCE_DIR=%s;OPS_DIR=%s;FILES=8;OVERWRITE=NO\n' \
            "$version" "$helper_dest" "$ops_dest"
        ;;
    *)
        fail operation_invalid
        ;;
esac
