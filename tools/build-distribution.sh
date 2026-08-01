#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION_FILE="${ROOT_DIR}/VERSION"
REQUIRED_FILES_FILE="${ROOT_DIR}/tools/required-distribution-files.txt"

if [[ ! -f "${VERSION_FILE}" ]]; then
  echo "Error: VERSION file is missing."
  exit 1
fi

VERSION="$(tr -d '[:space:]' < "${VERSION_FILE}")"

if [[ -z "${VERSION}" ]]; then
  echo "Error: VERSION file is empty."
  exit 1
fi

if [[ ! -f "${REQUIRED_FILES_FILE}" ]]; then
  echo "Error: tools/required-distribution-files.txt is missing."
  exit 1
fi

BUILD_ROOT="${ROOT_DIR}/build"
BUILD_DIR="${BUILD_ROOT}/tomos"
ZIP_PATH="${BUILD_ROOT}/tomos-${VERSION}.zip"

echo "Tomos distribution build"
echo "Version: ${VERSION}"
echo

echo "Cleaning build directory..."
rm -rf "${BUILD_DIR}"
mkdir -p "${BUILD_DIR}"

copy_item() {
  local source="$1"
  if [[ -e "${ROOT_DIR}/${source}" ]]; then
    rsync -a \
      --exclude='.DS_Store' \
      --exclude='.htpasswd' \
      --exclude='*.tmp' \
      --exclude='*.log' \
      --exclude='pages.json' \
      --exclude='link-aliases.json' \
      --exclude='image-references.json' \
      --exclude='image-deletion-retries.json' \
      --exclude='post-upload-sessions/*.json' \
      --exclude='post-upload-sessions/*-images' \
      --exclude='post-upload-sessions/*.lock' \
      --exclude='post-upload-sessions/*.tmp-*' \
      --include='update-backups/.gitkeep' \
      --include='update-logs/.gitkeep' \
      --include='update-tmp/.gitkeep' \
      --exclude='update-backups/*' \
      --exclude='update-logs/*' \
      --exclude='update-tmp/*' \
      --exclude='update.lock' \
      --exclude='tomos-creator' \
      --exclude='tomos-radical-poster' \
      --exclude='ai-theme-safe-workflow-draft.md' \
      "${ROOT_DIR}/${source}" \
      "${BUILD_DIR}/"
  fi
}

echo "Copying distribution files..."
copy_item "index.php"
copy_item ".htaccess"
copy_item "config.sample.php"
copy_item "README.md"
copy_item "INSTALL.md"
copy_item "DISCLAIMER.md"
copy_item "VERSION"
copy_item "LICENSE"
copy_item "NOTICE"
copy_item "TRADEMARKS.md"
copy_item "CHANGELOG.md"
copy_item "SECURITY.md"
copy_item "KNOWN_LIMITATIONS.md"
copy_item "core"
copy_item "setup"
copy_item "post"
copy_item "update"
copy_item "storage"
copy_item "trash"
copy_item "themes"
copy_item "docs"
copy_item "content"
copy_item "cache"

rm -f "${BUILD_DIR}/config.php"
rm -f "${BUILD_DIR}/post-reset.enable"
find "${BUILD_DIR}" -name '.htpasswd' -delete
rm -f "${BUILD_DIR}/cache/index/pages.json"
rm -f "${BUILD_DIR}/cache/index/link-aliases.json"
rm -f "${BUILD_DIR}/cache/index/image-references.json"
rm -f "${BUILD_DIR}/cache/index/image-deletion-retries.json"
find "${BUILD_DIR}/cache/post-upload-sessions" -mindepth 1 ! -name '.htaccess' ! -name '.gitkeep' -exec rm -rf {} + 2>/dev/null || true
find "${BUILD_DIR}/cache/html" -type f \( -name '*.html' -o -name '*.json' \) -delete 2>/dev/null || true
find "${BUILD_DIR}/cache/logs" -type f \( -name '*.log' -o -name '*.tmp' \) -delete 2>/dev/null || true
find "${BUILD_DIR}/cache/security/post-rate-limit" -type f \( -name '*.json' -o -name '*.tmp' -o -name '*.log' \) -delete 2>/dev/null || true
rm -rf "${BUILD_DIR}/trash/content"
find "${BUILD_DIR}/trash" -type f \( -name '*.json' -o -name '*.tmp' -o -name '*.log' \) -delete 2>/dev/null || true
rm -rf "${BUILD_DIR}/tests"
rm -rf "${BUILD_DIR}/tomos_logo_web_assets"
rm -rf "${BUILD_DIR}/書類"

rm -f "${BUILD_DIR}/content/wiki-test.md"
rm -f "${BUILD_DIR}/content/image-test.md"
rm -f "${BUILD_DIR}/content/tag-test.md"
rm -f "${BUILD_DIR}/content/draft-test.md"
rm -f "${BUILD_DIR}/content/images/sample.png"

mkdir -p "${BUILD_DIR}/cache/index"
mkdir -p "${BUILD_DIR}/cache/html"
mkdir -p "${BUILD_DIR}/cache/logs"
mkdir -p "${BUILD_DIR}/cache/post-upload-sessions"
mkdir -p "${BUILD_DIR}/cache/security/post-rate-limit"
mkdir -p "${BUILD_DIR}/storage/update-backups"
mkdir -p "${BUILD_DIR}/storage/update-logs"
mkdir -p "${BUILD_DIR}/storage/update-tmp"
mkdir -p "${BUILD_DIR}/trash"
touch "${BUILD_DIR}/cache/.gitkeep"
touch "${BUILD_DIR}/cache/index/.gitkeep"
touch "${BUILD_DIR}/cache/html/.gitkeep"
touch "${BUILD_DIR}/cache/logs/.gitkeep"
touch "${BUILD_DIR}/cache/post-upload-sessions/.gitkeep"
touch "${BUILD_DIR}/cache/security/post-rate-limit/.gitkeep"
touch "${BUILD_DIR}/storage/.gitkeep"
touch "${BUILD_DIR}/storage/update-backups/.gitkeep"
touch "${BUILD_DIR}/storage/update-logs/.gitkeep"
touch "${BUILD_DIR}/storage/update-tmp/.gitkeep"
touch "${BUILD_DIR}/trash/.gitkeep"

find "${BUILD_DIR}" -name '.DS_Store' -delete

require_file() {
  local path="$1"
  if [[ ! -f "${BUILD_DIR}/${path}" ]]; then
    echo "Error: build/tomos/${path} is missing."
    exit 1
  fi
}

reject_path() {
  local path="$1"
  if [[ -e "${BUILD_DIR}/${path}" ]]; then
    echo "Error: build/tomos/${path} must not be included in distribution."
    exit 1
  fi
}

echo "Checking distribution folder..."
while IFS= read -r path || [[ -n "${path}" ]]; do
  [[ -z "${path}" ]] && continue
  require_file "${path}"
done < "${REQUIRED_FILES_FILE}"

reject_path "config.php"
reject_path "post-reset.enable"
reject_path ".htpasswd"
reject_path "cache/index/pages.json"
reject_path "cache/index/link-aliases.json"
reject_path "cache/index/image-references.json"
reject_path "cache/index/image-deletion-retries.json"
reject_path "tests"
reject_path "tomos_logo_web_assets"
reject_path "書類"
reject_path "themes/tomos-creator"
reject_path "themes/tomos-radical-poster"
reject_path "docs/theme/ai-theme-safe-workflow-draft.md"

if grep -RhoE 'G-[A-Z0-9]{8,}' "${BUILD_DIR}" \
  --exclude='*.png' --exclude='*.jpg' --exclude='*.jpeg' --exclude='*.gif' --exclude='*.webp' \
  | grep -Fvx 'G-XXXXXXXXXX' >/dev/null; then
  echo "Error: a non-placeholder GA4 measurement ID must not be included in the distribution."
  exit 1
fi

if find "${BUILD_DIR}/cache/html" -type f \( -name '*.html' -o -name '*.json' \) -print -quit | grep -q .; then
  echo "Error: generated HTML cache files must not be included in distribution."
  exit 1
fi

if find "${BUILD_DIR}/cache/logs" -type f \( -name '*.log' -o -name '*.tmp' \) -print -quit | grep -q .; then
  echo "Error: generated performance log files must not be included in distribution."
  exit 1
fi

if find "${BUILD_DIR}/cache/security/post-rate-limit" -type f \( -name '*.json' -o -name '*.tmp' -o -name '*.log' \) -print -quit | grep -q .; then
  echo "Error: generated rate limit files must not be included in distribution."
  exit 1
fi

if find "${BUILD_DIR}/cache/post-upload-sessions" -mindepth 1 ! -name '.htaccess' ! -name '.gitkeep' -print -quit | grep -q .; then
  echo "Error: generated post upload session files must not be included in distribution."
  exit 1
fi

if find "${BUILD_DIR}/storage" -type f ! -name '.htaccess' ! -name '.gitkeep' -print -quit | grep -q .; then
  echo "Error: generated Tomos Update data must not be included in distribution."
  exit 1
fi

if [[ -d "${BUILD_DIR}/trash/content" ]]; then
  echo "Error: trash/content must not be included in distribution."
  exit 1
fi

if find "${BUILD_DIR}/trash" -type f \( -name '*.json' -o -name '*.tmp' -o -name '*.log' \) -print -quit | grep -q .; then
  echo "Error: generated trash metadata files must not be included in distribution."
  exit 1
fi

if find "${BUILD_DIR}" -name '.DS_Store' -print -quit | grep -q .; then
  echo "Error: .DS_Store must not be included in distribution."
  exit 1
fi

if find "${BUILD_DIR}" -name '.htpasswd' -print -quit | grep -q .; then
  echo "Error: .htpasswd must not be included in distribution."
  exit 1
fi

if ! command -v zip >/dev/null 2>&1; then
  echo "Warning: zip command is not available. ZIP package was not created."
  echo
  echo "Distribution folder:"
  echo "${BUILD_DIR}"
  exit 0
fi

echo "Creating ZIP package..."
rm -f "${ZIP_PATH}"
(
  cd "${BUILD_DIR}"
  zip -qr "${ZIP_PATH}" .
)

if [[ ! -f "${ZIP_PATH}" ]]; then
  echo "Error: ZIP package was not created."
  exit 1
fi

if command -v unzip >/dev/null 2>&1; then
  echo "Checking ZIP package..."
  ZIP_LIST="$(unzip -Z1 "${ZIP_PATH}")"

  zip_require() {
    local path="$1"
    if ! printf '%s\n' "${ZIP_LIST}" | grep -Fxq "${path}"; then
      echo "Error: ${path} is missing from ZIP."
      exit 1
    fi
  }

  zip_reject_exact() {
    local path="$1"
    if printf '%s\n' "${ZIP_LIST}" | grep -Fxq "${path}"; then
      echo "Error: ${path} must not be included in ZIP."
      exit 1
    fi
  }

  zip_reject_prefix() {
    local prefix="$1"
    if printf '%s\n' "${ZIP_LIST}" | grep -E "^${prefix}" >/dev/null; then
      echo "Error: ${prefix} must not be included in ZIP."
      exit 1
    fi
  }

  while IFS= read -r path || [[ -n "${path}" ]]; do
    [[ -z "${path}" ]] && continue
    zip_require "${path}"
  done < "${REQUIRED_FILES_FILE}"

  zip_reject_exact "config.php"
  zip_reject_exact "post-reset.enable"
  zip_reject_exact ".htpasswd"
  zip_reject_exact "cache/index/pages.json"
  zip_reject_exact "cache/index/link-aliases.json"
  zip_reject_exact "cache/index/image-references.json"
  zip_reject_exact "cache/index/image-deletion-retries.json"
  if printf '%s\n' "${ZIP_LIST}" | grep -E '^cache/post-upload-sessions/.+\.(json|lock)$|^cache/post-upload-sessions/.+-images/' >/dev/null; then
    echo "Error: generated post upload session files must not be included in ZIP."
    exit 1
  fi
  if printf '%s\n' "${ZIP_LIST}" | grep -Eq '^cache/html/.*\.(html|json)$'; then
    echo "Error: generated HTML cache files must not be included in ZIP."
    exit 1
  fi
  if printf '%s\n' "${ZIP_LIST}" | grep -Eq '^cache/security/post-rate-limit/.*\.(json|tmp|log)$'; then
    echo "Error: generated rate limit files must not be included in ZIP."
    exit 1
  fi
  zip_reject_prefix "trash/content/"
  if printf '%s\n' "${ZIP_LIST}" | grep -Eq '^trash/.*\.(json|tmp|log)$'; then
    echo "Error: generated trash metadata files must not be included in ZIP."
    exit 1
  fi
  if printf '%s\n' "${ZIP_LIST}" | grep -E '^storage/.+' | grep -Ev '^storage/(\.htaccess|\.gitkeep|update-backups/(\.gitkeep)?|update-logs/(\.gitkeep)?|update-tmp/(\.gitkeep)?)$' >/dev/null; then
    echo "Error: generated Tomos Update data must not be included in ZIP."
    exit 1
  fi
  zip_reject_prefix "tests/"
  zip_reject_prefix "tomos_logo_web_assets/"
  zip_reject_prefix "書類/"

  if printf '%s\n' "${ZIP_LIST}" | grep -Eq '(^|/)\.DS_Store$'; then
    echo "Error: .DS_Store must not be included in ZIP."
    exit 1
  fi

  if printf '%s\n' "${ZIP_LIST}" | grep -Eq '(^|/)\.htpasswd$'; then
    echo "Error: .htpasswd must not be included in ZIP."
    exit 1
  fi

  if printf '%s\n' "${ZIP_LIST}" | grep -Fxq "tomos/index.php"; then
    echo "Error: ZIP contains tomos/index.php. index.php must be at ZIP root."
    exit 1
  fi
else
  echo "Warning: unzip command is not available. ZIP content inspection was skipped."
fi

echo
echo "Distribution build completed."
echo
echo "Folder:"
echo "${BUILD_DIR}"
echo
echo "ZIP:"
echo "${ZIP_PATH}"
