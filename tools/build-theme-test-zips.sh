#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUTPUT_DIR="${ROOT_DIR}/build/theme-test"

if [[ "${1:-}" == --output-dir=* ]]; then
  OUTPUT_DIR="${1#*=}"
elif [[ "${1:-}" != "" ]]; then
  echo "Usage: $0 [--output-dir=PATH]" >&2
  exit 2
fi

mkdir -p "$OUTPUT_DIR"
tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

build_theme() {
  local slug="$1"
  local manifest="$ROOT_DIR/themes/$slug/theme.json"
  local version
  local output="$OUTPUT_DIR/${slug}-${2}-test.zip"

  version="$(php -r '$json = json_decode((string) file_get_contents($argv[1]), true); echo is_array($json) ? (string) ($json["version"] ?? "") : "";' "$manifest")"
  [[ -n "$version" ]] || { echo "$slug: theme version is missing" >&2; exit 1; }
  [[ "$version" == "$2" ]] || { echo "$slug: expected version $2, got $version" >&2; exit 1; }

  (cd "$ROOT_DIR/themes" && zip -q -r -X "$tmp_dir/$slug.zip" "$slug")
  unzip -tq "$tmp_dir/$slug.zip"
  local entries
  entries="$(unzip -Z1 "$tmp_dir/$slug.zip")"
  [[ "$(printf '%s\n' "$entries" | awk -F/ 'NF > 1 {print $1}' | sort -u)" == "$slug" ]] || {
    echo "$slug: ZIP has an unexpected top-level directory" >&2
    exit 1
  }
  for required in theme.json templates/layout.html templates/page.html templates/list.html assets/style.css; do
    grep -Fxq "$slug/$required" <<<"$entries" || {
      echo "$slug: missing required file $required" >&2
      exit 1
    }
  done
  if grep -Eiq '(^|/)[^/]+\.php$' <<<"$entries"; then
    echo "$slug: PHP file found in Theme ZIP" >&2
    exit 1
  fi
  mv "$tmp_dir/$slug.zip" "$output"
  printf '%s\t%s\t%s bytes\t%s\n' "$output" "$version" "$(wc -c < "$output" | tr -d ' ')" "$(sha256sum "$output" | awk '{print $1}')"
}

build_theme tomos-quiet 1.0.5
build_theme tomos-index 1.0.6
