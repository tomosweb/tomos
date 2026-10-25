#!/usr/bin/env bash
# Offline preflight for PR #114. Does not deploy, change config.php,
# connect to production, or depend on GitHub Actions.
set -euo pipefail
cd "$(dirname "$0")/.."

command -v php >/dev/null 2>&1 || { echo "ERROR: php CLI is required" >&2; exit 1; }
echo "PHP: $(php -r 'echo PHP_VERSION;')"
echo "[1/3] Lint all first-party PHP"
while IFS= read -r -d '' file; do
    php -l "$file" >/dev/null
done < <(find . -path './core/webauthn/vendor' -prune -o -name '*.php' -print0)

echo "[2/3] Authentication and passkey regression tests"
tests=(
    tests/post_auth_policy_check.php
    tests/post_auth_remember_token_check.php
    tests/post_auth_wall_check.php
    tests/post_rate_limiter_check.php
    tests/passkey_security_navigation_check.php
    tests/passkey_authentication_service_check.php
    tests/passkey_registration_service_check.php
    tests/passkey_management_service_check.php
    tests/passkey_password_reset_service_check.php
    tests/passkey_server_recovery_service_check.php
)
for script in "${tests[@]}"; do
    echo "Running $script"
    php "$script"
done

echo "[3/3] Distribution/runtime integrity"
php tests/passkey_php_compatibility_check.php
if [ -f core/webauthn/vendor/autoload.php ]; then
    php tests/passkey_distribution_check.php
else
    echo "WARNING: WebAuthn vendor runtime not present; distribution test NOT RUN"
    echo "The runtime must be built and tested before release"
fi

echo "Offline authentication preflight finished. This is NOT an actual WebAuthn browser or Sakura server test."
