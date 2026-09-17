#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="$(tr -d '[:space:]' < "${ROOT_DIR}/VERSION")"
OUTPUT_DIR="${ROOT_DIR}/build/installer-real-server-test"
ASSET_BASE_URL=""
PRIVATE_KEY=""
PUBLIC_KEY=""
SKIP_DEPENDENCIES=0

usage() {
  cat >&2 <<'USAGE'
Usage: bash tools/build-installer-real-server-test.sh --private-key=/outside/project/test-private.pem --public-key=/outside/project/test-public.pem --asset-base-url=https://test-host/installer-test-assets [options]

Options:
  --private-key=PATH       Test signing key outside the repository (required)
  --public-key=PATH        Matching test public key (required)
  --asset-base-url=URL     HTTPS root where versioned test assets will be served (required)
  --output-dir=PATH        Candidate directory (default: build/installer-real-server-test)
  --skip-dependencies      Reuse already prepared local distribution dependencies
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

if [[ -z "${PRIVATE_KEY}" || ! -f "${PRIVATE_KEY}" || -z "${PUBLIC_KEY}" || ! -f "${PUBLIC_KEY}" ]]; then
  echo "Error: external test private and public keys are required." >&2
  exit 1
fi
if [[ "${ASSET_BASE_URL}" != https://* || "${ASSET_BASE_URL}" == *\?* || "${ASSET_BASE_URL}" == *'#'* ]]; then
  echo "Error: asset base URL must be HTTPS without query or fragment." >&2
  exit 1
fi

if [[ "${SKIP_DEPENDENCIES}" -eq 0 ]]; then
  bash "${ROOT_DIR}/tools/prepare-distribution-dependencies.sh"
fi
bash "${ROOT_DIR}/tools/build-distribution.sh"
ZIP_PATH="${ROOT_DIR}/build/tomos-${VERSION}.zip"
unzip -t "${ZIP_PATH}" >/dev/null
php "${ROOT_DIR}/tests/passkey_distribution_check.php"

for test in \
  tests/installer_phase1_check.php \
  tests/installer_phase2_check.php \
  tests/installer_phase3_check.php \
  tests/installer_phase4_check.php; do
  php "${ROOT_DIR}/${test}"
done

VERSIONED_URL="${ASSET_BASE_URL%/}/installer/releases/${VERSION}"
mkdir -p "${OUTPUT_DIR}/versioned"
rm -f \
  "${OUTPUT_DIR}/install.php" \
  "${OUTPUT_DIR}/latest.json" \
  "${OUTPUT_DIR}/SHA256SUMS" \
  "${OUTPUT_DIR}/versioned/tomos-${VERSION}.zip" \
  "${OUTPUT_DIR}/versioned/install-manifest.json" \
  "${OUTPUT_DIR}/versioned/install-manifest.sig"
cp "${ZIP_PATH}" "${OUTPUT_DIR}/versioned/tomos-${VERSION}.zip"

php "${ROOT_DIR}/tools/build-install-manifest.php" \
  --zip="${OUTPUT_DIR}/versioned/tomos-${VERSION}.zip" \
  --asset-url="${VERSIONED_URL}/tomos-${VERSION}.zip" \
  --output="${OUTPUT_DIR}/versioned/install-manifest.json" \
  --version="${VERSION}"
php "${ROOT_DIR}/tools/sign-install-manifest.php" \
  --manifest="${OUTPUT_DIR}/versioned/install-manifest.json" \
  --private-key="${PRIVATE_KEY}" \
  --public-key="${PUBLIC_KEY}" \
  --output="${OUTPUT_DIR}/versioned/install-manifest.sig"
php "${ROOT_DIR}/tools/verify-install-package.php" \
  --manifest="${OUTPUT_DIR}/versioned/install-manifest.json" \
  --signature="${OUTPUT_DIR}/versioned/install-manifest.sig" \
  --zip="${OUTPUT_DIR}/versioned/tomos-${VERSION}.zip" \
  --public-key="${PUBLIC_KEY}"
php "${ROOT_DIR}/tools/build-install-latest-pointer.php" \
  --version="${VERSION}" \
  --manifest-url="${VERSIONED_URL}/install-manifest.json" \
  --signature-url="${VERSIONED_URL}/install-manifest.sig" \
  --output="${OUTPUT_DIR}/latest.json"

php "${ROOT_DIR}/tools/build-installer.php" \
  --output="${OUTPUT_DIR}/install.php" \
  --pointer-url="${ASSET_BASE_URL%/}/latest.json" \
  --public-key-file="${PUBLIC_KEY}"

if grep -EIn -- "pointer_url' => 'https://tomoswords.org/download/install/latest.json|-----BEGIN (RSA )?PRIVATE KEY-----|fixture\.test|localhost|require(_once)?[[:space:]]*\(" "${OUTPUT_DIR}/install.php" >/dev/null 2>&1; then
  echo "Error: test installer contains production pointer, private key, fixture host, localhost, or external require." >&2
  exit 1
fi
if ! grep -Fq "${ASSET_BASE_URL%/}/latest.json" "${OUTPUT_DIR}/install.php"; then
  echo "Error: test pointer URL is not embedded in test installer." >&2
  exit 1
fi

(
  cd "${OUTPUT_DIR}"
  sha256sum "install.php" latest.json versioned/tomos-${VERSION}.zip versioned/install-manifest.json versioned/install-manifest.sig > SHA256SUMS
)

echo "Installer real-server test candidate: ${OUTPUT_DIR}"
echo "Version: ${VERSION}"
echo "Test pointer: ${ASSET_BASE_URL%/}/latest.json"
