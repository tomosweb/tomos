#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEBAUTHN_DIR="${ROOT_DIR}/core/webauthn"

if [[ ! -f "${WEBAUTHN_DIR}/composer.json" || ! -f "${WEBAUTHN_DIR}/composer.lock" ]]; then
  echo "Error: core/webauthn/composer.json or composer.lock is missing."
  exit 1
fi

if ! command -v composer >/dev/null 2>&1; then
  echo "Error: Composer 2 is required to prepare the distribution dependencies."
  echo "Install Composer in the release build environment, then rerun this command."
  exit 1
fi

echo "Validating locked WebAuthn dependency definition..."
composer validate \
  --working-dir="${WEBAUTHN_DIR}" \
  --no-check-publish

echo "Installing locked WebAuthn runtime..."
composer install \
  --working-dir="${WEBAUTHN_DIR}" \
  --no-dev \
  --prefer-dist \
  --no-interaction \
  --no-progress \
  --no-scripts \
  --classmap-authoritative

for required in \
  "${WEBAUTHN_DIR}/vendor/autoload.php" \
  "${WEBAUTHN_DIR}/vendor/lbuchs/webauthn/src/WebAuthn.php"; do
  if [[ ! -f "${required}" ]]; then
    echo "Error: Composer completed but required WebAuthn file is missing: ${required#${ROOT_DIR}/}"
    exit 1
  fi
done

echo "WebAuthn runtime is ready for distribution build."
