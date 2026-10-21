#!/usr/bin/env bash
set -euo pipefail
umask 077

# Unprivileged canonical-Git preflight. The only privileged action is one
# fixed root-owned no-argument bootstrap whose inputs are root-pinned.
readonly GIT=/usr/bin/git
readonly SUDO=/usr/bin/sudo
readonly BOOTSTRAP=/usr/local/sbin/wpcaregrid-staging-diagnostics-bootstrap
readonly PACKAGE_FILES=(
  scripts/staging-diagnostics/dispatch.sh
  scripts/staging-diagnostics/provisioning/activation-check.sh
  scripts/staging-diagnostics/provisioning/diagnostics-shell.sh
  scripts/staging-diagnostics/read-m12-cache.php
  scripts/staging-diagnostics/provisioning/run-m12-readback.sh
  scripts/staging-diagnostics/provisioning/provision.py
  scripts/staging-diagnostics/provisioning/verify.py
  scripts/staging-diagnostics/provisioning/rollback.py
  scripts/staging-diagnostics/provisioning/bootstrap.py
)

fail() {
  printf 'SOURCE_PREPARATION=FAILED code=%s\n' "$1" >&2
  exit 1
}

git_clean() {
  /usr/bin/env -i \
    PATH=/usr/bin:/bin HOME="$HOME" \
    GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null \
    GIT_NO_REPLACE_OBJECTS=1 GIT_NO_LAZY_FETCH=1 GIT_OPTIONAL_LOCKS=0 \
    "$GIT" --no-replace-objects -C "$REPO" "$@"
}

validate_git_inputs() {
  while IFS='=' read -r name _; do
    [[ "$name" == GIT_* ]] && fail UNEXPECTED_GIT_ENVIRONMENT
  done < <(/usr/bin/env)

  [[ -d "$REPO/.git" && ! -L "$REPO/.git" ]] || fail GIT_METADATA_UNSUPPORTED
  [[ -d "$REPO/.git/objects" && ! -L "$REPO/.git/objects" ]] || fail GIT_OBJECT_STORE_UNSAFE
  [[ ! -e "$REPO/.git/commondir" && ! -L "$REPO/.git/commondir" ]] || fail GIT_METADATA_UNSUPPORTED
  for metadata in \
    "$REPO/.git/objects/info/alternates" \
    "$REPO/.git/objects/info/http-alternates" \
    "$REPO/.git/objects/info/grafts" \
    "$REPO/.git/info/grafts"; do
    [[ ! -e "$metadata" && ! -L "$metadata" ]] || fail GIT_EXTERNAL_OBJECTS_REJECTED
  done

  local config_key config_keys
  config_keys="$(git_clean config --local --no-includes --name-only --list)" || fail GIT_CONFIG_READ_FAILED
  while IFS= read -r config_key; do
    case "$config_key" in
      include.*|includeif.*|core.usereplacerefs|core.alternaterefscommand|core.worktree|core.fsmonitor|core.hookspath|core.sparsecheckout|extensions.partialclone|remote.*.promisor)
        fail GIT_PROVENANCE_CONFIG_REJECTED
        ;;
    esac
  done <<< "$config_keys"

  [[ "$(git_clean rev-parse --show-object-format)" == sha1 ]] || fail GIT_OBJECT_FORMAT_UNSUPPORTED
  local replace_refs
  replace_refs="$(git_clean for-each-ref --format='%(refname)' refs/replace/)" || fail GIT_REFS_READ_FAILED
  [[ -z "$replace_refs" ]] || fail GIT_REPLACE_REFS_REJECTED
  [[ "$(git_clean rev-parse --verify "$APPROVED_SHA^{commit}")" == "$APPROVED_SHA" ]] || fail APPROVED_COMMIT_UNAVAILABLE
  [[ "$(git_clean rev-parse --verify HEAD)" == "$APPROVED_SHA" ]] || fail SOURCE_HEAD_MISMATCH
  [[ -z "$(git_clean status --porcelain --untracked-files=all)" ]] || fail SOURCE_TREE_DIRTY
  TREE_OID="$(git_clean rev-parse --verify "$APPROVED_SHA^{tree}")" || fail APPROVED_TREE_UNAVAILABLE
  [[ "$TREE_OID" =~ ^[0-9a-f]{40}$ ]] || fail APPROVED_TREE_INVALID
}

canonical_blob_oid() {
  local relative="$1" entry mode kind oid path metadata
  entry="$(git_clean ls-tree "$APPROVED_SHA" -- "$relative")" || fail APPROVED_TREE_UNAVAILABLE
  [[ "$entry" == *$'\t'* ]] || fail APPROVED_BLOB_MISSING
  IFS=$'\t' read -r metadata path <<< "$entry"
  read -r mode kind oid <<< "$metadata"
  [[ "$path" == "$relative" && "$kind" == blob && "$oid" =~ ^[0-9a-f]{40}$ ]] || fail APPROVED_BLOB_INVALID
  [[ "$mode" == 100644 || "$mode" == 100755 ]] || fail APPROVED_FILE_TYPE_UNSAFE
  printf '%s\n' "$oid"
}

verify_canonical_package() {
  local relative oid actual_oid
  for relative in "${PACKAGE_FILES[@]}"; do
    oid="$(canonical_blob_oid "$relative")"
    actual_oid="$(git_clean cat-file blob "$oid" | git_clean hash-object --stdin)" || fail APPROVED_BLOB_UNREADABLE
    [[ "$actual_oid" == "$oid" ]] || fail APPROVED_BLOB_HASH_MISMATCH
  done
}

mode=install
if [[ "${1-}" == '--verify-only' || "${1-}" == '--prepare-only' ]]; then
  mode="$1"
  shift
fi
[[ "$#" -eq 2 ]] || fail USAGE
REPO="$(/usr/bin/realpath -e -- "$1")" || fail SOURCE_PATH_INVALID
APPROVED_SHA="$2"
[[ "$APPROVED_SHA" =~ ^[0-9a-f]{40}$ ]] || fail APPROVED_SHA_INVALID
validate_git_inputs
verify_canonical_package

case "$mode" in
  --verify-only) printf '%s\n' 'SOURCE_PROVENANCE=PASS'; exit 0 ;;
  --prepare-only) printf '%s\n' 'SOURCE_PROVENANCE=PASS; ROOT_BOOTSTRAP=NOT_RUN'; exit 0 ;;
esac

"$SUDO" -n -- "$BOOTSTRAP" || fail ROOT_BOOTSTRAP_FAILED
