#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="$(tr -d '[:space:]' < "${ROOT_DIR}/VERSION")"
OUTPUT_DIR="${ROOT_DIR}/build/release-candidate"
ASSET_BASE_URL="https://tomoswords.org/download/install"
PRIVATE_KEY=""
PUBLIC_KEY="${ROOT_DIR}/update/public-key.pem"
SKIP_DEPENDENCIES=0

usage() {
  cat >&2 <<'USAGE'
Usage: bash tools/build-installer-release-candidate.sh --private-key=/outside/project/key.pem [options]

Options:
  --private-key=PATH       Signing key outside the repository (required)
  --public-key=PATH        Verification key (default: update/public-key.pem)
  --asset-base-url=URL     Official versioned asset base (default: https://tomoswords.org/download/install)
  --output-dir=PATH        Candidate directory (default: build/release-candidate)
  --skip-dependencies      Use only for a local fixture rerun after preparation
USAGE
  exit 2
}

for argument in "$@"; do
  case "${argument}" in
    --private-key=*) PRIVATE_KEY="${argument#*=}" ;;
    --public-key=*) PUBLIC_KEY="${argument#*=}" ;;
    --asset-base-url=*) ASSET_BASE_URL="${argument#*=}" ;;
    --output-dir=*) OUTPUT_DIR="${argument#*=}" ;;
    --skip-dependencies) SKIP_DEPENDENCIES=1 ;;
    *) usage ;;
  esac
done

if [[ -z "${PRIVATE_KEY}" || ! -f "${PRIVATE_KEY}" ]]; then
  echo "Error: an external signing private key is required." >&2
  exit 1
fi
if [[ ! -f "${PUBLIC_KEY}" ]]; then
  echo "Error: verification public key is missing: ${PUBLIC_KEY}" >&2
  exit 1
fi
if [[ "${ASSET_BASE_URL}" != https://* || "${ASSET_BASE_URL}" == *\?* || "${ASSET_BASE_URL}" == *'#'* ]]; then
  echo "Error: asset base URL must be an HTTPS URL without query or fragment." >&2
  exit 1
fi

if [[ "${SKIP_DEPENDENCIES}" -eq 0 ]]; then
  bash "${ROOT_DIR}/tools/prepare-distribution-dependencies.sh"
fi

bash "${ROOT_DIR}/tools/build-distribution.sh"
VERSION="$(tr -d '[:space:]' < "${ROOT_DIR}/VERSION")"
ZIP_PATH="${ROOT_DIR}/build/tomos-${VERSION}.zip"
if [[ ! -f "${ZIP_PATH}" ]]; then
  echo "Error: distribution ZIP was not created." >&2
  exit 1
fi
unzip -t "${ZIP_PATH}" >/dev/null
php "${ROOT_DIR}/tests/passkey_distribution_check.php"

for test in \
  tests/installer_phase1_check.php \
  tests/installer_phase2_check.php \
  tests/installer_phase3_check.php \
  tests/installer_phase4_check.php; do
  php "${ROOT_DIR}/${test}"
done

php "${ROOT_DIR}/tools/build-installer.php"
php -l "${ROOT_DIR}/build/install.php" >/dev/null

VERSIONED_URL="${ASSET_BASE_URL%/}/v${VERSION}"
mkdir -p "${OUTPUT_DIR}"
rm -f \
  "${OUTPUT_DIR}/tomos-${VERSION}.zip" \
  "${OUTPUT_DIR}/install-manifest.json" \
  "${OUTPUT_DIR}/install-manifest.sig" \
  "${OUTPUT_DIR}/install.php" \
  "${OUTPUT_DIR}/latest.json" \
  "${OUTPUT_DIR}/SHA256SUMS"
cp "${ZIP_PATH}" "${OUTPUT_DIR}/tomos-${VERSION}.zip"
cp "${ROOT_DIR}/build/install.php" "${OUTPUT_DIR}/install.php"

php "${ROOT_DIR}/tools/build-install-manifest.php" \
  --zip="${OUTPUT_DIR}/tomos-${VERSION}.zip" \
  --asset-url="${VERSIONED_URL}/tomos-${VERSION}.zip" \
  --output="${OUTPUT_DIR}/install-manifest.json" \
  --version="${VERSION}"
php "${ROOT_DIR}/tools/sign-install-manifest.php" \
  --manifest="${OUTPUT_DIR}/install-manifest.json" \
  --private-key="${PRIVATE_KEY}" \
  --public-key="${PUBLIC_KEY}" \
  --output="${OUTPUT_DIR}/install-manifest.sig"
php "${ROOT_DIR}/tools/verify-install-package.php" \
  --manifest="${OUTPUT_DIR}/install-manifest.json" \
  --signature="${OUTPUT_DIR}/install-manifest.sig" \
  --zip="${OUTPUT_DIR}/tomos-${VERSION}.zip" \
  --public-key="${PUBLIC_KEY}"
php "${ROOT_DIR}/tools/build-install-latest-pointer.php" \
  --version="${VERSION}" \
  --manifest-url="${VERSIONED_URL}/install-manifest.json" \
  --signature-url="${VERSIONED_URL}/install-manifest.sig" \
  --output="${OUTPUT_DIR}/latest.json"

if grep -RInE -- '-----BEGIN (RSA )?PRIVATE KEY-----|fixture\.test|localhost|require(_once)?[[:space:]]*\(' "${OUTPUT_DIR}" >/dev/null 2>&1; then
  echo "Error: release candidate contains a private key, fixture reference, local host, or external require." >&2
  exit 1
fi
if ! grep -Fq 'https://tomoswords.org/download/install/latest.json' "${OUTPUT_DIR}/install.php"; then
  echo "Error: generated installer does not contain the production pointer URL." >&2
  exit 1
fi

(
  cd "${OUTPUT_DIR}"
  sha256sum "tomos-${VERSION}.zip" install-manifest.json install-manifest.sig install.php latest.json > SHA256SUMS
)

echo "Release candidate: ${OUTPUT_DIR}"
echo "Version: ${VERSION}"
