#!/usr/bin/env bash
# Mutation testing (Infection) of the saveOutline write path in
# system/backend/php, the PHP side of haxcms-nodejs's Stryker run.
# Configuration: system/backend/php/infection.json5 (which tests run) and the
# file list below (which code is mutated). Report-only: no score threshold.
#
# Infection is not in the committed vendor/ directory. Like scripts/phpstan.sh,
# this script uses the official infection.phar from the infection/infection
# GitHub release pinned below (BSD-3-Clause, a dev tool that never ships),
# kept in a cache directory outside the repo, and refuses to run it unless its
# SHA-256 matches the pin. CI (.github/workflows/mutation-testing.yml) runs
# this same script.
#
# Needs a coverage driver: PCOV (preferred) or Xdebug.
#
# Usage (from the repo root):
#   npm run test:mutation
#   npm run test:mutation -- --threads=4
#
# Environment:
#   INFECTION_CACHE_DIR  where the phar is kept (default ~/.cache/haxcms-infection)
#   INFECTION_PHAR       use an already-downloaded phar (still checksum-verified)
set -euo pipefail

# To upgrade: change both values together. The checksum is the SHA-256 of
# infection.phar attached to https://github.com/infection/infection/releases/tag/<version>
INFECTION_VERSION="0.35.10"
INFECTION_SHA256="aa8ac8aeb3d7b96d4e66119908c1654c6bbf29472ef8bf1d1e84900fd01d6b31"

# The code under mutation: the site.json / page write helpers saveOutline calls
# (JSONOutlineSchema, JSONOutlineSchemaItem), as in haxcms-nodejs
# stryker.config.json. The route itself (lib/operations/saveOutline.php) is
# not in the list: it is a trait (OperationsRouteSaveOutline), and Infection
# cannot mutate traits (it needs a class to reflect; checked with 0.29 to
# 0.35). Its tests still run against every mutant here.
MUTATE=(lib/JSONOutlineSchema.php lib/JSONOutlineSchemaItem.php)

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cache_dir="${INFECTION_CACHE_DIR:-${XDG_CACHE_HOME:-${HOME}/.cache}/haxcms-infection}"
phar="${INFECTION_PHAR:-${cache_dir}/infection-${INFECTION_VERSION}.phar}"

sha256_of() {
  php -r 'echo hash_file("sha256", $argv[1]);' "$1"
}

if [ ! -f "${phar}" ]; then
  if [ -n "${INFECTION_PHAR:-}" ]; then
    echo "INFECTION_PHAR=${INFECTION_PHAR} does not exist" >&2
    exit 2
  fi
  mkdir -p "${cache_dir}"
  tmp="$(mktemp "${cache_dir}/infection.XXXXXX")"
  trap 'rm -f "${tmp}"' EXIT
  echo "Downloading Infection ${INFECTION_VERSION}..." >&2
  curl -fsSL --retry 3 -o "${tmp}" \
    "https://github.com/infection/infection/releases/download/${INFECTION_VERSION}/infection.phar"
  actual="$(sha256_of "${tmp}")"
  if [ "${actual}" != "${INFECTION_SHA256}" ]; then
    echo "infection.phar checksum mismatch: expected ${INFECTION_SHA256}, got ${actual}" >&2
    exit 2
  fi
  mv "${tmp}" "${phar}"
  trap - EXIT
fi

actual="$(sha256_of "${phar}")"
if [ "${actual}" != "${INFECTION_SHA256}" ]; then
  echo "${phar} is not Infection ${INFECTION_VERSION} (checksum ${actual}); delete it and rerun" >&2
  exit 2
fi

cd "${root}/system/backend/php"
exec php "${phar}" --no-progress --no-interaction "$@" "${MUTATE[@]}"
