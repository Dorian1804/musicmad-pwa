#!/usr/bin/env sh
set -eu
exec "${PHP_BIN:-php}" "$(dirname "$0")/package.php" "$@"
