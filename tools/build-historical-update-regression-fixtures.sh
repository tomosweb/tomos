#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUTPUT_DIR="${1:-}"
if [[ -z "${OUTPUT_DIR}" || "${OUTPUT_DIR}" == "/" ]]; then
  echo "Usage: $0 OUTPUT_DIR" >&2
  exit 2
fi
if [[ -e "${OUTPUT_DIR}" ]]; then
  echo "Output directory already exists: ${OUTPUT_DIR}" >&2
  exit 1
fi
mkdir -p "${OUTPUT_DIR}"

V061_URL="https://github.com/tomosweb/tomos/releases/download/v0.6.1/tomos-0.6.1.zip"
V061_SHA256="203e9b05c44a374fb3c8c7107d9729f59de0bbe9ebff73b2c87b5d174c146c92"
MIGRATION_REF="ab48d0f256c79e9bb2d92b1c0aa62fd7d52ed7a2"
BOOTSTRAP_REF="286a881b79fd1e79fca402a791367cb9cde75e16"
V061_DIST="${OUTPUT_DIR}/tomos-0.6.1.zip"
MIGRATION_PACKAGE="${OUTPUT_DIR}/tomos-update-0.6.2-migration.zip"
BOOTSTRAP_PACKAGE="${OUTPUT_DIR}/tomos-update-0.6.2-legacy-bootstrap.zip"

sha256_file() {
  shasum -a 256 "$1" | awk '{print $1}'
}

WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/tomos-historical-update.XXXXXX")"
MIGRATION_SOURCE="${WORK_DIR}/migration-source"
BOOTSTRAP_SOURCE="${WORK_DIR}/bootstrap-source"
PRIVATE_KEY="${WORK_DIR}/test-signing-key.pem"
PUBLIC_KEY="${OUTPUT_DIR}/historical-test-public-key.pem"

cleanup() {
  git -C "${ROOT_DIR}" worktree remove --force "${MIGRATION_SOURCE}" >/dev/null 2>&1 || true
  git -C "${ROOT_DIR}" worktree remove --force "${BOOTSTRAP_SOURCE}" >/dev/null 2>&1 || true
  rm -rf "${WORK_DIR}"
}
trap cleanup EXIT

curl --fail --location --silent --show-error "${V061_URL}" --output "${V061_DIST}"
[[ "$(sha256_file "${V061_DIST}")" == "${V061_SHA256}" ]]
git -C "${ROOT_DIR}" cat-file -e "${MIGRATION_REF}^{commit}"
git -C "${ROOT_DIR}" cat-file -e "${BOOTSTRAP_REF}^{commit}"
git -C "${ROOT_DIR}" worktree add --detach "${MIGRATION_SOURCE}" "${MIGRATION_REF}" >/dev/null
git -C "${ROOT_DIR}" worktree add --detach "${BOOTSTRAP_SOURCE}" "${BOOTSTRAP_REF}" >/dev/null

for source in "${MIGRATION_SOURCE}" "${BOOTSTRAP_SOURCE}"; do
  mkdir -p "${source}/core/webauthn"
  rsync -a "${ROOT_DIR}/core/webauthn/vendor/" "${source}/core/webauthn/vendor/"
done

openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out "${PRIVATE_KEY}" 2>/dev/null
chmod 600 "${PRIVATE_KEY}"
openssl pkey -in "${PRIVATE_KEY}" -pubout -out "${PUBLIC_KEY}" 2>/dev/null

php "${MIGRATION_SOURCE}/tools/build-update-package.php" \
  --from=0.6.1 --version=0.6.2 \
  --private-key="${PRIVATE_KEY}" \
  --output="${MIGRATION_PACKAGE}" \
  --file=VERSION \
  --file=core/App.php \
  --file=core/ContentSecurityPolicy.php \
  --file=core/InstalledIntegrityVerifier.php \
  --file=core/ThemePackagePolicy.php \
  --file=core/ThemeRules.php \
  --file=core/ThemeValidator.php \
  --file=core/UpdateReleaseProvider.php \
  --file=core/UpdateService.php \
  --file=core/UpdaterSelfUpdate.php \
  --file=core/required-installed-files.txt \
  --file=docs/theme/theme-rules.json

LEGACY_REQUIRED_LIST="${WORK_DIR}/legacy-required-files.txt"
printf 'VERSION\ncore/required-installed-files.txt\n' > "${LEGACY_REQUIRED_LIST}"
php "${BOOTSTRAP_SOURCE}/tools/build-update-package.php" \
  --from=0.6.1 --version=0.6.2 \
  --private-key="${PRIVATE_KEY}" \
  --output="${BOOTSTRAP_PACKAGE}" \
  --bootstrap-legacy-required-list="${LEGACY_REQUIRED_LIST}" \
  --file=VERSION \
  --file=core/App.php \
  --file=core/ContentSecurityPolicy.php \
  --file=core/ThemePackagePolicy.php \
  --file=core/ThemeRules.php \
  --file=core/ThemeValidator.php \
  --file=core/UpdateService.php \
  --file=core/UpdaterSelfUpdate.php \
  --file=core/required-installed-files.txt \
  --file=docs/theme/theme-rules.json

MIGRATION_SHA256="$(sha256_file "${MIGRATION_PACKAGE}")"
BOOTSTRAP_SHA256="$(sha256_file "${BOOTSTRAP_PACKAGE}")"
PUBLIC_KEY_SHA256="$(sha256_file "${PUBLIC_KEY}")"
python3 - "${OUTPUT_DIR}/provenance.json" "${V061_SHA256}" "${MIGRATION_SHA256}" "${BOOTSTRAP_SHA256}" "${PUBLIC_KEY_SHA256}" <<'PY'
import json
import sys
from pathlib import Path

output, v061_sha, migration_sha, bootstrap_sha, public_key_sha = sys.argv[1:]
data = {
    "v061_distribution": {
        "url": "https://github.com/tomosweb/tomos/releases/download/v0.6.1/tomos-0.6.1.zip",
        "source": "public GitHub Release v0.6.1",
        "sha256": v061_sha,
    },
    "migration_package": {
        "source_ref": "ab48d0f256c79e9bb2d92b1c0aa62fd7d52ed7a2",
        "intended_generation": "post-incident pending-runtime migration regression",
        "sha256": migration_sha,
    },
    "legacy_bootstrap_package": {
        "source_ref": "286a881b79fd1e79fca402a791367cb9cde75e16",
        "intended_generation": "legacy required-file bootstrap regression",
        "sha256": bootstrap_sha,
    },
    "historical_test_public_key": {
        "source": "ephemeral CI fixture key; private key is not retained",
        "sha256": public_key_sha,
    },
}
Path(output).write_text(json.dumps(data, indent=2) + "\n")
PY

echo "Historical fixtures: ${OUTPUT_DIR}"
