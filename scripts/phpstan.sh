#!/usr/bin/env bash
# Static analysis (PHPStan) of the PHP backend in system/backend/php.
# Configuration: system/backend/php/phpstan.neon; known errors that existed
# when the check was added live in system/backend/php/phpstan-baseline.neon,
# so only NEW errors fail.
#
# PHPStan is not in the committed vendor/ directory. This script uses the
# official phpstan.phar from the phpstan/phpstan GitHub release pinned below,
# kept in a cache directory outside the repo, and refuses to run it unless its
# SHA-256 matches the pin. CI (.github/workflows/phpstan.yml) runs this same
# script, so local and CI results come from the same binary.
#
# Usage (from the repo root):
#   npm run lint:php                      analyse; exit 1 on new errors
#   npm run lint:php -- --error-format=raw
#   npm run lint:php:baseline             regenerate phpstan-baseline.neon
#
# Environment:
#   PHPSTAN_CACHE_DIR  where the phar is kept (default ~/.cache/haxcms-phpstan)
#   PHPSTAN_PHAR       use an already-downloaded phar (still checksum-verified)
set -euo pipefail

# To upgrade: change both values together. The checksum is the SHA-256 of
# phpstan.phar attached to https://github.com/phpstan/phpstan/releases/tag/<version>
# (the same file is committed at that tag of phpstan/phpstan).
PHPSTAN_VERSION="2.3.2"
PHPSTAN_SHA256="0ded687c44b1f9d0346f7f00fa505212b018efe532b2b18e3a72f6e16feb32be"

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cache_dir="${PHPSTAN_CACHE_DIR:-${XDG_CACHE_HOME:-${HOME}/.cache}/haxcms-phpstan}"
phar="${PHPSTAN_PHAR:-${cache_dir}/phpstan-${PHPSTAN_VERSION}.phar}"

sha256_of() {
  php -r 'echo hash_file("sha256", $argv[1]);' "$1"
}

if [ ! -f "${phar}" ]; then
  if [ -n "${PHPSTAN_PHAR:-}" ]; then
    echo "PHPSTAN_PHAR=${PHPSTAN_PHAR} does not exist" >&2
    exit 2
  fi
  mkdir -p "${cache_dir}"
  tmp="$(mktemp "${cache_dir}/phpstan.XXXXXX")"
  trap 'rm -f "${tmp}"' EXIT
  echo "Downloading PHPStan ${PHPSTAN_VERSION}..." >&2
  curl -fsSL --retry 3 -o "${tmp}" \
    "https://github.com/phpstan/phpstan/releases/download/${PHPSTAN_VERSION}/phpstan.phar"
  actual="$(sha256_of "${tmp}")"
  if [ "${actual}" != "${PHPSTAN_SHA256}" ]; then
    echo "phpstan.phar checksum mismatch: expected ${PHPSTAN_SHA256}, got ${actual}" >&2
    exit 2
  fi
  mv "${tmp}" "${phar}"
  trap - EXIT
fi

actual="$(sha256_of "${phar}")"
if [ "${actual}" != "${PHPSTAN_SHA256}" ]; then
  echo "${phar} is not PHPStan ${PHPSTAN_VERSION} (checksum ${actual}); delete it and rerun" >&2
  exit 2
fi

cd "${root}/system/backend/php"
exec php "${phar}" analyse --no-progress --memory-limit=2G "$@"
