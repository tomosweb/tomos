#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MODE="check"
OUTPUT_DIR="${ROOT_DIR}/build/local-release"
PRIVATE_KEY=""
PUBLIC_KEY="${ROOT_DIR}/update/public-key.pem"
ALLOW_DIRTY=0
SKIP_FETCH=0
SKIP_MATRIX=0
REPORT_FILE=""
TMP_DIR=""

usage() {
  cat <<'USAGE'
Usage:
  bash tools/release-local.sh [options]

Modes:
  --mode=check       Local CI-equivalent verification with an ephemeral signing key (default)
  --mode=release     Formal local release gate. Requires external signing key and PHP matrix.

Options:
  --private-key=PATH External production signing key. Required for --mode=release.
  --public-key=PATH  Verification public key (default: update/public-key.pem).
  --output-dir=PATH  Output root (default: build/local-release).
  --report=PATH      Markdown report path (default: <output-dir>/release-report.md).
  --allow-dirty      Allow a dirty working tree (never recommended for formal release).
  --skip-fetch       Do not fetch immutable public baselines.
  --skip-matrix      Skip multi-PHP compatibility checks (check mode only).
  -h, --help         Show help.

PHP matrix:
  Set TOMOS_PHP_MATRIX to space-separated PHP binaries, for example:
    TOMOS_PHP_MATRIX="php74 php80 php82 php85"

  Formal release mode requires four runtimes whose reported major.minor versions are:
    7.4 8.0 8.2 8.5

Examples:
  bash tools/release-local.sh

  TOMOS_PHP_MATRIX="php74 php80 php82 php85" \
  bash tools/release-local.sh --mode=release \
    --private-key=/secure/outside-project/install-signing-private.pem
USAGE
}

for argument in "$@"; do
  case "${argument}" in
    --mode=*) MODE="${argument#*=}" ;;
    --private-key=*) PRIVATE_KEY="${argument#*=}" ;;
    --public-key=*) PUBLIC_KEY="${argument#*=}" ;;
    --output-dir=*) OUTPUT_DIR="${argument#*=}" ;;
    --report=*) REPORT_FILE="${argument#*=}" ;;
    --allow-dirty) ALLOW_DIRTY=1 ;;
    --skip-fetch) SKIP_FETCH=1 ;;
    --skip-matrix) SKIP_MATRIX=1 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Error: unknown option: ${argument}" >&2; usage >&2; exit 2 ;;
  esac
done

if [[ "${MODE}" != "check" && "${MODE}" != "release" ]]; then
  echo "Error: --mode must be check or release." >&2
  exit 2
fi

mkdir -p "${OUTPUT_DIR}"
if [[ -z "${REPORT_FILE}" ]]; then
  REPORT_FILE="${OUTPUT_DIR}/release-report.md"
fi
mkdir -p "$(dirname "${REPORT_FILE}")"

TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/tomos-release-local.XXXXXX")"
cleanup() {
  rm -rf "${TMP_DIR}"
}
trap cleanup EXIT INT TERM

cd "${ROOT_DIR}"

# macOS compatibility for existing Linux-oriented release scripts.
if ! command -v sha256sum >/dev/null 2>&1; then
  if ! command -v shasum >/dev/null 2>&1; then
    echo "Error: sha256sum or shasum is required." >&2
    exit 1
  fi
  mkdir -p "${TMP_DIR}/bin"
  cat > "${TMP_DIR}/bin/sha256sum" <<'SHIM'
#!/usr/bin/env bash
set -euo pipefail
if [[ "${1:-}" == "-c" ]]; then
  shift
  exec shasum -a 256 -c "$@"
fi
exec shasum -a 256 "$@"
SHIM
  chmod +x "${TMP_DIR}/bin/sha256sum"
  export PATH="${TMP_DIR}/bin:${PATH}"
fi

require_command() {
  if ! command -v "$1" >/dev/null 2>&1; then
    echo "Error: required command is missing: $1" >&2
    exit 1
  fi
}

for command in bash php git composer openssl unzip zip rsync gh jq; do
  require_command "${command}"
done

VERSION="$(tr -d '[:space:]' < "${ROOT_DIR}/VERSION")"
HEAD_SHA="$(git -C "${ROOT_DIR}" rev-parse HEAD)"
BRANCH="$(git -C "${ROOT_DIR}" branch --show-current || true)"
STARTED_AT="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"

# Finder may recreate .DS_Store anywhere under the repository. Remove these
# harmless macOS metadata files before enforcing the clean-tree release gate.
find "${ROOT_DIR}" -name .DS_Store -type f -delete 2>/dev/null || true

if [[ "${ALLOW_DIRTY}" -eq 0 ]] && [[ -n "$(git -C "${ROOT_DIR}" status --porcelain)" ]]; then
  echo "Error: working tree is dirty. Commit/stash changes or use --allow-dirty for non-release investigation." >&2
  exit 1
fi
if [[ "${MODE}" == "release" && "${ALLOW_DIRTY}" -ne 0 ]]; then
  echo "Error: --allow-dirty is forbidden in release mode." >&2
  exit 1
fi
if [[ "${MODE}" == "release" && ( -z "${PRIVATE_KEY}" || ! -f "${PRIVATE_KEY}" ) ]]; then
  echo "Error: --mode=release requires --private-key outside the repository." >&2
  exit 1
fi
if [[ "${MODE}" == "release" ]]; then
  PRIVATE_KEY_DIR="$(cd "$(dirname "${PRIVATE_KEY}")" && pwd -P)"
  PRIVATE_KEY_REAL="${PRIVATE_KEY_DIR}/$(basename "${PRIVATE_KEY}")"
  case "${PRIVATE_KEY_REAL}" in
    "${ROOT_DIR}"|"${ROOT_DIR}/"*)
      echo "Error: production signing key must be outside the repository: ${PRIVATE_KEY_REAL}" >&2
      exit 1
      ;;
  esac
fi
if [[ ! -f "${PUBLIC_KEY}" ]]; then
  echo "Error: public verification key is missing: ${PUBLIC_KEY}" >&2
  exit 1
fi

LOG_DIR="${OUTPUT_DIR}/logs"
ARTIFACT_DIR="${OUTPUT_DIR}/artifacts"
INSTALLER_DIR="${ARTIFACT_DIR}/installer"
UPDATE_DIR="${ARTIFACT_DIR}/test-update"
PRODUCTION_UPDATE_DIR="${ARTIFACT_DIR}/update"
mkdir -p "${LOG_DIR}" "${INSTALLER_DIR}" "${UPDATE_DIR}" "${PRODUCTION_UPDATE_DIR}"

STEPS=()
run_step() {
  local id="$1"
  shift
  local log="${LOG_DIR}/${id}.log"
  echo
  echo "==> ${id}"
  if "$@" > >(tee "${log}") 2>&1; then
    STEPS+=("${id}|PASS")
  else
    STEPS+=("${id}|FAIL")
    write_report "FAIL" "${id}"
    echo "Release gate failed at: ${id}" >&2
    exit 1
  fi
}

write_report() {
  local overall="$1"
  local blocked_at="${2:-}"
  local ended_at
  ended_at="$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
  {
    echo "# Tomos Local Release Report"
    echo
    printf '%s\n' "- Result: **${overall}**"
    printf '%s%s%s\n' '- Mode: `' "${MODE}" '`'
    printf '%s%s%s\n' '- Version: `' "${VERSION}" '`'
    printf '%s%s%s\n' '- Branch: `' "${BRANCH:-detached}" '`'
    printf '%s%s%s\n' '- HEAD: `' "${HEAD_SHA}" '`'
    printf '%s%s%s\n' '- Started: `' "${STARTED_AT}" '`'
    printf '%s%s%s\n' '- Finished: `' "${ended_at}" '`'
    if [[ -n "${blocked_at}" ]]; then
      printf '%s%s%s\n' '- Blocked at: `' "${blocked_at}" '`'
    fi
    echo
    echo "## Automated gates"
    echo
    echo "| Gate | Result |"
    echo "| --- | --- |"
    local item
    for item in "${STEPS[@]:-}"; do
      [[ -n "${item}" ]] || continue
      echo "| ${item%%|*} | ${item##*|} |"
    done
    echo
    echo "## Artifacts"
    echo
    if [[ -d "${ARTIFACT_DIR}" ]]; then
      find "${ARTIFACT_DIR}" -type f -print | sed "s#^${ROOT_DIR}/##" | sort | sed 's/^/- `/; s/$/`/'
    fi
    echo
    echo "## Human gates before PUBLIC RELEASE COMPLETE"
    echo
    echo "- [ ] Review AI-generated release summary against the final diff."
    echo "- [ ] Pre-release production smoke test completed and production restored."
    echo "- [ ] Official release notes / News / usage docs updated as required."
    echo "- [ ] GitHub Release/tag/version consistency checked."
    echo "- [ ] Production-signed artifacts uploaded without replacing an immutable same-version asset."
    printf '%s%s%s\n' '- [ ] Official installer mirror synchronized and public `latest.json` points to `' "${VERSION}" '`.'
    echo "- [ ] Published artifacts re-downloaded from public URLs and SHA/signature/manifest verified."
    echo "- [ ] Supported old-version Browser Update completed with the released artifact."
    echo "- [ ] Updated production site starts normally; protected data retained."
    echo
    printf '%s\n' 'A local PASS is not by itself `PUBLIC RELEASE COMPLETE`; the human/public artifact gates above remain mandatory.'
  } > "${REPORT_FILE}"
}

if [[ "${SKIP_FETCH}" -eq 0 ]]; then
  run_step fetch-public-baselines bash -c '
set -euo pipefail
git fetch --no-tags https://github.com/tomosweb/tomos.git e022a6396b86d00e80181c44611f45ce85a443ab
git fetch --no-tags https://github.com/tomosweb/tomos.git refs/tags/v0.5.2:refs/tags/tomos-public-v0.5.2
git fetch --no-tags https://github.com/tomosweb/tomos.git refs/tags/v0.7.0:refs/tags/tomos-public-v0.7.0
git fetch --no-tags https://github.com/tomosweb/tomos.git refs/tags/v1.0.5:refs/tags/tomos-public-v1.0.5
git fetch --no-tags https://github.com/tomosweb/tomos.git refs/tags/v1.0.6:refs/tags/tomos-public-v1.0.6
git fetch --no-tags https://github.com/tomosweb/tomos.git refs/tags/v1.0.7:refs/tags/tomos-public-v1.0.7
'
fi
run_step php-lint bash -c 'set -euo pipefail; while IFS= read -r -d "" file; do php -l "$file" >/dev/null; done < <(find . -path "./core/webauthn/vendor" -prune -o -name "*.php" -print0)' 

run_step prepare-distribution-dependencies bash "${ROOT_DIR}/tools/prepare-distribution-dependencies.sh"
run_step build-distribution bash "${ROOT_DIR}/tools/build-distribution.sh"

run_step core-regression bash -c '
set -euo pipefail
for test in tests/*_check.php; do
  case "$test" in
    tests/installer_phase5_check.php|tests/public_artifact_update_acceptance_check.php|tests/update_v073_to_v090_migration_check.php)
      continue
      ;;
    tests/update_self_update_no_proc_open_check.php)
      php -d disable_functions=proc_open "$test"
      ;;
    *)
      php "$test"
      ;;
  esac
done
'

run_step release-transition env TOMOS_RELEASE_TRANSITION_ARTIFACT_DIR="${UPDATE_DIR}" php "${ROOT_DIR}/tests/release_transition_check.php"
run_step test-update-zip unzip -t "${UPDATE_DIR}/tomos-update-1.0.7-to-${VERSION}-TEST.zip"
run_step test-update-checksums bash -c "cd \"${UPDATE_DIR}\" && sha256sum -c SHA256SUMS"

if [[ "${MODE}" == "release" ]]; then
  PRODUCTION_UPDATE_ZIP="${PRODUCTION_UPDATE_DIR}/tomos-update-1.0.7-to-${VERSION}.zip"
  LEGACY_REQUIRED_LIST="${TMP_DIR}/required-installed-files-1.0.7.txt"
  run_step production-update-legacy-required-list bash -c "git -C \"${ROOT_DIR}\" show refs/tags/tomos-public-v1.0.7:core/required-installed-files.txt > \"${LEGACY_REQUIRED_LIST}\" && test -s \"${LEGACY_REQUIRED_LIST}\""
  run_step production-update-package php "${ROOT_DIR}/tools/build-update-package.php" \
    --from=1.0.7 \
    --version="${VERSION}" \
    --private-key="${PRIVATE_KEY}" \
    --output="${PRODUCTION_UPDATE_ZIP}" \
    --bootstrap-legacy-required-list="${LEGACY_REQUIRED_LIST}" \
    --from-ref=refs/tags/tomos-public-v1.0.7 \
    --to-ref=HEAD
  run_step production-update-zip unzip -t "${PRODUCTION_UPDATE_ZIP}"
  run_step production-update-candidate-v107-acceptance env \
    TOMOS_CANDIDATE_UPDATE_PACKAGE="${PRODUCTION_UPDATE_ZIP}" \
    TOMOS_CANDIDATE_FROM="1.0.7" \
    TOMOS_CANDIDATE_TARGET="${VERSION}" \
    php "${ROOT_DIR}/tests/public_artifact_update_acceptance_check.php"
  run_step production-update-signature php -r '
$zip = new ZipArchive();
if ($zip->open($argv[1]) !== true) { fwrite(STDERR, "cannot open update zip\n"); exit(1); }
$manifest = $zip->getFromName("manifest.json");
$signature = $zip->getFromName("manifest.sig");
$zip->close();
$publicKey = file_get_contents($argv[2]);
if (!is_string($manifest) || !is_string($signature) || !is_string($publicKey)
    || openssl_verify($manifest, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1
) {
    fwrite(STDERR, "production update signature verification failed\n");
    exit(1);
}
echo "production_update_signature: OK\n";
' "${PRODUCTION_UPDATE_ZIP}" "${PUBLIC_KEY}"
  run_step production-update-checksum bash -c "cd \"${PRODUCTION_UPDATE_DIR}\" && sha256sum \"$(basename "${PRODUCTION_UPDATE_ZIP}")\" > SHA256SUMS && sha256sum -c SHA256SUMS"
fi

if [[ "${SKIP_MATRIX}" -eq 0 ]]; then
  MATRIX="${TOMOS_PHP_MATRIX:-}"
  if [[ -z "${MATRIX}" ]]; then
    if [[ "${MODE}" == "release" ]]; then
      echo "Error: release mode requires TOMOS_PHP_MATRIX with PHP 7.4, 8.0, 8.2 and 8.5 binaries." >&2
      write_report "BLOCKED" "php-compatibility-matrix"
      exit 1
    fi
    STEPS+=("php-compatibility-matrix|PARTIAL (current PHP only)")
    run_step passkey-current-php php "${ROOT_DIR}/tests/passkey_php_compatibility_check.php"
    run_step imageprocessor-current-php php -d error_reporting=E_ALL "${ROOT_DIR}/tests/image_processor_php85_compatibility_check.php"
  else
    SEEN_VERSIONS=" "
    for php_bin in ${MATRIX}; do
      require_command "${php_bin}"
      mm="$("${php_bin}" -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')"
      SEEN_VERSIONS="${SEEN_VERSIONS}${mm} "
      run_step "php-${mm}-lint" bash -c "set -euo pipefail; while IFS= read -r -d '' file; do \"${php_bin}\" -l \"\$file\" >/dev/null; done < <(find . -path './core/webauthn/vendor' -prune -o -name '*.php' -print0)"
      run_step "php-${mm}-passkey-fallback" "${php_bin}" "${ROOT_DIR}/tests/passkey_php_compatibility_check.php"
      run_step "php-${mm}-imageprocessor" "${php_bin}" -d error_reporting=E_ALL "${ROOT_DIR}/tests/image_processor_php85_compatibility_check.php"
    done
    if [[ "${MODE}" == "release" ]]; then
      for required_mm in 7.4 8.0 8.2 8.5; do
        if [[ "${SEEN_VERSIONS}" != *" ${required_mm} "* ]]; then
          echo "Error: PHP matrix is missing ${required_mm}." >&2
          write_report "BLOCKED" "php-compatibility-matrix"
          exit 1
        fi
      done
    fi
  fi
else
  if [[ "${MODE}" == "release" ]]; then
    echo "Error: --skip-matrix is forbidden in release mode." >&2
    exit 1
  fi
  STEPS+=("php-compatibility-matrix|SKIPPED")
fi

if [[ -z "${PRIVATE_KEY}" ]]; then
  PRIVATE_KEY="${TMP_DIR}/installer-test-private.pem"
  TEST_PUBLIC_KEY="${TMP_DIR}/installer-test-public.pem"
  run_step create-test-signing-key openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out "${PRIVATE_KEY}"
  run_step export-test-public-key openssl pkey -in "${PRIVATE_KEY}" -pubout -out "${TEST_PUBLIC_KEY}"
  CANDIDATE_PUBLIC_KEY="${TEST_PUBLIC_KEY}"
else
  CANDIDATE_PUBLIC_KEY="${PUBLIC_KEY}"
fi

run_step installer-release-candidate bash "${ROOT_DIR}/tools/build-installer-release-candidate.sh"   --private-key="${PRIVATE_KEY}"   --public-key="${CANDIDATE_PUBLIC_KEY}"   --output-dir="${INSTALLER_DIR}"   --skip-dependencies

run_step installer-phase5 env   TOMOS_PHASE5_CANDIDATE_DIR="${INSTALLER_DIR}"   TOMOS_PHASE5_PUBLIC_KEY="${CANDIDATE_PUBLIC_KEY}"   php "${ROOT_DIR}/tests/installer_phase5_check.php"

run_step installer-checksums bash -c "cd \"${INSTALLER_DIR}\" && sha256sum -c SHA256SUMS"

write_report "PASS"
echo
echo "LOCAL RELEASE GATE: PASS"
echo "Report: ${REPORT_FILE}"
echo "Artifacts: ${ARTIFACT_DIR}"
if [[ "${MODE}" == "check" ]]; then
  echo "Note: check mode may use a test signing key. Do not publish test-signed artifacts."
fi
