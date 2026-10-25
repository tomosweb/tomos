#!/usr/bin/env bash
# PR 114 isolated test fixtures only. No publishing, network, official signing key or user config.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT/build/pr114-validation}"
mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
TEMP="$(mktemp -d "${TMPDIR:-/tmp}/tomos-pr114-fixture.XXXXXX")"
trap 'rm -rf "$TEMP"' EXIT
export RANDFILE="$TEMP/openssl-rand"
bash "$ROOT/tools/build-distribution.sh"
mkdir -p "$TEMP/site"
rsync -a "$ROOT/build/tomos/" "$TEMP/site/"
cp -R "$ROOT/tools" "$TEMP/site/tools"
php -r '$k=openssl_pkey_new(["private_key_bits"=>2048,"private_key_type"=>OPENSSL_KEYTYPE_RSA]); if (!$k || !openssl_pkey_export($k,$s)) exit(1); file_put_contents($argv[1],$s); chmod($argv[1],0600); $d=openssl_pkey_get_details($k); file_put_contents($argv[2],$d["key"]);' "$TEMP/private.pem" "$TEMP/site/update/public-key.pem"
printf '%s\n' '1.1.12-pr114.1' > "$TEMP/site/VERSION"
bash "$TEMP/site/tools/build-distribution.sh"
cp "$TEMP/site/build/tomos-1.1.12-pr114.1.zip" "$OUT/tomos-pr114-validation.zip"
printf '%s\n' '1.1.12-pr114.2' > "$TEMP/site/VERSION"
php "$TEMP/site/tools/build-update-package.php" --from=1.1.12-pr114.1 --version=1.1.12-pr114.2 \
  --private-key="$TEMP/private.pem" --output="$OUT/tomos-pr114-update-validation.zip" \
  --file=VERSION --file=update/index.php --file=core/required-installed-files.txt
# Validate the signed update and self-update finalization using the exact generated artifacts.
cp "$ROOT/tests/post_security_validation_artifacts.php" "$TEMP/artifact-check.php"
php "$TEMP/artifact-check.php" "$TEMP/site/build/tomos" "$OUT/tomos-pr114-update-validation.zip"
cp "$TEMP/site/update/public-key.pem" "$OUT/validation-public-key.pem"
php -r '$paths=array_slice($argv,1); foreach($paths as $p) echo hash_file("sha256",$p),"  ",basename($p),PHP_EOL;' \
  "$OUT/tomos-pr114-validation.zip" "$OUT/tomos-pr114-update-validation.zip" "$OUT/validation-public-key.pem" > "$OUT/SHA256SUMS"
echo 'ISOLATED VALIDATION ONLY: test signing key and prerelease versions; no production deployment.'
echo "Artifacts: $OUT"
