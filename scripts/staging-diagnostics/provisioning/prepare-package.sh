#!/usr/bin/env bash
set -euo pipefail
umask 077

# This helper runs as the Owner's unprivileged account. It emits only verified
# Git blob bytes into a new root-owned package; root Python starts only later.
readonly GIT=/usr/bin/git
readonly SUDO=/usr/bin/sudo
readonly BASE=/var/lib/wpcaregrid-staging-diagnostics-provisioning
readonly PACKAGE_FILES=(
  scripts/staging-diagnostics/dispatch.sh
  scripts/staging-diagnostics/provisioning/activation-check.sh
  scripts/staging-diagnostics/provisioning/diagnostics-shell.sh
  scripts/staging-diagnostics/read-m12-cache.php
  scripts/staging-diagnostics/provisioning/run-m12-readback.sh
  scripts/staging-diagnostics/provisioning/provision.py
  scripts/staging-diagnostics/provisioning/verify.py
  scripts/staging-diagnostics/provisioning/rollback.py
)

fail() {
  printf 'SOURCE_PREPARATION=FAILED code=%s\n' "$1" >&2
  exit 1
}

validate_root_directory() {
  local directory="$1" details owner group mode kind
  "$SUDO" test ! -L "$directory" || fail SOURCE_PARENT_UNSAFE
  details="$("$SUDO" stat -c '%u:%g:%a:%F' -- "$directory")" || fail SOURCE_PARENT_UNSAFE
  IFS=: read -r owner group mode kind <<< "$details"
  [[ "$owner" == 0 && "$group" == 0 && "$kind" == directory ]] || fail SOURCE_PARENT_UNSAFE
  (( (8#$mode & 0022) == 0 )) || fail SOURCE_PARENT_UNSAFE
}

git_clean() {
  /usr/bin/env -i \
    PATH=/usr/bin:/bin \
    HOME="$HOME" \
    GIT_CONFIG_NOSYSTEM=1 \
    GIT_CONFIG_GLOBAL=/dev/null \
    GIT_NO_REPLACE_OBJECTS=1 \
    GIT_NO_LAZY_FETCH=1 \
    GIT_OPTIONAL_LOCKS=0 \
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
      include.*|includeif.*|core.usereplacerefs|core.alternaterefscommand|extensions.partialclone|remote.*.promisor)
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
  TREE_OID="$(git_clean rev-parse --verify "$APPROVED_SHA^{tree}")" || fail APPROVED_TREE_UNAVAILABLE
  [[ "$TREE_OID" =~ ^[0-9a-f]{40}$ ]] || fail APPROVED_TREE_INVALID
}

canonical_blob_oid() {
  local relative="$1" entry mode kind oid path
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

if [[ "${1-}" == '--verify-only' ]]; then
  [[ "$#" -eq 3 ]] || fail USAGE
  REPO="$(/usr/bin/realpath -e -- "$2")"
  APPROVED_SHA="$3"
  [[ "$APPROVED_SHA" =~ ^[0-9a-f]{40}$ ]] || fail APPROVED_SHA_INVALID
  validate_git_inputs
  verify_canonical_package
  printf '%s\n' 'SOURCE_PROVENANCE=PASS'
  exit 0
fi

prepare_only=0
if [[ "${1-}" == '--prepare-only' ]]; then
  prepare_only=1
  shift
fi
if (( prepare_only )); then
  [[ "$#" -eq 2 ]] || fail USAGE
else
  [[ "$#" -eq 3 ]] || fail USAGE
fi
REPO="$(/usr/bin/realpath -e -- "$1")"
APPROVED_SHA="$2"
if (( ! prepare_only )); then PUBLIC_KEY="$3"; fi
[[ "$APPROVED_SHA" =~ ^[0-9a-f]{40}$ ]] || fail APPROVED_SHA_INVALID
if (( ! prepare_only )); then
  [[ -f "$PUBLIC_KEY" && ! -L "$PUBLIC_KEY" ]] || fail PUBLIC_KEY_FILE_UNSAFE
fi

validate_git_inputs
verify_canonical_package

readonly STAGE="$BASE/source/$APPROVED_SHA"
if "$SUDO" test -e "$STAGE" || "$SUDO" test -L "$STAGE"; then
  fail SOURCE_STAGE_ALREADY_EXISTS
fi

for directory in / /var /var/lib; do
  validate_root_directory "$directory"
done

for directory in "$BASE" "$BASE/source"; do
  if "$SUDO" test -L "$directory"; then fail SOURCE_PARENT_UNSAFE; fi
  if "$SUDO" test -e "$directory"; then
    [[ "$("$SUDO" stat -c '%u:%g:%a:%F' -- "$directory")" == '0:0:700:directory' ]] || fail SOURCE_PARENT_UNSAFE
  else
    "$SUDO" install -d -o root -g root -m 0700 -- "$directory"
  fi
done
"$SUDO" install -d -o root -g root -m 0700 -- "$STAGE"
[[ "$("$SUDO" stat -c '%u:%g:%a:%F' -- "$STAGE")" == '0:0:700:directory' ]] || fail SOURCE_STAGE_UNSAFE

manifest="$(/usr/bin/mktemp)"
trap 'rm -f -- "$manifest"' EXIT
printf 'commit=%s\ntree=%s\n' "$APPROVED_SHA" "$TREE_OID" > "$manifest"

for relative in "${PACKAGE_FILES[@]}"; do
  oid="$(canonical_blob_oid "$relative")"
  destination="$STAGE/$relative"
  "$SUDO" install -d -o root -g root -m 0700 -- "$(/usr/bin/dirname -- "$destination")"
  git_clean cat-file blob "$oid" | "$SUDO" tee "$destination" >/dev/null
  "$SUDO" chown root:root -- "$destination"
  "$SUDO" chmod 0444 -- "$destination"

  staged_oid="$("$SUDO" cat -- "$destination" | git_clean hash-object --stdin)" || fail STAGED_BLOB_UNREADABLE
  [[ "$staged_oid" == "$oid" ]] || fail STAGED_BLOB_IDENTITY_MISMATCH
  digest="$("$SUDO" sha256sum -- "$destination" | /usr/bin/cut -d' ' -f1)"
  [[ "$("$SUDO" stat -c '%u:%g:%a:%F' -- "$destination")" == '0:0:444:regular file' ]] || fail STAGED_FILE_UNSAFE
  printf '%s %s  %s\n' "$oid" "$digest" "$relative" >> "$manifest"
done

"$SUDO" install -o root -g root -m 0444 -- "$manifest" "$STAGE/.wpcaregrid-provenance"
[[ "$("$SUDO" stat -c '%u:%g:%a:%F' -- "$STAGE/.wpcaregrid-provenance")" == '0:0:444:regular file' ]] || fail STAGED_MANIFEST_UNSAFE
[[ -z "$("$SUDO" find "$STAGE" -type l -print -quit)" ]] || fail STAGED_SYMLINK_REJECTED

if (( prepare_only )); then
  printf '%s\n' 'SOURCE_PREPARATION=PACKAGE_STAGED'
  exit 0
fi

# All canonical blob and staged-file checks must finish before this first root
# Python invocation. Keep the public key path and source SHA as fixed arguments.
"$SUDO" /usr/bin/python3 -I -S "$STAGE/scripts/staging-diagnostics/provisioning/provision.py" \
  --approved-source-sha "$APPROVED_SHA" \
  --public-key-file "$PUBLIC_KEY"
"$SUDO" /usr/bin/python3 -I -S "$STAGE/scripts/staging-diagnostics/provisioning/verify.py" \
  --approved-source-sha "$APPROVED_SHA" \
  --public-key-file "$PUBLIC_KEY"
