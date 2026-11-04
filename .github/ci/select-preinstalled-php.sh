#!/usr/bin/env bash
# The isolated runner image supplies PHP; never install tools as the PR job.
set -euo pipefail
case "${1:-}" in
  8.2|8.4) php_version="$1" ;;
  *) echo 'Unsupported CI PHP version' >&2; exit 1 ;;
esac
[ "$(id -u)" -ne 0 ] || { echo 'Isolated CI must run as nonroot' >&2; exit 1; }
: "${RUNNER_TEMP:?}" "${GITHUB_PATH:?}" "${GITHUB_ENV:?}"
php_binary="$(command -v "php${php_version}")"
test -x "$php_binary"
"$php_binary" -r '
$expected = $argv[1];
if (PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION !== $expected) { exit(1); }
foreach (["bcmath", "curl", "dom", "fileinfo", "gd", "intl", "json", "mbstring", "mysqli", "pgsql", "pdo_pgsql", "simplexml", "zip"] as $extension) {
    if (!extension_loaded($extension)) { fwrite(STDERR, "Required PHP extension absent: " . $extension . "\n"); exit(1); }
}
if (extension_loaded("xdebug") || extension_loaded("pcov")) { exit(1); }
' "$php_version"
php_bin_dir="$(mktemp -d "${RUNNER_TEMP}/studio-php.XXXXXX")"
ln -s "$php_binary" "${php_bin_dir}/php"
printf '%s\n' "$php_bin_dir" >> "$GITHUB_PATH"
printf '%s\n' 'opcache.jit_buffer_size=0' > "${php_bin_dir}/ci.ini"
printf 'PHP_INI_SCAN_DIR=:%s\n' "$php_bin_dir" >> "$GITHUB_ENV"
